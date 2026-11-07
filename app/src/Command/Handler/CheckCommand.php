<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\Validation\ProjectValidator;
use Campanella\Lite\Validation\Violation;

/**
 * Validates the whole project and lists every problem, including those
 * that existed before (for example from hand-edited files).
 */
final class CheckCommand implements CommandHandler
{
    public function __construct(private readonly ProjectValidator $validator = new ProjectValidator())
    {
    }

    public function signature(): Signature
    {
        return new Signature(name: 'check', examples: ['check'], readOnly: true);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $violations = $this->validator->validate($workspace->project());

        if ($violations === []) {
            return CommandResult::ok('ok.check_clean');
        }

        $rows = array_map(
            static fn (Violation $violation): array => [$violation->path, new Message('violation.' . $violation->code, $violation->params)],
            $violations,
        );

        return CommandResult::ok('ok.check_problems', ['count' => count($violations)], Output::table(['path', 'problem'], $rows));
    }
}
