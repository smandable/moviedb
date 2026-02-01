<?php

if (!function_exists('normalizeFileBaseName')) {
    function normalizeFileBaseName(string $base): string
    {
        $base = basicFunctions($base);
        $base = titleCase($base);
        $base = cleanupFunctions($base);
        $base = sceneNormalization($base);
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
        $name = preg_replace('/scene_/i', $SCENE_MARKER, $name);
        $name = preg_replace('/_/', ' ', $name);
        $name = str_replace($SCENE_MARKER, 'Scene_', $name);

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

        // Strip quality/codec/etc junk
        $patterns = [
            '/2160p/i',
            '/4k/i',
            '/1080p/i',
            '/720p/i',
            '/480p/i',
            '/360p/i',
            '/DVDRip/i',
            '/h264/i',
            '/x264/i',
            '/WEBRip/i',
            '/XXX/i',
            '/MP4/i',
            '/xvid/i',
            '/(\s+)vs(\s+)/i',  // normalize spacing around "vs"
            '/disc/i',
            '/disk(\s*)/i',
            '/\bcd\b/i',
            '/\b(\s|\.)cd/i',
        ];

        $replacements = [
            // remove 2160p / 4k / codecs / etc.
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
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

        // "#07" or "#   07" → "# 07"
        $name = preg_replace('/#\s*(\d+)/', '# $1', $name);

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

        // Numbers immediately before " - Scene_"
        $name = preg_replace_callback(
            '/\b(\d+)(?=\s-\sScene_)/',
            function ($matches) {
                $number = $matches[1];
                if (strlen($number) === 1) {
                    $number = '0' . $number;
                }
                return '# ' . $number;
            },
            $name
        );

        // Trailing numbers at the end
        // BUT ignore:
        //  - already formatted numbers
        //  - numbers that are part of "Scene_1"
        $name = preg_replace_callback(
            '/(?<!# )(?<!Scene_)\b(\d+)\b$/',
            function ($matches) {
                $number = $matches[1];
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
        // "Scene_1 Title" → "Scene_1 - Title" (but don't double-insert " - ")
        $pattern     = '/([Ss]cene_\d+)\s(?!- )([A-Za-z\-]+)/';
        $replacement = '$1 - $2';

        return preg_replace($pattern, $replacement, $fileName);
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
