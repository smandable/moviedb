<?php

/**
 * Cast-name vocabulary audit: finds junk and duplicates in server/cast_names.json
 * so they can be reviewed from Settings ("Check for junk & duplicates", via
 * castNamesAudit.php) or the CLI (scripts/audit_cast_names.php).
 *
 * Read-only over the store. Three kinds of finding:
 *   duplicate  the same name apart from case, accents, spacing or punctuation
 *              ("Zoë Doll" / "Zoe Doll", "AbbyLee Brazil" / "Abby Lee Brazil"),
 *              or the same two words swapped ("Lee Aria" / "Aria Lee")
 *   variant    one word a letter or so apart, with the rest identical
 *              ("Abby Rain" / "Abby Rains", "Aleksa" / "Aleska"); chains are
 *              grouped, so three spellings of one name show as one finding
 *   junk       not shaped like a performer name (a digit, a symbol, a word
 *              like "Intro" or "Girl", two stored names run together, ...)
 *   male       a man's first name — the vocabulary is the women in each
 *              scene, so these are usually male talent that slipped in
 *
 * Precision is tuned for periodic use: anything that turns out fine can be
 * dismissed once (server/cast_audit_dismissed.json) and stays hidden until the
 * finding itself changes — a third spelling joining a pair is a new finding.
 *
 * All functions take explicit args for testability.
 */

require_once __DIR__ . '/cast_helpers.php';

const MOVIEDB_CAST_AUDIT_DISMISSED_FILE = __DIR__ . '/cast_audit_dismissed.json';

/** Sample files listed per name, so a name's spelling can be traced on disk. */
const MOVIEDB_CAST_AUDIT_SAMPLE_FILES = 3;

/**
 * Whole words that mark a "name" as a filename fragment rather than a person.
 * Lowercase. Colour words (Black, White) are deliberately absent — they are
 * real surnames all over the store.
 */
const MOVIEDB_CAST_AUDIT_JUNK_WORDS = [
    'anal', 'ass', 'behind', 'bonus', 'bts', 'compilation', 'cum', 'cutie',
    'feat', 'fuck', 'fucked', 'fucking', 'fucks', 'girl', 'girls', 'hot',
    'interview', 'intro', 'lesbian', 'me', 'milf', 'outro', 'part', 'pov',
    'scene', 'scenes', 'sex', 'sexy', 'solo', 'squirt', 'teen', 'teens', 'the',
    'trailer', 'unknown', 'various', 'vs', 'with', 'xxx',
];

/**
 * First names that mark a man (lowercase). Deliberately leaves out names
 * women in the library go by — Ryan, Tyler, Spencer, Blake, Jordan, Cameron,
 * Carter, Ryder, Mason, Austin, Max, Charlie, Alex, ... — so the list stays
 * precise; the few women it still catches (a "Tommy") are dismissed once.
 */
