<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\EnvLoader;
use App\Database\Connection;
use App\Repositories\SettingsRepository;
use PDO;

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

    public function getCsrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public function verifyCsrfToken(?string $token): bool
    {
        self::startSession();
        if (empty($_SESSION['csrf_token']) || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals((string) $_SESSION['csrf_token'], $token);
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

        $pdo = Connection::getInstance();
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => trim($username)]);
        $user = $stmt->fetch();

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

    public function verifyLanOrToken(?string $token): bool
    {
        $settings = new SettingsRepository();
        $expectedToken = (string) $settings->get('output_token', '');
        $enforceLan = (string) $settings->get('enforce_lan_ip', '1') === '1';

        if ($expectedToken !== '') {
            if (!is_string($token) || $token === '' || !hash_equals($expectedToken, $token)) {
                return false;
            }
        }

        if ($enforceLan) {
            $clientIp = self::getClientIp();
            if (!self::isIpInAllowedSubnets($clientIp)) {
                return false;
            }
        }

        return true;
    }

    public static function getClientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function isIpInAllowedSubnets(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        $subnetsRaw = EnvLoader::get('LAN_SUBNET_ALLOW', '192.168.0.0/16,10.0.0.0/8');
        $subnets = array_filter(array_map('trim', explode(',', (string) $subnetsRaw)));

        foreach ($subnets as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskInt = (int) $mask;

        if ($ipLong === false || $subnetLong === false || $maskInt < 0 || $maskInt > 32) {
            return false;
        }

        $maskLong = $maskInt === 0 ? 0 : (~0 << (32 - $maskInt));
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
