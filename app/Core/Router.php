<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, array{0: class-string, 1: string}> */
    private array $apiRoutes = [];

    /**
     * @param class-string $controllerClass
     */
    public function addApiRoute(string $endpoint, string $controllerClass, string $method): void
    {
        $this->apiRoutes[$endpoint] = [$controllerClass, $method];
    }

    public function dispatchApi(string $endpoint): void
    {
        if (!isset($this->apiRoutes[$endpoint])) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Onbekend API endpoint.']);
            exit;
        }

        [$class, $method] = $this->apiRoutes[$endpoint];
        $fqcn = str_contains($class, '\\') ? $class : 'App\\Controllers\\' . $class;
        if (!class_exists($fqcn)) {
            throw new \RuntimeException("Controller '{$fqcn}' niet gevonden.");
        }
        $controller = new $fqcn();
        $controller->$method();
    }
}
