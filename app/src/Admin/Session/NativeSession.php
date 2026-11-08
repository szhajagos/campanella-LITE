<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin\Session;

/**
 * PHP's own session, with hardened settings: a dedicated cookie limited to
 * /admin, HttpOnly, SameSite=Strict, Secure on HTTPS, strict mode (unknown
 * session ids are refused) and no session id in URLs.
 */
final class NativeSession implements Session
{
    public const NAME = 'campanella_admin';

    private bool $started = false;

    public function __construct(private readonly bool $secure)
    {
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        setcookie(self::NAME, '', ['expires' => 1, 'path' => '/admin', 'secure' => $this->secure, 'httponly' => true, 'samesite' => 'Strict']);
        $this->started = false;
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/admin',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        $this->started = true;
    }
}
