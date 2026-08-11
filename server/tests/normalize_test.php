<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}


/**
 * Verification harness for server/normalize_helpers.php — covers the full
 * normalizeFileBaseName pipeline, with emphasis on Scene_N handling:
 * any "scene<sep>N" spelling is canonicalized to "Scene_N", set off with
 * " - " on both sides when a title precedes / a cast name follows.
 *
 *   Run:  php server/tests/normalize_test.php
 *   Exit: 0 = all checks passed, 1 = at least one failed.
 */

require_once __DIR__ . '/../normalize_helpers.php';

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

echo "scene separator canonicalization:\n";
check(
    'periods around scene + name',
    normalizeFileBaseName('Naturals.4.Scene.1.Alyx.Star'),
    'Naturals # 04 - Scene_1 - Alyx Star'
);
check(
    'spaces around scene + name',
    normalizeFileBaseName('Naturals 4 Scene 1 Alyx Star'),
    'Naturals # 04 - Scene_1 - Alyx Star'
);
check(
    'underscore scene form',
    normalizeFileBaseName('Naturals.4.Scene_1.Alyx.Star'),
    'Naturals # 04 - Scene_1 - Alyx Star'
);
check(
    'glued "Scene1" and lowercase "scene"',
    normalizeFileBaseName('naturals 4 scene1 alyx star'),
    'Naturals # 04 - Scene_1 - Alyx Star'
);
check(
    'hyphenated "Scene-1"',
    normalizeFileBaseName('Naturals 4 Scene-1 Alyx Star'),
    'Naturals # 04 - Scene_1 - Alyx Star'
);
check(
    'ALL-CAPS SCENE',
    normalizeFileBaseName('NATURALS 4 SCENE 1 Alyx Star', true),
    'NATURALS # 04 - Scene_1 - Alyx Star'
);

echo "dash placement around Scene_N:\n";
check(
    'name glued to scene number with hyphen',
    normalizeFileBaseName('Naturals.4.Scene.1-Alyx.Star'),
    'Naturals # 04 - Scene_1 - Alyx Star'
);
check(
    'already has dash before scene',
    normalizeFileBaseName('Naturals 4 - Scene_1 Alyx Star'),
    'Naturals # 04 - Scene_1 - Alyx Star'
);
check(
    'no name after scene number',
    normalizeFileBaseName('Naturals 4 Scene 2'),
    'Naturals # 04 - Scene_2'
);
check(
    'scene at start of name is left unprefixed',
    normalizeFileBaseName('Scene 1 Alyx Star'),
    'Scene_1 - Alyx Star'
);
check(
    'scene number is not zero-padded',
    normalizeFileBaseName('Title 12 Scene 3 Jane Doe'),
    'Title # 12 - Scene_3 - Jane Doe'
);
check(
    'zero-padded scene number loses the leading zero',
    normalizeFileBaseName('Naturals.4.Scene.01.Alyx.Star'),
    'Naturals # 04 - Scene_1 - Alyx Star'
);
check(
    'small word after scene dash is capitalized (idempotent form)',
    normalizeFileBaseName('Title.Scene.2.With.Kira.Noir'),
    'Title - Scene_2 - With Kira Noir'
);

