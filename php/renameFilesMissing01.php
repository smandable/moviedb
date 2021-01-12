<?php

function renameFilesMissing01($titlesMissing01Array)
{
    // session_id("files");
    // session_start();

    for ($i = 0; $i < count($titlesMissing01Array); $i++) {

        foreach ($_SESSION["files"] as $file) {

            $path = $file['path'] . "/";
            $tmpFileName = array('', '');
            $pattern = '';
            $digit = '';

            if (strcasecmp($file['fileNameNoExtension'], $titlesMissing01Array[$i]['title']) == 0) {

                $newFileName = $file['fileNameNoExtension'] . ' # 01' . $file['fileExtension'];
                $newFileNameAndPath = $path . $newFileName;

                rename($file['fileNameAndPath'], $newFileNameAndPath);
            }

            if ((stripos($file['fileNameNoExtension'], $titlesMissing01Array[$i]['title']) === 0)) {

                if (stristr($file['fileNameNoExtension'], ' - Scene_', true)) {

                    $tmpFileName = preg_split('/ - Scene_/', $file['fileNameNoExtension']);

                    $newFileName = $tmpFileName[0] . ' # 01';
                    $pattern = ' - Scene_';
                    $digit = $tmpFileName[1];
                    $newFileName = $newFileName . $pattern . $digit . $file['fileExtension'];
                    $newFileNameAndPath = $path . $newFileName;

                    rename($file['fileNameAndPath'], $newFileNameAndPath);
                }
                if (stristr($file['fileNameNoExtension'], ' - CD', true)) {

                    $tmpFileName = preg_split('/ - CD/', $file['fileNameNoExtension']);

                    $newFileName = $tmpFileName[0] . ' # 01';
                    $pattern = ' - CD';
                    $digit = $tmpFileName[1];
                    $newFileName = $newFileName . $pattern . $digit . $file['fileExtension'];
                    $newFileNameAndPath = $path . $newFileName;

                    rename($file['fileNameAndPath'], $newFileNameAndPath);
                }
            }
        }
    }
}
