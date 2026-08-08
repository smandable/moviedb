import {
  Component,
  OnInit,
  ChangeDetectorRef,
  ChangeDetectionStrategy,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PageLayoutComponent } from '@layouts/page-layout/page-layout.component';
import { SettingsService } from '@services/settings.service';
import { environment } from 'src/environments/environment';

@Component({
  selector: 'app-settings',
  templateUrl: './settings.component.html',
  styleUrls: ['./settings.component.scss'],
  standalone: true,
  imports: [CommonModule, PageLayoutComponent, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsComponent implements OnInit {
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

  constructor(
    private settingsService: SettingsService,
    private cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.settingsService.getSettings().subscribe({
      next: ({ settings }) => {
        if (settings.defaultDirectory) {
          this.defaultDirectory = settings.defaultDirectory;
        }
        this.cdr.markForCheck();
      },
      error: () => this.cdr.markForCheck(),
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
}
