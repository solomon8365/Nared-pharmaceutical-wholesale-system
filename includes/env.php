<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free .env loader + environment accessors.
 *
 * Credentials (MySQL password, Supabase keys) must live in server-side
 * environment variables, never in committed code. This loader reads a
 * project-root `.env` file (git-ignored) and exposes the values through
 * getenv(), $_ENV and $_SERVER so the rest of the app can read them via
 * nared_env().
 *
 * Precedence: real process/system environment variables ALWAYS win over the
 * `.env` file, so production can inject secrets without a file on disk.
 */

if (!function_exists('nared_env_load')) {
    /**
     * Parse a .env file and populate the environment (without overriding
     * variables that already exist).
     */
    function nared_env_load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            // Support an optional leading "export ".
            if (stripos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            if ($key === '') {
                continue;
            }

            $value = trim(substr($line, $eq + 1));

            // Strip matching surrounding quotes.
            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Do not override an already-present (real) environment variable.
            $existing = getenv($key);
            if ($existing !== false && $existing !== '') {
                continue;
            }
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                continue;
            }
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('nared_env')) {
    /**
     * Read a string environment variable. Returns $default when unset or
     * empty (so an unconfigured Supabase key is treated as "not set").
     */
    function nared_env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }
}

if (!function_exists('nared_env_bool')) {
    /**
     * Read a boolean environment variable ("1", "true", "yes", "on" => true).
     */
    function nared_env_bool(string $key, bool $default = false): bool
    {
        $value = nared_env($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
