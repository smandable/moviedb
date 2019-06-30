<?php

include('getDimensions.php');
include('getDuration.php');
include('formatSize.php');

$dirName = $_POST['dirName'];

if (isset($_POST['searchPattern '])) {
    $searchPattern  = $_POST['searchPattern'];
} else {
    $searchPattern  = "";
}

$files = array();
$filesProcessed = array();
$filesProcessedSorted = array();

getFiles($dirName, $files);

function getFiles($dirName, $files)
{
    global $files;

    $directory = new \RecursiveDirectoryIterator($dirName);
    $iterator = new \RecursiveIteratorIterator($directory);

    foreach ($iterator as $file) {
        if ($file->getBasename() === '.' || $file->getBasename() === '..' || $file->getBasename() === '.DS_Store'
|| $file->getBasename() === 'Thumbs.db' || $file->getBasename() === '.AppleDouble') {
            continue;
        }
        $path = $file->getPath();
        $originalFileName = $file->getBasename();
        $fileExtension = pathinfo($file->getBasename(), PATHINFO_EXTENSION);
        $fileExtension = "." . $fileExtension;
        $newFileName = $file->getBasename($fileExtension);
        $fileSize = filesize($file->getPathname());

        $files[] = array('Path' => $path, 'originalFileName' => $originalFileName, 'newFileName' => $newFileName, 'fileExtension' => $fileExtension, 'fileSize' => $fileSize);
    }
    processFiles();
}

function processFiles()
{
    global $dirName;
    global $files;
    global $filesProcessed;

    foreach ($files as $file) {
        $path = $file['Path'];
        $originalFileName = $file['originalFileName'];
        $newFileName = $file['newFileName'];
        $fileNameAndPath = $path."/".$originalFileName;
        $fileExtension = $file['fileExtension'];
        $fileSize = $file['fileSize'];

        $formattedSize = formatSize($fileSize);
        $dimensions = getDimensions($fileNameAndPath);
        $duration = getDuration($fileNameAndPath);

        $newFileName = basicFunctions($newFileName);
        $newFileName = titleCase($newFileName);
        $newFileName = cleanupFunctions($newFileName);
        $newFilename = finalCleanup($newFileName);
        $fileWasRenamed = false;
        $fileRenameConflict = false;
        $fileAlreadyExists = false;

        if (checkIfFileExists($originalFileName, $newFileName, $fileExtension, $path) === true) {
            $fileAlreadyExists = true;
        }
        if (checkConflicts($originalFileName, $newFileName, $fileExtension, $path, $fileAlreadyExists) === true) {
            $fileRenameConflict = true;
        }

        $newFileName = $newFileName.$fileExtension;

        $filesProcessed[] = array('Path' => $path, 'originalFileName' => $originalFileName, 'newFileName' => $newFileName, 'fileAlreadyExists' => $fileAlreadyExists, 'fileRenameConflict' => $fileRenameConflict, 'fileWasRenamed' => $fileWasRenamed, 'fileExtension' => $fileExtension, 'Size' => $formattedSize, 'Dimensions' => $dimensions, 'Duration' => $duration);
    }
}

function basicFunctions($newFileName)
{
    $newFileName = preg_replace('/\./', ' ', trim($newFileName)); // periods to spaces
    $newFileName = preg_replace('/_/', ' ', trim($newFileName)); // underscores to spaces
    $newFileName = preg_replace('/-/', ' ', trim($newFileName)); // dash to space
    $newFileName = preg_replace('/\s{3}/', ' - ', trim($newFileName)); // 3 spaces to ' - '
    $newFileName = preg_replace('/\s+/', ' ', trim($newFileName)); // filter multiple spaces
    $newFileName = preg_replace('/\.+/', '.', trim($newFileName)); // filter multiple periods
    $newFileName = preg_replace('/^\.+/', '', trim($newFileName)); // trim leading period

return $newFileName;
}

