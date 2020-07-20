<?php

function renameDuplicateFilesMissing01($duplicateTitlesMissing01Array)
{
    session_id("files");
    session_start();

    for ($i = 0; $i < count($duplicateTitlesMissing01Array); $i++) {

        foreach ($_SESSION["files"] as $file) {

            $path = $file['path'] . "\\";

            $tmpFileName = array('', '');
            $pattern = '';
            $digit = '';

            if (strcasecmp($file['fileNameNoExtension'], $duplicateTitlesMissing01Array[$i]['title']) == 0) {

                $newFileName = $file['fileNameNoExtension'] . ' # 01' . $file['fileExtension'];
                $newFileNameAndPath = $path . $newFileName;

                rename($file['fileNameAndPath'], $newFileNameAndPath);

                $destination = $path . "\\duplicates\\";

                if ($duplicateTitlesMissing01Array[$i]["isLarger"] == true) {
                    $destination = $path . "\\duplicates\\larger\\";
                }

                moveDuplicateFilesMissing01($path, $destination, $newFileName);
            }

            if ((stripos($file['fileNameNoExtension'], $duplicateTitlesMissing01Array[$i]['title']) === 0)) {
                if (stristr($file['fileNameNoExtension'], ' - Scene_', true)) {

                    $tmpFileName = preg_split('/ - Scene_/', $file['fileNameNoExtension']);

                    $newFileName = $tmpFileName[0] . ' # 01';
                    $pattern = ' - Scene_';
                    $digit = $tmpFileName[1];
                    $newFileName = $newFileName . $pattern . $digit . $file['fileExtension'];
                    $newFileNameAndPath = $path . $newFileName;

                    rename($file['fileNameAndPath'], $newFileNameAndPath);

                    $destination = $path . "\\duplicates\\";

                    if ($duplicateTitlesMissing01Array[$i]["isLarger"] == true) {
                        $destination = $path . "\\duplicates\\larger\\";
                    }

                    moveDuplicateFilesMissing01($path, $destination, $newFileName);
                }
                if (stristr($file['fileNameNoExtension'], ' - CD', true)) {

                    $tmpFileName = preg_split('/ - CD/', $file['fileNameNoExtension']);

                    $newFileName = $tmpFileName[0] . ' # 01';
                    $pattern = ' - CD';
                    $digit = $tmpFileName[1];
                    $newFileName = $newFileName . $pattern . $digit . $file['fileExtension'];
                    $newFileNameAndPath = $path . $newFileName;

                    rename($file['fileNameAndPath'], $newFileNameAndPath);

                    $destination = $path . "\\duplicates\\";

                    if ($duplicateTitlesMissing01Array[$i]["isLarger"] == true) {
                        $destination = $path . "\\duplicates\\larger\\";
                    }

                    moveDuplicateFilesMissing01($path, $destination, $newFileName);
                }
            }
        }
    }
}

function moveDuplicateFilesMissing01($path, $destination, $newFileName)
{
    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }

    if (!is_file($destination . "\\" . $newFileName)) {
        $file_to_rename = $path . "\\" . $newFileName;
        $rename_file = $destination . $newFileName;

        str_replace("'", "\'", $rename_file);
        echo rename($file_to_rename, $rename_file);
    }
}
