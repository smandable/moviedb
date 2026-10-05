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
    'volume number before a subtitle segment becomes # NN',
    normalizeFileBaseName('Mountain.Crush.2 - Snowbunnies - Scene_1 - Ella_Hughes'),
    'Mountain Crush # 02 - Snowbunnies - Scene_1 - Ella Hughes'
);
check(
    'year before a subtitle segment stays plain',
    normalizeFileBaseName('Title.2020 - Subtitle - Scene_1 - Jane Doe'),
    'Title 2020 - Subtitle - Scene_1 - Jane Doe'
);
check(
    'segment-ending number without a Scene_ segment stays plain',
    normalizeFileBaseName('Just 18 - Pussycat Teens'),
    'Just 18 - Pussycat Teens'
);
check(
    'segment-ending number mid-name without a Scene_ segment stays plain',
    normalizeFileBaseName('Sinners - Club 18 - Teenie Toys'),
    'Sinners - Club 18 - Teenie Toys'
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

echo "lookup-site copy-paste echo (\"<Name> <Name> Bodyshot\"):\n";
check(
    'doubled pasted name with its photo alt-text collapses',
    normalizeFileBaseName('Movie - Scene_1 - Jane Fauxheart Jane Fauxheart Bodyshot'),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'echo already comma-split by the client tidier collapses too',
    normalizeFileBaseName('Movie - Scene_1 - Jane Fauxheart, Jane Fauxheart Bodyshot'),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'echo mid-list keeps the other names',
    normalizeFileBaseName('Movie - Scene_1 - Kira Mock, Jane Fauxheart Jane Fauxheart Bodyshot'),
    'Movie - Scene_1 - Kira Mock, Jane Fauxheart'
);
check(
    'lowercase dotted release form of the echo',
    normalizeFileBaseName('movie.scene.1.jane.fauxheart.jane.fauxheart.bodyshot'),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'a lone Bodyshot surname is kept (no doubled-name evidence)',
    normalizeFileBaseName('Movie - Scene_1 - Jane Bodyshot'),
    'Movie - Scene_1 - Jane Bodyshot'
);
check(
    'doubled words before the scene marker are a title, not an echo',
    normalizeFileBaseName('Bang Bang Bodyshot - Scene_1 - Jane Fauxheart'),
    'Bang Bang Bodyshot - Scene_1 - Jane Fauxheart'
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
    'Mountain Crush # 02 - Snowbunnies - Scene_1 - Ella Hughes',
    'Adventures in XXX',
    'Private Tropical # 37 - Anal Honeymoon in the Tropics',
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
    'Movie.Scene.1.Jane.Fauxheart.Busty.Teen.Tries.Anal',
];
foreach ($rawInputs as $raw) {
    $n1 = normalizeFileBaseName($raw);
    check("first pass is fixed point: $raw", normalizeFileBaseName($n1), $n1);
}
foreach ($fixedPoints as $name) {
    check("stable (default): $name", normalizeFileBaseName($name), $name);
    check("stable (respect): $name", normalizeFileBaseName($name, true), $name);
}

echo "user-edited names (respectUserCasing) keep small words lowercase:\n";
// Typing a cast flips the preview into respect mode, which used to skip the
// small-word rule: an untouched lowercase "and" in the title came back as
// "And", and the next scan (default mode) flagged the renamed file again.
check(
    'lowercase "and" in the title survives a typed cast',
    normalizeFileBaseName('Quietly and Slowly # 02 - Scene_1 - Mira Quell', true),
    'Quietly and Slowly # 02 - Scene_1 - Mira Quell'
);
check(
    'all-lowercase typing: small words stay lowercase, the rest is title-cased',
    normalizeFileBaseName('a night in the city - scene 1 - mira quell', true),
    'A Night in the City - Scene_1 - Mira Quell'
);
check(
    'a deliberately capitalized small word is still kept',
    normalizeFileBaseName('Back To The Start - Scene_1', true),
    'Back To The Start - Scene_1'
);
// What a cast rename writes must be what the next scan expects, or the
// rename tool re-flags the file it just renamed.
foreach ([
    'Quietly and Slowly # 02 - Scene_1 - Mira Quell',
    'a night in the city - scene 1 - mira quell',
] as $typed) {
    $renamed = normalizeFileBaseName($typed, true);
    check("next scan leaves it alone: $typed", normalizeFileBaseName($renamed), $renamed);
}

echo "the last word of a segment is capitalized, small or not:\n";
check(
    'before a volume marker',
    normalizeFileBaseName('Kept Me up # 01'),
    'Kept Me Up # 01'
);
check(
    'before " - Scene_N"',
    normalizeFileBaseName('Wrap Me up - Scene_1 - Mira Quell'),
    'Wrap Me Up - Scene_1 - Mira Quell'
);
check(
    'a trailing initial in the cast',
    normalizeFileBaseName('Movie.Scene.1.Mira.A'),
    'Movie - Scene_1 - Mira A'
);
check(
    'before a "(...)" tag',
    normalizeFileBaseName('Turn Me on (2020)'),
    'Turn Me On (2020)'
);
check(
    'last only once the release junk is gone',
    normalizeFileBaseName('Wind.Me.Up.1080p.x264-GRP'),
    'Wind Me Up'
);
check(
    'interior small words stay lowercase',
    normalizeFileBaseName('Out Of The Blue # 02'),
    'Out of the Blue # 02'
);
check(
    'respect mode: lowercase typing gets the same ends',
    normalizeFileBaseName('fire me up - scene 2 - mira a', true),
    'Fire Me Up - Scene_2 - Mira A'
);
foreach ([
    'Kept Me Up # 01',
    'Wrap Me Up - Scene_1 - Mira Quell',
    'Movie - Scene_1 - Mira A',
    'Turn Me On (2020)',
    'Out of the Blue # 02',
] as $name) {
    check("stable (default): $name", normalizeFileBaseName($name), $name);
    check("stable (respect): $name", normalizeFileBaseName($name, true), $name);
}

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

echo "seriesVolumeNumber (store-backed volume marker, injected titles):\n";
$seriesTitles = [
    'Fake Series # 01 - Alpha',
    'Fake Series # 02',
    'Lone # 01',
];
check(
    'known series gets volume marker + subtitle break',
    seriesVolumeNumber('Fake Series 5 Beta Gamma', $seriesTitles),
    'Fake Series # 05 - Beta Gamma'
);
check(
    'an already-typed dash after the number is not doubled',
    seriesVolumeNumber('Fake Series 5 - Beta Gamma', $seriesTitles),
    'Fake Series # 05 - Beta Gamma'
);
check(
    'marked-up output is a fixed point of the stage',
    seriesVolumeNumber('Fake Series # 05 - Beta Gamma', $seriesTitles),
    'Fake Series # 05 - Beta Gamma'
);
check(
    'a single-volume prefix does not qualify (corroboration gate)',
    seriesVolumeNumber('Lone 5 Beta', $seriesTitles),
    'Lone 5 Beta'
);
check(
    'unknown prefix is untouched',
    seriesVolumeNumber('Other 5 Beta', $seriesTitles),
    'Other 5 Beta'
);
check(
    'trailing number is left for the trailing-number rule',
    seriesVolumeNumber('Fake Series 5', $seriesTitles),
    'Fake Series 5'
);
check(
    '4-digit numbers never match',
    seriesVolumeNumber('Fake Series 1974 Beta', $seriesTitles),
    'Fake Series 1974 Beta'
);
check(
    'a glued hyphen is part of the word, not a separator',
    seriesVolumeNumber('Fake Series 3-Somes # 06', array_merge($seriesTitles, ['Fake Series 3-Somes # 03'])),
    'Fake Series 3-Somes # 06'
);
check(
    'a number right before an existing # NN is part of the series name',
    seriesVolumeNumber('Fake 18 # 06', ['Fake # 01', 'Fake # 02', 'Fake 18 # 01', 'Fake 18 # 02']),
    'Fake 18 # 06'
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

echo "dropCastJunkTail (store-guided junk truncation, injected vocab):\n";
check(
    'junk after a recognized name is dropped',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart Busty Teen Tries Anal', $vocab),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'store casing is adopted for the kept name',
    dropCastJunkTail('Movie - Scene_1 - jane fauxheart anal study break', $vocab),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'comma-joined pair keeps both, junk after the second dropped',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart, Kira Mock Hot Action', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'glued pair with trailing junk gets the comma and loses the junk',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart Kira Mock Junk Words', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'junk split across a comma (castSeparator ate a junk "and") is all dropped',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart Gf Bends Over Car, Does Anal', $vocab),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'a name buried in the junk is rescued',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart With Kira Mock', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'a name in a later comma part survives the junk before it',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart Junk, Kira Mock', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'an unknown "and"-joined name before any junk is kept whole',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart, Newbie Person', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Newbie Person'
);
check(
    'no leading store name, no truncation',
    dropCastJunkTail('Movie - Scene_1 - Busty Teen Tries Anal', $vocab),
    'Movie - Scene_1 - Busty Teen Tries Anal'
);
check(
    'a mononym is no anchor',
    dropCastJunkTail('Movie - Scene_1 - Vanity Does Anal', $vocab),
    'Movie - Scene_1 - Vanity Does Anal'
);
check(
    'a mononym mid-junk is not rescued',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart Hot Vanity Action', $vocab),
    'Movie - Scene_1 - Jane Fauxheart'
);
check(
    'clean tail is untouched',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart, Kira Mock', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Kira Mock'
);
check(
    'clean tail with a mononym is untouched',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart, Vanity', $vocab),
    'Movie - Scene_1 - Jane Fauxheart, Vanity'
);
check(
    'no scene marker, no truncation',
    dropCastJunkTail('Jane Fauxheart Busty Teen', $vocab),
    'Jane Fauxheart Busty Teen'
);
check(
    'title segment is never touched',
    dropCastJunkTail('Jane Fauxheart Busty - Scene_1 - Kira Mock', $vocab),
    'Jane Fauxheart Busty - Scene_1 - Kira Mock'
);
check(
    'dotless spelling of a dotted store name anchors and is restored',
    dropCastJunkTail(
        'Movie - Scene_1 - Tessa St Marrow Anal Celebration',
        array_merge($vocab, ['Tessa St. Marrow'])
    ),
    'Movie - Scene_1 - Tessa St. Marrow'
);
check(
    'truncated output is a fixed point of the stage',
    dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart, Kira Mock', $vocab),
    dropCastJunkTail(dropCastJunkTail('Movie - Scene_1 - Jane Fauxheart Kira Mock Junk Words', $vocab), $vocab)
);

echo "dotted-cast-tail gate (release names qualify, typed input never):\n";
check(
    'dotted release tail qualifies',
    moviedb_has_dotted_cast_tail('Movie.Scene.1.Jane.Fauxheart.Busty.Teen'),
    true
);
check(
    'partially dotted tail qualifies',
    moviedb_has_dotted_cast_tail('Movie - Scene_1 - Jane.Fauxheart.junk'),
    true
);
check(
    'spaced tail does not qualify',
    moviedb_has_dotted_cast_tail('Movie - Scene_1 - Jane Fauxheart Busty Teen'),
    false
);
check(
    'an abbreviation period (dot before a space) does not qualify',
    moviedb_has_dotted_cast_tail('Movie - Scene_1 - Tessa St. Marrow'),
    false
);
check(
    'a trailing final-initial period does not qualify',
    moviedb_has_dotted_cast_tail('Movie - Scene_1 - Wren J.'),
    false
);
check(
    'a year after "Scene" does not anchor the gate',
    moviedb_has_dotted_cast_tail('The.Crime.Scene.1999'),
    false
);
check(
    'no scene marker, no gate',
    moviedb_has_dotted_cast_tail('Some.Dotted.Title'),
    false
);
check(
    'bare scene number with nothing after does not qualify',
    moviedb_has_dotted_cast_tail('Movie.Scene.1'),
    false
);
// End-to-end: names absent from the real store are never anchors, so a
// dotted junk tail passes through unchanged (and stays a fixed point).
check(
    'pipeline leaves an unrecognized dotted tail alone',
    normalizeFileBaseName('Movie.Scene.1.Jane.Fauxheart.Busty.Teen.Tries.Anal'),
    'Movie - Scene_1 - Jane Fauxheart Busty Teen Tries Anal'
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

echo "glued '#' volume marker:\n";
check(
    'a volume number glued to the title gets its space back',
    normalizeFileBaseName('Faux.Meadow#28.Scene_1.1080'),
    'Faux Meadow # 28 - Scene_1'
);
check(
    'a previously half-fixed "Title# NN" self-heals',
    normalizeFileBaseName('Faux Meadow# 28 - Scene_1'),
    'Faux Meadow # 28 - Scene_1'
);
check(
    'the canonical form is a fixed point',
    normalizeFileBaseName('Faux Meadow # 28 - Scene_1'),
    'Faux Meadow # 28 - Scene_1'
);
check(
    'a "#" with no digits after it is not a volume marker',
    normalizeFileBaseName('Music in C# Minor'),
    'Music in C# Minor'
);

echo "deliberate cast periods (keepCastDots + store dot-restore):\n";
check(
    'default pipeline sweeps a cast period to a space',
    normalizeFileBaseName('Movie - Scene_1 - Tessa St. Marrow'),
    'Movie - Scene_1 - Tessa St Marrow'
);
check(
    'a pasted period is swept even in user-edited (casing-respect) mode',
    normalizeFileBaseName('Movie - Scene_1 - Tessa St. Marrow', true),
    'Movie - Scene_1 - Tessa St Marrow'
);
check(
    'keepCastDots preserves the cast tail\'s periods',
    normalizeFileBaseName('Movie - Scene_1 - Tessa St. Marrow', true, true),
    'Movie - Scene_1 - Tessa St. Marrow'
);
check(
    'keepCastDots output is a fixed point under the flag',
    normalizeFileBaseName(
        normalizeFileBaseName('Movie - Scene_1 - Tessa St. Marrow', true, true),
        true,
        true
    ),
    'Movie - Scene_1 - Tessa St. Marrow'
);
check(
    'keepCastDots still normalizes a dotted title before the scene marker',
    normalizeFileBaseName('cool.movie.scene 1 - Tessa St. Marrow', true, true),
    'Cool Movie - Scene_1 - Tessa St. Marrow'
);
check(
    'keepCastDots without a scene marker changes nothing',
    normalizeFileBaseName('Some.Dotted.Title', true, true),
    'Some Dotted Title'
);
check(
    'keepCastDots preserves a trailing period (final initial)',
    normalizeFileBaseName('Movie - Scene_1 - Wren J.', true, true),
    'Movie - Scene_1 - Wren J.'
);
check(
    'trailing-period output is a fixed point under the flag',
    normalizeFileBaseName(
        normalizeFileBaseName('Movie - Scene_1 - Wren J.', true, true),
        true,
        true
    ),
    'Movie - Scene_1 - Wren J.'
);
check(
    'dot-restore adopts the dotted store spelling',
    castDesquash(
        'Movie - Scene_1 - Tessa St Marrow, Kira Mock',
        array_merge($vocab, ['Tessa St. Marrow'])
    ),
    'Movie - Scene_1 - Tessa St. Marrow, Kira Mock'
);
check(
    'dot-restore stands down when the dotless twin is itself stored',
    castDesquash(
        'Movie - Scene_1 - Tessa St Marrow',
        array_merge($vocab, ['Tessa St. Marrow', 'Tessa St Marrow'])
    ),
    'Movie - Scene_1 - Tessa St Marrow'
);
check(
    'a dotted-release store leftover is never a restore target',
    castDesquash(
        'Movie - Scene_1 - Anvi Amelia',
        array_merge($vocab, ['anvi.amelia'])
    ),
    'Movie - Scene_1 - Anvi Amelia'
);

echo "numbers that are part of the title, not a volume:\n";
check(
    'an age after "Barely" stays',
    normalizeFileBaseName("Velvet Lantern - They're Barely 18 - Scene_1 - Mira Quell"),
    "Velvet Lantern - They're Barely 18 - Scene_1 - Mira Quell"
);
check(
    'an age after "Over" stays',
    normalizeFileBaseName('Moms Over 40 - Scene_1 - Mira Quell'),
    'Moms Over 40 - Scene_1 - Mira Quell'
);
check(
    'a small number after "Over" is still a volume',
    normalizeFileBaseName('Bent Over 2 - Scene_1 - Mira Quell'),
    'Bent Over # 02 - Scene_1 - Mira Quell'
);
check(
    'a number after "Than" stays',
    normalizeFileBaseName('Three Heads Are Better Than 2'),
    'Three Heads Are Better Than 2'
);
check(
    'a listed numbered title keeps its number (injected list)',
    unprotectTitleNumbers(cleanupFunctions(protectTitleNumbers('Moon Code 42 - Scene_1 - Mira Quell', ['Moon Code 42']))),
    'Moon Code 42 - Scene_1 - Mira Quell'
);
check(
    'an unlisted title still gets its volume marker',
    unprotectTitleNumbers(cleanupFunctions(protectTitleNumbers('Moon Code 42 - Scene_1 - Mira Quell', []))),
    'Moon Code # 42 - Scene_1 - Mira Quell'
);

echo "phrases the small-word rule must not lowercase:\n";
check('a compound noun', normalizeFileBaseName('Strap on Stargazers - Scene_1'), 'Strap On Stargazers - Scene_1');
check('a compound adjective', normalizeFileBaseName('Oiled up Vixens # 02'), 'Oiled Up Vixens # 02');
check('a letter grade', normalizeFileBaseName('Straight a Sorority'), 'Straight A Sorority');
check('a cup size', normalizeFileBaseName('The a Cup Club'), 'The A Cup Club');
check('an undashed parody subtitle', normalizeFileBaseName('Moonbase a XXX Parody'), 'Moonbase A XXX Parody');
check('a plain article before "Parody" stays small', normalizeFileBaseName('This Is a Parody'), 'This is a Parody');
check('a two-word parody subtitle', normalizeFileBaseName('Moonbase An XXX Porn Parody'), 'Moonbase An XXX Porn Parody');
check('a grade', normalizeFileBaseName('Grade A Starlets'), 'Grade A Starlets');
check('a spelled-out initial', normalizeFileBaseName('L A Moonlight'), 'L A Moonlight');
check('spelled-out letters', normalizeFileBaseName('A N A L Lantern # 01'), 'A N A L Lantern # 01');
check('the article beside "I" stays small', normalizeFileBaseName('Am I A Lantern'), 'Am I a Lantern');
check('a lowercase article beside a letter stays small', normalizeFileBaseName('I Wanna B a Lantern'), 'I Wanna B a Lantern');
check('"up" as a preposition stays small', normalizeFileBaseName('Straight Up the Ladder'), 'Straight up the Ladder');
check(
    'respect mode keeps the compound too',
    normalizeFileBaseName('strap on stargazers - scene 1 - mira quell', true),
    'Strap On Stargazers - Scene_1 - Mira Quell'
);

echo "hand-settled title spellings (injected lists):\n";
$overrides = moviedb_build_title_overrides(
    ['Moon.Base.', 'Night Shift The Return'],
    ['Moon and Stars' => 'Moon & Stars', 'Four Lanterns' => '4 Lanterns']
);
check(
    'a variant spelling becomes the chosen one, suffix kept',
    applyTitleOverride('Moon and Stars # 02 - Scene_1 - Mira Quell', $overrides),
    'Moon & Stars # 02 - Scene_1 - Mira Quell'
);
check('the chosen spelling is a fixed point', applyTitleOverride('Moon & Stars', $overrides), 'Moon & Stars');
check('a word spelling becomes digits', applyTitleOverride('Four Lanterns # 01', $overrides), '4 Lanterns # 01');
check(
    'an exact title survives its periods being swept',
    applyTitleOverride('Moon Base # 03 - Scene_2 - Mira Quell', $overrides),
    'Moon.Base. # 03 - Scene_2 - Mira Quell'
);
check(
    'an exact title keeps its casing',
    applyTitleOverride('Night Shift the Return - Scene_1', $overrides),
    'Night Shift The Return - Scene_1'
);
check('an unlisted title is untouched', applyTitleOverride('Moon Stars # 02', $overrides), 'Moon Stars # 02');

echo "directory scan for the rename modal (temp-dir fixture):\n";
$scanDir = sys_get_temp_dir() . '/moviedb_scan_' . uniqid();
mkdir($scanDir);
mkdir("$scanDir/duplicates");
mkdir("$scanDir/needs-cast");
touch("$scanDir/movie.scene.1.mira.quell.mp4");
touch("$scanDir/Quietly and Slowly - Scene_1 - Mira Quell.mp4");
touch("$scanDir/.DS_Store");
$scanRows = moviedb_scan_names_to_normalize($scanDir);
$byName = array_column($scanRows ?? [], null, 'originalFileName');
check(
    'lists regular files only — no subfolders, no dot-files',
    array_keys($byName) == ['Quietly and Slowly - Scene_1 - Mira Quell.mp4', 'movie.scene.1.mira.quell.mp4']
        || array_keys($byName) == ['movie.scene.1.mira.quell.mp4', 'Quietly and Slowly - Scene_1 - Mira Quell.mp4'],
    true
);
check(
    'a file needing a rename carries the normalized name',
    [$byName['movie.scene.1.mira.quell.mp4']['needsNormalization'] ?? null, $byName['movie.scene.1.mira.quell.mp4']['newFileName'] ?? null],
    [true, 'Movie - Scene_1 - Mira Quell.mp4']
);
check(
    'an already-normalized file is listed with no rename',
    [$byName['Quietly and Slowly - Scene_1 - Mira Quell.mp4']['needsNormalization'] ?? null, $byName['Quietly and Slowly - Scene_1 - Mira Quell.mp4']['newFileName'] ?? null],
    [false, '']
);
check('an unlistable directory returns null', moviedb_scan_names_to_normalize("$scanDir/missing"), null);
touch("$scanDir/convert_helper.php");
check(
    'a type filter keeps videos and drops scripts',
    array_column(moviedb_scan_names_to_normalize($scanDir, ['mp4']) ?? [], 'originalFileName'),
    ['Quietly and Slowly - Scene_1 - Mira Quell.mp4', 'movie.scene.1.mira.quell.mp4']
);
check(
    'without the filter the script is listed too',
    in_array('convert_helper.php', array_column(moviedb_scan_names_to_normalize($scanDir) ?? [], 'originalFileName'), true),
    true
);
unlink("$scanDir/convert_helper.php");

// Recursive (the Settings page): subfolders walked, files first, each row's
// path its own folder; duplicates/, needs-cast/, dot-folders and symlinks
// never entered; the depth cap holds.
mkdir("$scanDir/Keep/Older", 0777, true);
mkdir("$scanDir/.Trashes");
touch("$scanDir/Keep/velvet.gold.2.mp4");
touch("$scanDir/Keep/Older/Velvet Gold # 03.mp4");
touch("$scanDir/duplicates/velvet.gold.4.mp4");
touch("$scanDir/needs-cast/velvet.gold.5.scene.1.mp4");
touch("$scanDir/.Trashes/velvet.gold.6.mp4");
symlink("$scanDir/Keep", "$scanDir/KeepLink");
$deep = "$scanDir/d1/d2/d3/d4/d5";
mkdir($deep, 0777, true);
touch("$scanDir/d1/d2/d3/d4/at.depth.four.mp4");
touch("$deep/past.the.cap.mp4");
$recRows = moviedb_scan_names_to_normalize($scanDir, ['mp4'], true) ?? [];
$recNames = array_map(fn($r) => substr($r['path'], strlen($scanDir)) . '/' . $r['originalFileName'], $recRows);
check(
    'recursive scan walks subfolders, files first, and skips the reserved ones',
    $recNames,
    [
        '/Quietly and Slowly - Scene_1 - Mira Quell.mp4',
        '/movie.scene.1.mira.quell.mp4',
        '/Keep/velvet.gold.2.mp4',
        '/Keep/Older/Velvet Gold # 03.mp4',
        '/d1/d2/d3/d4/at.depth.four.mp4',
    ]
);
check(
    'a subfolder row carries its own folder and normalized name',
    [$recRows[2]['path'] ?? null, $recRows[2]['newFileName'] ?? null],
    ["$scanDir/Keep", 'Velvet Gold # 02.mp4']
);
check(
    'without recursive the subfolders stay unlisted',
    count(moviedb_scan_names_to_normalize($scanDir, ['mp4']) ?? []),
    2
);
unlink("$scanDir/KeepLink");
unlink("$deep/past.the.cap.mp4");
unlink("$scanDir/d1/d2/d3/d4/at.depth.four.mp4");
foreach (['d1/d2/d3/d4/d5', 'd1/d2/d3/d4', 'd1/d2/d3', 'd1/d2', 'd1'] as $d) {
    rmdir("$scanDir/$d");
}
unlink("$scanDir/Keep/Older/Velvet Gold # 03.mp4");
unlink("$scanDir/Keep/velvet.gold.2.mp4");
rmdir("$scanDir/Keep/Older");
rmdir("$scanDir/Keep");
unlink("$scanDir/duplicates/velvet.gold.4.mp4");
unlink("$scanDir/needs-cast/velvet.gold.5.scene.1.mp4");
unlink("$scanDir/.Trashes/velvet.gold.6.mp4");
rmdir("$scanDir/.Trashes");
array_map('unlink', glob("$scanDir/*.mp4"));
unlink("$scanDir/.DS_Store");
rmdir("$scanDir/duplicates");
rmdir("$scanDir/needs-cast");
rmdir($scanDir);

echo "abbreviation periods and ordinals:\n";
check('honorific period kept', normalizeFileBaseName('Mr. Lonelyheart # 13 - Scene_1'), 'Mr. Lonelyheart # 13 - Scene_1');
check('Saint after a dash keeps its period', normalizeFileBaseName('Velvet Gold # 174 - St. Agatha\'s Night Out'), 'Velvet Gold # 174 - St. Agatha\'s Night Out');
check('Mrs. kept', normalizeFileBaseName('The Return of Mrs. Quill'), 'The Return of Mrs. Quill');
check('honorific glued in a release name', normalizeFileBaseName('mr.lonelyheart.13.scene.2.jane.doe'), 'Mr. Lonelyheart # 13 - Scene_2 - Jane Doe');
check('honorific glued to the next word', normalizeFileBaseName('Mr.Quill Goes to Town'), 'Mr. Quill Goes to Town');
check('missing honorific period added', normalizeFileBaseName('Dr Quill\'s Clinic'), 'Dr. Quill\'s Clinic');
check('all-caps MS is not an honorific', normalizeFileBaseName('MS Paint Party'), 'MS Paint Party');
check('cast tail left to the store', normalizeFileBaseName('Velvet Gold - Scene_1 - Ms Quill'), 'Velvet Gold - Scene_1 - Ms Quill');
check('dotted initials kept', normalizeFileBaseName('U.S. Road Trip # 03'), 'U.S. Road Trip # 03');
check('trailing initials keep their last period', normalizeFileBaseName('Lonely in L.A.'), 'Lonely in L.A.');
check('initials without a final period', normalizeFileBaseName('The J.O.B'), 'The J.O.B');
check('lowercase initials uppercased', normalizeFileBaseName('lonely in l.a.'), 'Lonely in L.A.');
check('initials in a fully dotted name still sweep', normalizeFileBaseName('Lonely.In.L.A.2'), 'Lonely in L A # 02');
check('web address kept, ending lowercased', normalizeFileBaseName('Quillfilms.Com'), 'Quillfilms.com');
check('dotted web address series', normalizeFileBaseName('quillfilms.com.18'), 'Quillfilms.com # 18');
check('web address in a tag', normalizeFileBaseName('Velvet Gold # 01 (Quill.com)'), 'Velvet Gold # 01 (Quill.com)');
check('a word starting with com is not a domain', normalizeFileBaseName('Velvet.Compilation'), 'Velvet Compilation');
check('ordinal suffix lowercased', normalizeFileBaseName('1St Time Velvet # 04'), '1st Time Velvet # 04');
check('all-caps ordinal', normalizeFileBaseName('Her 3RD Velvet 03'), 'Her 3rd Velvet # 03');
check('ellipsis kept mid-title', normalizeFileBaseName('Hmm... Quiet Please!'), 'Hmm... Quiet Please!');
check('ellipsis before a volume is not a break', normalizeFileBaseName('Wait for It... # 02'), 'Wait for It... # 02');
check('title-only de lowercased', normalizeFileBaseName('Le Retour De Quill'), 'Le Retour de Quill');
check('du and des too', normalizeFileBaseName('Chateau Des Quills Du Nord'), 'Chateau des Quills du Nord');
check('de first word stays capitalized', normalizeFileBaseName('De Quill Returns'), 'De Quill Returns');
check('cast name keeps its De', normalizeFileBaseName('Velvet Gold # 02 - Scene_1 - Jane De Quill'), 'Velvet Gold # 02 - Scene_1 - Jane De Quill');
check('LA is not a French article', normalizeFileBaseName('Lonely in LA # 01'), 'Lonely in LA # 01');
check('title-only de lowercased in respect mode too', normalizeFileBaseName('Le Retour De Quill - Scene_1 - Jane Doe', true), 'Le Retour de Quill - Scene_1 - Jane Doe');
check('a listed year is not a volume', normalizeFileBaseName('Debbie Class of 88'), 'Debbie Class of 88');
check('a listed year survives a dotted release name', normalizeFileBaseName('debbie.does.dallas.99'), 'Debbie Does Dallas 99');
check('a settled full title pins one volume\'s subtitle',
    applyTitleOverride('Velvet # 23 - Bis Zur Quelle - Scene_1', ['velvet # 23 - bis zur quelle' => 'Velvet # 23 - Bis zur Quelle']),
    'Velvet # 23 - Bis zur Quelle - Scene_1');
check('... leaving the other volumes to the rules',
    applyTitleOverride('Velvet # 24 - Am Ende', ['velvet # 23 - bis zur quelle' => 'Velvet # 23 - Bis zur Quelle']),
    'Velvet # 24 - Am Ende');
check('a base-title override still applies under a subtitle',
    applyTitleOverride('Sexo En Velvet # 07 - Scene_1', ['sexo en velvet' => 'Sexo en Velvet']),
    'Sexo en Velvet # 07 - Scene_1');
check('abbreviation periods survive respect mode', normalizeFileBaseName('Mr. Lonelyheart # 13 - Scene_1 - Jane Doe', true), 'Mr. Lonelyheart # 13 - Scene_1 - Jane Doe');
check('invalid UTF-8 passes protectAbbreviationDots untouched', protectAbbreviationDots("Amat\xF6r Mr. X"), "Amat\xF6r Mr. X");

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