echo "interaction with existing rules:\n";
check(
    'quality-marker junk is truncated before scene handling',
    normalizeFileBaseName('Naturals.4.scene.3.Some.Name.XXX.1080p.WEBRip-GRP'),
    'Naturals # 04 - Scene_3 - Some Name'
);
check(
    'year before scene is preserved (not turned into # NN)',
    normalizeFileBaseName('Title 2020 Scene 1 Jane Doe'),
    'Title 2020 - Scene_1 - Jane Doe'
);
check(
    'year AFTER the word "Scene" stays a year, not a scene number',
    normalizeFileBaseName('The.Crime.Scene.1999'),
    'The Crime Scene 1999'
);
check(
    'underscore-glued year is detached, kept as a year',
    normalizeFileBaseName('Crime_Scene_1999'),
    'Crime Scene 1999'
);
check(
    'hyphen-glued year is detached, kept as a year',
    normalizeFileBaseName('Crime.Scene-1999'),
    'Crime Scene 1999'
);
check(
    'trailing 4-digit number outside the old year window stays plain',
    normalizeFileBaseName('The.Scene.1974'),
    'The Scene 1974'
);
check(
    '4-digit number before a scene marker stays plain',
    normalizeFileBaseName('Title.1974.Scene.2.Jane.Doe'),
    'Title 1974 - Scene_2 - Jane Doe'
);
check(
    'title ending in "Scene" + year + quality junk',
    normalizeFileBaseName('The.Kill.Scene.2019.1080p.WEBRip.x264-GRP'),
    'The Kill Scene 2019'
);
check(
    'quality tag straight after "Scene" is junk, not a scene number',
    normalizeFileBaseName('Hot.Tub.Scene.1080p'),
    'Hot Tub Scene'
);
check(
    '2160p + release junk after "Scene" fully truncated',
    normalizeFileBaseName('Crime.Scene.2160p.HEVC-GRP'),
    'Crime Scene'
);
check(
    'bare trailing resolution (no "p") is junk',
    normalizeFileBaseName('Black Owned # 02_scene_1_1080'),
    'Black Owned # 02 - Scene_1'
);
check(
    'bare trailing resolution after scene+name',
    normalizeFileBaseName('Title.Scene.2.Jane.Doe.720'),
    'Title - Scene_2 - Jane Doe'
);
check(
    'bare resolution followed by codec junk, stripped in one pass',
    normalizeFileBaseName('Movie.Title.1080.x264-KTR'),
    'Movie Title'
);
check(
    'bare resolution + rip tag, no "# 720" fabrication',
    normalizeFileBaseName('Movie.720.WEBRip'),
    'Movie'
);
check(
    'existing "# 720" series index is NOT eaten by the resolution rule',
    normalizeFileBaseName('Deep Anal Drilling Scene1 # 720'),
    'Deep Anal Drilling - Scene_1 # 720'
);
check(
    'hyphen-glued XXX after scene number, split in one pass',
    normalizeFileBaseName('Snow.White.Scene.1-XXX.An.Axel.Braun.Parody'),
    'Snow White - Scene_1 - XXX - An Axel Braun Parody'
);
check(
    'doubled "and" in cast list collapses cleanly',
    normalizeFileBaseName('Title.Scene.1.Jane.and.and.Kira'),
    'Title - Scene_1 - Jane, Kira'
);
check(
    '"and" after scene cast becomes comma',
    normalizeFileBaseName('Naturals.4.Scene.1.Alyx.Star.and.Jane.Doe'),
    'Naturals # 04 - Scene_1 - Alyx Star, Jane Doe'
);
check(
    'title containing "Scenes" is untouched',
    normalizeFileBaseName('Behind the Scenes'),
    'Behind the Scenes'
);
check(
    'word ending in "scene" is untouched',
    normalizeFileBaseName('Obscene 2 Jane'),
    'Obscene 2 Jane'
);
check(
    'underscore after a word ending in "scene" is not a scene marker',
    normalizeFileBaseName('Obscene_1'),
    'Obscene # 01'
);
check(
    'parenthesized scene marker: canonicalized but no dash insertion',
    normalizeFileBaseName('Movie Title (Scene 1)'),
    'Movie Title (Scene_1)'
);
check(
    'comma before scene marker becomes the dash',
    normalizeFileBaseName('Title, Scene 2'),
    'Title - Scene_2'
);
check(
    'comma glued to scene word, period separators',
    normalizeFileBaseName('Title,Scene.2'),
    'Title - Scene_2'
);
check(
    'comma surrounded by period separators',
    normalizeFileBaseName('Title.,.Scene.2.Jane.Doe'),
    'Title - Scene_2 - Jane Doe'
);
check(
    'accented cast name gets the dash and cast comma',
    normalizeFileBaseName('Scene.2.Émilie.and.Zoé'),
    'Scene_2 - Émilie, Zoé'
);
check(
    'hyphen-separated site prefix is split and title-cased',
    normalizeFileBaseName('brazzers-scene-4-jane'),
    'Brazzers - Scene_4 - Jane'
);
check(
    'plain title without scene',
    normalizeFileBaseName('some.title.4'),
    'Some Title # 04'
);
check(
    '"vs" stays lowercase through the second titleCase pass',
    normalizeFileBaseName('Alektra.vs.Amy.Reid'),
    'Alektra vs. Amy Reid'
);

