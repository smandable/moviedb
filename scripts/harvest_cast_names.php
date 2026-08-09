<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}


/**
 * Drive-wide cast-name harvester. Walks the library volumes, extracts female
 * performer names from three shapes of evidence, and merges them (deduped)
 * into server/cast_names.json — the vocabulary behind the Add Cast tab's
 * autocomplete and any future matching.
 *
 *   A. Scene tails      "Title - Scene_2 - Angel Long, Paige Owens.mp4"
 *                        (also the space form: "... - Scene_1 Nikki Hearts.mp4")
 *   B. Performer folders /Volumes/SP/<Name>/, and name-shaped folders under
 *                        /Volumes/Etc/Extra (a trailing " Movies" is dropped:
 *                        "Elektra Rose Movies" -> "Elektra Rose")
 *   C. Dotted releases   "Atke.15.06.28.Ashley.Luvbug.And.Blair.Summers.Lesbian.mp4"
 *                        Precision-first: only TitleCase word pairs adjoining
 *                        ".And.", junk-word stoplist, and a candidate must
 *                        either recur (>=2 files) or already be known from A/B.
 *
 * Usage:
 *   php scripts/harvest_cast_names.php            # harvest + merge into store
 *   php scripts/harvest_cast_names.php --dry-run  # report only, write nothing
 *
 * Re-run any time; the merge is idempotent. Precision beats recall throughout:
 * a junk name pollutes autocomplete forever, a missed name costs one paste.
 */

require_once __DIR__ . '/../server/cast_helpers.php';

const ROOTS_SCENES = [
    '/Volumes/Recorded 1/recorded',
    '/Volumes/Recorded 2/recorded',
    '/Volumes/Recorded 3/recorded',
    '/Volumes/Recorded 4/recorded',
    '/Volumes/Etc/Extra',
    '/Volumes/Misc',
    '/Volumes/SP',
    '/Volumes/Download/fixed',
];
const ROOTS_PERFORMER_FOLDERS = ['/Volumes/SP', '/Volumes/Etc/Extra'];
const ROOTS_DOTTED = ['/Volumes/Misc', '/Volumes/SP'];

// Irrelevant directory names (Sean, 2026-08-08): skipped entirely wherever met.
const EXCLUDED_DIRS = ['Banned Cartoons', 'TV-Movies-Misc', 'Tom and Jerry'];

// Words that end the cast portion of a dotted release name, or that are junk
// on their own. Lowercase. Applied to Source C only — never to A or B.
const DOTTED_STOPWORDS = [
    'and', 'the', 'a', 'an', 'in', 'on', 'of', 'with', 'for', 'to', 'at', 'my',
    'all', 'about', 'it', 'she', 'her', 'his', 'him', 'our', 'your', 'its',
    'this', 'that', 'first', 'last', 'lets', 'gets', 'wants', 'needs', 'loves',
    'anal', 'lesbian', 'solo', 'toys', 'masturbation', 'hardcore', 'bts',
    'action', 'workout', 'threesome', 'creampie', 'facial', 'fetish', 'footjob',
    'blowjob', 'pov', 'gonzo', 'casting', 'audition', 'massage', 'squirt',
    'bonus', 'scene', 'part', 'vol', 'xxx', 'parody', 'remastered', 'edition',
    'internal', 'external', 'french', 'german', 'russian', 'czech', 'euro',
    'american', 'pornstar', 'anime', 'body', 'school', 'teen', 'teens', 'girl',
    'girls', 'big', 'tits', 'ass', 'wet', 'hot', 'sexy', 'young', 'busty',
    'mp4', 'avi', 'mkv', 'wmv', 'x264', 'x265', 'xvid', '1080p', '720p', '2160p', '4k',
];

$dryRun = in_array('--dry-run', $argv ?? [], true);

/** Recursively list files under $dir, skipping EXCLUDED_DIRS and dotfiles. */
function walk_files(string $dir, callable $onFile): void
{
    $entries = @scandir($dir);
    if ($entries === false) {
        fwrite(STDERR, "  !! cannot read {$dir}\n");
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            if (!in_array($entry, EXCLUDED_DIRS, true)) {
                walk_files($path, $onFile);
            }
        } else {
            $onFile($entry);
        }
    }
}

/** Name-shaped: 2-3 words, each starting with an uppercase letter. */
function is_name_shaped(string $candidate): bool
{
    return (bool) preg_match(
        '/^\p{Lu}[\p{L}\'.\-]*(?:\s+\p{Lu}[\p{L}\'.\-]*){1,2}$/u',
        $candidate,
    );
}

$names = [];        // name (as found) keyed by lowercase
$perSource = ['scene_tails' => 0, 'performer_folders' => 0, 'dotted' => 0];
$addName = function (string $name, string $source) use (&$names, &$perSource): void {
    $clean = moviedb_clean_cast_name($name);
    if ($clean === '') {
        return;
    }
    $key = mb_strtolower($clean);
    if (!isset($names[$key])) {
        $names[$key] = $clean;
        $perSource[$source]++;
    }
};

// ---------------------------------------------------------------- Source A
echo "Source A: Scene_N tails\n";
foreach (ROOTS_SCENES as $root) {
    if (!is_dir($root)) {
        echo "  -- skipped (not mounted): {$root}\n";
        continue;
    }
    $before = count($names);
    $count = 0;
    walk_files($root, function (string $file) use (&$count, $addName): void {
        $count++;
        $base = pathinfo($file, PATHINFO_FILENAME);
        // Dash form (the convention), then the rarer space form.
        if (preg_match(MOVIEDB_SCENE_CAST_RE, $base, $m)
            || preg_match('/Scene_\d+\s+(\p{Lu}.+)$/u', $base, $m)) {
            foreach (moviedb_split_cast_tail($m[1]) as $name) {
                if (is_name_shaped($name)) {
                    $addName($name, 'scene_tails');
                }
            }
        }
    });
    printf("  %-38s %6d files, +%d new names\n", $root, $count, count($names) - $before);
}

