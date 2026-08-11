<?php

/**
 * Shared cast-name helpers: cleaning, splitting, and the persisted vocabulary
 * store. Used by castNames.php (the modal's autocomplete endpoint) and
 * scripts/harvest_cast_names.php (the drive-wide harvester).
 */

const MOVIEDB_CAST_STORE = __DIR__ . '/cast_names.json';

// A cast tail is whatever follows the scene number; performers are comma-separated.
const MOVIEDB_SCENE_CAST_RE = '/Scene_\d+\s*-\s*(.+)$/i';

// Cyrillic/Greek letters visually identical to Latin ones. Scene-release
// filenames occasionally carry them ("Аria Lee" arrived with U+0410 CYRILLIC
// CAPITAL A and became a phantom twin of "Aria Lee" in the store).
const MOVIEDB_HOMOGLYPHS = [
    // Cyrillic capitals / lowercase
    'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
    'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X', 'Ѕ' => 'S',
    'І' => 'I', 'Ј' => 'J', 'У' => 'Y',
    'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x',
    'ѕ' => 's', 'і' => 'i', 'ј' => 'j', 'у' => 'y',
    // Greek capitals (and the one safe lowercase)
    'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I',
    'Κ' => 'K', 'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T',
    'Υ' => 'Y', 'Χ' => 'X', 'ο' => 'o',
];

if (!function_exists('moviedb_fold_homoglyphs')) {
    /**
     * Fold Cyrillic/Greek lookalike letters to Latin — but only where the
     * evidence says the name is MEANT to be Latin. Per word:
     *   - a mixed-script word ("Аria" = Cyrillic А + Latin ria) is folded;
     *   - a word made entirely of lookalikes is folded when another word in
     *     the name is plainly Latin ("СОСО Lopez" -> "COCO Lopez");
     *   - a genuinely Cyrillic/Greek name (no Latin anywhere) is untouched.
     */
    function moviedb_fold_homoglyphs(string $name): string
    {
        $words = preg_split('/\s+/u', $name) ?: [];
        $glyphClass = '[' . implode('', array_keys(MOVIEDB_HOMOGLYPHS)) . ']';
        $nameHasLatinWord = false;
        foreach ($words as $word) {
            if (preg_match('/^[\p{Latin}\'.\-]+$/u', $word)) {
                $nameHasLatinWord = true;
                break;
            }
        }
        foreach ($words as $i => $word) {
            $hasLookalike = (bool) preg_match("/{$glyphClass}/u", $word);
            if (!$hasLookalike) {
                continue;
            }
            $hasLatin = (bool) preg_match('/[A-Za-z]/', $word);
            $allLookalikes = (bool) preg_match("/^(?:{$glyphClass}|['.\\-])+$/u", $word);
            if ($hasLatin || ($allLookalikes && $nameHasLatinWord)) {
                $words[$i] = strtr($word, MOVIEDB_HOMOGLYPHS);
            }
        }
        return implode(' ', $words);
    }
}

