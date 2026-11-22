<?php

declare(strict_types=1);

namespace App\Core;

final class EnvLoader
{
    private static bool $loaded = false;
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(?string $envPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        $path = $envPath ?? dirname(__DIR__, 2) . '/.env';
        if (is_file($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    $eqPos = strpos($trimmed, '=');
                    if ($eqPos === false) {
                        continue;
                    }

                    $key = trim(substr($trimmed, 0, $eqPos));
                    $val = trim(substr($trimmed, $eqPos + 1));

                    if (
                        (str_starts_with($val, '"') && str_ends_with($val, '"'))
                        || (str_starts_with($val, "'") && str_ends_with($val, "'"))
                    ) {
                        $val = substr($val, 1, -1);
                    }

                    self::$values[$key] = $val;
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $val;
                    }
                    if (getenv($key) === false) {
                        putenv("{$key}={$val}");
                    }
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (!self::$loaded) {
            self::load();
        }

        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }

        $envVal = getenv($key);
        return $envVal !== false ? $envVal : $default;
    }
}
