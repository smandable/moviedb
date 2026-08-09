<?php

/**
 * Consolidation endpoint: spawns and monitors scripts/consolidate_movies.php
 * (the episodic-file consolidator). All logic lives in consolidate_lib.php
 * and the script; this file is transport plus the detached spawn.
 *
 * POST { action: 'status' }                  -> { running, pid, lastRun, settings }
 * POST { action: 'run', execute: bool }      -> { started, pid?, message? }
 * POST { action: 'progress' }                -> { active, ...progress fields }
 * POST { action: 'logTail', lines?: number } -> { lines: string[] }
 *
 * Runtime files (all gitignored, all fixed server-side constants — nothing
 * from the request body reaches the shell except the boolean execute flag
 * choosing a literal '--execute' / '--dry-run'):
 *   server/consolidate_progress.json (+ .last / .lock / .pid)
 *   server/consolidate_last_run.tsv
 *   server/consolidate_spawn.log
 */

require_once __DIR__ . '/consolidate_lib.php';

ini_set('display_errors', '0');
header('Content-Type: application/json');

const MOVIEDB_CONSOLIDATE_PROGRESS_FILE  = __DIR__ . '/consolidate_progress.json';
const MOVIEDB_CONSOLIDATE_SPAWN_LOG_FILE = __DIR__ . '/consolidate_spawn.log';
const MOVIEDB_CONSOLIDATE_LOG_FILE       = __DIR__ . '/consolidate_last_run.tsv';
const MOVIEDB_CONSOLIDATE_SCRIPT_FILE    = __DIR__ . '/../scripts/consolidate_movies.php';
const MOVIEDB_CONSOLIDATE_SETTINGS_FILE  = __DIR__ . '/app_settings.json';
const MOVIEDB_CONSOLIDATE_STALE_SECONDS  = 300;

/**
 * [running(bool), pid(int|null)] — the flock is the oracle: the kernel
 * releases it on ANY process death, while a pidfile can go stale (shutdown
 * handlers don't run on SIGKILL/power loss) and posix_kill(pid, 0) answers
 * true for any same-user process after PID reuse — trusting the pidfile
 * first wedged 'run' at 409 indefinitely. The pid is advisory detail only;
 * a stale pidfile is cleaned up under the probe lock (safe: the child writes
 * its pidfile only after taking this same lock).
 */
