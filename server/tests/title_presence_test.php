<?php

/**
 * Verification harness for server/title_presence.php — proves the in-memory
 * presence index returns the same answers the per-base SQL queries did. No DB
 * required.
 *
 *   Run:  php server/tests/title_presence_test.php
 *   Exit: 0 = all checks passed, 1 = at least one failed.
 */

require_once __DIR__ . '/../title_presence.php';

$failures = 0;
$checks = 0;

function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual === $expected) {
        echo "  ok: $label\n";
        return;
    }
    $failures++;
    fwrite(STDERR, sprintf(
        "FAIL: %s\n    expected: %s\n    actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    ));
}

$titles = [
    'Big Movie',                    // unnumbered + has numbered variants
    'Big Movie # 01',
    'Big Movie # 02',
    'Solo # 03',                    // numbered only, no unnumbered variant
    'Plain Title',                  // unnumbered only
    'Cast Show # 04 - Alice, Bob',  // numbered with a cast suffix
];
$idx = buildTitlePresenceIndex($titles);

echo "presenceFromIndex (mirrors getDbNumberingPresence):\n";
check('Big Movie -> unnumbered', presenceFromIndex($idx, 'Big Movie')['dbHasUnnumberedVariant'], true);
check('Big Movie -> numbered',   presenceFromIndex($idx, 'Big Movie')['dbHasNumberedVariant'],   true);
check('Solo -> NOT unnumbered',  presenceFromIndex($idx, 'Solo')['dbHasUnnumberedVariant'],      false);
check('Solo -> numbered',        presenceFromIndex($idx, 'Solo')['dbHasNumberedVariant'],        true);
check('Plain Title -> unnumbered', presenceFromIndex($idx, 'Plain Title')['dbHasUnnumberedVariant'], true);
check('Plain Title -> NOT numbered', presenceFromIndex($idx, 'Plain Title')['dbHasNumberedVariant'], false);
check('Cast Show (base via suffix) -> numbered', presenceFromIndex($idx, 'Cast Show')['dbHasNumberedVariant'], true);
check('Unknown -> NOT unnumbered', presenceFromIndex($idx, 'Nope')['dbHasUnnumberedVariant'], false);
check('Unknown -> NOT numbered',   presenceFromIndex($idx, 'Nope')['dbHasNumberedVariant'],   false);

echo "case-insensitive (mirrors default ci collation):\n";
check('BIG MOVIE -> unnumbered', presenceFromIndex($idx, 'BIG MOVIE')['dbHasUnnumberedVariant'], true);
check('big movie -> numbered',   presenceFromIndex($idx, 'big movie')['dbHasNumberedVariant'],   true);

echo "hasOtherNumberedFromIndex (mirrors 'LIKE Base # %' AND title <> ?):\n";
check('# 01 sees sibling # 02', hasOtherNumberedFromIndex($idx, 'Big Movie # 01', 'Big Movie'), true);
check('# 02 sees sibling # 01', hasOtherNumberedFromIndex($idx, 'Big Movie # 02', 'Big Movie'), true);
check('solo # 03 has no sibling', hasOtherNumberedFromIndex($idx, 'Solo # 03', 'Solo'), false);
check('not-yet-present # 99 still sees existing siblings', hasOtherNumberedFromIndex($idx, 'Big Movie # 99', 'Big Movie'), true);
check('unknown base has no other', hasOtherNumberedFromIndex($idx, 'Nope # 01', 'Nope'), false);

echo "edge cases:\n";
$idx2 = buildTitlePresenceIndex(['', '   ', 'Real']);
check('blank titles ignored; real present', presenceFromIndex($idx2, 'Real')['dbHasUnnumberedVariant'], true);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures/$checks checks FAILED\n");
    exit(1);
}
echo "All $checks checks passed.\n";
exit(0);
