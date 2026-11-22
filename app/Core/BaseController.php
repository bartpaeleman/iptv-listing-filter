<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\AuthService;
use App\Security\CsrfGuard;
use RuntimeException;

abstract class BaseController
{
    protected AuthService $auth;
    protected CsrfGuard $csrf;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->csrf = new CsrfGuard();
    }

    protected function jsonSuccess(mixed $data = null, int $status = 200): never
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function jsonError(string $message, int $status = 400): never
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function requireAuth(): void
    {
        if (!$this->auth->isAuthenticated()) {
            $this->jsonError('Niet ingelogd.', 401);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function verifyCsrf(?array $input = null): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new RuntimeException('POST vereist.');
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? ($input['csrf_token'] ?? $_POST['csrf_token'] ?? '');
        if (!$this->csrf->verify(is_string($token) ? $token : null)) {
            $this->jsonError('Ongeldig CSRF-token.', 403);
        }
    }
}
