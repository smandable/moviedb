<?php

// if (isset($_POST['searchPattern '])) {
//     $searchPattern  = $_POST['searchPattern'];
// } else {
//     $searchPattern  = "";
// }

session_id("files");
session_start();

//var_dump($_SESSION["files"]);

checkFiles();

returnHTML();

function checkFiles()
{
    foreach ($_SESSION["files"] as &$file) {
        $path = $file["path"];
        $fileName = $file["fileName"];
        $fileExtension = $file["fileExtension"];
        $fileNameNoExtension = $file["fileNameNoExtension"];
        $fileShouldBeRenamed = false;

        $fileNameNoExtension = basicFunctions($fileNameNoExtension);
        $fileNameNoExtension = titleCase($fileNameNoExtension);
        $fileNameNoExtension = cleanupFunctions($fileNameNoExtension);
        $fileNameNoExtension = finalCleanup($fileNameNoExtension);

        $newFileName = $fileNameNoExtension . $fileExtension;

        if (strcmp($fileName, $newFileName) !== 0) {
            $file["newFileName"] = $newFileName;
        }
    }
}

function basicFunctions($fileNameNoExtension)
{
    $fileNameNoExtension = preg_replace('/\./', ' ', trim($fileNameNoExtension)); // periods to spaces
    $fileNameNoExtension = preg_replace('/_/', ' ', trim($fileNameNoExtension)); // underscores to spaces
    $fileNameNoExtension = preg_replace('/-/', ' ', trim($fileNameNoExtension)); // dash to space
    $fileNameNoExtension = preg_replace('/\s{3}/', ' - ', trim($fileNameNoExtension)); // 3 spaces to ' - '
    $fileNameNoExtension = preg_replace('/\s+/', ' ', trim($fileNameNoExtension)); // filter multiple spaces
    $fileNameNoExtension = preg_replace('/\.+/', '.', trim($fileNameNoExtension)); // filter multiple periods
    $fileNameNoExtension = preg_replace('/^\.+/', '', trim($fileNameNoExtension)); // trim leading period

    return $fileNameNoExtension;
}

function titleCase($fileNameNoExtension)
{
    $delimiters = array(" ");
    $exceptions = array("the", "a", "an", "as", "at", "be", "but", "by", "for", "in", "of", "off", "on", "per", "to", "up", "via", "and", "nor", "or", "so", "yet", "with", "vs", "BBC", "OMG", "CD", "POV", "MILF", "XXX");
    /*
     * Exceptions in lower case are words you don't want converted
     * Exceptions all in upper case are any words you don't want converted to title case
     *   but should be converted to upper case, e.g.:
     *   king henry viii or king henry Viii should be King Henry VIII
     */
    $fileNameNoExtension = mb_convert_case($fileNameNoExtension, MB_CASE_TITLE, "UTF-8");
    foreach ($delimiters as $dlnr => $delimiter) {
        $words = explode($delimiter, $fileNameNoExtension);
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
        $fileNameNoExtension = join($delimiter, $newwords);
    }

    $fileNameNoExtension = preg_replace('/^(the)\s/', 'The ', trim($fileNameNoExtension)); // if 'the ' is at the beginning of the string, replace with "The "
    $fileNameNoExtension = preg_replace('/^(a)\s/', 'A ', trim($fileNameNoExtension)); // if 'a ' is at the beginning of the string, replace with "A "
    $fileNameNoExtension = preg_replace('/^(so)\s/', 'So ', trim($fileNameNoExtension)); // if 'so ' is at the beginning of the string, replace with "So "

    return $fileNameNoExtension;
}

