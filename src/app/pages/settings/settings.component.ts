import {
  Component,
  OnInit,
  OnDestroy,
  ChangeDetectorRef,
  ChangeDetectionStrategy,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PageLayoutComponent } from '@layouts/page-layout/page-layout.component';
import { SettingsService } from '@services/settings.service';
import {
  DriveIndexService,
  DriveIndexStatus,
  DriveIndexProgress,
} from '@services/drive-index.service';
import {
  ConsolidateService,
  ConsolidateSettings,
  ConsolidateStatus,
  ConsolidateProgress,
} from '@services/consolidate.service';
import { formatBytes, formatDurationHuman } from '@helpers/formatters';
import { environment } from 'src/environments/environment';

/** One parsed row of the consolidation TSV log, ready for display. */
export interface ConsolidateLogRow {
  time: string;
  action: string;
  group: string;
  srcName: string;
  srcDrive: string;
  destDrive: string;
  bytes: string;
  status: string;
  message: string;
}

@Component({
  selector: 'app-settings',
  templateUrl: './settings.component.html',
  styleUrls: ['./settings.component.scss'],
  standalone: true,
  imports: [CommonModule, PageLayoutComponent, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsComponent implements OnInit, OnDestroy {
  // ---- Default directory ----
  defaultDirectory: string = environment.defaultDirectory;
  directoryStatus: 'idle' | 'saving' | 'saved' | 'error' = 'idle';
  directoryMessage: string = '';

  // ---- Cast name vocabulary ----
  castNames: string[] = [];
  castNamesLoaded = false;
  castNamesError = '';
  filterText = '';
  newName = '';
  /** Name currently being edited (original value), or null. */
  editingName: string | null = null;
  editValue = '';
  castStatus = '';

  /** Cap the rendered list so typing in the filter stays snappy. */
  readonly renderCap = 300;

  // ---- Drive index ----
  // Populated from stored settings, else from the status endpoint's effective
  // roots — the server (drive_index_lib.php) is the single owner of the
  // defaults, so nothing is duplicated here to drift.
  driveIndexRoots: string[] = [];
  private rootsFromSettings = false;
  /** The roots editor lives in a collapsed-by-default accordion. */
  rootsExpanded = false;
  rootsStatus: 'idle' | 'saving' | 'saved' | 'error' = 'idle';
  rootsMessage = '';
  indexStatus: DriveIndexStatus | null = null;
  indexStatusError = '';
  isRebuilding = false;
  rebuildProgress: DriveIndexProgress | null = null;
  private progressTimer: ReturnType<typeof setInterval> | undefined;
  rebuildError = '';

  // ---- Consolidation ----
  // Form state mirrors the "consolidate" settings key; populated from the
  // status endpoint's effective settings — the server owns the defaults.
  consolidateOptionsExpanded = false;
  consolidateDrives: string[] = [];
  consolidateBalanceTo = '';
  consolidateTargetFreeGb = 100;
  consolidateReserveGb = 20;
  /** Off by default — matches the server default in consolidate_lib.php. */
  consolidateBalance = false;
  consolidateRecursive = false;
  consolidateSaveStatus: 'idle' | 'saving' | 'saved' | 'error' = 'idle';
  consolidateSaveMessage = '';
  consolidateStatus: ConsolidateStatus | null = null;
  consolidateStatusError = '';
  isConsolidating = false;
  consolidateProgress: ConsolidateProgress | null = null;
  private consolidateTimer: ReturnType<typeof setInterval> | undefined;
  consolidateRunError = '';
  /** Plan-only mode; off by default (Sean's call) — the confirm() in
   *  runConsolidate() is the remaining guard before a real run. */
  dryRun = false;
  consolidateLogExpanded = false;
  consolidateLogLines: string[] = [];
  consolidateLogError = '';
  consolidateLogLoading = false;

  constructor(
    private settingsService: SettingsService,
    private driveIndexService: DriveIndexService,
    private consolidateService: ConsolidateService,
    private cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.settingsService.getSettings().subscribe({
      next: ({ settings }) => {
        if (settings.defaultDirectory) {
          this.defaultDirectory = settings.defaultDirectory;
        }
        if (settings.driveIndexRoots?.length) {
          this.driveIndexRoots = [...settings.driveIndexRoots];
          this.rootsFromSettings = true;
        }
        this.cdr.markForCheck();
      },
      error: () => this.cdr.markForCheck(),
    });

    this.driveIndexService.status().subscribe({
      next: (status) => {
        this.indexStatus = status;
        // No stored override -> show the server's effective roots
        if (!this.rootsFromSettings && status.roots?.length) {
          this.driveIndexRoots = [...status.roots];
        }
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.indexStatusError = err.message;
        this.cdr.markForCheck();
      },
    });

    this.consolidateService.status().subscribe({
      next: (status) => {
        this.applyConsolidateStatus(status);
        // A consolidation outlives page visits: resume the readout when one
        // is already running
        if (status.running) {
          this.isConsolidating = true;
          this.startConsolidatePolling();
        }
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.consolidateStatusError = err.message;
        this.cdr.markForCheck();
      },
    });

    this.settingsService.listCastNames().subscribe({
      next: ({ names }) => {
        this.castNames = names;
        this.castNamesLoaded = true;
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.castNamesError = err.message;
        this.castNamesLoaded = true;
        this.cdr.markForCheck();
      },
    });
  }

  saveDirectory(): void {
    const dir = this.defaultDirectory.trim();
    if (!dir) {
      this.directoryStatus = 'error';
      this.directoryMessage = 'Directory cannot be empty.';
      return;
    }
    this.directoryStatus = 'saving';
    this.settingsService.saveSettings({ defaultDirectory: dir }).subscribe({
      next: (res) => {
        this.defaultDirectory = res.settings.defaultDirectory ?? dir;
        this.directoryStatus = 'saved';
        this.directoryMessage =
          res.directoryExists === false
            ? 'Saved — but that directory is not visible right now (unmounted volume?).'
            : 'Saved.';
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.directoryStatus = 'error';
        this.directoryMessage = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  get filteredNames(): string[] {
    const q = this.filterText.trim().toLowerCase();
    if (!q) {
      return this.castNames;
    }
    return this.castNames.filter((n) => n.toLowerCase().includes(q));
  }

  addName(): void {
    const name = this.newName.trim();
    if (!name) {
      return;
    }
    this.settingsService.addCastName(name).subscribe({
      next: ({ names, added }) => {
        this.castNames = names;
        this.newName = '';
        this.castStatus = added ? `Added “${added}”.` : '';
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.castStatus = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  startEdit(name: string): void {
    this.editingName = name;
    this.editValue = name;
  }

  cancelEdit(): void {
    this.editingName = null;
    this.editValue = '';
  }

  saveEdit(): void {
    const original = this.editingName;
    const next = this.editValue.trim();
    if (!original || !next || next === original) {
      this.cancelEdit();
      return;
    }
    this.settingsService.renameCastName(original, next).subscribe({
      next: ({ names, renamed }) => {
        this.castNames = names;
        this.castStatus = renamed ? `Renamed to “${renamed}”.` : '';
        this.cancelEdit();
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.castStatus = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  deleteName(name: string): void {
    if (!confirm(`Delete “${name}” from the cast vocabulary?`)) {
      return;
    }
    this.settingsService.deleteCastName(name).subscribe({
      next: ({ names }) => {
        this.castNames = names;
        this.castStatus = `Deleted “${name}”.`;
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.castStatus = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  // ---- Drive index ----

  /** ngModel writes back into the array by index, so identity is the index. */
  trackByIndex(index: number): number {
    return index;
  }

  addRoot(): void {
    this.driveIndexRoots.push('');
    this.rootsStatus = 'idle';
  }

  removeRoot(index: number): void {
    this.driveIndexRoots.splice(index, 1);
    this.rootsStatus = 'idle';
  }

  saveRoots(): void {
    const roots = this.driveIndexRoots
      .map((root) => root.trim())
      .filter((root) => !!root);
    if (roots.length === 0) {
      this.rootsStatus = 'error';
      this.rootsMessage = 'At least one root directory is required.';
      return;
    }
    this.rootsStatus = 'saving';
    this.settingsService.saveSettings({ driveIndexRoots: roots }).subscribe({
      next: (res) => {
        this.driveIndexRoots = [...(res.settings.driveIndexRoots ?? roots)];
        this.rootsStatus = 'saved';
        this.rootsMessage = 'Saved.';
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.rootsStatus = 'error';
        this.rootsMessage = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  rebuildIndex(): void {
    if (this.isRebuilding) {
      return;
    }
    this.isRebuilding = true;
    this.rebuildError = '';
    this.rebuildProgress = null;
    this.startProgressPolling();
    this.driveIndexService.rebuild().subscribe({
      next: (res) => {
        // The live endpoint omits `exists` on a successful rebuild — a
        // successful rebuild means the index exists.
        this.indexStatus = {
          exists: res.exists ?? true,
          builtAt: res.builtAt,
          fileCount: res.fileCount,
          roots: res.roots,
          missingRoots: res.missingRoots,
        };
        this.indexStatusError = '';
        this.isRebuilding = false;
        this.stopProgressPolling();
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.rebuildError = err.message;
        this.isRebuilding = false;
        this.stopProgressPolling();
        this.cdr.markForCheck();
      },
    });
  }

  /**
   * While a rebuild runs, poll the server's progress sidecar every 500ms.
   * Zoneless: each poll response must markForCheck itself or the readout
   * would never repaint.
   */
  private startProgressPolling(): void {
    this.stopProgressPolling();
    this.progressTimer = setInterval(() => {
      this.driveIndexService.progress().subscribe({
        next: (p) => {
          this.rebuildProgress = p.active ? p : null;
          this.cdr.markForCheck();
        },
        error: () => {
          // Progress is cosmetic; polling errors must not disturb the rebuild
        },
      });
    }, 500);
  }

  private stopProgressPolling(): void {
    if (this.progressTimer) {
      clearInterval(this.progressTimer);
      this.progressTimer = undefined;
    }
    this.rebuildProgress = null;
  }

  // ---- Consolidation ----

  private applyConsolidateStatus(status: ConsolidateStatus): void {
    this.consolidateStatus = status;
    const s = status.settings;
    if (s) {
      this.consolidateDrives = [...(s.drives ?? [])];
      this.consolidateBalanceTo = s.balanceTo ?? '';
      this.consolidateTargetFreeGb =
        s.targetFreeGb ?? this.consolidateTargetFreeGb;
      this.consolidateReserveGb = s.reserveGb ?? this.consolidateReserveGb;
      this.consolidateBalance = s.balance ?? false;
      this.consolidateRecursive = s.recursive ?? false;
      this.markConsolidateFormClean();
    }
  }

  /**
   * A run always uses the SAVED settings (the spawned script reads
   * app_settings.json) — so unsaved form edits must block Run rather than be
   * silently ignored.
   */
  private savedConsolidateSnapshot = '';

  private consolidateFormSnapshot(): string {
    return JSON.stringify([
      this.consolidateDrives.map((d) => d.trim()).filter(Boolean),
      this.consolidateBalanceTo,
      this.consolidateTargetFreeGb,
      this.consolidateReserveGb,
      this.consolidateBalance,
      this.consolidateRecursive,
    ]);
  }

  private markConsolidateFormClean(): void {
    this.savedConsolidateSnapshot = this.consolidateFormSnapshot();
  }

  get consolidateFormDirty(): boolean {
    return (
      this.savedConsolidateSnapshot !== '' &&
      this.consolidateFormSnapshot() !== this.savedConsolidateSnapshot
    );
  }

  addDrive(): void {
    this.consolidateDrives.push('');
    this.consolidateSaveStatus = 'idle';
  }

  removeDrive(index: number): void {
    this.consolidateDrives.splice(index, 1);
    this.consolidateSaveStatus = 'idle';
  }

  saveConsolidateSettings(): void {
    const drives = this.consolidateDrives
      .map((drive) => drive.trim())
      .filter((drive) => !!drive);
    if (drives.length === 0) {
      this.consolidateSaveStatus = 'error';
      this.consolidateSaveMessage = 'At least one drive directory is required.';
      return;
    }
    const balanceTo = this.consolidateBalanceTo.trim();
    if (!drives.includes(balanceTo)) {
      this.consolidateSaveStatus = 'error';
      this.consolidateSaveMessage =
        'The balance-to drive must be one of the listed drives.';
      return;
    }
    const targetFreeGb = Number(this.consolidateTargetFreeGb);
    const reserveGb = Number(this.consolidateReserveGb);
    if (
      !Number.isFinite(targetFreeGb) ||
      targetFreeGb < 0 ||
      !Number.isFinite(reserveGb) ||
      reserveGb < 0
    ) {
      this.consolidateSaveStatus = 'error';
      this.consolidateSaveMessage =
        'Target free and reserve must be non-negative numbers.';
      return;
    }
    const consolidate: ConsolidateSettings = {
      drives,
      balanceTo,
      targetFreeGb,
      reserveGb,
      balance: this.consolidateBalance,
      recursive: this.consolidateRecursive,
    };
    this.consolidateSaveStatus = 'saving';
    this.settingsService.saveSettings({ consolidate }).subscribe({
      next: (res) => {
        const saved = res.settings.consolidate ?? consolidate;
        this.consolidateDrives = [...saved.drives];
        this.consolidateBalanceTo = saved.balanceTo;
        this.consolidateTargetFreeGb = saved.targetFreeGb;
        this.consolidateReserveGb = saved.reserveGb;
        this.consolidateBalance = saved.balance;
        this.consolidateRecursive = saved.recursive;
        this.markConsolidateFormClean();
        this.consolidateSaveStatus = 'saved';
        this.consolidateSaveMessage = 'Saved.';
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.consolidateSaveStatus = 'error';
        this.consolidateSaveMessage = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  runConsolidate(): void {
    // The spawned run reads SAVED settings; a dirty form must save first
    // (the button is disabled too — this guards programmatic calls)
    if (this.isConsolidating || this.consolidateFormDirty) {
      return;
    }
    const execute = !this.dryRun;
    if (
      execute &&
      !confirm(
        'Consolidate files for real? This MOVES files between drives ' +
          '(not a dry run). Continue?',
      )
    ) {
      return;
    }
    this.isConsolidating = true;
    this.consolidateRunError = '';
    this.consolidateProgress = null;
    this.consolidateService.run(execute).subscribe({
      next: (res) => {
        if (res.started) {
          this.startConsolidatePolling();
        } else {
          this.isConsolidating = false;
          this.consolidateRunError =
            res.message || 'The server refused to start the run.';
        }
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        // Covers the 409 already-running refusal, surfaced inline
        this.isConsolidating = false;
        this.consolidateRunError = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  /**
   * While a run is active, poll the server's progress sidecar every 1s.
   * Zoneless: each poll response must markForCheck itself or the readout
   * would never repaint. The run happens in a detached server process, so
   * progress going inactive is the completion signal: stop polling and
   * refresh status so the lastRun summary appears.
   */
  private startConsolidatePolling(): void {
    this.stopConsolidatePolling();
    this.consolidateTimer = setInterval(() => {
      this.consolidateService.progress().subscribe({
        next: (p) => {
          if (p.active) {
            this.consolidateProgress = p;
          } else {
            this.finishConsolidateRun();
          }
          this.cdr.markForCheck();
        },
        error: () => {
          // Progress is cosmetic; polling errors must not disturb the run
        },
      });
    }, 1000);
  }

  /**
   * Re-read the index card's count/timestamp. Deliberately does NOT adopt
   * status.roots the way ngOnInit does — that would overwrite roots the user
   * is editing in the form.
   */
  private refreshDriveIndexStatus(): void {
    this.driveIndexService.status().subscribe({
      next: (status) => {
        // A stale error from an earlier failed status read would otherwise
        // keep hiding the card that just got fresh numbers
        this.indexStatusError = '';
        this.indexStatus = status;
        this.cdr.markForCheck();
      },
      error: () => this.cdr.markForCheck(),
    });
  }

  private stopConsolidatePolling(): void {
    if (this.consolidateTimer) {
      clearInterval(this.consolidateTimer);
      this.consolidateTimer = undefined;
    }
    this.consolidateProgress = null;
  }

  private finishConsolidateRun(): void {
    this.stopConsolidatePolling();
    this.consolidateService.status().subscribe({
      next: (status) => {
        // The process is the completion oracle, not one inactive-looking
        // poll: if the run still lives (e.g. the progress read raced the
        // spawn), resume the readout instead of declaring it finished.
        if (status.running) {
          this.isConsolidating = true;
          this.startConsolidatePolling();
          this.cdr.markForCheck();
          return;
        }
        this.isConsolidating = false;
        // Refresh the lastRun summary only — re-applying the settings here
        // would clobber unsaved form edits made while the run was going
        this.consolidateStatus = status;
        // The run rebuilt the index; show its new count/timestamp
        if (status.lastRun?.indexRebuilt) {
          this.refreshDriveIndexStatus();
        }
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.isConsolidating = false;
        this.consolidateStatusError = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  toggleConsolidateLog(): void {
    this.consolidateLogExpanded = !this.consolidateLogExpanded;
    if (this.consolidateLogExpanded) {
      this.refreshConsolidateLog();
    }
  }

  refreshConsolidateLog(): void {
    this.consolidateLogLoading = true;
    this.consolidateLogError = '';
    // Actions view fetches the last 400 ACTION rows (server-filtered) — the
    // raw tail of a big run is entirely GROUP_DONE bookkeeping. The
    // "show bookkeeping" toggle switches to the raw tail instead.
    this.consolidateService.logTail(400, !this.logShowAll).subscribe({
      next: ({ lines }) => {
        this.consolidateLogLines = lines ?? [];
        this.consolidateLogLoading = false;
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.consolidateLogError = err.message;
        this.consolidateLogLoading = false;
        this.cdr.markForCheck();
      },
    });
  }

  get consolidatePhaseLabel(): string {
    switch (this.consolidateProgress?.phase) {
      case 'scan':
        return 'Scanning drives';
      case 'groups':
        return 'Consolidating groups';
      case 'balance':
        return 'Balancing free space';
      case 'index':
        return 'Rebuilding the drive index';
      case 'done':
        return 'Finishing up';
      default:
        return 'Working';
    }
  }

  // The readout is deliberately three short lines rather than one long one —
  // as a single string it wrapped several times mid-run and was hard to read.

  /** Line 1: "Consolidating groups — group 1255 of 8055:" (the trailing colon
   *  leads into the group line below it). */
  get consolidatePhaseLine(): string {
    const p = this.consolidateProgress;
    if (!p?.active) {
      return '';
    }
    let line = this.consolidatePhaseLabel;
    if (p.phase !== 'index' && p.groupsTotal) {
      const current = Math.min((p.groupsDone ?? 0) + 1, p.groupsTotal);
      line += ` — group ${current} of ${p.groupsTotal}`;
    }
    // Only when something actually follows on the next line
    return this.consolidateGroupLine ? `${line}:` : line;
  }

  /** Line 2: "Black Owned — Black Owned # 08.mp4". */
  get consolidateGroupLine(): string {
    const p = this.consolidateProgress;
    // The index rebuild walks every root without touching this sidecar, so any
    // group/file here is left over from the finished move phase.
    if (!p?.active || p.phase === 'index') {
      return '';
    }
    const file = (p.currentSrc ?? '').split('/').filter(Boolean).pop() ?? '';
    if (p.group && file) {
      return `${p.group} — ${file}`;
    }
    return p.group || file;
  }

  /** Its own line when the sidecar is quiet but the run provably lives. */
  get consolidateStalledNote(): string {
    const p = this.consolidateProgress;
    if (!p?.active || !p.stalled) {
      return '';
    }
    return p.phase === 'index'
      ? 'This can take a minute.'
      : 'Still copying — a large file can take minutes between updates.';
  }

  /** "3 moved (1.50 GB), 1 duplicate, 0 failed" while a run is active. */
  get consolidateCountsLine(): string {
    const p = this.consolidateProgress;
    if (!p?.active) {
      return '';
    }
    const duped = p.duped ?? 0;
    let line =
      `${p.moved ?? 0} moved (${formatBytes(p.movedBytes ?? 0)}), ` +
      `${duped} ${duped === 1 ? 'duplicate' : 'duplicates'}, ` +
      `${p.failed ?? 0} failed`;
    if (p.renamed) {
      line += `, ${p.renamed} renamed to # 01`;
    }
    if (p.skipped) {
      line += `, ${p.skipped} skipped`;
    }
    return line;
  }

  /** Summary of the previous run, shown once none is active. */
  get consolidateLastRunLine(): string {
    const run = this.consolidateStatus?.lastRun;
    if (!run) {
      return '';
    }
    const finishedAt = run.finishedAt;
    const parsed =
      typeof finishedAt === 'number'
        ? new Date(finishedAt * 1000) // unix seconds
        : finishedAt
          ? new Date(finishedAt)
          : null;
    const finished =
      parsed && !isNaN(parsed.getTime())
        ? parsed.toLocaleString()
        : String(finishedAt ?? 'unknown');
    const kind = run.dryRun ? 'Dry run' : 'Run';
    // Older .last files predate durationSeconds — omit rather than guess
    const took =
      typeof run.durationSeconds === 'number'
        ? ` (took ${formatDurationHuman(run.durationSeconds)})`
        : '';
    let line =
      `${kind} finished ${finished}${took} — ${run.moved} moved ` +
      `(${formatBytes(run.movedBytes)}), ${run.duped} duplicates, ` +
      `${run.failed} failed`;
    if (run.renamed) {
      line += `, ${run.renamed} renamed to # 01`;
    }
    if (run.skipped) {
      // An incomplete run must never read as a full success
      line += `, ${run.skipped} groups skipped (space)`;
    }
    // null/undefined = not attempted (dry run, or nothing changed) — say
    // nothing. A failed rebuild must be visible: the index is now stale.
    if (run.indexRebuilt === true) {
      line += '. Drive index rebuilt.';
    } else if (run.indexRebuilt === false) {
      line += '. Drive index rebuild FAILED — rebuild it manually.';
    }
    return line;
  }

  /** Show bookkeeping rows (GROUP_DONE, mkdir) too, not just the actions. */
  logShowAll = false;

  private logRowsCache: {
    source: string[];
    rows: ConsolidateLogRow[];
  } | null = null;

  /**
   * The TSV log parsed into readable rows: ts, mode, group, action, src,
   * dest, bytes, status, message. Header and malformed lines are dropped.
   */
  get consolidateLogRows(): ConsolidateLogRow[] {
    if (this.logRowsCache?.source === this.consolidateLogLines) {
      return this.logRowsCache.rows;
    }
    const driveOf = (p: string): string => {
      const m = p.match(/^\/Volumes\/([^/]+)\//);
      return m ? m[1] : '';
    };
    const rows: ConsolidateLogRow[] = [];
    for (const line of this.consolidateLogLines) {
      const c = line.split('\t');
      if (c.length < 8 || c[0] === 'ts') {
        continue; // header or malformed
      }
      const time = new Date(c[0]);
      rows.push({
        time: isNaN(time.getTime())
          ? c[0]
          : time.toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
            }),
        action: c[3],
        group: c[2],
        // BALANCE_* rows carry a drive root, not a file — its basename is a
        // meaningless "recorded"; their message is the content.
        srcName: c[3]?.startsWith('BALANCE')
          ? ''
          : c[4]
            ? (c[4].split('/').pop() ?? c[4])
            : '',
        srcDrive: driveOf(c[4] ?? ''),
        destDrive: driveOf(c[5] ?? ''),
        bytes: Number(c[6]) > 0 ? formatBytes(Number(c[6])) : '',
        status: c[7],
        message: c[8] ?? '',
      });
    }
    this.logRowsCache = { source: this.consolidateLogLines, rows };
    return rows;
  }

  /** Bookkeeping rows hidden by default so the actions stand out. */
  get visibleLogRows(): ConsolidateLogRow[] {
    if (this.logShowAll) {
      return this.consolidateLogRows;
    }
    const noise = new Set(['GROUP_DONE', 'EVAC_MKDIR', 'MKDIR_DUP', 'MKDIR_DEST']);
    return this.consolidateLogRows.filter((r) => !noise.has(r.action));
  }

  logStatusClass(status: string): string {
    switch (status) {
      case 'OK':
        return 'text-success';
      case 'DRYRUN':
        return 'text-info';
      case 'FAIL':
        return 'text-danger';
      case 'SKIP':
        return 'text-warning';
      default:
        return 'text-muted';
    }
  }

  ngOnDestroy(): void {
    this.stopProgressPolling();
    this.stopConsolidatePolling();
  }

  /** "Scanning <root> (2/5) — 12,345 files" while a rebuild runs. */
  get rebuildProgressLine(): string {
    const p = this.rebuildProgress;
    if (!p?.active) {
      return '';
    }
    const rootName = (p.root ?? '').split('/').filter(Boolean).slice(-2).join('/');
    const roots =
      p.rootsTotal ? ` (${Math.min((p.rootsDone ?? 0) + 1, p.rootsTotal)}/${p.rootsTotal})` : '';
    const files = (p.entries ?? 0).toLocaleString();
    return rootName
      ? `Scanning ${rootName}${roots} — ${files} files`
      : `Scanning…${roots} — ${files} files`;
  }

  /** "Never built" until the index exists, then its size and build time. */
  get indexStatusLine(): string {
    if (!this.indexStatus) {
      return '';
    }
    if (!this.indexStatus.exists) {
      return 'Never built';
    }
    const builtAt = this.indexStatus.builtAt;
    const parsed = builtAt ? new Date(builtAt) : null;
    const built =
      parsed && !isNaN(parsed.getTime())
        ? parsed.toLocaleString()
        : (builtAt ?? 'unknown');
    return `Indexed ${this.indexStatus.fileCount} files, built ${built}`;
  }
}
