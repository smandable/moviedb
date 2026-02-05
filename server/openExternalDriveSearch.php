<?php

declare(strict_types=1);

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input') ?: '', true);
$query = is_array($input) ? trim((string)($input['query'] ?? '')) : '';

$len = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
if ($query === '' || $len > 120) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid query']);
    exit;
}

if (preg_match('/["\\\\\\x00-\\x1F]/', $query)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Query contains unsupported characters']);
    exit;
}

$volumeNames = ['Extra', 'Misc', 'Recorded 1', 'Recorded 2', 'Recorded 3', 'Recorded 4', 'SP'];
$scopes = [];
foreach ($volumeNames as $name) {
    $path = "/Volumes/$name";
    if (is_dir($path)) $scopes[] = $path;
}
if (!$scopes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'None of the expected external volumes are mounted under /Volumes']);
    exit;
}

$esc = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

// Spotlight raw query (filename contains, case/diacritic-insensitive)
$rawQuery = '(kMDItemFSName == "*' . $query . '*"cd)';

$scopesXml = '';
foreach ($scopes as $s) {
    $scopesXml .= "      <string>{$esc($s)}</string>\n";
}

$plist = <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CompatibleVersion</key>
  <integer>1</integer>

  <key>RawQuery</key>
  <string>{$esc($rawQuery)}</string>

  <key>RawQueryDict</key>
  <dict>
    <key>FinderFilesOnly</key><true/>
    <key>UserFilesOnly</key><true/>
    <key>RawQuery</key><string>{$esc($rawQuery)}</string>

    <key>SearchScopes</key>
    <array>
$scopesXml    </array>
  </dict>
</dict>
</plist>
PLIST;

// Use temp folder (writable by Apache)
// Prefer stable /tmp, fall back to PHP's temp dir if needed.
$preferred = '/tmp/moviedb_savedsearch';
$fallback  = rtrim(sys_get_temp_dir(), '/') . '/moviedb_savedsearch';

$folder = $preferred;

// Try preferred
if (!is_dir($folder)) {
    @mkdir($folder, 0777, true);
}
$canWrite = is_dir($folder) && is_writable($folder);

// Fall back if needed
if (!$canWrite) {
    $folder = $fallback;
    if (!is_dir($folder)) {
        @mkdir($folder, 0777, true);
    }
    $canWrite = is_dir($folder) && is_writable($folder);
}

if (!$canWrite) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to create a writable temp folder',
        'preferred' => $preferred,
        'fallback' => $fallback,
        'sysTempDir' => sys_get_temp_dir(),
    ]);
    exit;
}

// Unique savedSearch per query so Finder can’t “cache” the first one
$safeName = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $query);
$safeName = trim($safeName);
if ($safeName === '') $safeName = 'query';
$hash = substr(md5($query), 0, 8);
$outFile = $folder . '/MovieDB External Search - ' . $safeName . ' - ' . $hash . '.savedSearch';

// Atomic write (best-effort)
$tmpFile = $outFile . '.tmp';

// 1) Write temp file
if (file_put_contents($tmpFile, $plist, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to write savedSearch temp file',
        'tmpFile' => $tmpFile,
    ]);
    exit;
}

// 2) Try atomic replace
if (!@rename($tmpFile, $outFile)) {
    // If rename fails (Finder sometimes holds a handle), fall back to direct write.
    @unlink($tmpFile);

    if (file_put_contents($outFile, $plist, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to write savedSearch (rename failed + direct write failed)',
            'outFile' => $outFile,
        ]);
        exit;
    }
}

$keep = 20;
pruneSavedSearchFiles($folder, $keep, $outFile);

$stateFile  = $folder . '/finder_window_id.txt';
$scriptFile = $folder . '/reuse_finder_window.applescript';

// Read prior window id (PHP controls state, not AppleScript)
$priorId = '';
if (is_file($stateFile)) {
    $priorId = trim((string)@file_get_contents($stateFile));
    if (!preg_match('/^\d+$/', $priorId)) $priorId = '';
}

