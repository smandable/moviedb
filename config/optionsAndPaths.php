<?php

$pathsArray = array('/Volumes/Recorded 1/recorded/', '/Volumes/Recorded 2/recorded/', '/Volumes/Recorded 3/recorded/', '/Volumes/Bi-Gay-TS/recorded overflow/', '/Volumes/Misc 1/recorded overflow/');

$returnedArray = array(
  'moveDuplicates' => 'false',
  'updateSizeInDB' => 'false',
  'updateDimensionsInDB' => 'false',
  'updateDurationInDB' => 'false',
  'paths' => array()
);

foreach ($pathsArray as $path) {
    $returnedArray['paths'][] = $path;
}

return $returnedArray;