echo "\"&\" as a cast separator (real names below come from the renamed corpus):\n";
check(
    '"&" between cast members becomes a comma',
    normalizeFileBaseName('Girls Playing # 03 - Scene_1 - Hollie Morgan & Courtney Simpson'),
    'Girls Playing # 03 - Scene_1 - Hollie Morgan, Courtney Simpson'
);
check(
    'multi-way "&" chain collapses in one pass (and gains the missing dash)',
    normalizeFileBaseName('Slippery When Wet # 03 - Scene_15 Aurora Snow & Felecia & Tanya Danielle'),
    'Slippery When Wet # 03 - Scene_15 - Aurora Snow, Felecia, Tanya Danielle'
);
check(
    'mixed "and"/"&" chain collapses in one pass',
    normalizeFileBaseName('Movie - Scene_1 - Jane Fauxheart and Kira Mock & Lena Dupe'),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock, Lena Dupe'
);
check(
    'comma before "&" is absorbed, not doubled',
    normalizeFileBaseName('Movie - Scene_1 - Jane Fauxheart, & Kira Mock'),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'repeated "&" collapses to a single comma',
    normalizeFileBaseName('Movie - Scene_1 - Jane Fauxheart & & Kira Mock'),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'period-separated "&" (dotted release name)',
    normalizeFileBaseName('Movie.Scene.1.Jane.Fauxheart.&.Kira.Mock'),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'title "&" before the scene marker is untouched',
    normalizeFileBaseName('The Busty & Bushy Cougar & Her Prey - Scene_1 - Chanel Preston'),
    'The Busty & Bushy Cougar & Her Prey - Scene_1 - Chanel Preston'
);
check(
    'title "and" is kept; only the cast tail becomes a comma',
    normalizeFileBaseName('Serene Siren and Her Girlfriends # 02 - Scene_1 - Serene Siren and Danni Rivers'),
    'Serene Siren and Her Girlfriends # 02 - Scene_1 - Serene Siren, Danni Rivers'
);
check(
    'title "&" with no cast tail at all',
    normalizeFileBaseName('18 & Creamed - Scene_1'),
    '18 & Creamed - Scene_1'
);
check(
    'no scene marker: "&" is not a cast separator',
    normalizeFileBaseName('Jane Fauxheart & Kira Mock Compilation'),
    'Jane Fauxheart & Kira Mock Compilation'
);

echo "trailing non-cast tags are dropped, not promoted to cast:\n";
check(
    'trailing "Lh" is dropped',
    normalizeFileBaseName('Cum Oozing Holes # 01 - Scene_1 Lh'),
    'Cum Oozing Holes # 01 - Scene_1'
);
check(
    'trailing "Lh" in the period-separated form',
    normalizeFileBaseName('Movie.Scene.1.Lh'),
    'Movie - Scene_1'
);
check(
    'a cast name merely starting with the tag is kept',
    normalizeFileBaseName('Movie - Scene_1 Lhotse'),
    'Movie - Scene_1 - Lhotse'
);
check(
    'lowercase "lh" is the same tag',
    normalizeFileBaseName('Movie - Scene_1 lh'),
    'Movie - Scene_1'
);
check(
    'all-caps "LH" is the same tag',
    normalizeFileBaseName('Movie - Scene_1 LH'),
    'Movie - Scene_1'
);
check(
    'the all-lowercase dotted release form drops the tag too',
    normalizeFileBaseName('cum.oozing.holes.01.scene.1.lh'),
    'Cum Oozing Holes # 01 - Scene_1'
);
check(
    'tag that is not the tail is kept',
    normalizeFileBaseName('Movie - Scene_1 Lh - Jane Fauxheart'),
    'Movie - Scene_1 - Lh - Jane Fauxheart'
);
check(
    'tag already promoted to cast is left as the user has it',
    normalizeFileBaseName('Movie - Scene_1 - Lh'),
    'Movie - Scene_1 - Lh'
);
check(
    'no scene marker: a trailing tag is just a word',
    normalizeFileBaseName('Movie Lh'),
    'Movie Lh'
);
// The tag is located against the release-junk-trimmed name, not the raw
// string: incoming release names carry a quality tail, and cleanupFunctions
// does not truncate it until after titleCase has destroyed the casing this
// match depends on. Before that, every one of these promoted the tag to cast.
check(
    'tag behind a quality marker is still the tail',
    normalizeFileBaseName('Movie - Scene_1 Lh 1080p'),
    'Movie - Scene_1'
);
check(
    'tag behind a bare trailing resolution',
    normalizeFileBaseName('Movie - Scene_1 Lh 1080'),
    'Movie - Scene_1'
);
check(
    'tag behind a full release tail',
    normalizeFileBaseName('Movie - Scene_1 Lh.1080p.x264-KTR'),
    'Movie - Scene_1'
);
check(
    'tag behind an "XXX"-anchored quality marker',
    normalizeFileBaseName('Movie - Scene_1 Lh XXX 1080p'),
    'Movie - Scene_1'
);
check(
    'tag behind a dangling separator',
    normalizeFileBaseName('Movie - Scene_1 Lh -'),
    'Movie - Scene_1'
);
check(
    'lowercase "lh" behind a quality tail is still the tag',
    normalizeFileBaseName('Movie - Scene_1 lh 1080p'),
    'Movie - Scene_1'
);
check(
    'junk trimming does not make a longer name a tag',
    normalizeFileBaseName('Movie - Scene_1 Lhotse 1080p'),
    'Movie - Scene_1 - Lhotse'
);
check(
    'junk trimming does not drop a non-tail tag',
    normalizeFileBaseName('Movie - Scene_1 Lh - Jane Fauxheart 1080p'),
    'Movie - Scene_1 - Lh - Jane Fauxheart'
);
check(
    'a name that merely ends in the tag word keeps its quality trimming',
    normalizeFileBaseName('Movie - Scene_1 - Jane Fauxheart 1080p'),
    'Movie - Scene_1 - Jane Fauxheart'
);

