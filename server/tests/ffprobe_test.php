<?php

/**
 * Verification harness for server/ffprobe.php — proves the JSON parsing matches
 * the old inline extraction, and that the parallel pool launches/collects across
 * waves. No real ffprobe or video files required.
 *
 *   Run:  php server/tests/ffprobe_test.php
 *   Exit: 0 = all passed, 1 = at least one failed.
 */

require_once __DIR__ . '/../ffprobe.php';

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

echo "parseFfprobeOutput (mirrors the old inline extraction):\n";
check(
    'full blob',
    parseFfprobeOutput('{"streams":[{"width":1920,"height":1080}],"format":{"duration":"3600.7"}}'),
    ['dimensions' => '1920 x 1080', 'duration' => 3600]
);
check(
    'non-positive duration -> 0',
    parseFfprobeOutput('{"format":{"duration":"0"}}'),
    ['dimensions' => '', 'duration' => 0]
);
check(
    'missing format -> duration 0',
    parseFfprobeOutput('{"streams":[{"width":1280,"height":720}]}'),
    ['dimensions' => '1280 x 720', 'duration' => 0]
);
check(
    'skips stream without w/h, uses first that has them',
    parseFfprobeOutput('{"streams":[{"codec_type":"audio"},{"width":640,"height":480}],"format":{"duration":"10.0"}}'),
    ['dimensions' => '640 x 480', 'duration' => 10]
);
check(
    'zero dimensions -> blank',
    parseFfprobeOutput('{"streams":[{"width":0,"height":0}]}'),
    ['dimensions' => '', 'duration' => 0]
);
check(
    'no streams key -> blank dimensions',
    parseFfprobeOutput('{"format":{"duration":"5.9"}}'),
    ['dimensions' => '', 'duration' => 5]
);
check('invalid JSON -> null (file skipped)', parseFfprobeOutput('not json{'), null);
check('null input -> null', parseFfprobeOutput(null), null);
check('empty string -> null', parseFfprobeOutput(''), null);

echo "probeVideosParallel (pool plumbing, via a stub ffprobe):\n";
$stub = rtrim(sys_get_temp_dir(), '/') . '/moviedb_fake_ffprobe.sh';
$wrote = @file_put_contents(
    $stub,
    "#!/bin/sh\necho '{\"streams\":[{\"width\":1280,\"height\":720}],\"format\":{\"duration\":\"1800.2\"}}'\n"
);
if ($wrote !== false && @chmod($stub, 0755) && function_exists('proc_open')) {
    // 3 jobs, pool of 2 -> exercises two waves and keyed collection.
    $res = probeVideosParallel(
        ['a' => '/fake/x.mp4', 'b' => '/fake/y.mp4', 'c' => '/fake/z.mp4'],
        $stub,
        2
    );
    check('collects every job', count($res), 3);
    check('parses pooled result (wave 1)', $res['a'] ?? null, ['dimensions' => '1280 x 720', 'duration' => 1800]);
    check('parses pooled result (wave 2)', $res['c'] ?? null, ['dimensions' => '1280 x 720', 'duration' => 1800]);
    @unlink($stub);
} else {
    echo "  (skipped: could not create an executable stub or proc_open is unavailable)\n";
}

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures/$checks checks FAILED\n");
    exit(1);
}
echo "All $checks checks passed.\n";
exit(0);
