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
            '/disc/i',
            '/disk(\s*)/i',
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