const MOVIEDB_CAST_AUDIT_MALE_FIRST_NAMES = [
    'aaron', 'abel', 'adam', 'adrian', 'al', 'alan', 'albert', 'alberto', 'alec',
    'alejandro', 'alexander', 'alfred', 'andre', 'andrei', 'andrew', 'angelo',
    'anthony', 'anton', 'antonio', 'archie', 'arnold', 'arthur', 'axel', 'barry',
    'ben', 'benjamin', 'benny', 'bill', 'billy', 'bob', 'bobby', 'boris', 'brad',
    'brandon', 'brendan', 'brent', 'brian', 'bruce', 'bruno', 'bryan', 'buck',
    'byron', 'caleb', 'calvin', 'carl', 'carlo', 'carlos', 'chad', 'charles',
    'chris', 'christian', 'christopher', 'chuck', 'clark', 'clay', 'cliff',
    'clint', 'codey', 'colin', 'connor', 'conor', 'craig', 'curtis', 'damian',
    'damien', 'damon', 'dan', 'daniel', 'danny', 'dante', 'darius', 'darren',
    'darryl', 'dave', 'david', 'dean', 'dennis', 'derek', 'derrick', 'diego',
    'dimitri', 'dirk', 'dmitri', 'dominic', 'don', 'donald', 'donny', 'donte',
    'doug', 'douglas', 'duncan', 'dustin', 'dwayne', 'earl', 'ed', 'eddie',
    'edgar', 'edward', 'eli', 'elijah', 'elliot', 'emilio', 'enrique', 'eric',
    'erik', 'ernest', 'ethan', 'eugene', 'evan', 'ezra', 'felipe', 'felix',
    'fernando', 'francisco', 'franco', 'frank', 'fred', 'freddie', 'freddy',
    'gabriel', 'gareth', 'garrett', 'gary', 'gavin', 'geoff', 'george', 'gerald',
    'gino', 'giovanni', 'glen', 'glenn', 'gordon', 'graham', 'greg', 'gregory',
    'gus', 'guy', 'hank', 'hans', 'harold', 'harry', 'harvey', 'hector', 'henry',
    'herman', 'howard', 'hugo', 'ian', 'igor', 'isaac', 'isaiah', 'isiah', 'ivan',
    'jack', 'jacob', 'jacques', 'jake', 'jamal', 'james', 'jared', 'jason',
    'javier', 'jax', 'jeff', 'jeffrey', 'jeremy', 'jerome', 'jerry', 'jim',
    'jimmy', 'jmac', 'joe', 'joel', 'johan', 'johhny', 'john', 'johnny', 'jon',
    'jonah', 'jonathan', 'jonny', 'jorge', 'jose', 'joseph', 'josh', 'joshua',
    'juan', 'julio', 'justin', 'karl', 'keith', 'keiran', 'ken', 'kenneth',
    'kenny', 'kevin', 'kieran', 'kirk', 'klaus', 'kristof', 'kurt', 'kyle',
    'lance', 'larry', 'lars', 'lawrence', 'leon', 'leonard', 'leroy', 'lewis',
    'lexington', 'lionel', 'lloyd', 'lorenzo', 'louie', 'louis', 'luca', 'lucas',
    'luigi', 'luis', 'luke', 'malcolm', 'manny', 'manuel', 'marc', 'marcel',
    'marco', 'marcus', 'mario', 'mark', 'markus', 'martin', 'marty', 'marvin',
    'matt', 'matthew', 'maurice', 'maxim', 'michael', 'michel', 'mick', 'miguel',
    'mike', 'mikey', 'miles', 'mitch', 'nacho', 'nate', 'nathan', 'neil',
    'nelson', 'nicholas', 'nick', 'nicolas', 'nigel', 'noah', 'norman', 'oliver',
    'omar', 'oscar', 'owen', 'pablo', 'patrick', 'paul', 'pedro', 'percy', 'pete',
    'peter', 'phil', 'philip', 'phillip', 'pierre', 'preston', 'prince',
    'rafael', 'ralph', 'ramon', 'randy', 'raul', 'ray', 'raymond', 'reggie',
    'ricardo', 'richard', 'rick', 'ricky', 'rico', 'rob', 'robert', 'roberto',
    'rocco', 'rocky', 'rod', 'rodney', 'roger', 'roland', 'roman', 'romeo', 'ron',
    'ronald', 'roy', 'ruben', 'rudy', 'russ', 'russell', 'salvatore', 'samuel',
    'scott', 'sean', 'sebastian', 'sergei', 'sergio', 'seth', 'shane', 'shaun',
    'shawn', 'sherman', 'stan', 'stanley', 'stefan', 'stephen', 'steve',
    'steven', 'stuart', 'ted', 'teddy', 'thomas', 'tim', 'timothy', 'toby',
    'todd', 'tom', 'tommy', 'tony', 'travis', 'trent', 'trevor', 'troy', 'victor',
    'vince', 'vincent', 'vinnie', 'vito', 'wade', 'walter', 'warren', 'wayne',
    'wes', 'wesley', 'will', 'william', 'willie', 'wolf', 'xander', 'xavier',
    'zach', 'zachary', 'zack',
];

/** Lowercase name particles that are fine mid-name ("Alba de Silva"). */
const MOVIEDB_CAST_AUDIT_PARTICLES = [
    'al', 'bin', 'da', 'das', 'de', 'del', 'della', 'den', 'der', 'di', 'do',
    'dos', 'du', 'el', 'la', 'le', 'ten', 'ter', 'van', 'von', 'y',
];

