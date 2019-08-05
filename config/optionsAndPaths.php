<?php

// $pathsArray = array('/Volumes/Recorded 1/recorded/', '/Volumes/Recorded 2/recorded/', '/Volumes/Recorded 3/recorded/', '/Volumes/Bi-Gay-TS/recorded overflow/', '/Volumes/Misc 1/recorded overflow/');
// $pathsArray = array(
//   '/Users/sean/Download/test/recorded 1/',
//   '/Users/sean/Download/test/recorded 2/',
//   '/Users/sean/Download/test/recorded 3/',
//   '/Users/sean/Download/test/recorded 4/',
//   '/Users/sean/Download/test/recorded 5/',
//   '/Users/sean/Download/test/recorded 6/'
// );

$pathsArray = array(
  '/Volumes/Recorded 1/recorded/',
  '/Volumes/Recorded 2/recorded/',
  // '/Volumes/Recorded 3/recorded/',
  '/Volumes/Bi-Gay-TS/recorded/',
  '/Volumes/Misc 1/recorded/'
);

// $pathsArray = array(
//   '/Volumes/Recorded 1/recorded/'
// );



// $optionsArray = array(
//   'moveDuplicates' => 'false',
//   'updateDimensionsInDB' => 'false',
//   'updateDurationInDB' => 'false',
//   'updatePathInDB' => 'false',
//   'updateSizeInDB' => 'true'
// );

$optionsAndPaths = array(
  'moveDuplicates' => 'false',
  'updateDimensionsInDB' => 'true',
  'updateDurationInDB' => 'true',
  'updatePathInDB' => 'true',
  'updateSizeInDB' => 'true',
  'moveRecorded' => 'false',
  'pathsToProcess' => array()
);

// $optionsAndPaths = MergeArrays($optionsArray, $pathsArray);
//
// function MergeArrays($optionsArray, $pathsArray)
// {
//     foreach ($pathsArray as $key => $Value) {
//         if (array_key_exists($key, $optionsArray) && is_array($Value)) {
//             $optionsArray[$key] = MergeArrays($optionsArray[$key], $pathsArray[$key]);
//         } else {
//             $optionsArray[$key] = $Value;
//         }
//     }
//
//     return $optionsArray;
// }

foreach ($pathsArray as $path) {
    $optionsAndPaths['pathsToProcess'][] = $path;
}

return $optionsAndPaths;
