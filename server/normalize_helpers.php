<?php

if (!function_exists('stripTitleVariantSuffixes')) {
    /**
     * Strip " - Scene...", " - CD..." and " - Bonus..."/" Bonus..." suffixes
     * from a file base name to derive the canonical title. Shared by
     * processFilesForDB.php and refreshFilesizes.php.
     */
    function stripTitleVariantSuffixes(string $name): string
    {
        return preg_replace(
            ['/ - Scene.*/i', '/ - CD.*/i', '/ - Bonus.*| Bonus.*/i'],
            '',
            $name
        );
    }
}

if (!function_exists('normalizeFileBaseName')) {
    function normalizeFileBaseName(string $base, bool $respectUserCasing = false): string
    {
        $base = basicFunctions($base);
        $base = titleCase($base, $respectUserCasing);
        $base = cleanupFunctions($base);
        $base = sceneNormalization($base);
        $base = castSeparator($base);
        // Store-backed fix for cast names missing their spaces
        // ("GinaValentina" → "Gina Valentina"); before the titleCase re-run so
        // an all-lowercase store entry still gets cased in the same pass
        $base = castDesquash($base);
        // Re-run titleCase: the " - " inserters above can start new segments
        // (e.g. "brazzers-scene-4-jane" → "brazzers - Scene_4 - Jane" needs
        // "Brazzers"), and the output must be a fixed point of the pipeline.
        $base = titleCase($base, $respectUserCasing);
        $base = finalCleanup($base);
        return $base;
    }
}

// Shared filename normalization helpers.
// Used by checkFileNamesToNormalize.php, etc.

if (!function_exists('basicFunctions')) {
    function basicFunctions(string $fileName): string
    {
        $name = trim($fileName);

        // Periods and brackets → spaces
        $name = preg_replace('/\./', ' ', $name);
        $name = preg_replace('/\[|\]/', ' ', $name);

        // Underscores → spaces, but preserve any case of "scene_"
        $SCENE_MARKER = 'SCENETEMPXXMARKER';
        $name = preg_replace('/\bscene_/i', $SCENE_MARKER, $name);
        $name = preg_replace('/_/', ' ', $name);
        $name = str_replace($SCENE_MARKER, 'Scene_', $name);

        // Detach a name glued straight onto the scene number
        // ("Scene_2AlexGrey" → "Scene_2 AlexGrey") so the canonicalizer below
        // can see the number. Only 1-2 digits followed by an UPPERCASE letter
        // plus at least one more letter: real scene numbers are 1-2 digits,
        // and the guards keep quality tags glued ("Scene 720p", "Scene_4K").
        // (?i:) scopes the insensitivity to the word "scene" so [A-Z] stays
        // strict. All-lowercase glue ("scene_2alexgrey") is left for
        // castDesquash, which can consult the cast store.
        $name = preg_replace('/\b((?i:scene)[\s_\-]*\d{1,2})(?=[A-Z][A-Za-z])/', '$1 ', $name);

        // Canonicalize any "scene<sep>N" ("Scene 1", "Scene-1", "Scene1") → "Scene_1".
        // The trailing \b keeps digit-leading quality tags out ("Scene 1080p"),
        // leading zeros are stripped ("Scene 01" → "Scene_1"), and 4-digit
        // years stay years ("Crime Scene 1999").
        $name = preg_replace_callback('/\bscene[\s_\-]*(\d+)\b/i', function ($m) {
            // A scene number is never 4+ digits — that's a year or similar.
            // Detach it from the word ("Scene_1999" → "Scene 1999") but
            // leave it a plain number.
            if (strlen($m[1]) >= 4) {
                return preg_replace('/[\s_\-]+/', ' ', $m[0]);
            }
            return 'Scene_' . (int)$m[1];
        }, $name);

        // Triple spaces → " - "
        $name = preg_replace('/\s{3}/', ' - ', $name);

        // Collapse multiple spaces
        $name = preg_replace('/\s+/', ' ', $name);

        // Multiple periods → single
        $name = preg_replace('/\.+/', '.', $name);

        // Leading periods removed
        $name = preg_replace('/^\.+/', '', $name);

        return trim($name);
    }
}