if (!function_exists('moviedb_cast_audit_ascii')) {
    /** Lowercase ASCII spelling: accents and lookalike letters folded away. */
    function moviedb_cast_audit_ascii(string $text): string
    {
        $folded = function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text)
            : false;
        if (!is_string($folded)) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
            $folded = is_string($folded) ? strtolower($folded) : mb_strtolower($text);
        }
        return $folded;
    }
}

if (!function_exists('moviedb_cast_audit_fold')) {
    /** Duplicate key: ASCII letters only, so case/accents/spacing/punctuation vanish. */
    function moviedb_cast_audit_fold(string $name): string
    {
        return preg_replace('/[^a-z]/', '', moviedb_cast_audit_ascii($name)) ?? '';
    }
}

if (!function_exists('moviedb_cast_audit_skeleton')) {
    /**
     * A word's spelling skeleton: the common variant spellings of one sound
     * collapse to the same string ("Annika" / "Anikka", "Aleksa" / "Alexa",
     * "Bradburry" / "Bradbury"). Input is already ASCII-lowercase.
     */
    function moviedb_cast_audit_skeleton(string $word): string
    {
        $s = preg_replace('/[^a-z]/', '', $word) ?? '';
        $s = strtr($s, ['ph' => 'f', 'ck' => 'k', 'ks' => 'x', 'ie' => 'i', 'ey' => 'i', 'ee' => 'i']);
        $s = strtr($s, ['c' => 'k', 'y' => 'i']);
        $s = preg_replace('/(.)\1+/', '$1', $s) ?? $s;
        return preg_replace('/[es]+$/', '', $s) ?? $s;
    }
}

if (!function_exists('moviedb_cast_audit_words_close')) {
    /**
     * Two DIFFERENT words that look like spellings of one: same first letter
     * (a changed first letter is usually a different person — Gia / Mia), not
     * a lone initial, and one edit apart, an adjacent swap, or the same
     * skeleton. Mononyms must be 5+ letters: short ones (Dana / Dara) are
     * nearly always different people.
     */
    function moviedb_cast_audit_words_close(string $a, string $b, bool $mononym): bool
    {
        if ($a === $b || $a === '' || $b === '' || $a[0] !== $b[0]) {
            return false;
        }
        $min = min(strlen($a), strlen($b));
        if ($min < 2 || ($mononym && $min < 5)) {
            return false;
        }
        if (moviedb_cast_audit_skeleton($a) === moviedb_cast_audit_skeleton($b)) {
            return true;
        }
        $distance = levenshtein($a, $b);
        if ($distance === 1) {
            return true;
        }
        // Adjacent transposition ("Aleksa" / "Aleska") costs 2 in Levenshtein.
        if ($distance === 2 && strlen($a) === strlen($b)) {
            $diff = [];
            for ($i = 0, $n = strlen($a); $i < $n; $i++) {
                if ($a[$i] !== $b[$i]) {
                    $diff[] = $i;
                }
            }
            return count($diff) === 2 && $diff[1] === $diff[0] + 1
                && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]];
        }
        return false;
    }
}

