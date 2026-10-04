<?php

/**
 * Which catalog table the app reads and writes. DB_TABLE in server/.env is
 * the default; Settings → Catalog Table stores an override as `dbTable` in
 * app_settings.json (appSettings.php), so switching between the het and bi
 * catalogs needs no config edit. Only the tables listed here are accepted.
 */

const MOVIEDB_CATALOG_TABLES = ['movies_het', 'movies_bi'];

if (!function_exists('moviedb_active_table')) {
    /**
     * The stored override when it names a known catalog table, else $default.
     */
    function moviedb_active_table(string $default, ?string $settingsPath = null): string
    {
        $settingsPath ??= __DIR__ . '/app_settings.json';
        $raw = is_file($settingsPath) ? @file_get_contents($settingsPath) : false;
        $settings = $raw === false ? null : json_decode($raw, true);
        $stored = is_array($settings) ? ($settings['dbTable'] ?? null) : null;
        return is_string($stored) && in_array($stored, MOVIEDB_CATALOG_TABLES, true) ? $stored : $default;
    }
}

if (!function_exists('moviedb_require_loaded_table')) {
    /**
     * Refuse a row write aimed at a table other than the active one. Delete and
     * edit address rows by bare id, and ids overlap between the catalogs, so a
     * grid loaded before a Catalog Table switch (another tab, a long-open
     * Update DB session) would otherwise hit the same-numbered row in the
     * other table. Callers send the table their rows were loaded from
     * (getAllMovies.php?withTable=1, processFilesForDB.php's `table`).
     * Exits with 409 on a mismatch or a missing table.
     */
    function moviedb_require_loaded_table($sent, string $active): void
    {
        if (is_string($sent) && $sent === $active) {
            return;
        }
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'tableChanged' => true,
            'message' => "The catalog table is now $active — reload this list before changing rows.",
        ]);
        exit();
    }
}