function cleanupFunctions($fileNameNoExtension)
{
    $fileNameNoExtension = preg_replace('/1080p/i', '', $fileNameNoExtension); // Look for '1080p', replace with nothing
    $fileNameNoExtension = preg_replace('/720p/i', '', trim($fileNameNoExtension)); // Look for '720p', replace with nothing
    $fileNameNoExtension = preg_replace('/DVDRip/i', '', trim($fileNameNoExtension)); // Look for 'DVDRip', replace with nothingcheckFileNamesToNormalize
    $fileNameNoExtension = preg_replace('/x264/i', '', trim($fileNameNoExtension)); // Look for 'x264', replace with nothing
    $fileNameNoExtension = preg_replace('/XCITE/i', '', trim($fileNameNoExtension)); // Look for 'XCITE', replace with nothing
    $fileNameNoExtension = preg_replace('/KTR/i', '', trim($fileNameNoExtension)); // Look for 'KTR', replace with nothing
    $fileNameNoExtension = preg_replace('/DigitalSin/i', '', trim($fileNameNoExtension)); // Look for 'DigitalSin', replace with nothing
    $fileNameNoExtension = preg_replace('/WEBRip/i', '', trim($fileNameNoExtension)); // Look for 'WEBRip', replace with nothing
    $fileNameNoExtension = preg_replace('/VSEX/i', '', trim($fileNameNoExtension)); // Look for 'VSEX', replace with nothing
    $fileNameNoExtension = preg_replace('/XXX/i', '', trim($fileNameNoExtension)); // Look for 'XXX', replace with nothing
    $fileNameNoExtension = preg_replace('/MP4/i', '', trim($fileNameNoExtension)); // Look for 'MP4', replace with nothing
    $fileNameNoExtension = preg_replace('/^gush\./i', '', trim($fileNameNoExtension)); // Look for 'gush.', replace with nothing
    $fileNameNoExtension = preg_replace('/(\sVol|Vol\s|Vol\.|\.Vol|Vol)/i', ' ', trim($fileNameNoExtension)); // ' Vol' or 'Vol ' or 'Vol.' or '.Vol' or 'Vol' to ' '
    $fileNameNoExtension = preg_replace('/all star/i', 'All-Star', trim($fileNameNoExtension)); // 'all star' to All-Star
    //$fileNameNoExtension = preg_replace('/cant/i', 'Can\'t, trim($fileNameNoExtension)); // 'cant' to Can't
    $fileNameNoExtension = preg_replace('/disc/i', 'CD', trim($fileNameNoExtension)); // 'disc' to CD
    $fileNameNoExtension = preg_replace('/disk(\s*)/i', 'CD', trim($fileNameNoExtension)); // 'disk' or 'disk ' to CD
    $fileNameNoExtension = preg_replace('/cd/i', 'CD', trim($fileNameNoExtension)); // 'cd' to CD
    $fileNameNoExtension = preg_replace('/\b(?<! \- )(\s|\.)cd/i', ' - CD', trim($fileNameNoExtension)); // space or single period 'cd' to ' - CD'
    $fileNameNoExtension = preg_replace('/\b(?!Scene_)(Scene\s|scene\.|\.scene|scene)/i', 'Scene_', trim($fileNameNoExtension)); // 'scene ' or 'scene.' or '.scene' or 'scene' to 'Scene_'
    $fileNameNoExtension = preg_replace('/\b(?<!\s\-\sScene)(\sscene)/i', ' - Scene', trim($fileNameNoExtension)); // Not following a ' - Scene', replace ' scene' with ' - Scene'
    $fileNameNoExtension = preg_replace('/(\s*)\#(\s*)/i', ' # ', trim($fileNameNoExtension)); // '#' to ' # '

    $pattern1 = '/(?<!^)(?<!CD)(?<!\_)(?<!\d)([1-9])(?!\d)/i'; // Not following an underscore or a digit or 'CD', a digit from 1-9 without a digit following
    $rep1 = '0$1'; // ' 0'
    $fileNameNoExtension = preg_replace($pattern1, $rep1, $fileNameNoExtension); //replace the first backreference with ' # 0'

    $pattern2 = '/(?<!^)(?<!CD)(?<!\#\s)(?<!\_)(?<!\d)([0-9]{1,3}?(?!\d))/i'; // Not following a '#  ' or '_' or 'CD',  1-3 digits from 0-9 without a digit following
    $rep2 = '# $1'; // ' # 0'
    $fileNameNoExtension = preg_replace($pattern2, $rep2, $fileNameNoExtension); //replace the first backreference with ' # '

    return $fileNameNoExtension;
}

function finalCleanup($fileNameNoExtension)
{
    $fileNameNoExtension = preg_replace('/\s+/', ' ', trim($fileNameNoExtension)); // filter multiple spaces
    $fileNameNoExtension = preg_replace('/\.+/', '.', trim($fileNameNoExtension)); // filter multiple periods
    $fileNameNoExtension = preg_replace('/^\.+/', '', trim($fileNameNoExtension)); // trim leading period

    return $fileNameNoExtension;
}

function returnHTML()
{
    include "safe_json_encode.php";
    echo safe_json_encode($_SESSION["files"]);

    // session_id("files");
    // session_start();
    // $_SESSION["files"] = $files;
}
