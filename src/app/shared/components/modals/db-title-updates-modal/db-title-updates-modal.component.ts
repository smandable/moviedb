import {
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  Input,
  OnInit,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import {
  TitleRename,
  TitleRow,
  TitleUpdateItem,
  TitleUpdateService,
} from '@services/title-update.service';
import { formatBytes } from '@helpers/formatters';

/** Per-item outcome shown inline once applied or merged. */
interface ItemOutcome {
  ok: boolean;
  text: string;
}

/**
 * "Update Database Titles": after library renames, shows which database rows
 * still carry the old titles and renames them on request. Opened from the
 * normalize modal (Settings → Normalize Library Filenames) with the renames
 * that landed.
 *
 * Titles whose new spelling already has a row are skipped by the bulk update
 * and listed with a per-item Merge button instead: the earliest-catalogued
 * row stays (keeping its date added), takes the new title and the values
 * shown, and the other row is deleted.
 */
@Component({
  selector: 'app-db-title-updates-modal',
  templateUrl: './db-title-updates-modal.component.html',
  styleUrls: ['./db-title-updates-modal.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DbTitleUpdatesModalComponent implements OnInit {
  @Input() renames: TitleRename[] = [];

  isLoading = false;
  loadError = '';
  /** The preview ran without a drive index (copies elsewhere unchecked). */
  indexMissing = false;
  isApplying = false;
  /** Merge in flight, by item. */
  merging: TitleUpdateItem | null = null;

  updates: TitleUpdateItem[] = [];
  merges: TitleUpdateItem[] = [];
  skipped: TitleUpdateItem[] = [];

  /** Updates the user left ticked. */
  readonly selected = new Set<TitleUpdateItem>();
  readonly outcomes = new Map<TitleUpdateItem, ItemOutcome>();

  constructor(
    public activeModal: NgbActiveModal,
    private titleUpdates: TitleUpdateService,
    private cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.isLoading = true;
    this.loadError = '';
    this.outcomes.clear();
    this.titleUpdates.preview(this.renames).subscribe({
      next: ({ items, indexMissing }) => {
        this.isLoading = false;
        this.indexMissing = !!indexMissing;
        this.updates = items.filter((i) => i.status === 'update');
        this.merges = items.filter((i) => i.status === 'merge');
        this.skipped = items.filter(
          (i) => i.status !== 'update' && i.status !== 'merge',
        );
        this.selected.clear();
        this.updates.forEach((i) => this.selected.add(i));
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.isLoading = false;
        this.loadError = `Couldn't check the database: ${err.message}`;
        this.cdr.markForCheck();
      },
    });
  }

  get pendingUpdates(): TitleUpdateItem[] {
    return this.updates.filter(
      (i) => this.selected.has(i) && !this.outcomes.get(i)?.ok,
    );
  }

  toggle(item: TitleUpdateItem, on: boolean): void {
    if (on) {
      this.selected.add(item);
    } else {
      this.selected.delete(item);
    }
  }

  applyUpdates(): void {
    const items = this.pendingUpdates;
    if (this.isApplying || items.length === 0) {
      return;
    }
    this.isApplying = true;
    this.titleUpdates
      .apply(
        items.map((i) => ({
          table: i.table,
          id: i.row!.id,
          from: i.row!.title,
          to: i.newTitle,
        })),
      )
      .subscribe({
        next: ({ results }) => {
          this.isApplying = false;
          // Results come back in request order (two items can share a row)
          items.forEach((item, i) => {
            const r = results[i];
            this.outcomes.set(item, {
              ok: !!r?.ok,
              text: !r
                ? 'No result'
                : r.ok
                  ? r.logged === false
                    ? 'Updated (undo log not written!)'
                    : 'Updated'
                  : r.error || 'Failed',
            });
          });
          this.cdr.markForCheck();
        },
        error: (err: Error) => {
          this.isApplying = false;
          items.forEach((i) =>
            this.outcomes.set(i, { ok: false, text: err.message }),
          );
          this.cdr.markForCheck();
        },
      });
  }

  merge(item: TitleUpdateItem): void {
    if (this.merging || this.outcomes.get(item)?.ok || !item.keep || !item.drop) {
      return;
    }
    const confirmed = window.confirm(
      `Merge into row ${item.keep.id} as "${item.newTitle}" and delete row ${item.drop.id} ("${item.drop.title}")?`,
    );
    if (!confirmed) {
      return;
    }
    this.merging = item;
    this.titleUpdates.merge(item).subscribe({
      next: (r) => {
        this.merging = null;
        this.outcomes.set(item, {
          ok: r.ok,
          text: r.ok
            ? `Merged — row ${item.drop!.id} deleted` +
              (r.logged === false ? ' (undo log not written!)' : '')
            : r.error || 'Failed',
        });
        this.cdr.markForCheck();
      },
      error: (err: Error) => {
        this.merging = null;
        this.outcomes.set(item, { ok: false, text: err.message });
        this.cdr.markForCheck();
      },
    });
  }

  size(value: string | number | null | undefined): string {
    return value === null || value === undefined || value === ''
      ? '—'
      : formatBytes(value);
  }

  length(seconds: number | string | null | undefined): string {
    const s = Number(seconds);
    if (seconds === null || seconds === undefined || seconds === '' || !isFinite(s) || s <= 0) {
      return '—';
    }
    const minutes = Math.round(s / 60);
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
  }

  dims(value: string | null | undefined): string {
    return value ? value.replace(' x ', '×') : '—';
  }

  rowSummary(row: TitleRow): string {
    return [
      this.size(row.filesize),
      this.dims(row.dimensions),
      this.length(row.duration),
      row.date_created ? `added ${row.date_created}` : 'no date',
    ].join(', ');
  }

  statusLabel(item: TitleUpdateItem): string {
    switch (item.status) {
      case 'current':
        return 'Already up to date';
      case 'missing':
        return 'Not in the database';
      case 'partial':
        return 'Files still use the old title';
      case 'conflict':
        return 'Renamed two different ways';
      case 'ambiguous':
        return 'Several rows match';
      default:
        return item.status;
    }
  }
}