echo "mid-title XXX becomes a subtitle break:\n";
check(
    'XXX mid-title gets " - " after it, next small word capitalized',
    normalizeFileBaseName('Snow.White.XXX.An.Axel.Braun.Parody'),
    'Snow White XXX - An Axel Braun Parody'
);
check(
    'all-lowercase input',
    normalizeFileBaseName('snow.white.xxx.an.axel.braun.parody'),
    'Snow White XXX - An Axel Braun Parody'
);
check(
    'trailing junk XXX+quality still stripped, title XXX kept',
    normalizeFileBaseName('Snow.White.XXX.An.Axel.Braun.Parody.XXX.1080p.WEBRip-KTR'),
    'Snow White XXX - An Axel Braun Parody'
);
check(
    'article after XXX is capitalized at insertion',
    normalizeFileBaseName('batman.xxx.a.porn.parody'),
    'Batman XXX - A Porn Parody'
);
check(
    'no article after XXX → no dash (subtitle heuristic)',
    normalizeFileBaseName('Ghostbusters.XXX.Parody'),
    'Ghostbusters XXX Parody'
);
check(
    'XXX as mid-title adjective is left inline',
    normalizeFileBaseName('My.XXX.Secretary.02'),
    'My XXX Secretary # 02'
);
check(
    'leading XXX: kept, no dash inserted',
    normalizeFileBaseName('XXX.Adventures'),
    'XXX Adventures'
);
check(
    'trailing XXX: kept, no dash inserted',
    normalizeFileBaseName('Adventures.in.XXX'),
    'Adventures in XXX'
);
check(
    'junk-only XXX before quality marker still removed',
    normalizeFileBaseName('Some.Title.XXX.1080p.x264-GRP'),
    'Some Title'
);

