<?php

/**
 * Format a size in bytes into a human-readable string.
 *
 * @param int|float $size The size in bytes.
 * @param int $precision Number of decimal places for the formatted size.
 * @return string The formatted size with the appropriate unit.
 */
function formatSize($size, $precision = 2)
{
    if (!is_numeric($size) || $size < 0) {
        return 'Invalid size';
    }

    if ($size >= 1e9) {
        return number_format($size / 1e9, $precision) . ' GB';
    } elseif ($size >= 1e6) {
        return number_format($size / 1e6, $precision) . ' MB';
    } elseif ($size >= 1e3) {
        return number_format($size / 1e3, $precision) . ' KB';
    }

    return $size . ' Bytes';
}
