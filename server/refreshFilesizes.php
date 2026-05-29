<?php

/**
 * CLI script to refresh all filesizes in the database by scanning directories.
 *
 * Scans the Recorded volumes, matches filenames to DB titles using the same
 * title-cleaning logic as processFilesForDB, and updates filesizes that differ.
 *
 * Usage:  php refreshFilesizes.php [--dry-run]
 */

require 'db_connect.php';
require_once __DIR__ . '/normalize_helpers.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

if ($dryRun) {
    echo "DRY RUN — no changes will be made.\n\n";
}

// Directories to scan (same volumes used elsewhere in the app)
$directories = [
    '/Volumes/Recorded 1/recorded/',
    '/Volumes/Recorded 2/recorded/',
    '/Volumes/Recorded 3/recorded/',
    '/Volumes/Recorded 4/recorded/',
];

// Collect files from all directories, grouped by cleaned title (summing sizes for multi-part files)
$titleSizes = []; // lowercase title => ['title' => canonical, 'size' => total bytes]

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        echo "SKIP (not mounted): {$dir}\n";
        continue;
    }

    echo "Scanning: {$dir}\n";
    $files = array_diff(scandir($dir), ['..', '.']);

    foreach ($files as $file) {
        if (substr($file, 0, 1) === '.') continue;

        $filePath = rtrim($dir, '/') . '/' . $file;
        if (!is_file($filePath)) continue;

        $fileInfo = pathinfo($filePath);
        $nameNoExt = $fileInfo['filename'];

        // Same title-cleaning as processFilesForDB populateTitlesArray
        $title = stripTitleVariantSuffixes($nameNoExt);

        $size = filesize($filePath);
        if ($size === false) continue;

        $key = strtolower($title);
        if (isset($titleSizes[$key])) {
            $titleSizes[$key]['size'] += (int)$size;
        } else {
            $titleSizes[$key] = ['title' => $title, 'size' => (int)$size];
        }
    }
}

echo "\nFound " . count($titleSizes) . " unique titles on disk.\n\n";

// Now match against DB and update
$updated = 0;
$notFound = 0;
$unchanged = 0;
$errors = 0;

function findTitleInDB($db, $table, $title) {
    // Try exact title first
    $lookups = [strtolower($title)];

    // Fallback: strip cast suffix "Title # 03 - Cast Names" → "Title # 03"
    if (preg_match('/^(.*?\s+#\s+\d+)\s+-\s+/', $title, $castMatch)) {
        $lookups[] = strtolower(trim($castMatch[1]));
    }

    foreach ($lookups as $key) {
        $stmt = $db->prepare("SELECT id, filesize FROM `$table` WHERE LOWER(title) = ? LIMIT 1");
        if (!$stmt) continue;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row;
        }
        $stmt->close();
    }

    return null;
}

foreach ($titleSizes as $key => $info) {
    $title = $info['title'];
    $diskSize = $info['size'];

    $row = findTitleInDB($db, $table, $title);

    if (!$row) {
        $notFound++;
        echo "  NOT IN DB: {$title}\n";
        continue;
    }

    $id = (int)$row['id'];
    $dbFilesize = (int)$row['filesize'];

    if ($diskSize === $dbFilesize) {
        $unchanged++;
        continue;
    }

    echo "  UPDATE: {$title}  {$dbFilesize} -> {$diskSize}\n";

    if (!$dryRun) {
        $upd = $db->prepare("UPDATE `$table` SET filesize = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param('ii', $diskSize, $id);
            if (!$upd->execute()) {
                echo "    FAILED: " . $upd->error . "\n";
                $errors++;
            }
            $upd->close();
        }
    }

    $updated++;
}

echo "\nDone.\n";
echo "  Updated: {$updated}\n";
echo "  Unchanged: {$unchanged}\n";
echo "  Not in DB: {$notFound}\n";
echo "  Errors: {$errors}\n";

if ($dryRun) {
    echo "\n(dry run — re-run without --dry-run to apply changes)\n";
}

$db->close();
