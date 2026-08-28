<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Harness for server/rename_helpers.php — the needs-cast staging move-up.
 * Run: php server/tests/rename_helpers_test.php
 * Fixture-only: works in a temp directory, never touches /Volumes or the
 * real server/app_settings.json (every call passes an explicit settings file).
 */

// Point the path guard at the fixture root BEFORE the lib loads env_loader —
// the loader never overwrites an already-set variable, so this wins over any
// ALLOWED_BASE_PATH in server/.env.
$root = rtrim(sys_get_temp_dir(), '/') . '/moviedb-rename-helpers-test-' . getmypid();
putenv('ALLOWED_BASE_PATH=' . $root);

require_once __DIR__ . '/../rename_helpers.php';

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

function rrmdir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

// --- fixture tree ------------------------------------------------------------
$recorded = $root . '/recorded';
$staging  = $recorded . '/needs-cast';
if (!mkdir($staging, 0777, true)) {
    fwrite(STDERR, "Could not create fixture dir $staging\n");
    exit(1);
}

$settingsOn      = $root . '/settings-on.json';
$settingsOff     = $root . '/settings-off.json';
$settingsEmpty   = $root . '/settings-empty.json';
$settingsBad     = $root . '/settings-bad.json';
$settingsMissing = $root . '/settings-does-not-exist.json';
file_put_contents($settingsOn, json_encode(['moveRenamedUpFromNeedsCast' => true]));
file_put_contents($settingsOff, json_encode(['moveRenamedUpFromNeedsCast' => false]));
file_put_contents($settingsEmpty, '{}');
file_put_contents($settingsBad, 'not json at all');

// --- moviedb_move_up_after_rename_enabled ------------------------------------
check('enabled: stored true', moviedb_move_up_after_rename_enabled($settingsOn), true);
check('enabled: stored false', moviedb_move_up_after_rename_enabled($settingsOff), false);
check('enabled: key absent defaults on', moviedb_move_up_after_rename_enabled($settingsEmpty), true);
check('enabled: unreadable JSON defaults on', moviedb_move_up_after_rename_enabled($settingsBad), true);
check('enabled: missing file defaults on', moviedb_move_up_after_rename_enabled($settingsMissing), true);

// --- moviedb_needs_cast_parent -----------------------------------------------
check('parent: staging dir', moviedb_needs_cast_parent('/a/recorded/needs-cast'), '/a/recorded');
check('parent: trailing slash', moviedb_needs_cast_parent('/a/recorded/needs-cast/'), '/a/recorded');
check('parent: case-insensitive', moviedb_needs_cast_parent('/a/recorded/Needs-Cast'), '/a/recorded');
check('parent: ordinary dir', moviedb_needs_cast_parent('/a/recorded'), null);
check('parent: name merely contains it', moviedb_needs_cast_parent('/a/needs-cast-2'), null);

// --- moviedb_ends_with_scene_number ------------------------------------------
check('scene: bare scene number', moviedb_ends_with_scene_number('Fixture Feature - Scene_1'), true);
check('scene: un-normalized spelling', moviedb_ends_with_scene_number('fixture feature scene 2'), true);
check('scene: two-digit scene', moviedb_ends_with_scene_number('Fixture Feature - Scene_10'), true);
check('scene: cast named after it', moviedb_ends_with_scene_number('Fixture Feature - Scene_1 - Casey Fixture'), false);
check('scene: no scene number at all', moviedb_ends_with_scene_number('Fixture Feature'), false);
check('scene: 4+ digits are a year', moviedb_ends_with_scene_number('Crime Scene 1999'), false);

// --- moviedb_move_renamed_up -------------------------------------------------
// A: cast landed -> the file graduates up a level
$name = 'Fixture Feature - Scene_1 - Casey Fixture.mp4';
touch("$staging/$name");
check('move: graduates a cast-named file', moviedb_move_renamed_up($staging, $name, $settingsOn), ['movedTo' => $recorded]);
check('move: file now in the parent', is_file("$recorded/$name"), true);
check('move: file gone from staging', is_file("$staging/$name"), false);

// B: still cast-less -> stays staged
$name = 'Fixture Feature - Scene_2.mp4';
touch("$staging/$name");
check('move: cast-less file not applicable', moviedb_move_renamed_up($staging, $name, $settingsOn), null);
check('move: cast-less file stays staged', is_file("$staging/$name"), true);

// C: target exists in the parent -> error, never an overwrite
$name = 'Fixture Feature - Scene_3 - Casey Fixture.mp4';
touch("$staging/$name");
touch("$recorded/$name");
$result = moviedb_move_renamed_up($staging, $name, $settingsOn);
check('move: collision reports moveError', str_contains($result['moveError'] ?? '', 'already exists'), true);
check('move: collision keeps the staged copy', is_file("$staging/$name"), true);

// D: toggle off -> not applicable even for a cast-named file
$name = 'Fixture Feature - Scene_4 - Casey Fixture.mp4';
touch("$staging/$name");
check('move: toggle off is not applicable', moviedb_move_renamed_up($staging, $name, $settingsOff), null);
check('move: toggle off keeps the file staged', is_file("$staging/$name"), true);

// E: an ordinary (non-staging) directory is never touched
$name = 'Fixture Feature - Scene_5 - Casey Fixture.mp4';
touch("$recorded/$name");
check('move: ordinary dir not applicable', moviedb_move_renamed_up($recorded, $name, $settingsOn), null);
check('move: ordinary dir file untouched', is_file("$recorded/$name"), true);

// F: trailing slash on the staging dir works too
$name = 'Fixture Feature - Scene_6 - Casey Fixture.mp4';
touch("$staging/$name");
check('move: trailing-slash dir', moviedb_move_renamed_up("$staging/", $name, $settingsOn), ['movedTo' => $recorded]);

// G: missing settings file means the default (ON) applies
$name = 'Fixture Feature - Scene_7 - Casey Fixture.mp4';
touch("$staging/$name");
check('move: missing settings file moves', moviedb_move_renamed_up($staging, $name, $settingsMissing), ['movedTo' => $recorded]);

// H: a path-segment "filename" is refused outright
check('move: non-plain filename refused', moviedb_move_renamed_up($staging, '../evil.mp4', $settingsOn), null);

// --- moviedb_rename_with_reason ----------------------------------------------
touch("$root/reason-src.txt");
$reason = null;
check('rename_with_reason: success', moviedb_rename_with_reason("$root/reason-src.txt", "$root/reason-dst.txt", $reason), true);
check('rename_with_reason: no reason on success', $reason, '');
// The failure case warns by design (that warning is where the reason comes
// from) — @ keeps it off the console while error_get_last() still sees it.
$ok = @moviedb_rename_with_reason("$root/does-not-exist.txt", "$root/nope.txt", $reason);
check('rename_with_reason: failure returns false', $ok, false);
check('rename_with_reason: failure carries a reason', $reason !== '', true);

rrmdir($root);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
