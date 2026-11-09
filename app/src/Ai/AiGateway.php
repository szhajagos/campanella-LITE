<?php

declare(strict_types=1);

namespace Campanella\Lite\Ai;

use Campanella\Lite\Admin\User;
use Campanella\Lite\Command\Interpreter;
use Campanella\Lite\Command\Log\LogEntry;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Files\FileRejected;
use Campanella\Lite\Files\FileRepository;
use Campanella\Lite\Model\File;

/**
 * Everything an AI user can do, whatever way it connects (the JSON API,
 * MCP): run batches within its rights, propose batches that need approval,
 * add files. The dashboard uses it to approve and reject proposals.
 */
final class AiGateway
{
    public function __construct(
        private readonly Interpreter $interpreter,
        private readonly ProposalStore $proposals,
        private readonly FileRepository $files,
    ) {
    }

    /**
     * Runs a batch for an AI user, or proposes it when it needs approval.
     *
     * A batch that would be rejected or would break a rule is never
     * proposed; the dry run's result is returned instead.
     */
    public function submit(User $ai, string $commands, bool $dryRun = false, string $session = ''): Submission
    {
        $authorize = static fn (Signature $signature): bool => $ai->allows($signature);
        $signatures = $this->interpreter->signatures($commands);

        if ($dryRun || $signatures === null || !$ai->needsApproval($signatures)) {
            return Submission::done($this->interpreter->run($commands, $dryRun, $ai->name, $authorize));
        }

        $preview = $this->interpreter->run($commands, true, $ai->name, $authorize);

        if (!in_array($preview->outcome, [Outcome::DryRun, Outcome::Unchanged], true)) {
            return Submission::done($preview);
        }

        [$id, $time] = LogEntry::stamp();
        $proposal = new Proposal($id, $time, $ai->name, self::session($session), $commands, Proposal::summarize($preview));
        $this->proposals->save($proposal);

        return Submission::pending($proposal, $preview);
    }

    /**
     * Runs a proposed batch now, in the AI user's name and within its rights,
     * and records the admin who approved it.
     */
    public function approve(Proposal $proposal, User $ai, User $admin): BatchResult
    {
        $result = $this->interpreter->run(
            $proposal->batch,
            false,
            $ai->name,
            static fn (Signature $signature): bool => $ai->allows($signature),
            $admin->name,
        );
        $status = in_array($result->outcome, [Outcome::Applied, Outcome::Unchanged], true) ? Proposal::APPLIED : Proposal::FAILED;
        [, $time] = LogEntry::stamp();
        $this->proposals->save($proposal->decided($status, $admin->name, $time, Proposal::summarize($result)));

        return $result;
    }

    public function reject(Proposal $proposal, User $admin, ?string $reason = null): Proposal
    {
        [, $time] = LogEntry::stamp();
        $decided = $proposal->decided(Proposal::REJECTED, $admin->name, $time, null, $reason);
        $this->proposals->save($decided);

        return $decided;
    }

    /**
     * Adds a file to the repository for an AI user. Adding a file changes
     * nothing on the website, so it never needs approval.
     *
     * @throws FileRejected
     */
    public function upload(User $ai, string $path, string $name): File
    {
        if (!$ai->allowsName('file upload')) {
            throw new FileRejected('command.not_allowed', ['command' => 'file upload', 'user' => $ai->name]);
        }

        return $this->files->add($path, $name, $ai->name);
    }

    public function proposals(): ProposalStore
    {
        return $this->proposals;
    }

    /** A session name of at most 64 plain characters; empty becomes "default". */
    private static function session(string $session): string
    {
        $session = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $session)), 0, 64);

        return $session === '' ? 'default' : $session;
    }
}
