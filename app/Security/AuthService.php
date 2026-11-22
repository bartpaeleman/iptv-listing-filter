<?php

declare(strict_types=1);

namespace App\Security;

use App\Repositories\UserRepository;

final class AuthService
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 300;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isHttps,
        ]);
        session_start();
    }

    public function isAuthenticated(): bool
    {
        self::startSession();
        return !empty($_SESSION['user_id']);
    }

    public function currentUsername(): ?string
    {
        self::startSession();
        return isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function login(string $username, string $password): array
    {
        self::startSession();

        $now = time();
        $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            $remaining = $lockedUntil - $now;
            return ['ok' => false, 'error' => "Te veel mislukte pogingen. Probeer opnieuw over {$remaining}s."];
        }

        $users = new UserRepository();
        $user = $users->findByUsername(trim($username));

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
                $_SESSION['login_locked_until'] = $now + self::LOCKOUT_SECONDS;
                $_SESSION['login_attempts'] = 0;
                return ['ok' => false, 'error' => 'Account tijdelijk geblokkeerd (5 min) na te veel mislukte pogingen.'];
            }
            return ['ok' => false, 'error' => 'Ongeldige gebruikersnaam of wachtwoord.'];
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = (string) $user['username'];
        $_SESSION['login_attempts'] = 0;
        unset($_SESSION['login_locked_until']);

        return ['ok' => true];
    }

    public function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
