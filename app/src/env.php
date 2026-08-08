<?php

declare(strict_types=1);

const APP_TIMEZONE = 'Asia/Taipei';

date_default_timezone_set(APP_TIMEZONE);

function app_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    static $loaded = false;
    static $values = [];

    if (!$loaded) {
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$envKey, $envValue] = explode('=', $line, 2);
                $values[trim($envKey)] = trim(trim($envValue), "\"'");
            }
        }
        $loaded = true;
    }

    return $values[$key] ?? $default;
}
