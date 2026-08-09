<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}


/**
 * Harness for server/cast_helpers.php — cleaning, splitting, homoglyph folding,
 * store round-trip excluded (filesystem). Run: php server/tests/cast_helpers_test.php
 * No DB needed.
 */

require_once __DIR__ . '/../cast_helpers.php';

$pass = 0;
$fail = 0;

function check(string $label, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL {$label}\n  expected: " . var_export($expected, true)
            . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

// --- moviedb_fold_homoglyphs -------------------------------------------------
// The measured case: U+0410 CYRILLIC CAPITAL A glued to Latin "ria"
check('fold: mixed-script word', moviedb_fold_homoglyphs("\u{0410}ria Lee"), 'Aria Lee');
// A word made entirely of lookalikes folds when a Latin word anchors the name
check('fold: all-lookalike word beside Latin word',
    moviedb_fold_homoglyphs("\u{0421}\u{041E}\u{0421}\u{041E} Lopez"), 'COCO Lopez');
// A genuinely Cyrillic name (no Latin anywhere) is untouched
check('fold: pure Cyrillic name untouched',
    moviedb_fold_homoglyphs("\u{041C}\u{0430}\u{0440}\u{0438}\u{044F}"),
    "\u{041C}\u{0430}\u{0440}\u{0438}\u{044F}");
// Greek capital omicron in a Latin name
check('fold: Greek omicron', moviedb_fold_homoglyphs("J\u{039F}anna Angel"), 'JOanna Angel');
// Plain Latin passes through untouched
check('fold: plain Latin untouched', moviedb_fold_homoglyphs('Angel Long'), 'Angel Long');
// Accented Latin is NOT a homoglyph and must survive
check('fold: accented Latin survives', moviedb_fold_homoglyphs('Chloé Lacourt'), 'Chloé Lacourt');

// --- moviedb_clean_cast_name -------------------------------------------------
check('clean: folds before returning', moviedb_clean_cast_name(" \u{0410}ria Lee "), 'Aria Lee');
check('clean: collapses whitespace', moviedb_clean_cast_name('  Angel   Long '), 'Angel Long');
check('clean: strips wrapping punctuation', moviedb_clean_cast_name('(Lisa Ann),'), 'Lisa Ann');
check('clean: rejects letterless input', moviedb_clean_cast_name('1080 - 720'), '');
check('clean: rejects empty', moviedb_clean_cast_name('   '), '');

// --- moviedb_split_cast_tail -------------------------------------------------
check('split: comma', moviedb_split_cast_tail('Angel Long, Paige Owens'),
    ['Angel Long', 'Paige Owens']);
check('split: ampersand', moviedb_split_cast_tail('Jane Wilde & Blake Blossom'),
    ['Jane Wilde', 'Blake Blossom']);
check('split: "and"', moviedb_split_cast_tail('Lisa Ann and Kianna Dior'),
    ['Lisa Ann', 'Kianna Dior']);
check('split: folds each name', moviedb_split_cast_tail("\u{0410}ria Lee, Angel Long"),
    ['Aria Lee', 'Angel Long']);
check('split: drops junk parts', moviedb_split_cast_tail('Angel Long, 1080'),
    ['Angel Long']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