if (!function_exists('moviedb_cast_audit_junk_reasons')) {
    /**
     * Why a name doesn't look like a performer name ([] when it does).
     * $known is the store keyed by lowercase name, for the run-together check.
     */
    function moviedb_cast_audit_junk_reasons(string $name, array $known = []): array
    {
        $reasons = [];
        $clean = moviedb_clean_cast_name($name);
        if ($clean !== $name) {
            $reasons[] = $clean === ''
                ? 'Not a usable name'
                : "Would be stored as “{$clean}”";
        }
        if (preg_match('/\d/', $name)) {
            $reasons[] = 'Contains a digit';
        }
        if (preg_match('/[#@&+\/\\\\()\[\]{}!?;:,"_=~*<>|]/', $name, $m)) {
            $reasons[] = "Contains “{$m[0]}”";
        }
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        foreach ($words as $i => $word) {
            $bare = mb_strtolower(trim($word, ".'-"));
            if (in_array($bare, MOVIEDB_CAST_AUDIT_JUNK_WORDS, true)) {
                $reasons[] = "Contains the word “{$word}”";
                break;
            }
        }
        foreach ($words as $i => $word) {
            if (preg_match('/^\p{Ll}/u', $word)
                && !($i > 0 && in_array($word, MOVIEDB_CAST_AUDIT_PARTICLES, true))) {
                $reasons[] = "Lowercase word “{$word}”";
                break;
            }
        }
        foreach ($words as $word) {
            if (preg_match('/^\p{Lu}{4,}$/u', $word)) {
                $reasons[] = "All-capitals word “{$word}”";
                break;
            }
        }
        if (count($words) >= 4) {
            $reasons[] = 'Four or more words';
        }
        if (mb_strlen($name) <= 2) {
            $reasons[] = 'Too short to be a name';
        }
        // "Jane Doe Mary Major": two stored names run together (a lost comma).
        for ($i = 1, $n = count($words); $i < $n; $i++) {
            $head = implode(' ', array_slice($words, 0, $i));
            $tail = implode(' ', array_slice($words, $i));
            if (($i > 1 || $n - $i > 1)
                && isset($known[mb_strtolower($head)], $known[mb_strtolower($tail)])) {
                $reasons[] = "Two stored names run together: “{$head}” + “{$tail}”";
                break;
            }
        }
        return $reasons;
    }
}

if (!function_exists('moviedb_cast_audit_male_first_name')) {
    /** The name's first word when it is a man's first name, else ''. */
    function moviedb_cast_audit_male_first_name(string $name): string
    {
        $first = strtok(trim($name), " \t") ?: '';
        $bare = moviedb_cast_audit_ascii(trim($first, ".'-"));
        return in_array($bare, MOVIEDB_CAST_AUDIT_MALE_FIRST_NAMES, true) ? $first : '';
    }
}

if (!function_exists('moviedb_cast_audit_usage')) {
    /**
     * How often each name appears in indexed filenames' cast tails, keyed by
     * lowercase name: ['count' => n, 'files' => [first few paths]].
     * $entries are drive-index entries (file + dir).
     */
    function moviedb_cast_audit_usage(array $entries): array
    {
        $usage = [];
        foreach ($entries as $entry) {
            $file = is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $base = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match(MOVIEDB_SCENE_CAST_RE, $base, $m)
                && !preg_match('/Scene_\d+\s+(\p{Lu}.+)$/u', $base, $m)) {
                continue;
            }
            $path = rtrim((string) ($entry['dir'] ?? ''), '/') . '/' . $file;
            foreach (array_unique(array_map('mb_strtolower', moviedb_split_cast_tail($m[1]))) as $key) {
                $usage[$key]['count'] = ($usage[$key]['count'] ?? 0) + 1;
                if (count($usage[$key]['files'] ?? []) < MOVIEDB_CAST_AUDIT_SAMPLE_FILES) {
                    $usage[$key]['files'][] = $path;
                }
            }
        }
        return $usage;
    }
}

if (!function_exists('moviedb_cast_audit_key')) {
    /** Stable id of a finding — what a dismissal remembers. */
    function moviedb_cast_audit_key(string $kind, array $names): string
    {
        $lower = array_map('mb_strtolower', $names);
        sort($lower, SORT_STRING);
        return $kind . '|' . implode('|', $lower);
    }
}

