<?php

declare(strict_types=1);

namespace App\Security;

final class CsrfGuard
{
    public function getToken(): string
    {
        AuthService::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public function verify(?string $token): bool
    {
        AuthService::startSession();
        if (empty($_SESSION['csrf_token']) || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}