function titleCase($newFileName)
{
    $delimiters = array(" ");
    $exceptions = array("the", "a", "an", "as", "at", "be", "but", "by", "for", "in", "of", "off", "on", "per", "to", "up", "via", "and", "nor", "or", "so", "yet", "with", "vs", "BBC", "OMG", "CD", "POV", "MILF", "XXX");
    /*
     * Exceptions in lower case are words you don't want converted
     * Exceptions all in upper case are any words you don't want converted to title case
     *   but should be converted to upper case, e.g.:
     *   king henry viii or king henry Viii should be King Henry VIII
     */
    $newFileName = mb_convert_case($newFileName, MB_CASE_TITLE, "UTF-8");
    foreach ($delimiters as $dlnr => $delimiter) {
        $words = explode($delimiter, $newFileName);
        $newwords = array();
        foreach ($words as $wordnr => $word) {
            if (in_array(mb_strtoupper($word, "UTF-8"), $exceptions)) {
                // check exceptions list for any words that should be in upper case
                $word = mb_strtoupper($word, "UTF-8");
            } elseif (in_array(mb_strtolower($word, "UTF-8"), $exceptions)) {
                // check exceptions list for any words that should be in upper case
                $word = mb_strtolower($word, "UTF-8");
            } elseif (!in_array($word, $exceptions)) {
                // convert to uppercase (non-utf8 only)
                $word = ucfirst($word);
            }
            array_push($newwords, $word);
        }
        $newFileName = join($delimiter, $newwords);
    }

    $newFileName = preg_replace('/^(the)\s/', 'The ', trim($newFileName)); // if 'the ' is at the beginning of the string, replace with "The "
    $newFileName = preg_replace('/^(a)\s/', 'A ', trim($newFileName)); // if 'a ' is at the beginning of the string, replace with "A "
    $newFileName = preg_replace('/^(so)\s/', 'So ', trim($newFileName)); // if 'so ' is at the beginning of the string, replace with "So "

    return $newFileName;
}

function cleanupFunctions($newFileName)
{
    $newFileName = preg_replace('/1080p/i', '', $newFileName); // Look for '1080p', replace with nothing
    $newFileName = preg_replace('/720p/i', '', trim($newFileName)); // Look for '720p', replace with nothing
    $newFileName = preg_replace('/DVDRip/i', '', trim($newFileName)); // Look for 'DVDRip', replace with nothing
    $newFileName = preg_replace('/x264/i', '', trim($newFileName)); // Look for 'x264', replace with nothing
    $newFileName = preg_replace('/XCITE/i', '', trim($newFileName)); // Look for 'XCITE', replace with nothing
    $newFileName = preg_replace('/DigitalSin/i', '', trim($newFileName)); // Look for 'DigitalSin', replace with nothing
    $newFileName = preg_replace('/WEBRip/i', '', trim($newFileName)); // Look for 'WEBRip', replace with nothing
    $newFileName = preg_replace('/VSEX/i', '', trim($newFileName)); // Look for 'VSEX', replace with nothing
    $newFileName = preg_replace('/XXX/i', '', trim($newFileName)); // Look for 'XXX', replace with nothing
    $newFileName = preg_replace('/^gush\./i', '', trim($newFileName)); // Look for 'gush.', replace with nothing
    $newFileName = preg_replace('/(\sVol|Vol\s|Vol\.|\.Vol|Vol)/i', ' ', trim($newFileName)); // ' Vol' or 'Vol ' or 'Vol.' or '.Vol' or 'Vol' to ' '
    $newFileName = preg_replace('/all star/i', 'All-Star', trim($newFileName)); // 'all star' to All-Star
    //$newFileName = preg_replace('/cant/i', 'Can\'t, trim($newFileName)); // 'cant' to Can't
    $newFileName = preg_replace('/disc/i', 'CD', trim($newFileName)); // 'disc' to CD
    $newFileName = preg_replace('/disk(\s*)/i', 'CD', trim($newFileName)); // 'disk' or 'disk ' to CD
    $newFileName = preg_replace('/cd/i', 'CD', trim($newFileName)); // 'cd' to CD
    $newFileName = preg_replace('/\b(?<! \- )(\s|\.)cd/i', ' - CD', trim($newFileName)); // space or single period 'cd' to ' - CD'
    $newFileName = preg_replace('/\b(?!Scene_)(Scene\s|scene\.|\.scene|scene)/i', 'Scene_', trim($newFileName)); // 'scene ' or 'scene.' or '.scene' or 'scene' to 'Scene_'
    $newFileName = preg_replace('/\b(?<!\s\-\sScene)(\sscene)/i', ' - Scene', trim($newFileName)); // Not following a ' - Scene', replace ' scene' with ' - Scene'
    $newFileName = preg_replace('/(\s*)\#(\s*)/i', ' # ', trim($newFileName)); // '#' to ' # '

    $pattern1 = '/(?<!^)(?<!CD)(?<!\_)(?<!\d)([1-9])(?!\d)/i'; // Not following an underscore or a digit or 'CD', a digit from 1-9 without a digit following
    $rep1 = '0$1'; // ' 0'
    $newFileName = preg_replace($pattern1, $rep1, $newFileName); //replace the first backreference with ' # 0'

    $pattern2 = '/(?<!^)(?<!CD)(?<!\#\s)(?<!\_)(?<!\d)([0-9]{1,3}?(?!\d))/i'; // Not following a '#  ' or '_' or 'CD',  1-3 digits from 0-9 without a digit following
    $rep2 = '# $1'; // ' # 0'
    $newFileName = preg_replace($pattern2, $rep2, $newFileName); //replace the first backreference with ' # '

    return $newFileName;
}