echo "idempotency (normalized output is a fixed point):\n";
$fixedPoints = [
    'Naturals # 04 - Scene_1 - Alyx Star',
    'Naturals # 04 - Scene_1 - Alyx Star, Jane Doe',
    'Title 2020 - Scene_1 - Jane Doe',
    'Scene_1 - Alyx Star',
    'Some Title # 04',
    'Snow White XXX - An Axel Braun Parody',
    'Title - Scene_2 - With Kira Noir',
    'Movie - Scene_1 - And # 02',
    'The Crime Scene 1999',
    'Cum Oozing Holes # 01 - Scene_1',
    'The Busty & Bushy Cougar & Her Prey - Scene_1 - Chanel Preston',
    '18 & Creamed - Scene_1',
];
// Raw inputs whose FIRST normalization must already be a fixed point
// (otherwise the rename tool re-flags files it just renamed).
$rawInputs = [
    'Title.Scene.2.With.Kira.Noir',
    'Title.Scene.1.and.Scene.2',
    'Movie.Scene.1.and.2',
    'Title.Scene.2.of.8',
    'Naturals.4.Scene.1.Alyx.Star.and.Jane.Doe',
    'Snow.White.Scene.1-XXX.An.Axel.Braun.Parody',
    'Batman.Scene.2-XXX.A.Porn.Parody',
    'Movie.Title.1080.x264-KTR',
    'Movie.Scene.2.1080.WEBRip.x264-GRP',
    'Movie.720.WEBRip',
    'Movie.2.720.DVDRip',
    'Deep Anal Drilling Scene1 # 720',
    'Title.Scene.1.Jane.In.and.Out',
    'Title.Scene.1.Jane.and.and.Kira',
    'brazzers-scene-4',
    'evilangel-scene-12-adriana-chechik',
    'Backstage (Scene 2) Jane',
    'Title - XXX An Axel Braun Parody',
    'Cum Oozing Holes # 01 - Scene_1 Lh',
    'Cum.Oozing.Holes.01.Scene.1.Lh.1080p.x264-KTR',
    'Movie - Scene_1 lh 1080p',
    'Movie - Scene_1 Lhotse',
    'Girls Playing # 03 - Scene_1 - Hollie Morgan & Courtney Simpson',
    'Movie.Scene.1.Jane.Fauxheart.&.Kira.Mock',
];
foreach ($rawInputs as $raw) {
    $n1 = normalizeFileBaseName($raw);
    check("first pass is fixed point: $raw", normalizeFileBaseName($n1), $n1);
}
foreach ($fixedPoints as $name) {
    check("stable (default): $name", normalizeFileBaseName($name), $name);
    check("stable (respect): $name", normalizeFileBaseName($name, true), $name);
}
// Pre-existing respectUserCasing behavior capitalizes lowercase small words
// ("in" → "In"), so this one is only a fixed point in default mode.
check(
    'stable (default): Adventures in XXX',
    normalizeFileBaseName('Adventures in XXX'),
    'Adventures in XXX'
);

echo "disc / CD canonicalization:\n";
check(
    'Disc N becomes glued CD form',
    normalizeFileBaseName("Cherry's Anal Beauties Disc 1"),
    "Cherry's Anal Beauties - CD1"
);
check(
    'dotted glued disc',
    normalizeFileBaseName('Movie.Disc2'),
    'Movie - CD2'
);
check(
    'CD with leading zero',
    normalizeFileBaseName('Movie CD 01'),
    'Movie - CD1'
);
check(
    'self-heals the old mangled form',
    normalizeFileBaseName('Movie - CD # 01'),
    'Movie - CD1'
);
check(
    'fixed point: canonical CD form is stable',
    normalizeFileBaseName('Movie - CD1'),
    'Movie - CD1'
);
check(
    'two-digit disc number',
    normalizeFileBaseName('Movie Disc 12'),
    'Movie - CD12'
);
check(
    'words starting with disc are left alone',
    normalizeFileBaseName('Disco Nights'),
    'Disco Nights'
);
check(
    'discipline title is left alone',
    normalizeFileBaseName('Strict Discipline 4'),
    'Strict Discipline # 04'
);

echo "castDesquash (store-backed missing-space fix, injected vocab):\n";
$vocab = [
    'Jane Fauxheart',
    'Kira Mock',
    'Vanity',        // single-word store names never squash-match
    'Lena Dupe',
    'Le Nadupe',     // collides with Lena Dupe when squashed -> ambiguous
    'Anna Belle',
    'Annabelle',     // squash of Anna Belle IS a store name -> left alone
];
check(
    'squashed name gets its space back',
    castDesquash('Movie - Scene_1 - JaneFauxheart', $vocab),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'multiple names, comma separated',
    castDesquash('Movie - Scene_2 - JaneFauxheart, KiraMock', $vocab),
    'Movie - Scene_2 - Jane Fauxheart, Kira Mock'
);
check(
    'case-insensitive match uses store casing',
    castDesquash('Movie - Scene_1 - janefauxheart', $vocab),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'ambiguous squash is never rewritten',
    castDesquash('Movie - Scene_1 - LenaDupe', $vocab),
    'Movie - Scene_1 - LenaDupe'
);
check(
    'token that is already a store name is left alone',
    castDesquash('Movie - Scene_1 - Annabelle', $vocab),
    'Movie - Scene_1 - Annabelle'
);
check(
    'unknown token is left alone',
    castDesquash('Movie - Scene_1 - SomeRando', $vocab),
    'Movie - Scene_1 - SomeRando'
);
check(
    'title segment is never touched',
    castDesquash('JaneFauxheart - Scene_1 - Kira Mock', $vocab),
    'JaneFauxheart - Scene_1 - Kira Mock'
);
check(
    'no Scene_N tail, no rewrite',
    castDesquash('JaneFauxheart Compilation', $vocab),
    'JaneFauxheart Compilation'
);
check(
    'fixed point: already-spaced name is a no-op',
    castDesquash('Movie - Scene_1 - Jane Fauxheart', $vocab),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'short tokens are never rewritten',
    castDesquash('Movie - Scene_1 - KiraM', array_merge($vocab, ['Kira M'])),
    'Movie - Scene_1 - KiraM'
);

