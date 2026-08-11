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

// --- moviedb_remove_name -----------------------------------------------------
check('remove: case-insensitive', moviedb_remove_name(['Marla Vex', 'Dahlia Frost'], 'MARLA VEX'),
    ['Dahlia Frost']);
check('remove: absent name leaves the list alone',
    moviedb_remove_name(['Marla Vex'], 'Nobody Here'), ['Marla Vex']);

// --- moviedb_merge_cast_names ------------------------------------------------
// The merge path must stay FIRST-wins: it is the harvester's idempotent path,
// where an incoming badly-cased duplicate must not overwrite a good entry.
check('merge: first occurrence wins on casing',
    moviedb_merge_cast_names(['Marla Vex', 'marla vex']), ['Marla Vex']);
check('merge: sorts naturally, case-insensitively',
    moviedb_merge_cast_names(['Dahlia Frost', 'Bex Marlowe', 'Corinne Vale']),
    ['Bex Marlowe', 'Corinne Vale', 'Dahlia Frost']);

// --- moviedb_stored_cast_name ------------------------------------------------
// What the endpoints echo back, so a status line can't claim a casing the store
// didn't take.
check('stored: returns the stored spelling, not the queried one',
    moviedb_stored_cast_name(['Dahlia Frost', 'marla vex'], 'Marla Vex'), 'marla vex');
check('stored: absent name yields empty string',
    moviedb_stored_cast_name(['Dahlia Frost'], 'Marla Vex'), '');

// --- moviedb_add_cast_name ---------------------------------------------------
check('add: appends a new name, sorted',
    moviedb_add_cast_name(['Dahlia Frost'], 'Bex Marlowe'), ['Bex Marlowe', 'Dahlia Frost']);
check('add: cleans the name it stores',
    moviedb_add_cast_name([], '  (Corinne   Vale),  '), ['Corinne Vale']);
check('add: an identical name does not duplicate',
    moviedb_add_cast_name(['Marla Vex'], 'Marla Vex'), ['Marla Vex']);
// Deliberate asymmetry with rename: a BLIND add must not recase a stored entry
// (the typist may not know it exists). Only rename is authoritative about
// casing. Flipping this would let a careless add clobber a good spelling.
check('add: does NOT recase an entry that already exists',
    moviedb_add_cast_name(['marla vex', 'Dahlia Frost'], 'Marla Vex'),
    ['Dahlia Frost', 'marla vex']);
check('add: unusable name leaves the list alone',
    moviedb_add_cast_name(['Dahlia Frost'], '1080'), ['Dahlia Frost']);

// --- moviedb_rename_cast_name ------------------------------------------------
// Fictional performer names throughout.
// Merging onto an existing, identically-cased entry must not leave a duplicate.
check('rename: merges onto an identically-cased entry',
    moviedb_rename_cast_name(['Marla Vex', 'With Marla Vex', 'Dahlia Frost'],
        'With Marla Vex', 'Marla Vex'),
    ['Dahlia Frost', 'Marla Vex']);
// The fix: an explicit rename is authoritative about casing, so the typed
// casing replaces the stored one instead of being silently discarded.
check('rename: typed casing beats a differently-cased existing entry',
    moviedb_rename_cast_name(['marla vex', 'With Marla Vex', 'Dahlia Frost'],
        'With Marla Vex', 'Marla Vex'),
    ['Dahlia Frost', 'Marla Vex']);
// ...and that must collapse to exactly ONE entry, not two spellings.
check('rename: differently-cased merge leaves exactly one entry',
    count(moviedb_rename_cast_name(['marla vex', 'With Marla Vex'], 'With Marla Vex', 'Marla Vex')),
    1);
// Recasing an entry onto itself (the sole-entry case) still works.
check('rename: recases a sole entry',
    moviedb_rename_cast_name(['marla vex'], 'marla vex', 'Marla Vex'), ['Marla Vex']);
// Recasing one entry among many, matched case-insensitively.
check('rename: recases via a case-insensitive match of the old name',
    moviedb_rename_cast_name(['Dahlia Frost', 'marla vex'], 'MARLA VEX', 'Marla Vex'),
    ['Dahlia Frost', 'Marla Vex']);
// Long-standing behaviour, kept: renaming a name that is not stored adds it.
check('rename: absent old name still adds the new one',
    moviedb_rename_cast_name(['Dahlia Frost'], 'Nobody Here', 'Corinne Vale'),
    ['Corinne Vale', 'Dahlia Frost']);
// An unusable new name must not mutate the list (the endpoint 400s first).
check('rename: unusable new name is a no-op',
    moviedb_rename_cast_name(['Dahlia Frost', 'Marla Vex'], 'Marla Vex', '  1080  '),
    ['Dahlia Frost', 'Marla Vex']);
check('rename: empty old name is a no-op',
    moviedb_rename_cast_name(['Dahlia Frost'], '', 'Corinne Vale'), ['Dahlia Frost']);
// ...and "no-op" still means store shape. The checks above pass lists that are
// already sorted and deduped, so they'd hold even if these paths returned the
// input raw; a messy list is what actually pins the docblock's promise that
// EVERY path out of the function is safe to hand straight to the store.
check('rename: unusable new name still returns store shape',
    moviedb_rename_cast_name(['ivy sandoval', 'Bex Marlowe', 'Ivy Sandoval'], 'Bex Marlowe', '1080'),
    ['Bex Marlowe', 'ivy sandoval']);
check('rename: empty old name still returns store shape',
    moviedb_rename_cast_name(['ivy sandoval', 'Bex Marlowe', 'Ivy Sandoval'], '', 'Corinne Vale'),
    ['Bex Marlowe', 'ivy sandoval']);
// A rename to the same text changes nothing.
check('rename: no-op rename',
    moviedb_rename_cast_name(['Dahlia Frost', 'Marla Vex'], 'Marla Vex', 'Marla Vex'),
    ['Dahlia Frost', 'Marla Vex']);
// The new name is cleaned exactly as the endpoint used to clean it.
check('rename: cleans the new name',
    moviedb_rename_cast_name(['Dahlia Frost'], 'Dahlia Frost', '  (Corinne   Vale),  '),
    ['Corinne Vale']);
// Unrelated entries survive untouched and the result comes back sorted.
check('rename: unrelated entries and sort order survive',
    moviedb_rename_cast_name(
        ['ivy sandoval', 'Bex Marlowe', 'With Ivy Sandoval', 'Dahlia Frost'],
        'With Ivy Sandoval', 'Ivy Sandoval'),
    ['Bex Marlowe', 'Dahlia Frost', 'Ivy Sandoval']);

// --- the two endpoint compositions -------------------------------------------
// castNamesManage.php's 'add' case, end to end: the echoed name must be the one
// the store kept, or the Settings page reports an add that never happened.
check('add + echo: reports the spelling the store kept',
    moviedb_stored_cast_name(moviedb_add_cast_name(['marla vex'], 'Marla Vex'), 'Marla Vex'),
    'marla vex');
check('add + echo: reports the typed spelling for a genuinely new name',
    moviedb_stored_cast_name(moviedb_add_cast_name(['Dahlia Frost'], 'Marla Vex'), 'Marla Vex'),
    'Marla Vex');
// The 'rename' case: save_cast_store's first-wins merge runs AFTER the rename
// and would undo the recasing if any case-variant had survived it.
check('rename + merge: the typed casing survives the save-path merge',
    moviedb_merge_cast_names(
        moviedb_rename_cast_name(['marla vex', 'With Marla Vex'], 'With Marla Vex', 'Marla Vex')),
    ['Marla Vex']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
