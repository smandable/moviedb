import {
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  Input,
  OnDestroy,
  OnInit,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import {
  DriveIndexFile,
  DriveIndexGroup,
  DriveIndexService,
  DriveIndexStatus,
  DriveIndexTrashResult,
} from '@services/drive-index.service';
import { formatBytes } from '@helpers/formatters';

/**
 * Searches the drive index (server/drive_index.json) and acts on the results:
 * copy a file's full path, reveal it in Finder, or move it to the volume's
 * Trash. Opened from the update-db grid with the row's base title prefilled.
 */
@Component({
  selector: 'app-drive-index-modal',
  templateUrl: './drive-index-modal.component.html',
  styleUrls: ['./drive-index-modal.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DriveIndexModalComponent implements OnInit, OnDestroy {
  @Input() initialQuery = '';

  query = '';
  isSearching = false;
  /** True once the first search has answered (gates the empty state). */
  hasSearched = false;
  groups: DriveIndexGroup[] = [];
  totalGroups = 0;
  totalFiles = 0;
  searchError = '';

  status: DriveIndexStatus | null = null;
  statusError = '';

  /** Short status line after a reveal/trash ("Moved 2 files to the Trash."). */
  actionMessage = '';
  actionError = '';

  /** Paths whose copy icon should stay highlighted (home-grid copy idiom). */
  readonly copiedPaths = new Set<string>();

  /** Per-file inline errors from reveal/trash, keyed by path. */
  readonly fileErrors = new Map<string, string>();

  private searchTimer?: ReturnType<typeof setTimeout>;

  constructor(
    public activeModal: NgbActiveModal,
    private driveIndexService: DriveIndexService,
    private cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.query = this.initialQuery ?? '';

    this.driveIndexService.status().subscribe({
      next: (status) => {
        this.status = status;
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.statusError = err.message;
        this.cdr.markForCheck();
      },
    });

    this.runSearch();
  }

  ngOnDestroy(): void {
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
    }
  }

  /** Debounced: search once typing pauses, not on every keystroke. */
  onQueryChange(): void {
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
    }
    this.searchTimer = setTimeout(() => {
      this.searchTimer = undefined;
      this.runSearch();
    }, 300);
  }

  /** Enter key: search immediately, cancelling any pending debounce. */
  searchNow(): void {
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
      this.searchTimer = undefined;
    }
    this.runSearch();
  }

  clearQuery(): void {
    this.query = '';
    this.searchNow();
  }

  private runSearch(): void {
    const q = this.query.trim();
    // Empty query: show the empty state, don't ask the server (it would 400)
    if (q === '') {
      this.groups = [];
      this.totalGroups = 0;
      this.totalFiles = 0;
      this.isSearching = false;
      this.searchError = '';
      this.hasSearched = false;
      this.cdr.markForCheck();
      return;
    }
    this.isSearching = true;
    this.searchError = '';
    // Zoneless: a debounce timer doesn't notify change detection, so the
    // "Searching…" state must announce itself
    this.cdr.markForCheck();
    this.driveIndexService.search(q).subscribe({
      next: (res) => {
        // Ignore stale responses if the user kept typing.
        if (this.query.trim() !== q) {
          return;
        }
        this.groups = res.groups ?? [];
        this.totalGroups = res.totalGroups;
        this.totalFiles = res.totalFiles;
        this.isSearching = false;
        this.hasSearched = true;
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        if (this.query.trim() !== q) {
          return;
        }
        this.isSearching = false;
        this.hasSearched = true;
        this.searchError = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  trackGroup(_index: number, group: DriveIndexGroup): string {
    return group.base;
  }

  trackFile(_index: number, file: DriveIndexFile): string {
    return file.path;
  }

  groupSize(group: DriveIndexGroup): number {
    return group.files.reduce((sum, file) => sum + (file.size || 0), 0);
  }

  formatSize(bytes: number): string {
    return formatBytes(bytes);
  }

  get builtAtDisplay(): string {
    const builtAt = this.status?.builtAt;
    if (!builtAt) {
      return '';
    }
    const d = new Date(builtAt);
    return isNaN(d.getTime()) ? builtAt : d.toLocaleString();
  }

  /** Same approach as the home grid's copy icon; stays highlighted once used. */
  copyPath(file: DriveIndexFile): void {
    if (!navigator.clipboard) {
      console.warn('Clipboard API not available');
      return;
    }
    navigator.clipboard
      .writeText(file.path)
      .then(() => {
        this.copiedPaths.add(file.path);
        this.cdr.markForCheck();
      })
      .catch((err) => {
        console.error('Failed to copy path:', err);
      });
  }

  reveal(file: DriveIndexFile): void {
    this.driveIndexService.reveal(file.path).subscribe({
      next: (res) => {
        if (res.success) {
          this.fileErrors.delete(file.path);
          this.actionMessage = `Revealed “${file.file}” in Finder.`;
          this.actionError = '';
        } else {
          this.fileErrors.set(
            file.path,
            res.error || 'Could not reveal the file.',
          );
        }
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.fileErrors.set(file.path, err.message);
        this.cdr.markForCheck();
      },
    });
  }

  trashFile(file: DriveIndexFile): void {
    if (!confirm(`Move “${file.file}” to the Trash?`)) {
      return;
    }
    this.trashPaths([file.path]);
  }

  trashGroup(group: DriveIndexGroup): void {
    const count = group.files.length;
    const noun = count === 1 ? 'file' : 'files';
    // "listed", not "all": with a filename-substring search the group may be
    // a subset of the title's files — only what's shown is what moves
    if (
      !confirm(`Move the ${count} listed ${noun} of “${group.base}” to the Trash?`)
    ) {
      return;
    }
    this.trashPaths(group.files.map((file) => file.path));
  }

  private trashPaths(paths: string[]): void {
    this.driveIndexService.trash(paths).subscribe({
      next: ({ results, indexUpdated }) => {
        this.applyTrashResults(results ?? []);
        if (indexUpdated === false) {
          this.actionError =
            'Files moved, but the index could not be rewritten — rebuild it from Settings.';
        }
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.actionError = err.message;
        this.cdr.markForCheck();
      },
    });
  }

  /**
   * Folds trash results back in: trashed rows leave the list (and empty
   * groups leave with them); failures stay, with the error shown inline.
   */
  private applyTrashResults(results: DriveIndexTrashResult[]): void {
    const trashedPaths = new Set<string>();
    let failed = 0;

    for (const result of results) {
      if (result.trashed) {
        trashedPaths.add(result.path);
        this.fileErrors.delete(result.path);
      } else {
        failed++;
        this.fileErrors.set(
          result.path,
          result.error || 'Could not move to the Trash.',
        );
      }
    }

    if (trashedPaths.size > 0) {
      const groupsBefore = this.groups.length;
      this.groups = this.groups
        .map((group) => ({
          base: group.base,
          files: group.files.filter((file) => !trashedPaths.has(file.path)),
        }))
        .filter((group) => group.files.length > 0);
      this.totalFiles = Math.max(0, this.totalFiles - trashedPaths.size);
      this.totalGroups = Math.max(
        0,
        this.totalGroups - (groupsBefore - this.groups.length),
      );
      // Trashing marks an active clean-up session: 30s after the last one,
      // the index rebuilds itself to sweep up Finder-side moves too
      this.driveIndexService.scheduleDebouncedRebuild();
    }

    const noun = trashedPaths.size === 1 ? 'file' : 'files';
    this.actionMessage =
      trashedPaths.size > 0
        ? `Moved ${trashedPaths.size} ${noun} to the Trash.`
        : '';
    this.actionError =
      failed > 0
        ? `${failed} ${failed === 1 ? 'file' : 'files'} could not be moved.`
        : '';
  }
}
