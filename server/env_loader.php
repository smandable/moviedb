<?php
/**
 * Lightweight .env loader.
 * Reads key=value pairs from a .env file and registers them
 * via putenv() / $_ENV so getenv() works everywhere.
 */
function loadEnv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return; // Silently skip — config.php fallback values will be used
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        // Only process lines with an = sign
        if (strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Don't overwrite existing environment variables
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Auto-load the .env file from the same directory
loadEnv(__DIR__ . '/.env');
