<?php

/**
 * Helpers for the file-rename endpoint (renameTheFilesToNormalize.php):
 * rename() with a readable failure reason, and the needs-cast staging
 * move-up — after a rename adds a cast to a file sitting in a "needs-cast"
 * folder (scripts/stage_scenes_without_cast.php's staging dir), the file is
 * moved back up one level into the directory it was staged from.
 */

require_once __DIR__ . '/path_guard.php';

if (!function_exists('moviedb_rename_with_reason')) {
    /**
     * rename() that reports WHY it failed: the OS reason pulled from PHP's
     * warning ("Permission denied", …), '' when unavailable.
     */
    function moviedb_rename_with_reason(string $from, string $to, ?string &$reason): bool
    {
        $reason = '';
        error_clear_last();
        if (rename($from, $to)) {
            return true;
        }
        $message = error_get_last()['message'] ?? '';
        // The warning reads "rename(<from>,<to>): <reason>"; the paths may
        // themselves contain "): ", so split on the LAST occurrence.
        $pos = strrpos($message, '): ');
        if ($pos !== false) {
            $reason = trim(substr($message, $pos + 3));
        }
        return false;
    }
}

if (!function_exists('moviedb_move_up_after_rename_enabled')) {
    /**
     * The Settings toggle (app_settings.json "moveRenamedUpFromNeedsCast").
     * Default ON: a missing file, a missing key, or unreadable JSON all mean
     * true — only an explicitly stored false turns the move-up off.
     */
    function moviedb_move_up_after_rename_enabled(?string $settingsFile = null): bool
    {
        $settingsFile = $settingsFile ?? __DIR__ . '/app_settings.json';
        if (!is_file($settingsFile)) {
            return true;
        }
        $data = json_decode((string)@file_get_contents($settingsFile), true);
        return !is_array($data) || ($data['moveRenamedUpFromNeedsCast'] ?? true) !== false;
    }
}

if (!function_exists('moviedb_needs_cast_parent')) {
    /**
     * The directory one level up when $dir IS a needs-cast staging folder
     * (the default --into name of scripts/stage_scenes_without_cast.php),
     * null otherwise. Case-insensitive, matching the macOS filesystem.
     */
    function moviedb_needs_cast_parent(string $dir): ?string
    {
        $dir = rtrim($dir, '/');
        if (strcasecmp(basename($dir), 'needs-cast') !== 0) {
            return null;
        }
        $parent = dirname($dir);
        return ($parent !== '' && $parent !== '.' && $parent !== $dir) ? $parent : null;
    }
}

if (!function_exists('moviedb_ends_with_scene_number')) {
    /**
     * Mirror of endsWithSceneNumber() in src/app/shared/helpers/title.ts: a
     * base name ending in a bare scene number ("Title - Scene_1") still has
     * no cast named after it. Un-normalized spellings count ("scene 2",
     * "Scene-3"); 4+ digits are a year, not a scene ("Crime Scene 1999").
     */
    function moviedb_ends_with_scene_number(string $baseName): bool
    {
        return (bool)preg_match('/\bscene[\s._-]*\d{1,3}\s*$/i', $baseName);
    }
}

if (!function_exists('moviedb_move_renamed_up')) {
    /**
     * After a successful rename inside a needs-cast staging folder, move the
     * file up into the parent directory — a file whose new base name no
     * longer ends in a bare scene number carries its cast now and has
     * graduated from staging. Files still missing a cast (a dismissed group,
     * a title-only edit) deliberately stay staged.
     *
     * Returns null when the move doesn't apply (toggle off, not a needs-cast
     * folder, or the file still lacks a cast); ['movedTo' => parent] on
     * success; ['moveError' => reason] when it applied but failed. Never
     * overwrites: an existing file at the target is an error, not a clobber.
     */
    function moviedb_move_renamed_up(string $dir, string $fileName, ?string $settingsFile = null): ?array
    {
        if (!moviedb_move_up_after_rename_enabled($settingsFile)) {
            return null;
        }
        $dir = rtrim($dir, '/');
        $parent = moviedb_needs_cast_parent($dir);
        if ($parent === null) {
            return null;
        }
        // The endpoint validated this already; re-checked so the helper is
        // safe on its own ("." and ".." never leave the staging dir).
        if (!moviedb_is_plain_filename($fileName)) {
            return null;
        }
        if (moviedb_ends_with_scene_number(pathinfo($fileName, PATHINFO_FILENAME))) {
            return null; // still no cast — not done with staging
        }
        if (!moviedb_is_path_allowed($parent)) {
            return ['moveError' => 'Renamed, but not moved up: the parent directory is outside the allowed base path'];
        }
        $target = $parent . '/' . $fileName;
        if (file_exists($target)) {
            return ['moveError' => "Renamed, but not moved up: \"$fileName\" already exists in $parent"];
        }
        if (!moviedb_rename_with_reason($dir . '/' . $fileName, $target, $reason)) {
            return ['moveError' => 'Renamed, but the move up failed' . ($reason === '' ? '' : ': ' . $reason)];
        }
        return ['movedTo' => $parent];
    }
}