// AppleScript: close prior window id, open new savedSearch, return new window id.
// IMPORTANT: plain ASCII quotes only.
$appleScript = <<<AS
on run argv
  set searchFilePosix to item 1 of argv
  set priorIdText to item 2 of argv

  set searchFile to POSIX file searchFilePosix as alias

  tell application "Finder"
    activate

    if priorIdText is not "" then
      try
        set priorId to priorIdText as integer
        try
          close (first Finder window whose id is priorId)
        end try
      end try
    end if

    open searchFile
  end tell

  delay 0.25

  tell application "Finder"
    set newId to id of front Finder window
  end tell

  return newId as text
end run
AS;

if (!is_file($scriptFile) || !is_readable($scriptFile)) {
    if (file_put_contents($scriptFile, $appleScript, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to write AppleScript file',
            'scriptFile' => $scriptFile
        ]);
        exit;
    }
}

// Run AppleScript in the GUI user session (critical for Apache/PHP)
$uid = trim((string)@shell_exec('/usr/bin/stat -f%u /dev/console'));
if (!preg_match('/^\d+$/', $uid)) $uid = '';

$osascriptOut = [];
$osascriptCode = 0;

if ($uid !== '') {
    $uidInt = (int)$uid;

    $cmd =
        '/bin/launchctl asuser ' . $uidInt . ' ' .
        '/usr/bin/osascript ' . escapeshellarg($scriptFile) . ' ' .
        escapeshellarg($outFile) . ' ' .
        escapeshellarg($priorId) . ' 2>&1';

    exec($cmd, $osascriptOut, $osascriptCode);
} else {
    $osascriptCode = 999;
    $osascriptOut = ['Could not determine console user uid'];
}

$newId = '';
if ($osascriptCode === 0 && !empty($osascriptOut)) {
    // osascript returns the "return value" as the last line
    $candidate = trim((string)end($osascriptOut));
    if (preg_match('/^\d+$/', $candidate)) {
        $newId = $candidate;
        @file_put_contents($stateFile, $newId, LOCK_EX);
    }
}

if ($osascriptCode !== 0 || $newId === '') {
    // Fallback: open file (will spawn windows)
    exec('/usr/bin/open ' . escapeshellarg($outFile) . ' > /dev/null 2>&1 &');

    echo json_encode([
        'ok' => true,
        'opened' => $outFile,
        'folder' => $folder,
        'stateFile' => $stateFile,
        'scriptFile' => $scriptFile,
        'query' => $query,
        'rawQuery' => $rawQuery,
        'warning' => 'osascript failed; used open() fallback',
        'osascriptExitCode' => $osascriptCode,
        'osascriptOutput' => implode("\n", $osascriptOut),
        'priorWindowId' => $priorId,
        'newWindowId' => $newId,
        'consoleUid' => $uid,
    ]);
    exit;
}

function pruneSavedSearchFiles(string $folder, int $keep, string $keepPath): void
{
    if ($keep < 1) $keep = 1;

    $files = glob($folder . '/MovieDB External Search - *.savedSearch') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a)); // newest first

    $kept = 0;
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        // Always keep the one we just opened
        if ($f === $keepPath) continue;

        // Keep newest (keep-1) others
        if ($kept < ($keep - 1)) {
            $kept++;
            continue;
        }

        @unlink($f);
    }
}

// Success
echo json_encode([
    'ok' => true,
    'opened' => $outFile,
    'folder' => $folder,
    'folderMode' => ($folder === $preferred ? 'preferred' : 'fallback'),
    'stateFile' => $stateFile,
    'scriptFile' => $scriptFile,
    'query' => $query,
    'rawQuery' => $rawQuery,
    'osascriptExitCode' => $osascriptCode,
    'priorWindowId' => $priorId,
    'newWindowId' => $newId,
    'consoleUid' => $uid,
]);