if (!function_exists('titleCase')) {
    function titleCase(string $fileName, bool $respectUserCasing = false): string
    {
        $delimiters = [' '];

        // Words that should be lowercase *unless first word*
        $lowercaseExceptions = [
            'the',
            'a',
            'an',
            'and',
            'as',
            'at',
            'be',
            'but',
            'by',
            'for',
            'in',
            'it',
            'is',
            'of',
            'off',
            'on',
            'or',
            'per',
            'to',
            'up',
            'via',
            'with',
            'vs',
        ];

        // Words that should always be ALL CAPS
        $uppercaseExceptions = ['BBC', 'CD', 'MILF', 'XXX', 'AJ'];

        // Words that have special mixed casing
        $mixedCaseExceptions = [
            'labeau'  => 'LaBeau',
            'deville' => 'DeVille',
            // cleanupFunctions emits " vs. "; keep it lowercase when
            // titleCase re-runs after cleanup.
            'vs.'     => 'vs.',
        ];

        $result = $fileName;

        foreach ($delimiters as $delimiter) {
            $words = explode($delimiter, $result);

            foreach ($words as $i => $word) {
                $original   = $word;
                $lower      = mb_strtolower($original, 'UTF-8');
                $upper      = mb_strtoupper($original, 'UTF-8');
                $isAllLower = ($original === $lower);

                // 1) Mixed-case special words
                if (isset($mixedCaseExceptions[$lower])) {
                    $words[$i] = $mixedCaseExceptions[$lower];
                    continue;
                }

                // 2) Always-uppercase acronyms
                if (in_array($upper, $uppercaseExceptions, true)) {
                    $words[$i] = $upper;
                    continue;
                }

                // 3) If the word is NOT all-lowercase and NOT a "small word", assume user chose the case
                if (!$isAllLower) {
                    if ($respectUserCasing || !in_array($lower, $lowercaseExceptions, true)) {
                        $words[$i] = $original;
                        continue;
                    }
                }

                // 4) Small words: lowercase (unless first or after "-")
                $prevWord = $words[$i - 1] ?? null;
                if (
                    !$respectUserCasing &&
                    $i > 0 &&
                    in_array($lower, $lowercaseExceptions, true) &&
                    $prevWord !== '-'
                ) {
                    $words[$i] = $lower;
                    continue;
                }

                // 5) Normal Title Case for all-lower words
                $firstChar = mb_substr($lower, 0, 1, 'UTF-8');
                $rest      = mb_substr($lower, 1, null, 'UTF-8');
                $words[$i] = mb_strtoupper($firstChar, 'UTF-8') . $rest;
            }

            $result = implode($delimiter, $words);
        }

        return $result;
    }
}

