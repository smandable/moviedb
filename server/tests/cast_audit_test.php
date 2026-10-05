<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Harness for server/cast_audit_lib.php — the cast-name vocabulary audit
 * (duplicates, spelling variants, junk-shaped names, usage counts, dismissal
 * keys). Run: php server/tests/cast_audit_test.php
 * Fixture-only: invented names, an in-memory index, a temp dismissals file.
 */

require_once __DIR__ . '/../cast_audit_lib.php';

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

/** The name groups of one kind, for compact assertions. */
function groups(array $audit, string $kind): array
{
    $out = [];
    foreach ($audit['findings'] as $f) {
        if ($f['kind'] === $kind) {
            $out[] = $f['names'];
        }
    }
    return $out;
}

// --- folding and skeletons --------------------------------------------------
check('fold strips case/accents/space/punct', moviedb_cast_audit_fold('Zoë M.-Vale'), 'zoemvale');
check('skeleton: doubled letters', moviedb_cast_audit_skeleton('orlanna'), moviedb_cast_audit_skeleton('orlana'));
check('skeleton: ks = x', moviedb_cast_audit_skeleton('tekssa'), moviedb_cast_audit_skeleton('texsa'));
check('skeleton: y/ie/ey = i', moviedb_cast_audit_skeleton('brynley'), moviedb_cast_audit_skeleton('brinlie'));
check('skeleton: trailing s/e', moviedb_cast_audit_skeleton('quists'), moviedb_cast_audit_skeleton('quist'));

// --- word closeness ----------------------------------------------------------
check('close: one letter added', moviedb_cast_audit_words_close('vale', 'vales', false), true);
check('close: one letter changed', moviedb_cast_audit_words_close('brack', 'brick', false), true);
check('close: adjacent swap', moviedb_cast_audit_words_close('orlena', 'orlnea', false), true);
check('close: non-adjacent swap is not', moviedb_cast_audit_words_close('orlena', 'arleno', false), false);
check('close: different first letter is not', moviedb_cast_audit_words_close('tova', 'nova', false), false);
check('close: identical words are not', moviedb_cast_audit_words_close('vale', 'vale', false), false);
check('close: initials are not', moviedb_cast_audit_words_close('c', 'd', false), false);
check('close: short mononyms are not', moviedb_cast_audit_words_close('tova', 'tora', true), false);
check('close: long mononyms are', moviedb_cast_audit_words_close('orlena', 'orlana', true), true);
check('close: two edits apart is not', moviedb_cast_audit_words_close('brackett', 'brickel', false), false);

// --- junk reasons ------------------------------------------------------------
$known = ['marla vex' => 'Marla Vex', 'juna quist' => 'Juna Quist'];
check('junk: clean name', moviedb_cast_audit_junk_reasons('Marla Vex', $known), []);
check('junk: particle mid-name is fine', moviedb_cast_audit_junk_reasons('Alba de Vale'), []);
check('junk: digit', moviedb_cast_audit_junk_reasons('Vexa69'), ['Contains a digit']);
check('junk: symbol', moviedb_cast_audit_junk_reasons('Marla#Vex'), ['Contains “#”']);
check('junk: junk word', moviedb_cast_audit_junk_reasons('Unknown Girl'), ['Contains the word “Unknown”']);
check('junk: colour surname is fine', moviedb_cast_audit_junk_reasons('Tessaly Black'), []);
check('junk: lowercase first word', moviedb_cast_audit_junk_reasons('marla Vex'), ['Lowercase word “marla”']);
check('junk: leading particle is still lowercase', moviedb_cast_audit_junk_reasons('de Vale'), ['Lowercase word “de”']);
check('junk: all caps word', moviedb_cast_audit_junk_reasons('MARLA Vex'), ['All-capitals word “MARLA”']);
check('junk: two-letter caps is fine', moviedb_cast_audit_junk_reasons('JJ Vex'), []);
check('junk: four words', moviedb_cast_audit_junk_reasons('Alba Rosa Vale Quist'), ['Four or more words']);
check('junk: too short', moviedb_cast_audit_junk_reasons('Vi'), ['Too short to be a name']);
check('junk: run together', moviedb_cast_audit_junk_reasons('Marla Vex Juna Quist', $known),
    ['Four or more words', 'Two stored names run together: “Marla Vex” + “Juna Quist”']);
check('junk: one-word halves are not a run-together', moviedb_cast_audit_junk_reasons('Marla Vex', ['marla' => 1, 'vex' => 1]), []);
check('junk: would clean differently', moviedb_cast_audit_junk_reasons('With Marla Vex'),
    ['Would be stored as “Marla Vex”', 'Contains the word “With”']);