echo "castDesquash comma restoration:\n";
check(
    'two squashed names get spaces AND the comma',
    castDesquash('Movie - Scene_3 - JaneFauxheart KiraMock', $vocab),
    'Movie - Scene_3 - Jane Fauxheart, Kira Mock'
);
check(
    'already-spaced adjacent names get the comma',
    castDesquash('Movie - Scene_1 - Jane Fauxheart Kira Mock', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'single-word store name segments too',
    castDesquash('Movie - Scene_1 - Vanity Jane Fauxheart', $vocab),
    'Movie - Scene_1 - Vanity, Jane Fauxheart'
);
check(
    'single-word store name segments in the tail too',
    castDesquash('Movie - Scene_1 - Jane Fauxheart Vanity', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Vanity'
);
check(
    'two-word part is never split',
    castDesquash('Movie - Scene_1 - Kira Mock', array_merge($vocab, ['Kira', 'Mock'])),
    'Movie - Scene_1 - Kira Mock'
);
// Load-bearing for the Add Cast autocomplete: this pass only ever SPLITS a
// part, so a comma the client put in the wrong place stays wrong. That is why
// the modal will not offer a completion whose run the client-side tidier has to
// guess at (see updateCastSuggestions in file-normalization-modal.component.ts).
check(
    'a comma in the wrong place is never re-joined',
    castDesquash('Movie - Scene_1 - Vanity Jane, Fauxheart', $vocab),
    'Movie - Scene_1 - Vanity Jane, Fauxheart'
);
check(
    'part that IS a store name is never split',
    castDesquash(
        'Movie - Scene_1 - Anna Belle Fauxheart',
        array_merge($vocab, ['Anna Belle Fauxheart', 'Fauxheart'])
    ),
    'Movie - Scene_1 - Anna Belle Fauxheart'
);
check(
    'unconsumable words leave the part alone',
    castDesquash('Movie - Scene_1 - Jane Fauxheart Extended Cut', $vocab),
    'Movie - Scene_1 - Jane Fauxheart Extended Cut'
);
check(
    'comma restoration is a fixed point',
    castDesquash('Movie - Scene_3 - Jane Fauxheart, Kira Mock', $vocab),
    'Movie - Scene_3 - Jane Fauxheart, Kira Mock'
);

echo "glued scene-number + name:\n";
check(
    'name glued to scene number is detached',
    normalizeFileBaseName('The Feral Woman.Scene_2JaneFauxheart_1080p'),
    'The Feral Woman - Scene_2 - JaneFauxheart'
);
check(
    'glued name after bare sceneN',
    normalizeFileBaseName('Movie Scene2JaneFauxheart'),
    'Movie - Scene_2 - JaneFauxheart'
);
// cleanupFunctions has always stripped the quality tag itself; the guard
// being asserted is that "720" never becomes "Scene_720" (lowercase 'p'
// after the digits keeps the glue-detach from firing)
check(
    'quality tag digits never become a scene number',
    normalizeFileBaseName('Movie Scene 720p'),
    'Movie Scene'
);
check(
    'glued detach then desquash end to end',
    castDesquash(normalizeFileBaseName('Movie.Scene_2JaneFauxheart'), $vocab),
    'Movie - Scene_2 - Jane Fauxheart'
);
check(
    'ALL-LOWERCASE glued name resolves via the store',
    castDesquash('Movie - Scene_2janefauxheart', $vocab),
    'Movie - Scene_2 - Jane Fauxheart'
);
check(
    'glued single-word store name resolves',
    castDesquash('Movie - Scene_2vanity', $vocab),
    'Movie - Scene_2 - Vanity'
);
check(
    'glued blob with no unique store match is untouched',
    castDesquash('Movie - Scene_2somerando', $vocab),
    'Movie - Scene_2somerando'
);
check(
    'glued ambiguous squash is untouched',
    castDesquash('Movie - Scene_2lenadupe', $vocab),
    'Movie - Scene_2lenadupe'
);
check(
    '4K stays glued to the scene word (matches HEAD behavior)',
    normalizeFileBaseName('Movie.Scene_4K'),
    'Movie - Scene_4K'
);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
