<?php
// Lightweight environment loader for TileMasterAI.
// Reads key=value pairs from the project root .env file (if present) and
// populates PHP's environment without exposing secrets in code.

declare(strict_types=1);

$envPath = dirname(__DIR__) . '/.env';

if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);

        // Skip comments and malformed lines.
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if ($name === '') {
            continue;
        }

        // Strip optional surrounding quotes.
        $value = trim($value, " \t\n\r\0\x0B\"' ");

        // Only set if not already defined to avoid overriding server-provided env.
        if (getenv($name) === false) {
            putenv("{$name}={$value}");
        }

        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }

        if (!array_key_exists($name, $_SERVER)) {
            $_SERVER[$name] = $value;
        }
    }
}
