<?php

/**
 * ffprobe helpers for processFilesForDB.php.
 *
 * ffprobe is the slow part of a scan — one process per video, and it dominates
 * wall-clock time on fast storage. probeVideosParallel() runs a bounded pool of
 * them concurrently; parseFfprobeOutput() turns one ffprobe JSON blob into
 * [dimensions, duration] using the exact rules the old inline code used, so the
 * results are unchanged — just produced in parallel.
 */

if (!function_exists('ffprobeCmd')) {
    function ffprobeCmd(string $ffprobe, string $filePath): string
    {
        return escapeshellcmd($ffprobe)
            . ' -v error -print_format json -show_entries format=duration -show_entries stream=width,height '
            . escapeshellarg($filePath);
    }
}

if (!function_exists('parseFfprobeOutput')) {
    /**
     * Extract dimensions + duration from one ffprobe JSON blob.
     *
     * @return array{dimensions: string, duration: int}|null
     *         null means the probe failed (no output / invalid JSON); the caller
     *         skips that file, matching the previous behavior.
     */
    function parseFfprobeOutput(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if ($data === null) {
            return null;
        }

        $duration = 0;
        if (isset($data['format']['duration'])) {
            $d = floatval($data['format']['duration']);
            if ($d > 0) {
                $duration = (int) $d; // whole seconds
            }
        }

        $dimensions = '';
        if (isset($data['streams']) && is_array($data['streams'])) {
            foreach ($data['streams'] as $stream) {
                if (isset($stream['width'], $stream['height'])) {
                    $w = $stream['width'] ?? 0;
                    $h = $stream['height'] ?? 0;
                    if ($w > 0 && $h > 0) {
                        $dimensions = $w . ' x ' . $h;
                    }
                    break; // first stream carrying width/height wins
                }
            }
        }

        return ['dimensions' => $dimensions, 'duration' => $duration];
    }
}

if (!function_exists('probeVideosParallel')) {
    /**
     * Probe many files concurrently with a bounded pool. Returns results keyed by
     * the same keys as $jobs; files whose probe failed are omitted.
     *
     * @param array<int|string,string> $jobs key => filePath
     * @return array<int|string,array{dimensions:string,duration:int}>
     */
    function probeVideosParallel(array $jobs, string $ffprobe, int $maxParallel = 8): array
    {
        $results = [];
        if (!$jobs) {
            return $results;
        }

        // Fallback when proc_open is disabled in php.ini: probe sequentially.
        if (!function_exists('proc_open')) {
            foreach ($jobs as $key => $path) {
                $parsed = parseFfprobeOutput(shell_exec(ffprobeCmd($ffprobe, $path) . ' 2>/dev/null'));
                if ($parsed !== null) {
                    $results[$key] = $parsed;
                }
            }
            return $results;
        }

        $maxParallel = max(1, min(32, $maxParallel));
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        foreach (array_chunk(array_keys($jobs), $maxParallel) as $wave) {
            // Launch the whole wave...
            $running = [];
            foreach ($wave as $key) {
                $pipes = [];
                $proc = proc_open(ffprobeCmd($ffprobe, $jobs[$key]), $descriptors, $pipes);
                if (is_resource($proc)) {
                    $running[$key] = ['proc' => $proc, 'pipes' => $pipes];
                }
            }
            // ...then drain it. ffprobe's output is tiny (well under the pipe
            // buffer), so reading serially after launch cannot deadlock.
            foreach ($running as $key => $p) {
                $out = stream_get_contents($p['pipes'][1]);
                stream_get_contents($p['pipes'][2]); // drain stderr
                fclose($p['pipes'][1]);
                fclose($p['pipes'][2]);
                proc_close($p['proc']);

                $parsed = parseFfprobeOutput($out !== false ? $out : null);
                if ($parsed !== null) {
                    $results[$key] = $parsed;
                }
            }
        }

        return $results;
    }
}
