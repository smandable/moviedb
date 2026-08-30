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
    function normalizeFileBaseName(string $base, bool $respectUserCasing = false, bool $keepCastDots = false): string
    {
        // A period the user deliberately typed into the cast field must
        // survive basicFunctions' periods→spaces sweep ("Destiny St.
        // Claire"). Only the tail after the scene marker is protected — a
        // dotted TITLE still normalizes — and the dots ride the pipeline as
        // a letters-only marker so every other stage treats them as part of
        // the word. The marker also makes the word mixed-case, so titleCase
        // leaves the user's casing alone (the flag only rides user edits).
        $dotMarker = 'MDBCASTDOTMARKER';
        $dotsProtected = false;
        if ($keepCastDots) {
            $base = preg_replace_callback(
                '/^(.*?\bscene[\s._\-]*\d{1,3})(.*)$/i',
                function ($m) use ($dotMarker, &$dotsProtected) {
                    if (strpos($m[2], '.') === false) {
                        return $m[0];
                    }
                    $dotsProtected = true;
                    return $m[1] . str_replace('.', $dotMarker, $m[2]);
                },
                $base,
                1
            );
        }

        $base = basicFunctions($base);
        $base = titleCase($base, $respectUserCasing);
        $base = cleanupFunctions($base);
        // After cleanupFunctions, so the quality tail is already truncated and a
        // tag hidden behind it ("Scene_1 Lh 1080p") is at the real end of the
        // name; before sceneNormalization, which would otherwise promote the tag
        // into the cast position.
        $base = dropNonCastTags($base);
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
        if ($dotsProtected) {
            $base = str_replace($dotMarker, '.', $base);
        }
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

if (!function_exists('trimReleaseJunk')) {
    /**
     * Truncate the release-junk tail (quality/codec markers, bare trailing
     * resolutions, and the separator they dangle). Extracted from
     * cleanupFunctions so dropNonCastTags can ask the same question it does —
     * "where does the real name end?" — without duplicating the marker list.
     *
     * Always returns a PREFIX of its input; dropNonCastTags relies on that to
     * splice the untouched tail back on.
     */
    function trimReleaseJunk(string $name): string
    {
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
        return rtrim($name, " \t\n\r\0\x0B-._");
    }
}

if (!function_exists('dropNonCastTags')) {
    /**
     * Drop a known non-cast tag trailing the scene number ("Scene_1 Lh" →
     * "Scene_1"). Left in place it reaches sceneNormalization, which promotes
     * it into the cast position ("Scene_1 - Lh") — worse than leaving it,
     * because the Add Cast tab then counts the file as already having cast.
     *
     * Deliberately a list, never a heuristic: tags match whole and only as the
     * last thing in the name. So "Scene_1 Lhotse" is a cast name, and
     * "Scene_1 Lh - Jane Doe" keeps its Lh because it isn't the tail. Add
     * spellings here rather than loosening the match.
     *
     * Casing is ignored: the same file arrives as "Scene_1 Lh" or as the dotted
     * release form "scene.1.lh", and the library has no performer whose whole
     * name is a listed tag in any casing.
     */
    function dropNonCastTags(string $fileName): string
    {
        $NON_CAST_TAGS = ['Lh'];
        if (!$NON_CAST_TAGS) {
            return $fileName; // an empty alternation would match everything
        }

        $tags = implode('|', array_map(fn($t) => preg_quote($t, '/'), $NON_CAST_TAGS));

        return preg_replace('/(\bScene_\d+)\s+(?:' . $tags . ')$/i', '$1', $fileName);
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

        // Truncate the quality/codec/release-type tail and the separator it
        // dangles. Shared with dropNonCastTags, which needs the same answer to
        // find the real end of the name.
        $name = trimReleaseJunk($name);

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

        // Numbers ending a segment, when a Scene_N segment follows — directly
        // ("Title 2 - Scene_1") or past a subtitle ("Mountain Crush 2 -
        // Snowbunnies - Scene_1" → "Mountain Crush # 02 - Snowbunnies - …").
        // The Scene_ requirement keeps full-movie names alone: "Just 18 -
        // Pussycat Teens", "Fornication 101 - 2nd Semester", "Sinners - Club
        // 18 - Teenie Toys" are titles, not volume numbers (live-corpus
        // sweep, 2026-08-30). Scene numbers themselves are glued to their
        // underscore, so \b never matches them.
        $name = preg_replace_callback(
            '/(?<!# )(?<!Scene_)\b(\d+)(?=\s+-\s.*\bScene_\d)/',
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
        // After "Scene_N - ", treat " and " / " & " as a cast-member separator and turn it into ", ".
        // Only applies to the segment after Scene_N so titles containing "and" or "&" are untouched
        // ("The Busty & Bushy Cougar & Her Prey - Scene_1 - Chanel Preston").
        if (preg_match('/Scene_\d+\s+-\s+/i', $fileName, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $before = substr($fileName, 0, $offset);
            $after  = substr($fileName, $offset);
            // Absorb an optional preceding comma and repeated joins so
            // "Jane, and Kira" / "Jane and and Kira" / "Jane & and Kira"
            // collapse to "Jane, Kira" in one pass. Global, so a mixed chain
            // ("Ann and Bea & Cara") fully collapses in that same pass.
            $after  = preg_replace('/(?:\s*,)?(?:\s+(?:and|&))+\s+/i', ', ', $after);
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
     *   [3] dot-restore map: "katie st ives" => "Katie St. Ives" — store
     *       names whose periods basicFunctions' periods→spaces sweep would
     *       remove, keyed by that dotless form. Abbreviation-shaped names
     *       only (every dot ends a 1-2 letter word), so a dotted-release
     *       leftover in the store ("anja.amelia") can never become a restore
     *       target; ambiguous keys (two dotted spellings, or a dotless twin
     *       already in the store) map to null and are never rewritten.
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
        $dotRestore = [];
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
            if (strpos($name, '.') !== false) {
                preg_match_all('/(\p{L}*)\./u', $name, $runs);
                $abbrevOnly = true;
                foreach ($runs[1] as $run) {
                    $len = mb_strlen($run);
                    if ($len < 1 || $len > 2) {
                        $abbrevOnly = false;
                        break;
                    }
                }
                if ($abbrevOnly) {
                    $dotKey = trim(preg_replace('/\s+/', ' ', str_replace('.', ' ', $lower)));
                    $dotRestore[$dotKey] = array_key_exists($dotKey, $dotRestore) ? null : $name;
                }
            }
            if (strpos($name, ' ') === false) {
                continue; // a squash fix must be able to add a space
            }
            $key = str_replace(' ', '', $lower);
            $squash[$key] = array_key_exists($key, $squash) ? null : $name;
        }

        // A dotless spelling that is itself a store entry makes its dotted
        // twin ambiguous — leave both alone rather than pick a side.
        foreach ($dotRestore as $dotKey => $canonical) {
            if ($canonical !== null && isset($fullNames[$dotKey])) {
                $dotRestore[$dotKey] = null;
            }
        }

        $result = [$squash, $known, $fullNames, $dotRestore];
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
        [$squashMap, $known, $fullNames, $dotRestore] = moviedb_cast_squash_map($vocab);
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
            if ($part === '') {
                continue;
            }
            $partKey = mb_strtolower($part);
            // Store spelling wins for a name whose periods the pipeline
            // removed ("Katie St Ives" → "Katie St. Ives") — the same
            // authority the store already has over squashed spellings. This
            // also makes a deliberately dotted rename a fixed point of later
            // scans, once the rename feedback has stored the name.
            if (!empty($dotRestore[$partKey])) {
                $parts[$i] = $dotRestore[$partKey];
                continue;
            }
            if (isset($fullNames[$partKey])) {
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
