<?php

/**
 * Path allow-list guard for the filesystem endpoints.
 *
 * The scan/rename endpoints accept directory paths from the client. To keep a
 * compromised or mistaken request from reaching arbitrary locations the web
 * server can touch, every client-supplied path must resolve to a location
 * inside an allowed base directory.
 *
 * The base defaults to "/Volumes" (where this app's media lives) and can be
 * overridden with the ALLOWED_BASE_PATH env var. Set it to "/" to disable the
 * guard.
 */

require_once __DIR__ . '/env_loader.php';

if (!function_exists('moviedb_allowed_base_path')) {
    function moviedb_allowed_base_path(): string
    {
        $base = getenv('ALLOWED_BASE_PATH');
        $base = (is_string($base) && $base !== '') ? $base : '/Volumes';
        return rtrim($base, '/') ?: '/';
    }
}

if (!function_exists('moviedb_is_path_allowed')) {
    /**
     * True if $path resolves to a location inside the configured allowed base.
     * Uses realpath() so symlinks and ".." segments can't escape the base.
     *
     * @param string $path A directory (or file) path; must already exist.
     */
    function moviedb_is_path_allowed(string $path): bool
    {
        $base = moviedb_allowed_base_path();
        if ($base === '/') {
            return true; // guard explicitly disabled
        }

        $realBase = realpath($base);
        $realPath = realpath($path);
        if ($realBase === false || $realPath === false) {
            return false; // misconfigured base or non-existent path
        }

        return $realPath === $realBase
            || strpos($realPath, $realBase . DIRECTORY_SEPARATOR) === 0;
    }
}

if (!function_exists('moviedb_is_plain_filename')) {
    /**
     * True if $name is a single path component safe to append to a directory:
     * a non-empty string with no directory separators or NUL byte, and not
     * "." or "..". This stops a client-supplied filename from using "/" or
     * ".." segments to escape a directory that already passed
     * moviedb_is_path_allowed().
     *
     * @param mixed $name Candidate filename from untrusted input.
     */
    function moviedb_is_plain_filename($name): bool
    {
        if (!is_string($name) || $name === '' || $name === '.' || $name === '..') {
            return false;
        }
        return strpbrk($name, "/\\\0") === false;
    }
}

if (!function_exists('moviedb_reject_path')) {
    /**
     * Emit a 403 JSON error for a disallowed path and stop. (No-op if allowed.)
     */
    function moviedb_reject_path(string $path): void
    {
        if (moviedb_is_path_allowed($path)) {
            return;
        }
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Path is outside the allowed base directory ('
                . moviedb_allowed_base_path() . ').',
        ]);
        exit();
    }
}
