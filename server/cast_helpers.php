<?php

/**
 * Shared cast-name helpers: cleaning, splitting, and the persisted vocabulary
 * store. Used by castNames.php (the modal's autocomplete endpoint) and
 * scripts/harvest_cast_names.php (the drive-wide harvester).
 */

const MOVIEDB_CAST_STORE = __DIR__ . '/cast_names.json';

// Names deleted from the vocabulary, kept so filenames can't bring them back
// (Add Cast mining a folder, the harvester, a rename's cast fed back in).
// Typing one into Add — or restoring it on the Settings page — unblocks it.
const MOVIEDB_CAST_BLOCKLIST = __DIR__ . '/cast_names_blocked.json';

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
        $collapsed = preg_replace('/\s+/u', ' ', trim($name));
        $name = trim($collapsed, " \t\n\r\0\x0B-_.,;:|/\\\"'()[]");
        // "Scene_2 - With Juelz Ventura": the "With" is phrasing, not part of
        // the name (Sean, 2026-10-01) — the filename keeps it, the store doesn't.
        $name = preg_replace('/^with\s+(?=\S)/iu', '', $name);
        // A trailing period is edge junk on a pasted sentence ("Angel Long.")
        // but part of the name on a final initial ("Kylie G."). Keep it only
        // in the abbreviation shape castDesquash's dot-restore trusts — a
        // 1-2 letter final word that the raw text really ended with a period
        // after (possibly followed by other junk the trim removed).
        if ($name !== ''
            && preg_match('/(?:^|\s)\p{L}{1,2}$/u', $name)
            && preg_match('/\p{L}\.[^\p{L}]*$/u', $collapsed)) {
            $name .= '.';
        }
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

if (!function_exists('moviedb_load_cast_blocklist')) {
    function moviedb_load_cast_blocklist(?string $path = null): array
    {
        $path = $path ?? MOVIEDB_CAST_BLOCKLIST;
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }
}

if (!function_exists('moviedb_save_cast_blocklist')) {
    /** Write the blocklist deduped and sorted like the store; the list as saved. */
    function moviedb_save_cast_blocklist(array $names, ?string $path = null): array
    {
        $path = $path ?? MOVIEDB_CAST_BLOCKLIST;
        $merged = moviedb_merge_cast_names(array_filter($names, fn($n) => is_string($n) && $n !== ''));
        // The previous list stays beside it, so "Forget all" can be undone
        if (is_file($path)) {
            @copy($path, $path . '.bak');
        }
        @file_put_contents(
            $path,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        return $merged;
    }
}

if (!function_exists('moviedb_without_blocked')) {
    /** $names minus every name on $blocked (case-insensitively, the store's key). */
    function moviedb_without_blocked(array $names, array $blocked): array
    {
        $keys = array_flip(array_map('mb_strtolower', $blocked));
        return array_values(array_filter($names, fn($n) => !isset($keys[mb_strtolower($n)])));
    }
}

if (!function_exists('moviedb_block_cast_names')) {
    /** Add names to the blocklist; the list as saved. */
    function moviedb_block_cast_names(array $names, ?string $path = null): array
    {
        return moviedb_save_cast_blocklist(array_merge(moviedb_load_cast_blocklist($path), $names), $path);
    }
}

if (!function_exists('moviedb_unblock_cast_name')) {
    /** Take a name off the blocklist (case-insensitively); the list as saved. */
    function moviedb_unblock_cast_name(string $name, ?string $path = null): array
    {
        return moviedb_save_cast_blocklist(moviedb_remove_name(moviedb_load_cast_blocklist($path), $name), $path);
    }
}

if (!function_exists('moviedb_save_cast_store')) {
    /**
     * Merge names into the store, case-insensitively deduped, leaving out
     * blocked names — every path that writes the store comes through here,
     * so a deleted name can't sneak back from a filename. Best effort.
     */
    function moviedb_save_cast_store(array $names, ?array $blocked = null): array
    {
        $names = moviedb_without_blocked($names, $blocked ?? moviedb_load_cast_blocklist());
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