// ---------------------------------------------------------------- Source B
echo "Source B: performer folders\n";
foreach (ROOTS_PERFORMER_FOLDERS as $root) {
    if (!is_dir($root)) {
        echo "  -- skipped (not mounted): {$root}\n";
        continue;
    }
    $before = count($names);
    foreach (@scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        if (!is_dir($root . '/' . $entry) || in_array($entry, EXCLUDED_DIRS, true)) {
            continue;
        }
        // "Elektra Rose Movies" names the performer, not the movie
        $candidate = preg_replace('/\s+Movies$/i', '', $entry);
        if (is_name_shaped($candidate)) {
            $addName($candidate, 'performer_folders');
        }
    }
    printf("  %-38s +%d new names\n", $root, count($names) - $before);
}

// ---------------------------------------------------------------- Source C
echo "Source C: dotted release names (conservative)\n";
$dottedSeen = [];   // lowercase candidate -> ['name' => TitleCase, 'files' => n]
foreach (ROOTS_DOTTED as $root) {
    if (!is_dir($root)) {
        continue;
    }
    walk_files($root, function (string $file) use (&$dottedSeen): void {
        $base = pathinfo($file, PATHINFO_FILENAME);
        if (substr_count($base, '.') < 3) {
            return; // not a dotted release name
        }
        // The ".And." join is the signal that BOTH sides are performers
        // ("Ashley.Luvbug.And.Blair.Summers.Lesbian"). Take up to 3 TitleCase
        // words on each side of every ".And.", then trim leading/trailing
        // stopwords so title words don't ride along ("Big.Tits.Alex.Mae" ->
        // "Alex Mae", "Beauty.And.The.Beast" -> nothing).
        if (!preg_match_all(
            '/((?:\p{Lu}[\p{Ll}\']+\.){0,2}\p{Lu}[\p{Ll}\']+)\.And\.((?:\p{Lu}[\p{Ll}\']+\.){0,2}\p{Lu}[\p{Ll}\']+)/u',
            $base,
            $m,
            PREG_SET_ORDER,
        )) {
            return;
        }
        $isStop = fn(string $w): bool => in_array(mb_strtolower($w), DOTTED_STOPWORDS, true);
        foreach ($m as $match) {
            foreach ([explode('.', $match[1]), explode('.', $match[2])] as $words) {
                // Trim stopwords from the outside in; discard if any remain inside.
                while ($words && $isStop($words[0])) {
                    array_shift($words);
                }
                while ($words && $isStop(end($words))) {
                    array_pop($words);
                }
                if (count($words) < 2 || count($words) > 3
                    || array_filter($words, $isStop)) {
                    continue;
                }
                $candidate = implode(' ', $words);
                $key = mb_strtolower($candidate);
                if (!isset($dottedSeen[$key])) {
                    $dottedSeen[$key] = ['name' => $candidate, 'files' => 0];
                }
                $dottedSeen[$key]['files']++;
            }
        }
    });
}
// A 3-word candidate whose first two words already form a known name is a
// 2-word name with a title word riding along ("Abella.Danger.Booty.And.The
// .Beast" -> "Abella Danger" + junk). Fold its sightings into the 2-word name.
foreach (array_keys($dottedSeen) as $key) {
    $words = explode(' ', $key);
    if (count($words) !== 3) {
        continue;
    }
    $prefixKey = "{$words[0]} {$words[1]}";
    if (isset($names[$prefixKey]) || isset($dottedSeen[$prefixKey])) {
        if (!isset($dottedSeen[$prefixKey])) {
            $dottedSeen[$prefixKey] = [
                'name' => implode(' ', array_slice(explode(' ', $dottedSeen[$key]['name']), 0, 2)),
                'files' => 0,
            ];
        }
        $dottedSeen[$prefixKey]['files'] += $dottedSeen[$key]['files'];
        unset($dottedSeen[$key]);
    }
}

// Keep a dotted candidate only if it recurs, or corroborates a known name.
$dottedKept = 0;
$dottedNew = [];
foreach ($dottedSeen as $key => $info) {
    if ($info['files'] >= 2 || isset($names[$key])) {
        if (!isset($names[$key])) {
            $dottedNew[] = $info['name'] . " (x{$info['files']})";
        }
        $addName($info['name'], 'dotted');
        $dottedKept++;
    }
}
printf(
    "  %d candidates, %d kept (recurring or corroborated), %d dropped\n",
    count($dottedSeen),
    $dottedKept,
    count($dottedSeen) - $dottedKept,
);

// ---------------------------------------------------------------- merge
echo "\nTotals: " . count($names) . " distinct names";
echo " (tails +{$perSource['scene_tails']}, folders +{$perSource['performer_folders']}, dotted +{$perSource['dotted']})\n";

sort($dottedNew, SORT_NATURAL | SORT_FLAG_CASE);
echo "\nDotted-only names (not seen in tails/folders — eyeball these):\n";
foreach (array_slice($dottedNew, 0, 40) as $n) {
    echo "  {$n}\n";
}
if (count($dottedNew) > 40) {
    echo '  ... and ' . (count($dottedNew) - 40) . " more\n";
}

if ($dryRun) {
    echo "\n--dry-run: store NOT written\n";
    exit(0);
}

$existing = moviedb_load_cast_store();
$merged = moviedb_save_cast_store(array_merge($existing, array_values($names)));
echo "\nStore: " . count($existing) . ' -> ' . count($merged)
    . ' names in ' . MOVIEDB_CAST_STORE . "\n";