if (!function_exists('moviedb_clean_cast_name')) {
    /** Normalize one performer name; returns '' for anything that isn't usable. */
    function moviedb_clean_cast_name(string $name): string
    {
        // Collapse whitespace, drop wrapping punctuation the paste may carry.
        $name = preg_replace('/\s+/u', ' ', trim($name));
        $name = trim($name, " \t\n\r\0\x0B-_.,;:|/\\\"'()[]");
        // Fold script-lookalike letters before dedup, so "Аria Lee" (Cyrillic
        // А) and "Aria Lee" cannot coexist as distinct store entries.
        $name = moviedb_fold_homoglyphs($name);
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

if (!function_exists('moviedb_merge_cast_names')) {
    /**
     * Dedupe a name list case-insensitively and sort it — the in-memory half of
     * saving the store.
     *
     * FIRST occurrence wins, deliberately: this is the idempotent merge path
     * (the harvester, and the modal feeding successful renames back in), where
     * an incoming badly-cased duplicate must never overwrite an entry the store
     * already holds. Explicit human edits that need to WIN on casing go through
     * moviedb_rename_cast_name instead.
     */
    function moviedb_merge_cast_names(array $names): array
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
        return $merged;
    }
}

if (!function_exists('moviedb_save_cast_store')) {
    /** Merge names into the store, case-insensitively deduped. Best effort. */
    function moviedb_save_cast_store(array $names): array
    {
        $merged = moviedb_merge_cast_names($names);
        // A failed write just means autocomplete forgets — never fail the caller.
        @file_put_contents(
            MOVIEDB_CAST_STORE,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        return $merged;
    }
}

if (!function_exists('moviedb_remove_name')) {
    /** Drop every entry matching $target case-insensitively (the store's key). */
    function moviedb_remove_name(array $names, string $target): array
    {
        $key = mb_strtolower($target);
        return array_values(array_filter(
            $names,
            fn($n) => mb_strtolower($n) !== $key
        ));
    }
}

if (!function_exists('moviedb_stored_cast_name')) {
    /**
     * The spelling the list actually holds for $name, matched the store's way
     * (case-insensitively); '' when the name isn't there. Endpoints echo this
     * rather than what the user typed, so a status line can never claim a
     * casing the store didn't take.
     */
    function moviedb_stored_cast_name(array $names, string $name): string
    {
        $key = mb_strtolower($name);
        foreach ($names as $stored) {
            if (mb_strtolower($stored) === $key) {
                return $stored;
            }
        }
        return '';
    }
}

if (!function_exists('moviedb_add_cast_name')) {
    /**
     * Add one hand-typed name and return the list as the store will hold it.
     *
     * Deliberately NOT authoritative about casing — the opposite of
     * moviedb_rename_cast_name. Add is a blind insert: the typist may not know
     * the name is already stored, so an entry that already exists keeps its own
     * spelling (first-wins, like the merge path) rather than being recased by a
     * careless "deedee lynn". Changing the casing of a stored entry is what
     * rename is for, where the user picked that exact entry out of the list.
     *
     * A $newRaw that cleans to '' adds nothing; the endpoint 400s on it first.
     */
    function moviedb_add_cast_name(array $names, string $newRaw): array
    {
        $clean = moviedb_clean_cast_name($newRaw);
        if ($clean !== '') {
            $names[] = $clean;
        }
        return moviedb_merge_cast_names($names);
    }
}

if (!function_exists('moviedb_rename_cast_name')) {
    /**
     * Apply one explicit, human-made rename to a name list and return the list
     * as the store will hold it (cleaned, deduped, sorted).
     *
     * A rename is AUTHORITATIVE ABOUT CASING — that is the whole difference from
     * the merge path above. Any entry matching the NEW name case-insensitively
     * is removed along with the old one, so the casing typed on the Settings
     * page survives: with ["marla vex", "With Marla Vex"], renaming
     * "With Marla Vex" -> "Marla Vex" leaves exactly one entry, "Marla Vex".
     * (First-wins merging would have kept the lowercase one and silently thrown
     * the typed casing away.) Fixing casing by renaming onto an entry is a
     * supported edit, not an accident.
     *
     * Matching is case-insensitive on both ends. Renaming a name that is not in
     * the list still adds the new name — long-standing behaviour, kept. An empty
     * $old or a $newRaw that cleans to '' renames nothing (the endpoint rejects
     * both with a 400 before it ever calls this), but still returns store shape:
     * EVERY path out of here is deduped and sorted, so a caller can save the
     * result without checking which path it came from.
     */
    function moviedb_rename_cast_name(array $names, string $old, string $newRaw): array
    {
        $clean = moviedb_clean_cast_name($newRaw);
        if ($old === '' || $clean === '') {
            return moviedb_merge_cast_names($names);
        }
        $names = moviedb_remove_name($names, $old);
        $names = moviedb_remove_name($names, $clean);
        $names[] = $clean;
        return moviedb_merge_cast_names($names);
    }
}
