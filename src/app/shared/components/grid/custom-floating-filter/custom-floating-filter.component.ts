import {
  IFloatingFilter,
  IFloatingFilterParams,
  TextFilterModel,
} from 'ag-grid-community';
import { NgFor, NgIf, NgStyle } from '@angular/common';
import { ChangeDetectorRef, Component, ElementRef, inject, OnDestroy, ViewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';

const HISTORY_KEY = 'filterHistory';
const MAX_HISTORY = 10;
const MIN_TERM_LENGTH = 2;

@Component({
  selector: 'app-custom-floating-filter',
  template: `
    <div class="filter-wrapper">
      <input
        #filterInput
        type="text"
        [(ngModel)]="currentValue"
        (input)="onInputChanged()"
        (focus)="onFocus()"
        (blur)="onBlur()"
        placeholder="Filter..."
        class="filter-input"
      />
      <button
        *ngIf="currentValue"
        (click)="clearFilter()"
        class="clear-btn"
        title="Clear Filter"
      >
        <i class="fas fa-times clear-button fa-2x" style="color: red;"></i>
      </button>
    </div>
    <ul
      *ngIf="showDropdown && searchHistory.length"
      class="filter-history-dropdown"
      [ngStyle]="dropdownStyle"
    >
      <li
        *ngFor="let term of searchHistory"
        (mousedown)="selectTerm(term)"
      >
        {{ term }}
      </li>
    </ul>
  `,
  styles: [`
    .filter-wrapper {
      display: flex;
      align-items: center;
    }
    .filter-input {
      flex: 1;
      padding: 4px;
      width: 100%;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    .clear-btn {
      margin-left: 4px;
      cursor: pointer;
      background: none;
      border: none;
      font-size: 14px;
    }
    .filter-history-dropdown {
      position: fixed;
      margin: 0;
      padding: 0;
      list-style: none;
      background: #1e1e1e;
      border: 1px solid #444;
      border-radius: 4px;
      z-index: 9999;
      max-height: 220px;
      overflow-y: auto;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
    }
    .filter-history-dropdown li {
      padding: 6px 10px;
      cursor: pointer;
      color: #ddd;
      font-size: 13px;
    }
    .filter-history-dropdown li:hover {
      background: #3a3a3a;
    }
  `],
  standalone: true,
  imports: [FormsModule, NgIf, NgFor, NgStyle],
})
export class CustomFloatingFilterComponent
  implements IFloatingFilter<TextFilterModel>, OnDestroy {

  @ViewChild('filterInput', { static: false }) filterInputRef!: ElementRef<HTMLInputElement>;

  private cdr = inject(ChangeDetectorRef);
  params!: IFloatingFilterParams<TextFilterModel>;
  currentValue: string = '';
  showDropdown: boolean = false;
  searchHistory: string[] = [];
  dropdownStyle: Record<string, string> = {};

  private previousValue: string = '';
  private blurTimeout: ReturnType<typeof setTimeout> | null = null;
  private storageListener = (e: StorageEvent) => {
    if (e.key === HISTORY_KEY) {
      this.loadHistory();
    }
  };

  agInit(params: IFloatingFilterParams<TextFilterModel>): void {
    this.params = params;
    this.loadHistory();
    window.addEventListener('storage', this.storageListener);
  }

  ngOnDestroy(): void {
    this.saveTermIfValid(this.currentValue);
    window.removeEventListener('storage', this.storageListener);
    if (this.blurTimeout) {
      clearTimeout(this.blurTimeout);
    }
  }

  onParentModelChanged(parentModel: TextFilterModel): void {
    this.currentValue = parentModel?.filter ?? '';
  }

  onInputChanged(): void {
    if (!this.currentValue && this.previousValue) {
      this.saveTermIfValid(this.previousValue);
    }
    this.previousValue = this.currentValue;
    this.showDropdown = false;

    this.params.parentFilterInstance((instance: any) => {
      if (instance.onFloatingFilterChanged) {
        instance.onFloatingFilterChanged('contains', this.currentValue);
      }
    });
  }

  clearFilter(): void {
    this.saveTermIfValid(this.currentValue);
    this.currentValue = '';
    this.previousValue = '';
    this.params.parentFilterInstance((instance: any) => {
      if (instance.onFloatingFilterChanged) {
        instance.onFloatingFilterChanged(null, null);
      }
    });
  }

  onFocus(): void {
    this.loadHistory();
    if (this.searchHistory.length && !this.currentValue) {
      this.positionDropdown();
      this.showDropdown = true;
    }
  }

  onBlur(): void {
    this.blurTimeout = setTimeout(() => {
      this.showDropdown = false;
      this.cdr.markForCheck();
    }, 150);
  }

  selectTerm(term: string): void {
    this.currentValue = term;
    this.previousValue = term;
    this.showDropdown = false;
    this.params.parentFilterInstance((instance: any) => {
      if (instance.onFloatingFilterChanged) {
        instance.onFloatingFilterChanged('contains', this.currentValue);
      }
    });
  }

  private positionDropdown(): void {
    if (!this.filterInputRef) return;
    const rect = this.filterInputRef.nativeElement.getBoundingClientRect();
    this.dropdownStyle = {
      top: `${rect.bottom + 2}px`,
      left: `${rect.left}px`,
      width: `${rect.width}px`,
    };
  }

  private loadHistory(): void {
    try {
      const raw = localStorage.getItem(HISTORY_KEY);
      this.searchHistory = raw ? JSON.parse(raw) : [];
    } catch {
      this.searchHistory = [];
    }
  }

  private saveTermIfValid(term: string): void {
    const trimmed = term.trim();
    if (trimmed.length < MIN_TERM_LENGTH) return;

    this.loadHistory();

    this.searchHistory = this.searchHistory.filter(
      (t) => t.toLowerCase() !== trimmed.toLowerCase()
    );
    this.searchHistory.unshift(trimmed);

    if (this.searchHistory.length > MAX_HISTORY) {
      this.searchHistory = this.searchHistory.slice(0, MAX_HISTORY);
    }

    localStorage.setItem(HISTORY_KEY, JSON.stringify(this.searchHistory));
  }
}
