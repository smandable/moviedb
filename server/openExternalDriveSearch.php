<?php
declare(strict_types=1);

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input') ?: '', true);
$query = is_array($input) ? trim((string)($input['query'] ?? '')) : '';

if ($query === '' || mb_strlen($query) > 120) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid query']);
  exit;
}

// Keep it safe for XML + Spotlight raw query (no quotes/control chars)
if (preg_match('/["\\\\\\x00-\\x1F]/', $query)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Query contains unsupported characters']);
  exit;
}

// Only these external volumes
$volumeNames = ['Extra', 'Misc', 'Recorded 1', 'Recorded 2', 'Recorded 3', 'Recorded 4', 'SP'];
$scopes = [];

foreach ($volumeNames as $name) {
  $path = "/Volumes/$name";
  if (is_dir($path)) {
    $scopes[] = $path;
  }
}

if (!$scopes) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'None of the external volumes are mounted under /Volumes']);
  exit;
}

$esc = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

// “Name contains” style query (case/diacritic-insensitive)
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

$safeName = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $query);
$outFile = sys_get_temp_dir() . '/MovieDB External Search - ' . $safeName . ' - ' . time() . '.savedSearch';

if (file_put_contents($outFile, $plist) === false) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to write savedSearch']);
  exit;
}

// Open the smart folder in Finder (real files → rename/delete works)
exec('open ' . escapeshellarg($outFile) . ' > /dev/null 2>&1 &');

echo json_encode(['ok' => true, 'opened' => $outFile, 'scopes' => $scopes]);
