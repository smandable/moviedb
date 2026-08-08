<?php

/**
 * Shared cast-name helpers: cleaning, splitting, and the persisted vocabulary
 * store. Used by castNames.php (the modal's autocomplete endpoint) and
 * scripts/harvest_cast_names.php (the drive-wide harvester).
 */

const MOVIEDB_CAST_STORE = __DIR__ . '/cast_names.json';

// A cast tail is whatever follows the scene number; performers are comma-separated.
const MOVIEDB_SCENE_CAST_RE = '/Scene_\d+\s*-\s*(.+)$/i';

if (!function_exists('moviedb_clean_cast_name')) {
    /** Normalize one performer name; returns '' for anything that isn't usable. */
    function moviedb_clean_cast_name(string $name): string
    {
        // Collapse whitespace, drop wrapping punctuation the paste may carry.
        $name = preg_replace('/\s+/u', ' ', trim($name));
        $name = trim($name, " \t\n\r\0\x0B-_.,;:|/\\\"'()[]");
        if ($name === '' || mb_strlen($name) > 100) {
            return '';
        }
        // Must contain a letter — rejects stray numbers, resolutions, punctuation runs.
        if (!preg_match('/\p{L}/u', $name)) {
            return '';
        }
        return $name;
    }
}

if (!function_exists('moviedb_split_cast_tail')) {
    /** Split a cast tail ("Angel Long, Paige Owens") into cleaned names. */
    function moviedb_split_cast_tail(string $tail): array
    {
        $parts = preg_split('/\s*(?:,|&|\band\b|\+)\s*/iu', $tail) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $clean = moviedb_clean_cast_name($part);
            if ($clean !== '') {
                $names[] = $clean;
            }
        }
        return $names;
    }
}

if (!function_exists('moviedb_load_cast_store')) {
    function moviedb_load_cast_store(): array
    {
        if (!is_file(MOVIEDB_CAST_STORE)) {
            return [];
        }
        $raw = @file_get_contents(MOVIEDB_CAST_STORE);
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }
}

if (!function_exists('moviedb_save_cast_store')) {
    /** Merge names into the store, case-insensitively deduped. Best effort. */
    function moviedb_save_cast_store(array $names): array
    {
        $byLower = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            if (!isset($byLower[$key])) {
                $byLower[$key] = $name;
            }
        }
        $merged = array_values($byLower);
        sort($merged, SORT_NATURAL | SORT_FLAG_CASE);
        // A failed write just means autocomplete forgets — never fail the caller.
        @file_put_contents(
            MOVIEDB_CAST_STORE,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        return $merged;
    }
}