if (!function_exists('moviedb_cast_audit')) {
    /**
     * Audit a name list. Returns ['findings' => [...], 'hidden' => n], each
     * finding ['key', 'kind', 'reason', 'names' => [string, ...]], ordered
     * duplicates, variants, junk, male; findings whose key is in $dismissed are
     * left out and counted in 'hidden'.
     */
    function moviedb_cast_audit(array $names, array $dismissed = []): array
    {
        $names = array_values(array_unique(array_filter($names, 'is_string')));
        $known = [];
        foreach ($names as $name) {
            $known[mb_strtolower($name)] = $name;
        }

        $findings = ['duplicate' => [], 'variant' => [], 'junk' => [], 'male' => []];
        $add = function (string $kind, string $reason, array $group) use (&$findings): void {
            sort($group, SORT_NATURAL | SORT_FLAG_CASE);
            $key = moviedb_cast_audit_key($kind, $group);
            $findings[$kind][$key] = ['key' => $key, 'kind' => $kind, 'reason' => $reason, 'names' => $group];
        };

        // --- duplicates: same letters once case, accents, spaces and punctuation go
        $byFold = [];
        foreach ($names as $name) {
            $byFold[moviedb_cast_audit_fold($name)][] = $name;
        }
        foreach ($byFold as $fold => $group) {
            if ($fold !== '' && count($group) > 1) {
                $add('duplicate', 'Same name apart from case, accents, spacing or punctuation', $group);
            }
        }
        // --- duplicates: two words swapped
        $byWords = [];
        foreach ($names as $name) {
            $words = preg_split('/\s+/u', moviedb_cast_audit_ascii($name)) ?: [];
            if (count($words) === 2) {
                $byWords[$words[0] . ' ' . $words[1]] = $name;
            }
        }
        foreach ($byWords as $pair => $name) {
            [$first, $second] = explode(' ', $pair);
            $swapped = $byWords[$second . ' ' . $first] ?? null;
            if ($swapped !== null && $first < $second) {
                $add('duplicate', 'Same words, swapped order', [$name, $swapped]);
            }
        }

        // --- variants: every word identical but one, which is a near-spelling.
        // Bucket by "the other words" so only plausible pairs are compared.
        $buckets = [];
        foreach ($names as $name) {
            $words = preg_split('/\s+/u', moviedb_cast_audit_ascii($name)) ?: [];
            foreach ($words as $k => $word) {
                $rest = $words;
                unset($rest[$k]);
                $bucket = count($words) . ':' . $k . ':' . implode(' ', $rest);
                $buckets[$bucket][] = [$name, preg_replace('/[^a-z]/', '', $word) ?? ''];
            }
        }
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            if (!isset($parent[$x]) || $parent[$x] === $x) {
                return $x;
            }
            return $parent[$x] = $find($parent[$x]);
        };
        foreach ($buckets as $bucket => $members) {
            $mononym = str_starts_with($bucket, '1:');
            for ($i = 0, $n = count($members); $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$nameA, $wordA] = $members[$i];
                    [$nameB, $wordB] = $members[$j];
                    if (moviedb_cast_audit_words_close($wordA, $wordB, $mononym)) {
                        $parent[$nameA] ??= $nameA;
                        $parent[$nameB] ??= $nameB;
                        $parent[$find($nameA)] = $find($nameB);
                    }
                }
            }
        }
        $chains = [];
        foreach (array_keys($parent) as $name) {
            $chains[$find($name)][] = $name;
        }
        foreach ($chains as $group) {
            $add('variant', 'Spelled a letter or so apart', $group);
        }

        // --- junk-shaped names
        foreach ($names as $name) {
            $reasons = moviedb_cast_audit_junk_reasons($name, $known);
            if ($reasons) {
                $add('junk', implode('; ', $reasons), [$name]);
            }
            $male = moviedb_cast_audit_male_first_name($name);
            if ($male !== '') {
                $add('male', "Male first name “{$male}”", [$name]);
            }
        }

        $dismissedSet = array_flip(array_filter($dismissed, 'is_string'));
        $out = [];
        $hidden = 0;
        foreach ($findings as $kind => $list) {
            uasort($list, fn($a, $b) => strnatcasecmp($a['names'][0], $b['names'][0]));
            foreach ($list as $key => $finding) {
                if (isset($dismissedSet[$key])) {
                    $hidden++;
                    continue;
                }
                $out[] = $finding;
            }
        }
        return ['findings' => $out, 'hidden' => $hidden];
    }
}

if (!function_exists('moviedb_cast_audit_load_dismissed')) {
    function moviedb_cast_audit_load_dismissed(?string $path = null): array
    {
        $path = $path ?? MOVIEDB_CAST_AUDIT_DISMISSED_FILE;
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }
}

if (!function_exists('moviedb_cast_audit_save_dismissed')) {
    function moviedb_cast_audit_save_dismissed(array $keys, ?string $path = null): bool
    {
        $path = $path ?? MOVIEDB_CAST_AUDIT_DISMISSED_FILE;
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
        sort($keys, SORT_STRING);
        return @file_put_contents(
            $path,
            json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) !== false;
    }
}
