<?php

declare(strict_types=1);

namespace Campanella\Lite\Ai;

use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\I18n\Message;

/**
 * A batch an AI user proposed, waiting for an administrator, or decided.
 */
final class Proposal
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';

    /**
     * @param array{outcome: string, summary: Message, files: list<string>}       $preview what the dry run showed when it was proposed
     * @param array{outcome: string, summary: Message, files: list<string>}|null  $result  what happened when it was approved
     */
    public function __construct(
        public readonly string $id,
        public readonly string $createdAt,
        public readonly string $user,
        public readonly string $session,
        public readonly string $batch,
        public readonly array $preview,
        public readonly string $status = self::PENDING,
        public readonly ?string $decidedBy = null,
        public readonly ?string $decidedAt = null,
        public readonly ?array $result = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * @return array{outcome: string, summary: Message, files: list<string>}
     */
    public static function summarize(BatchResult $result): array
    {
        return ['outcome' => $result->outcome->value, 'summary' => $result->summary, 'files' => $result->changedFiles];
    }

    /**
     * @param array{outcome: string, summary: Message, files: list<string>}|null $result
     */
    public function decided(string $status, string $by, string $at, ?array $result = null, ?string $reason = null): self
    {
        return new self($this->id, $this->createdAt, $this->user, $this->session, $this->batch, $this->preview, $status, $by, $at, $result, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $summary = static fn (array $data): array => [
            'outcome' => $data['outcome'],
            'summary' => ['key' => $data['summary']->key, 'params' => $data['summary']->params],
            'files' => $data['files'],
        ];

        return array_filter([
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'user' => $this->user,
            'session' => $this->session,
            'batch' => $this->batch,
            'preview' => $summary($this->preview),
            'status' => $this->status,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt,
            'result' => $this->result === null ? null : $summary($this->result),
            'reason' => $this->reason,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $summary = static function (mixed $value): ?array {
            if (!is_array($value) || !is_string($value['outcome'] ?? null) || !is_array($value['summary'] ?? null)
                || !is_string($value['summary']['key'] ?? null)) {
                return null;
            }

            $params = array_filter(
                is_array($value['summary']['params'] ?? null) ? $value['summary']['params'] : [],
                static fn (mixed $param): bool => is_string($param) || is_int($param),
            );

            /** @var array<string, string|int> $params */
            return [
                'outcome' => $value['outcome'],
                'summary' => new Message($value['summary']['key'], $params),
                'files' => array_values(array_filter(is_array($value['files'] ?? null) ? $value['files'] : [], 'is_string')),
            ];
        };

        $preview = $summary($data['preview'] ?? null);
        $id = $text('id');
        $user = $text('user');
        $batch = $text('batch');

        if ($preview === null || $id === null || $user === null || $batch === null) {
            return null;
        }

        return new self(
            $id,
            $text('created_at') ?? '',
            $user,
            $text('session') ?? '',
            $batch,
            $preview,
            $text('status') ?? self::PENDING,
            $text('decided_by'),
            $text('decided_at'),
            $summary($data['result'] ?? null),
            $text('reason'),
        );
    }
}
