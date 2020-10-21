<?php

$key = $_POST['key'];
$state = $_POST['state'];

// $str = implode("", file('config/optionsAndPaths.php'));
// $fp = fopen('config/optionsAndPaths.php', 'w');
$file = '../config/optionsAndPaths.php';

$optionsAndPaths = array(
    $key => $state,
);

put_ini_file($file, $optionsAndPaths, $i = 0);

function put_ini_file($file, $optionsAndPaths, $i = 0)
{
    $str = "";
    foreach ($array as $k => $v) {
        if (is_array($v)) {
            $str .= str_repeat(" ", $i * 2) . "[$k]" . PHP_EOL;
            $str .= put_ini_file("", $v, $i + 1);
        } else {
            $str .= str_repeat(" ", $i * 2) . "$k = $v" . PHP_EOL;
        }
    }
    if ($file) {
        return file_put_contents($file, $str);
    } else {
        return $str;
    }
}

//change_config_file_settings($filePath, $newSettings);

function change_config_file_settings($filePath, $newSettings)
{

    // Get a list of the variables in the scope before including the file
    $old = get_defined_vars();

    // Include the config file and get it's values
    include($filePath);

    // Get a list of the variables in the scope after including the file
    $new = get_defined_vars();

    // Find the difference - after this, $fileSettings contains only the variables
    // declared in the file
    $fileSettings = array_diff($new, $old);

    // Update $fileSettings with any new values
    $fileSettings = array_merge($fileSettings, $newSettings);

    // Build the new file as a string
    $newFileStr = "<?php\n";
    foreach ($fileSettings as $name => $val) {
        // Using var_export() allows you to set complex values such as arrays and also
        // ensures types will be correct
        $newFileStr .= "\${$name} = " . var_export($val, true) . ";\n";
    }

    // Write it back to the file
    file_put_contents($filePath, $newFileStr);
}

// fwrite($fp, $str, strlen($str));
// fclose($fp);