if (!function_exists('cleanupFunctions')) {
    function cleanupFunctions(string $fileName): string
    {
        $name = trim($fileName);

        // Truncate at the first quality/codec/release-type marker — anything
        // past these (e.g. release-group tags like "-P0RNL0V3R", "-KTR") is junk.
        // Optionally consumes " XXX " when it appears as the junk-anchor right
        // before a quality marker, so titles that legitimately contain "XXX"
        // (e.g. "XXX Adventures", "Adventures in XXX") are preserved when no
        // quality marker follows.
        $name = preg_replace(
            '/(?:\s*\bXXX\b\s+)?\b(?:2160p|4k|1080p|720p|480p|360p|DVDRip|h264|x264|WEBRip|MP4|xvid)\b.*/i',
            '',
            $name
        );
        // Bare trailing resolution numbers ("..._1080", "... 720") are junk
        // too — the quality list above only catches the "p"-suffixed forms.
        // \s*$ tolerates the trailing space the truncation above leaves, and
        // (?<!#) keeps "# 720"-style series indexes intact.
        $name = preg_replace('/(?<!#)(?:\s+(?:2160|1080|720|480|360))+\s*$/', '', $name);

        // Drop dangling separators that the truncation may leave behind.
        $name = rtrim($name, " \t\n\r\0\x0B-._");

        // A title-internal "XXX <article> …" tail is a subtitle (Axel Braun
        // style: "Snow White XXX An Axel Braun Parody" → "Snow White XXX -
        // An Axel Braun Parody"): set it off with " - " and capitalize the
        // article. Other mid-title XXX ("My XXX Secretary", "Ghostbusters
        // XXX Parody") stays inline; leading/trailing XXX is left alone.
        // [\s-]+ before XXX also catches hyphen-glued forms ("Scene_1-XXX An
        // …"), which would otherwise only be split on a later pass.
        $name = preg_replace_callback(
            '/(?<=\S)[\s\-]+XXX\s+(a|an|the)\s+(?=\S)/i',
            fn($m) => ' XXX - ' . ucfirst(strtolower($m[1])) . ' ',
            $name
        );

        // Remaining formatting (run on whatever survives the truncation)
        $patterns = [
            '/(\s+)vs(\s+)/i',  // normalize spacing around "vs"
            // (?![a-z]) keeps words that merely start with disc/disk intact
            // ("Disco Nights", "Diskette") while still matching glued digits
            // ("Disc1")
            '/\bdisc(?![a-z])/i',
            '/\bdisk(?![a-z])/i',
            '/\bcd\b/i',
            '/\b(\s|\.)cd/i',
        ];

        $replacements = [
            // "vs" spacing:
            ' vs. ',
            // disc / disk / cd variants:
            'CD',
            'CD',
            'CD',
            ' - CD',
        ];

        $name = preg_replace($patterns, $replacements, $name);
        $name = trim($name);

        // Canonical disc form is " - CD<n>" glued, leading zeros stripped
        // ("Disc 1" → " - CD1") — run before the volume-number rules below so
        // the disc number is never rewritten to "# 01". Also self-heals names
        // a previous pass mangled ("CD # 01" → "CD1").
        $name = preg_replace_callback(
            '/(?:\s*-\s*)?\bCD\s*[.#]?\s*0*(\d{1,2})\b/',
            fn($m) => ' - CD' . $m[1],
            $name
        );

        // "#07" or "#   07" → "# 07"; "#1" → "# 01"
        $name = preg_replace_callback('/#\s*(\d+)/', function ($matches) {
            $number = $matches[1];
            if (strlen($number) === 1) {
                $number = '0' . $number;
            }
            return '# ' . $number;
        }, $name);

        // Handle "Vol4", "Vol 4", "Vol.4", "Vol#4", "Vol #4"
        $name = preg_replace_callback(
            '/\bVol\.?\s*#?\s*(\d+)\b/i',
            function ($matches) {
                $number = $matches[1];
                if (strlen($number) === 1) {
                    $number = '0' . $number;
                }
                return '# ' . $number;
            },
            $name
        );
        // Numbers before a parenthetical suffix:
        $name = preg_replace_callback(
            '/(?<!# )(?<!Scene_)\b(\d{1,3})\b(?=\s*\()/',
            function ($matches) {
                $number = $matches[1];
                if (strlen($number) === 1) {
                    $number = '0' . $number;
                }
                return '# ' . $number;
            },
            $name
        );
        // Ensure " - " before Scene_N: "Title Scene_1" / "Title-Scene_1" /
        // "Title, Scene_1" → "Title - Scene_1". Requires an actual separator
        // (spaces/dashes, optionally led by a comma) preceded by a word-ish
        // char, so glued text ("Obscene_1") and parenthesized forms
        // ("(Scene_1)") are left alone.
        $name = preg_replace('/(?<=[\w)])\s*(?:,[\s\-]*|[\s\-]+)Scene_(?=\d)/', ' - Scene_', $name);

        // Numbers immediately before " - Scene_"
        $name = preg_replace_callback(
            '/(?<!# )\b(\d+)(?=\s+-\s*Scene_)/',
            function ($matches) {
                $number = $matches[1];

                // 4+-digit numbers are years etc., never series indexes
                if (strlen($number) >= 4) {
                    return $number;
                }

                if (strlen($number) === 1) {
                    $number = '0' . $number;
                }
                return '# ' . $number;
            },
            $name
        );

        // Trailing numbers at the end (but not already "# " and not part of Scene_)
        $name = preg_replace_callback(
            '/(?<!# )(?<!Scene_)\b(\d+)\b$/',
            function ($matches) {
                $number = $matches[1];

                // 4+-digit numbers are years etc., never series indexes
                if (strlen($number) >= 4) {
                    return $number;
                }

                if (strlen($number) === 1) {
                    $number = '0' . $number;
                }
                return '# ' . $number;
            },
            $name
        );

        // Ensure no redundant "# #"
        $name = preg_replace('/#\s+#/', '# ', $name);

        return trim($name);
    }
}