// --- the audit ---------------------------------------------------------------
$names = [
    'Zoë Vale', 'Zoe Vale',              // accent duplicate
    'AbbyLee Brack', 'Abby Lee Brack',   // spacing duplicate
    'Quist Juna', 'Juna Quist',          // swapped
    'Orlena Rain', 'Orlena Rains', 'Orlenna Rains', // a chain of three
    'Tova Lux', 'Nova Lux',              // first letter differs: not a variant
    'Dallas C', 'Dallas D',              // initials: not a variant
    'Marla Vex',
    'Intro',
];
$audit = moviedb_cast_audit($names);
// Each group is in the store's own natural order
check('audit: duplicates', groups($audit, 'duplicate'), [
    ['AbbyLee Brack', 'Abby Lee Brack'],
    ['Juna Quist', 'Quist Juna'],
    ['Zoe Vale', 'Zoë Vale'],
]);
check('audit: variants chain into one finding', groups($audit, 'variant'), [
    ['Orlena Rain', 'Orlena Rains', 'Orlenna Rains'],
]);
check('audit: junk', groups($audit, 'junk'), [['Intro']]);
check('audit: kinds in order', array_values(array_unique(array_column($audit['findings'], 'kind'))),
    ['duplicate', 'variant', 'junk']);
check('audit: nothing hidden', $audit['hidden'], 0);
check('audit: key is kind + sorted lowercase names',
    $audit['findings'][0]['key'], 'duplicate|abby lee brack|abbylee brack');

$dismissed = moviedb_cast_audit($names, ['variant|orlena rain|orlena rains|orlenna rains', 'junk|intro']);
check('dismissed: findings hidden', groups($dismissed, 'variant'), []);
check('dismissed: junk hidden', groups($dismissed, 'junk'), []);
check('dismissed: counted', $dismissed['hidden'], 2);
// A new spelling joining the chain is a new finding, so it shows again
$grown = moviedb_cast_audit(array_merge($names, ['Orlena Raine']), ['variant|orlena rain|orlena rains|orlenna rains']);
check('dismissed: grown chain shows again', groups($grown, 'variant'),
    [['Orlena Rain', 'Orlena Raine', 'Orlena Rains', 'Orlenna Rains']]);
check('dismissed: grown chain not counted hidden', $grown['hidden'], 0);

check('audit: empty store', moviedb_cast_audit([]), ['findings' => [], 'hidden' => 0]);

// --- usage --------------------------------------------------------------------
$entries = [
    ['dir' => '/Volumes/X/recorded', 'file' => 'Sample Movie # 01 - Scene_1 - Marla Vex, Juna Quist.mp4'],
    ['dir' => '/Volumes/X/recorded/', 'file' => 'Sample Movie # 01 - Scene_2 - marla vex.mp4'],
    ['dir' => '/Volumes/X/recorded', 'file' => 'Sample Movie # 02 - Scene_1 Marla Vex & Marla Vex.mp4'],
    ['dir' => '/Volumes/X/recorded', 'file' => 'Sample Movie # 03 - Marla Vex.mp4'],
    ['dir' => '/Volumes/X/recorded', 'file' => 'Other # 01 - Scene_1 - Marla Vex.mp4'],
];
$usage = moviedb_cast_audit_usage($entries);
check('usage: counted case-insensitively, once per file', $usage['marla vex']['count'], 4);
check('usage: sample files capped', count($usage['marla vex']['files']), MOVIEDB_CAST_AUDIT_SAMPLE_FILES);
check('usage: sample path joined cleanly', $usage['marla vex']['files'][1], '/Volumes/X/recorded/Sample Movie # 01 - Scene_2 - marla vex.mp4');
check('usage: second cast member', $usage['juna quist']['count'], 1);
check('usage: no Scene_N, no cast', isset($usage['sample movie # 03']), false);

// --- dismissals file round-trip ----------------------------------------------
$tmp = tempnam(sys_get_temp_dir(), 'castaudit');
unlink($tmp);
check('dismissed file: missing = empty', moviedb_cast_audit_load_dismissed($tmp), []);
check('dismissed file: save', moviedb_cast_audit_save_dismissed(['junk|b', 'junk|a', 'junk|b'], $tmp), true);
check('dismissed file: deduped and sorted', moviedb_cast_audit_load_dismissed($tmp), ['junk|a', 'junk|b']);
file_put_contents($tmp, '{"not": "a list"');
check('dismissed file: corrupt = empty', moviedb_cast_audit_load_dismissed($tmp), []);
unlink($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
