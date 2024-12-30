<?php

function safe_json_encode($value, $options = 0, $depth = 512, $utfErrorFlag = false)
{
    $encoded = json_encode($value, $options, $depth);

    if (json_last_error() === JSON_ERROR_NONE) {
        return $encoded;
    }

    switch (json_last_error()) {
        case JSON_ERROR_DEPTH:
            $errorMsg = 'Maximum stack depth exceeded';
            break;
        case JSON_ERROR_STATE_MISMATCH:
            $errorMsg = 'Underflow or the modes mismatch';
            break;
        case JSON_ERROR_CTRL_CHAR:
            $errorMsg = 'Unexpected control character found';
            break;
        case JSON_ERROR_SYNTAX:
            $errorMsg = 'Syntax error, malformed JSON';
            break;
        case JSON_ERROR_UTF8:
            // Try to fix the data and re-encode
            if (!$utfErrorFlag) {
                $clean = utf8ize($value);
                return safe_json_encode($clean, $options, $depth, true);
            }
            $errorMsg = 'UTF8 encoding error';
            break;
        default:
            $errorMsg = 'Unknown JSON encoding error';
            break;
    }

    // If you prefer to return a JSON object indicating an error:
    // return json_encode(["error" => $errorMsg]);

    return $errorMsg;
}

function utf8ize($mixed)
{
    if (is_array($mixed)) {
        foreach ($mixed as $key => $value) {
            $mixed[$key] = utf8ize($value);
        }
    } elseif (is_string($mixed)) {
        // Using utf8_encode() might be limiting. For a more robust solution:
        // $mixed = mb_convert_encoding($mixed, 'UTF-8', 'auto');
        return utf8_encode($mixed);
    }
    return $mixed;
}
