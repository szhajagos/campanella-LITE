<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin\Session;

/**
 * The admin's session. An interface, so the controller can be tested without PHP's session functions.
 */
interface Session
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Gives the session a new id, keeping its data (after login, against session fixation). */
    public function regenerate(): void;

    /** Removes all data and ends the session. */
    public function destroy(): void;
}
