<?php

declare(strict_types=1);

namespace Campanella\Lite\Ai;

use Campanella\Lite\Admin\ApiToken;
use Campanella\Lite\Admin\AuthLog;
use Campanella\Lite\Admin\LoginThrottle;
use Campanella\Lite\Admin\User;
use Campanella\Lite\Admin\UserRepository;
use Campanella\Lite\Http\Request;

/**
 * Finds the AI user behind the token of a request ("Authorization: Bearer cl_…").
 * Failed attempts are throttled per address (like admin logins) and logged.
 */
final class TokenAuthenticator
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottle $throttle,
        private readonly AuthLog $log,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /** Seconds the address has to wait before it may try again; 0 if it may now. */
    public function retryAfter(Request $request): int
    {
        return $this->throttle->retryAfter($request->clientAddress, $this->now());
    }

    /**
     * @return array{0: User, 1: ApiToken}|null
     */
    public function authenticate(Request $request): ?array
    {
        $now = $this->now();
        $header = $request->header('authorization') ?? '';
        $found = str_starts_with($header, 'Bearer ')
            ? $this->users->findByToken(substr($header, 7), (new \DateTimeImmutable())->setTimestamp($now))
            : null;

        if ($found === null && $header !== '') {
            $this->throttle->recordFailure($request->clientAddress, $now);
            $this->log->record('api_token_failed', ApiToken::parse(substr($header, 7))[0] ?? '-', $request->clientAddress, $now);
        }

        return $found;
    }
}
