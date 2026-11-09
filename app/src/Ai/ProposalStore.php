<?php

declare(strict_types=1);

namespace Campanella\Lite\Ai;

use Campanella\Lite\Storage\FileStore;

/**
 * Proposals in var/proposals/{id}.json; the newest 500 decided ones are kept.
 */
final class ProposalStore
{
    private const DIRECTORY = 'proposals';
    private const KEEP_DECIDED = 500;

    public function __construct(private readonly FileStore $var)
    {
    }

    public function save(Proposal $proposal): void
    {
        $this->var->write(
            self::DIRECTORY . '/' . $proposal->id . '.json',
            json_encode($proposal->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        );

        if (!$proposal->isPending()) {
            $this->prune();
        }
    }

    public function find(string $id): ?Proposal
    {
        if (preg_match('/^[0-9]{8}-[0-9]{6}-[0-9]{6}$/', $id) !== 1 || !$this->var->exists(self::DIRECTORY . '/' . $id . '.json')) {
            return null;
        }

        $data = json_decode($this->var->read(self::DIRECTORY . '/' . $id . '.json'), true);

        return is_array($data) ? Proposal::fromArray($data) : null;
    }

    /**
     * Proposals, newest first.
     *
     * @return list<Proposal>
     */
    public function list(?string $user = null, ?bool $pending = null, int $limit = 50): array
    {
        $ids = $this->var->listFiles(self::DIRECTORY, 'json');
        rsort($ids, SORT_STRING);
        $proposals = [];

        foreach ($ids as $id) {
            $proposal = $this->find($id);

            if ($proposal === null || ($user !== null && $proposal->user !== $user)
                || ($pending !== null && $proposal->isPending() !== $pending)) {
                continue;
            }

            $proposals[] = $proposal;

            if (count($proposals) === $limit) {
                break;
            }
        }

        return $proposals;
    }

    private function prune(): void
    {
        $decided = array_slice($this->list(pending: false, limit: PHP_INT_MAX), self::KEEP_DECIDED);

        foreach ($decided as $proposal) {
            $this->var->delete(self::DIRECTORY . '/' . $proposal->id . '.json');
        }
    }
}