function finalCleanup($newFileName)
{
    $newFileName = preg_replace('/\s+/', ' ', trim($newFileName)); // filter multiple spaces
    $newFileName = preg_replace('/\.+/', '.', trim($newFileName)); // filter multiple periods
    $newFileName = preg_replace('/^\.+/', '', trim($newFileName)); // trim leading period

  return $newFileName;
}

function checkIfFileExists($originalFileName, $newFileName, $fileExtension, $path)
{
    $path = $path . "/";

    $pathAndOriginalFileName = $path.$originalFileName;
    $newFileNameAndExt = $newFileName.$fileExtension;
    $pathAndNewFileName = $path.$newFileNameAndExt;

    //Ok so the question here is whether I care if an existing file matches.
    //if ((file_exists($pathAndNewFileName)) || (strcmp($originalFileName, $newFileNameAndExt)==0)) {
    //I COULD add another property to the obj...
    if (strcmp($originalFileName, $newFileNameAndExt) == 0) {
        return true;
    }
}

function checkConflicts($originalFileName, $newFileName, $fileExtension, $path, $fileAlreadyExists)
{
    $path = $path . "/";

    $pathAndOriginalFileName = $path.$originalFileName;
    $newFileNameAndExt = $newFileName.$fileExtension;
    $pathAndNewFileName = $path.$newFileNameAndExt;

    if ($fileAlreadyExists === false) {
        if (file_exists($pathAndNewFileName)) {
            return true;
        }
    }
}

//I'm thinking of re-running this for cleanup's sake, I mean if something in cleanupFunctions() causes there to be multiple spaces or something...
//getFiles($dirName, $files);

foreach ($filesProcessed as $key => $row) {
    $filesProcessedSorted[$key] = $row['newFileName'];
}

array_multisort($filesProcessedSorted, SORT_ASC, $filesProcessed);

returnHTML($filesProcessed);

function returnHTML($filesProcessed)
{
    // ob_start();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['filesProcessed'] = $filesProcessed;
    // ob_end_flush();
    echo json_encode($filesProcessed);
}