if (!function_exists('sceneNormalization')) {
    function sceneNormalization(string $fileName): string
    {
        // Ensure " - " between Scene_N and a following name:
        // "Scene_1 Title" / "Scene_1-Title" → "Scene_1 - Title". The first
        // letter after the dash is uppercased ("Scene_2 with Jane" →
        // "Scene_2 - With Jane") to match titleCase's after-dash convention —
        // otherwise a second normalization pass would change the name again.
        return preg_replace_callback(
            '/(Scene_\d+)(?:\s*-\s*|\s+)(\p{L})/u',
            fn($m) => $m[1] . ' - ' . mb_strtoupper($m[2], 'UTF-8'),
            $fileName
        );
    }
}

if (!function_exists('castSeparator')) {
    function castSeparator(string $fileName): string
    {
        // After "Scene_N - ", treat " and " as a cast-member separator and turn it into ", ".
        // Only applies to the segment after Scene_N so titles containing "and" are untouched.
        if (preg_match('/Scene_\d+\s+-\s+/i', $fileName, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $before = substr($fileName, 0, $offset);
            $after  = substr($fileName, $offset);
            // Absorb an optional preceding comma and repeated "and"s so
            // "Jane, and Kira" / "Jane and and Kira" collapse to "Jane, Kira"
            // in one pass.
            $after  = preg_replace('/(?:\s*,)?(?:\s+and)+\s+/i', ', ', $after);
            return $before . $after;
        }
        return $fileName;
    }
}

if (!function_exists('moviedb_cast_squash_map')) {
    /**
     * Lookup tables for castDesquash, built from the cast-name store (or an
     * injected vocabulary in tests):
     *   [0] squash map: "ginavalentina" => "Gina Valentina" — only names that
     *       contain a space; a squash produced by two different names maps to
     *       null (ambiguous, never rewritten)
     *   [1] known set: every full name and every individual word of a name,
     *       lowercased — tokens found here are already valid and left alone
     */
    function moviedb_cast_squash_map(?array $vocab = null): array
    {
        static $cached = null;
        $useStore = ($vocab === null);
        if ($useStore) {
            if ($cached !== null) {
                return $cached;
            }
            require_once __DIR__ . '/cast_helpers.php';
            $vocab = moviedb_load_cast_store();
        }

        $squash = [];
        $known = [];
        $fullNames = [];
        foreach ($vocab as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $lower = mb_strtolower($name);
            $known[$lower] = true;
            $fullNames[$lower] = $name;
            foreach (explode(' ', $lower) as $word) {
                if ($word !== '') {
                    $known[$word] = true;
                }
            }
            if (strpos($name, ' ') === false) {
                continue; // a squash fix must be able to add a space
            }
            $key = str_replace(' ', '', $lower);
            $squash[$key] = array_key_exists($key, $squash) ? null : $name;
        }

        $result = [$squash, $known, $fullNames];
        if ($useStore) {
            $cached = $result;
        }
        return $result;
    }
}

if (!function_exists('moviedb_segment_cast_words')) {
    /**
     * Try to split $words into a sequence of store names (longest match
     * first, backtracking, names up to 4 words). Returns the canonical
     * names, or null when the words don't consume cleanly.
     */
    function moviedb_segment_cast_words(array $words, array $fullNames): ?array
    {
        if (!$words) {
            return [];
        }
        $n = count($words);
        for ($len = min(4, $n); $len >= 1; $len--) {
            $key = mb_strtolower(implode(' ', array_slice($words, 0, $len)));
            if (isset($fullNames[$key])) {
                $rest = moviedb_segment_cast_words(array_slice($words, $len), $fullNames);
                if ($rest !== null) {
                    return array_merge([$fullNames[$key]], $rest);
                }
            }
        }
        return null;
    }
}

if (!function_exists('castDesquash')) {
    /**
     * Fix cast names that lost their spaces ("GinaValentina" → "Gina
     * Valentina") in the segment after "Scene_N - ", using the cast-name
     * store as the reference. Precision-first: a token is rewritten only when
     * it is at least 6 characters, is not already a store name (or a word of
     * one), and exactly one store name matches it with the spaces removed.
     */
    function castDesquash(string $fileName, ?array $vocab = null): string
    {
        [$squashMap, $known, $fullNames] = moviedb_cast_squash_map($vocab);
        if (!$squashMap && !$fullNames) {
            return $fileName;
        }

        // A name glued straight onto the scene number in any casing
        // ("Scene_2alexgrey"). basicFunctions only detaches the uppercase
        // form; here the store itself is the evidence that the glue is a
        // name, so lowercase resolves too — but only on a unique match.
        $fileName = preg_replace_callback(
            '/\b(Scene_\d{1,2})(\p{L}{6,})\b/u',
            function ($m) use ($squashMap, $known, $fullNames) {
                $key = mb_strtolower($m[2]);
                if (isset($fullNames[$key])) {
                    // The blob IS a store name ("Scene_2vanity")
                    return $m[1] . ' - ' . $fullNames[$key];
                }
                if (isset($known[$key]) || !isset($squashMap[$key]) || $squashMap[$key] === null) {
                    return $m[0];
                }
                return $m[1] . ' - ' . $squashMap[$key];
            },
            $fileName
        );

        if (!preg_match('/Scene_\d+\s+-\s+/i', $fileName, $m, PREG_OFFSET_CAPTURE)) {
            return $fileName;
        }

        $offset = $m[0][1] + strlen($m[0][0]);
        $before = substr($fileName, 0, $offset);
        $after = substr($fileName, $offset);

        $after = preg_replace_callback(
            '/[^\s,]+/u',
            function ($t) use ($squashMap, $known) {
                $key = mb_strtolower($t[0]);
                if (mb_strlen($key) < 6 || isset($known[$key])) {
                    return $t[0];
                }
                return $squashMap[$key] ?? $t[0];
            },
            $after
        );

        // Second pass: restore missing commas. A comma part that is not
        // itself a store name but segments cleanly into 2+ store names
        // ("Elsa Jean Alexis Fawx") gets ", " between them. Two-word parts
        // are never split — "First Last" is almost always one performer.
        $parts = preg_split('/\s*,\s*/', $after);
        $parts = array_values(array_filter($parts, fn($p) => trim($p) !== ''));
        foreach ($parts as $i => $part) {
            $part = trim($part);
            if ($part === '' || isset($fullNames[mb_strtolower($part)])) {
                continue;
            }
            $words = explode(' ', $part);
            if (count($words) < 3) {
                continue;
            }
            $segmented = moviedb_segment_cast_words($words, $fullNames);
            if ($segmented !== null && count($segmented) >= 2) {
                $parts[$i] = implode(', ', $segmented);
            }
        }
        $after = implode(', ', $parts);

        return $before . $after;
    }
}

if (!function_exists('finalCleanup')) {
    function finalCleanup(string $fileName): string
    {
        $fileName = trim($fileName);

        $fileName = preg_replace(
            [
                '/\s+/',  // multiple spaces
                '/\.+/',  // multiple periods
                '/^\.+/', // leading periods
            ],
            [
                ' ',
                '.',
                '',
            ],
            $fileName
        );

        return trim($fileName);
    }
}
