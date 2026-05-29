<?php

/**
 * Pure, in-memory helpers for the pre-loop "DB numbering presence" snapshots in
 * processFilesForDB.php.
 *
 * Previously each unique base title triggered its own DB queries (an N+1):
 *   - "does the unnumbered 'Base' exist?"            (title = ?)
 *   - "does any numbered 'Base # NN' exist?"         (title LIKE 'Base # %')
 *   - "does another numbered variant exist?"         (title LIKE 'Base # %' AND title <> ?)
 *
 * Instead we load every title once and answer all of those from an index built
 * here. Comparisons are case-insensitive to mirror MySQL's default (ci)
 * collation, which this app already assumes (it freely mixes `title = ?` and
 * `LOWER(title) = LOWER(?)`).
 */

if (!function_exists('loadAllTitles')) {
    /**
     * Load every title from the table in one query, for buildTitlePresenceIndex().
     * @return string[]
     */
    function loadAllTitles($db, string $table): array
    {
        $titles = [];
        if ($res = $db->query("SELECT title FROM `$table`")) {
            while ($row = $res->fetch_row()) {
                $titles[] = (string)$row[0];
            }
            $res->free();
        }
        return $titles;
    }
}

if (!function_exists('buildTitlePresenceIndex')) {
    /**
     * @param string[] $titles All titles currently in the table.
     * @return array{exact: array<string,bool>, numberedByBase: array<string,string[]>}
     */
    function buildTitlePresenceIndex(array $titles): array
    {
        $exact = [];          // lower(title) => true
        $numberedByBase = []; // lower(base)  => [lower(title), ...]

        foreach ($titles as $t) {
            $lt = titlePresenceLower((string)$t);
            if ($lt === '') {
                continue;
            }
            $exact[$lt] = true;

            // A "numbered variant" is any title of the form "Base # ...", i.e.
            // one that starts with "<base> # " — exactly what LIKE 'Base # %'
            // matched. Split at the first " # " to recover the base.
            $pos = strpos($lt, ' # ');
            if ($pos !== false) {
                $base = substr($lt, 0, $pos);
                $numberedByBase[$base][] = $lt;
            }
        }

        return ['exact' => $exact, 'numberedByBase' => $numberedByBase];
    }
}

if (!function_exists('presenceFromIndex')) {
    /**
     * Mirrors getDbNumberingPresence(): whether the DB contains the exact
     * unnumbered "Base" and/or any numbered "Base # NN" variant.
     *
     * @return array{dbHasUnnumberedVariant: bool, dbHasNumberedVariant: bool}
     */
    function presenceFromIndex(array $index, string $base): array
    {
        $lb = titlePresenceLower($base);
        return [
            'dbHasUnnumberedVariant' => isset($index['exact'][$lb]),
            'dbHasNumberedVariant'   => !empty($index['numberedByBase'][$lb]),
        ];
    }
}

if (!function_exists('hasOtherNumberedFromIndex')) {
    /**
     * Mirrors the "title LIKE 'Base # %' AND title <> sourceTitle" check:
     * is there a numbered variant of $base that isn't $sourceTitle itself?
     */
    function hasOtherNumberedFromIndex(
        array $index,
        string $sourceTitle,
        string $base
    ): bool {
        $lb = titlePresenceLower($base);
        if (empty($index['numberedByBase'][$lb])) {
            return false;
        }
        $ls = titlePresenceLower($sourceTitle);
        foreach ($index['numberedByBase'][$lb] as $t) {
            if ($t !== $ls) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('titlePresenceLower')) {
    function titlePresenceLower(string $s): string
    {
        $s = trim($s);
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}
