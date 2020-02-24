<?php

function renameTheFilesMissing01(&$titlesArray)
{
    foreach ($titlesArray as &$titleR) {
        $loopNum = 0;

        foreach ($_SESSION['files'] as &$file) {
            $path = $file['path'] . "/";
       
            if ((isset($titleR['fileMissing01'])) && (!preg_match("/ # 01/", ($file['fileName'])))) {
                $tmpFileName = array('','');
                $pattern = '';
                $digit = '';

                if (strcasecmp($file['fileNameNoExtension'], $titleR['title']) == 0) {
                    $newFileName = $file['fileNameNoExtension'] . ' # 01';

                    $position = "in first test";
                    logFile($loopNum, $position, $file['fileNameNoExtension'], $titleR['title'], $newFileName);
                }
                if (stristr($file['fileNameNoExtension'], ' - Scene_', true)) {
                    $tmpFileName = preg_split('/ - Scene_/', $file['fileNameNoExtension']);

                    $newFileName = $tmpFileName[0] . ' # 01';
                    $pattern = ' - Scene_';
                    $digit = $tmpFileName[1];
                    $position = "in - Scene_ test";
                    logFile($loopNum, $position, $file['fileNameNoExtension'], $titleR['title'], $newFileName);
                }
                if (stristr($file['fileNameNoExtension'], ' - CD', true)) {
                    $tmpFileName = preg_split('/ - CD/', $file['fileNameNoExtension']);

                    $newFileName = $tmpFileName[0] . ' # 01';
                    $pattern = ' - CD';
                    $digit = $tmpFileName[1];
                    $position = "in - CD test";
                    logFile($loopNum, $position, $file['fileNameNoExtension'], $titleR['title'], $newFileName);
                }
                    
                str_replace("'", "\'", $newFileName);

                rename($file['fileNameAndPath'], $path . $newFileName . $pattern . $digit . $file['fileExtension']);

                $file['fileName'] = $newFileName. $pattern . $digit . $file['fileExtension'];
                $file['fileNameNoExtension'] = $newFileName;
                $titleR['title'] = $newFileName;
                $position = "after loops";
                logFile($loopNum, $position, $file['fileNameNoExtension'], $titleR['title'], $newFileName);
            }
            $loopNum++;
        }
    }

    // include "safe_json_encode.php";
// echo safe_json_encode($_SESSION["files"]);
}

function logFile($loopNum, $position, $file, $titleR, $newFileName)
{
    $directory = getcwd();
    $myfile = fopen("$directory/log.txt", "a") or die("Unable to open file!");

    $txt = "$loopNum\n$position\n$file\t$titleR\t$newFileName\n\n";

    fwrite($myfile, $txt);
    fclose($myfile);
}