function moviedb_consolidate_running(): array
{
    $pidFile = MOVIEDB_CONSOLIDATE_PROGRESS_FILE . '.pid';
    $pid = is_file($pidFile) ? (int) trim((string) @file_get_contents($pidFile)) : 0;

    $fh = @fopen(MOVIEDB_CONSOLIDATE_PROGRESS_FILE . '.lock', 'c');
    if ($fh === false) {
        // Can't probe: fall back to the (weaker) pid signal
        return [$pid > 0 && moviedb_consolidate_pid_alive($pid), $pid > 0 ? $pid : null];
    }
    $acquired = flock($fh, LOCK_EX | LOCK_NB);
    if (!$acquired) {
        fclose($fh);
        return [true, $pid > 0 ? $pid : null];
    }
    if ($pid > 0) {
        @unlink($pidFile); // stale — its process no longer holds the lock
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return [false, null];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
$data = is_array($data) ? $data : [];
$action = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';

// Only 'run' mutates. A custom header forces a CORS preflight a hostile page
// won't be granted, killing blind cross-site POSTs (see driveIndex.php).
if ($action === 'run' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Missing X-Requested-With header']);
    exit();
}

switch ($action) {
    case 'status':
        [$running, $pid] = moviedb_consolidate_running();
        $lastRaw = @file_get_contents(MOVIEDB_CONSOLIDATE_PROGRESS_FILE . '.last');
        $lastRun = $lastRaw === false ? null : json_decode($lastRaw, true);
        echo json_encode([
            'running'  => $running,
            'pid'      => $pid,
            'lastRun'  => is_array($lastRun) ? $lastRun : null,
            'settings' => moviedb_consolidate_settings(MOVIEDB_CONSOLIDATE_SETTINGS_FILE),
        ]);
        break;

    case 'run':
        // Serialize concurrent 'run' POSTs on a spawn guard: without it, two
        // simultaneous requests both pass the running-probe (the run lock is
        // taken by the CHILD, moments after spawn) and both report started.
        $spawnGuard = @fopen(MOVIEDB_CONSOLIDATE_PROGRESS_FILE . '.spawnlock', 'c');
        if ($spawnGuard === false || !flock($spawnGuard, LOCK_EX)) {
            http_response_code(500);
            echo json_encode(['started' => false, 'message' => 'Could not acquire the spawn guard']);
            break;
        }

        [$running, $pid] = moviedb_consolidate_running();
        if ($running) {
            flock($spawnGuard, LOCK_UN);
            fclose($spawnGuard);
            http_response_code(409);
            echo json_encode(['started' => false, 'message' => 'A consolidation run is already in progress']);
            break;
        }

        $httpCode = 200;
        $resp = null;
        do {
            $settings = moviedb_consolidate_settings(MOVIEDB_CONSOLIDATE_SETTINGS_FILE);
            $check = moviedb_validate_consolidate_settings($settings);
            if (!$check['ok']) {
                $httpCode = 400;
                $resp = ['started' => false, 'message' => 'Invalid consolidate settings: ' . $check['error']];
                break;
            }
            $missingDrive = null;
            foreach ($check['clean']['drives'] as $drive) {
                if (!is_dir($drive)) {
                    $missingDrive = $drive;
                    break;
                }
            }
            if ($missingDrive !== null) {
                $httpCode = 400;
                $resp = ['started' => false, 'message' => "Drive directory is missing (unmounted?): $missingDrive"];
                break;
            }

            $script = realpath(MOVIEDB_CONSOLIDATE_SCRIPT_FILE);
            if ($script === false) {
                $httpCode = 500;
                $resp = ['started' => false, 'message' => 'Consolidation script not found'];
                break;
            }

            // Previous run's sidecar would read as a stale/finished record —
            // drop it so 'progress' never reports the old run as this one.
            // The spawn log holds one run only (it is the startup-failure
            // diagnostic; unbounded append was just noise).
            @unlink(MOVIEDB_CONSOLIDATE_PROGRESS_FILE);
            @unlink(MOVIEDB_CONSOLIDATE_SPAWN_LOG_FILE);

            $execute = ($data['execute'] ?? false) === true;
            $spawnedAt = time();
            $cmd = 'nohup /usr/bin/env php ' . escapeshellarg($script)
                . ' --settings=' . escapeshellarg(MOVIEDB_CONSOLIDATE_SETTINGS_FILE)
                . ' --progress-file=' . escapeshellarg(MOVIEDB_CONSOLIDATE_PROGRESS_FILE)
                . ' ' . ($execute ? '--execute' : '--dry-run')
                // Consolidation invalidates the drive index's paths; the script
                // rebuilds it in-run (no-op for a dry run or a no-change run).
                . ' --rebuild-index'
                . ' >> ' . escapeshellarg(MOVIEDB_CONSOLIDATE_SPAWN_LOG_FILE) . ' 2>&1 & echo $!';
            $out = [];
            $code = 0;
            exec($cmd, $out, $code);
            $spawnedPid = (int) trim((string) end($out));
            if ($code !== 0 || $spawnedPid <= 0) {
                $httpCode = 500;
                $resp = ['started' => false, 'message' => 'Failed to spawn the consolidation run'];
                break;
            }

            // Verify the child actually came up before reporting started:
            // proof is its run lock being held, its pidfile, or a progress
            // sidecar from this spawn (a fast run may already be done).
            $started = false;
            for ($i = 0; $i < 20; $i++) {
                usleep(100000);
                if (
                    is_file(MOVIEDB_CONSOLIDATE_PROGRESS_FILE . '.pid')
                    || (is_file(MOVIEDB_CONSOLIDATE_PROGRESS_FILE)
                        && @filemtime(MOVIEDB_CONSOLIDATE_PROGRESS_FILE) >= $spawnedAt)
                ) {
                    $started = true;
                    break;
                }
                [$aliveNow, ] = moviedb_consolidate_running();
                if ($aliveNow) {
                    $started = true;
                    break;
                }
            }
            if (!$started) {
                $httpCode = 500;
                $resp = [
                    'started' => false,
                    'message' => 'The run exited during startup: '
                        . implode(' | ', moviedb_consolidate_tail_lines(MOVIEDB_CONSOLIDATE_SPAWN_LOG_FILE, 5)),
                ];
                break;
            }
            $resp = ['started' => true, 'pid' => $spawnedPid];
        } while (false);

        flock($spawnGuard, LOCK_UN);
        fclose($spawnGuard);
        if ($httpCode !== 200) {
            http_response_code($httpCode);
        }
        echo json_encode($resp);
        break;

    case 'progress':
        $raw = @file_get_contents(MOVIEDB_CONSOLIDATE_PROGRESS_FILE);
        $p = $raw === false ? null : json_decode($raw, true);
        $stale = !is_array($p) || (time() - (int) ($p['at'] ?? 0)) > MOVIEDB_CONSOLIDATE_STALE_SECONDS;
        if ($stale) {
            // A single cross-volume mv of a 25-38GB file blocks the script —
            // and the sidecar — for longer than the staleness window. The
            // process itself is the oracle: while it is alive the run is
            // active (stalled:true so the UI can say "still moving …"), and
            // only a genuinely dead run reads inactive.
            [$aliveNow, ] = moviedb_consolidate_running();
            if (!$aliveNow) {
                echo json_encode(['active' => false]);
                break;
            }
            $p = is_array($p) ? $p : [];
            $p['stalled'] = true;
        }
        $fields = [
            'at'          => (int) ($p['at'] ?? 0),
            'phase'       => (string) ($p['phase'] ?? ''),
            'group'       => (string) ($p['group'] ?? ''),
            'groupsDone'  => (int) ($p['groupsDone'] ?? 0),
            'groupsTotal' => (int) ($p['groupsTotal'] ?? 0),
            'currentSrc'  => (string) ($p['currentSrc'] ?? ''),
            'currentDest' => (string) ($p['currentDest'] ?? ''),
            'movedBytes'  => (int) ($p['movedBytes'] ?? 0),
            'moved'       => (int) ($p['moved'] ?? 0),
            'duped'       => (int) ($p['duped'] ?? 0),
            'failed'      => (int) ($p['failed'] ?? 0),
            'skipped'     => (int) ($p['skipped'] ?? 0),
            'renamed'     => (int) ($p['renamed'] ?? 0),
            'dryRun'      => (bool) ($p['dryRun'] ?? false),
            'driveFree'   => is_array($p['driveFree'] ?? null) ? $p['driveFree'] : (object) [],
            'stalled'     => (bool) ($p['stalled'] ?? false),
        ];
        // A finished run reads as inactive, but its summary is still returned.
        echo json_encode(['active' => $fields['phase'] !== 'done'] + $fields);
        break;

    case 'logTail':
        $lines = $data['lines'] ?? 40;
        $lines = is_numeric($lines) ? (int) $lines : 40;
        $lines = max(1, min(400, $lines));
        // actions:true filters bookkeeping rows server-side — the raw tail of
        // a large run is all GROUP_DONE noise and useless for review
        $actionsOnly = ($data['actions'] ?? false) === true;
        echo json_encode([
            'lines' => $actionsOnly
                ? moviedb_consolidate_action_tail(MOVIEDB_CONSOLIDATE_LOG_FILE, $lines)
                : moviedb_consolidate_tail_lines(MOVIEDB_CONSOLIDATE_LOG_FILE, $lines),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
