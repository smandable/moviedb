<?php
// Start output buffering to prevent premature output
ob_start();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Only POST requests are allowed']);
    exit();
}

// Get the raw POST data
$data = json_decode(file_get_contents('php://input'), true);

// Validate the input
if (!isset($data['directory']) || empty($data['directory'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Directory path is required']);
    exit();
}

$directory = rtrim($data['directory'], '/');

// Check if the directory exists
if (!is_dir($directory)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid directory path']);
    exit();
}

$files = scandir($directory);
$normalizedFiles = [];

foreach ($files as $file) {
    // Skip current and parent directories
    if ($file === '.' || $file === '..') continue;

    // **Skip hidden files**
    if (substr($file, 0, 1) === '.') continue;

    $path = $directory;
    $fileName = $file;
    $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
    $fileNameNoExtension = pathinfo($fileName, PATHINFO_FILENAME);

    // Apply transformations
    $originalFileName = $fileNameNoExtension; // Keep original for comparison
    $normalizedFileNameNoExtension = basicFunctions($fileNameNoExtension);
    $normalizedFileNameNoExtension = titleCase($normalizedFileNameNoExtension);
    $normalizedFileNameNoExtension = cleanupFunctions($normalizedFileNameNoExtension);
    $normalizedFileNameNoExtension = finalCleanup($normalizedFileNameNoExtension);

    $newFileName = $normalizedFileNameNoExtension . ($fileExtension ? '.' . $fileExtension : '');

    // Determine if normalization is needed
    $needsNormalization = ($originalFileName !== $normalizedFileNameNoExtension);

    // Log original and new filenames for debugging
    error_log("Original: $originalFileName, New: $normalizedFileNameNoExtension");

    // Prepare file data
    $normalizedFiles[] = [
        'path' => $path,
        'originalFileName' => $fileName,
        'newFileName' => $needsNormalization ? $newFileName : '', // Empty if no normalization
        'fileExtension' => $fileExtension,
        'fileNameNoExtension' => $normalizedFileNameNoExtension,
        'needsNormalization' => $needsNormalization,
        'status' => $needsNormalization ? 'Needs Renaming' : 'Name ok',
    ];
}

// Set appropriate headers for JSON response
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-Type: application/json');

// Output the result as a flat array
echo json_encode(['files' => $normalizedFiles]);

// End output buffering and send output
ob_end_flush();

// Helper Functions (Same as your existing functions)
function basicFunctions($fileName)
{
    $replacements = [
        '/\./' => ' ',     // Periods to spaces
        '/\[|\]/' => ' ',  // Brackets to spaces
        '/_/' => ' ',      // Underscores to spaces
        '/-/' => ' ',      // Dashes to spaces
        '/\s{3}/' => ' - ',// Triple spaces to ' - '
        '/\s+/' => ' ',    // Multiple spaces to a single space
        '/\.+/' => '.',    // Multiple periods to a single period
        '/^\.+/' => '',    // Leading periods removed
    ];
    return preg_replace(array_keys($replacements), array_values($replacements), trim($fileName));
}

function titleCase($fileName)
{
    $delimiters = [" "];
    $exceptions = ["the", "a", "an", "and", "as", "at", "be", "but", "by", "for", "in", "it", "is", "of", "off", "on", "or", "per", "to", "up", "via", "with", "vs", "BBC", "CD", "MILF", "XXX"];

    $fileName = mb_convert_case($fileName, MB_CASE_TITLE, "UTF-8");

    foreach ($delimiters as $delimiter) {
        $words = explode($delimiter, $fileName);
        foreach ($words as &$word) {
            if (in_array(mb_strtolower($word, "UTF-8"), $exceptions)) {
                $word = mb_strtolower($word, "UTF-8");
            } elseif (!in_array(mb_strtoupper($word, "UTF-8"), $exceptions)) {
                $word = ucfirst($word);
            }
        }
        $fileName = implode($delimiter, $words);
    }

    // Adjust for specific cases
    $replacements = [
        '/^(the)\s/i' => 'The ',
        '/^(a)\s/i' => 'A ',
        '/^(so)\s/i' => 'So ',
    ];
    return preg_replace(array_keys($replacements), array_values($replacements), $fileName);
}

function cleanupFunctions($fileName)
{
    $patterns = [
        '/1080p/i', '/720p/i', '/360p/i', '/DVDRip/i', '/x264/i',
        '/WEBRip/i', '/XXX/i', '/ipt/i', '/MP4/i', '/xvid/i',
        '/team/i', '/(\s+)vs(\s+)/i', '/(Vol\s|Vol\.|\.Vol)/i',
        '/all star/i', '/disc/i', '/disk(\s*)/i', '/cd/i',
        '/\b(\s|\.)cd/i', '/\b(?!Scene_)(Scene\s|scene\.|\.scene|scene)/i',
        '/\b(?<!\s\-\sScene)(\sscene)/i', '/(\s*)\#(\s*)/i',
    ];

    $replacements = [
        '', '', '', '', '', '', '', '', '', '', '', ' vs. ',
        ' ', 'All-Star', 'CD', 'CD', 'CD', ' - CD', 'Scene_',
        ' - Scene', ' # ',
    ];

    // Custom patterns for numeric formatting
    $fileName = preg_replace($patterns, $replacements, trim($fileName));
    $fileName = preg_replace('/(?<!^)(?<!CD)(?<!\_)(?<!\d)([1-9])(?!\d)/i', '0$1', $fileName);
    $fileName = preg_replace('/(?<!^)(?<!CD)(?<!\#\s)(?<!\_)(?<!\d)([0-9]{1,3}(?!\d))/i', '# $1', $fileName);

    return $fileName;
}

function finalCleanup($fileName)
{
    $fileName = preg_replace([
        '/\s+/', // Multiple spaces
        '/\.+/', // Multiple periods
        '/^\.+/',// Leading periods
    ], [
        ' ', '.', ''
    ], trim($fileName));

    return $fileName;
}
?>
