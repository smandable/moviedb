<?php

// current directory
echo "Current working directory:" . getcwd() . "\n";

//$path = "/Users/sean/Download/test/";

//$path = getcwd();
$path = "/Volumes/Recorded 1/test/";

foreach (new DirectoryIterator($path) as $fileInfo) {
    if ($fileInfo->isDir() || $fileInfo->isDot() || $fileInfo->getBasename() === '.DS_Store') {
        continue;
    }

    $fileName = $fileInfo->getBasename();

    //$baseName = $fileInfo->getBasename();
    $fileNameAndPath = $fileInfo->getPathname();
    $fileSize = filesize($fileInfo->getPathname());

    $formattedSize = formatSize($fileSize);
    $duration = getDuration($fileNameAndPath);
    $dimensions = getDimensions($fileNameAndPath);
    //$files[] = array('Name' => $fileName, 'baseName' => $baseName, 'Dimensions' => $dimensions, 'Size' => $fileSize, 'Path' => $fileNameAndPath);

    echo $fileName . "\t\t" . $formattedSize . "\t\t" . $duration . "\t\t" . $dimensions . "\n";
createHTMLFile($path, $fileName, $formattedSize, $dimensions, $duration);

}

function getDimensions($fileNameAndPath)
{
    $path = $fileNameAndPath;
    $path = str_replace("'", "'\''", $path);

    exec("/usr/local/bin/ffprobe -v error -show_entries stream=width,height -of default=noprint_wrappers=1 '$path'", $O, $S);
    if (!empty($O)) {
        $dimensions = [
                "width"=>explode("=", $O[0])[1],
                "height"=>explode("=", $O[1])[1],
        ];

        $dimensionsString = $dimensions["width"] . " x " . $dimensions["height"];
    } else {
        $dimensionsString = "";
    }
    return $dimensionsString;
}

function getDuration($file)
{
    $path = $file;
    $path = str_replace("'", "'\''", $path);

    $duration = exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 -sexagesimal '$path'");

    return $duration;
}

function createHTMLFile($path, $fileName, $formattedSize, $dimensions, $duration)
{

        $myfile = fopen("$path/fileInfo.html", "a") or die("Unable to open file!");

        $txt = <<<HTML

        HTML;




    fwrite($myfile, $txt);
    fclose($myfile);
}

function formatSize($size)
{
    if ($size >= 1073741824) {
        $size = number_format($size / 1073741824, 2) . ' GB';
    } elseif ($size >= 1048576) {
        $size = number_format($size / 1048576, 2) . ' MB';
    } elseif ($size >= 1024) {
        $size = number_format($size / 1024, 2) . ' KB';
    }
    return $size;
}
