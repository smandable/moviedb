import {
  IFloatingFilter,
  IFloatingFilterParams,
  TextFilterModel,
} from 'ag-grid-community';
import { NgFor, NgIf } from '@angular/common';
import { Component, OnDestroy } from '@angular/core';
import { FormsModule } from '@angular/forms';

const HISTORY_KEY = 'filterHistory';
const MAX_HISTORY = 10;
const MIN_TERM_LENGTH = 2;

@Component({
  selector: 'app-custom-floating-filter',
  template: `
    <div class="filter-wrapper">
      <input
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
      <ul *ngIf="showDropdown && searchHistory.length" class="filter-history-dropdown">
        <li
          *ngFor="let term of searchHistory"
          (mousedown)="selectTerm(term)"
        >
          {{ term }}
        </li>
      </ul>
    </div>
  `,
  styles: [`
    .filter-wrapper {
      position: relative;
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
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      margin: 2px 0 0;
      padding: 0;
      list-style: none;
      background: #1e1e1e;
      border: 1px solid #444;
      border-radius: 4px;
      z-index: 9999;
      max-height: 220px;
      overflow-y: auto;
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
  imports: [FormsModule, NgIf, NgFor],
})
export class CustomFloatingFilterComponent
  implements IFloatingFilter<TextFilterModel>, OnDestroy {

  params!: IFloatingFilterParams<TextFilterModel>;
  currentValue: string = '';
  showDropdown: boolean = false;
  searchHistory: string[] = [];

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
    // Save current term before component is destroyed
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
    // If the user manually empties the input, save the previous term
    if (!this.currentValue && this.previousValue) {
      this.saveTermIfValid(this.previousValue);
    }
    this.previousValue = this.currentValue;

    // Hide dropdown while typing
    this.showDropdown = false;

    this.params.parentFilterInstance((instance: any) => {
      if (instance.onFloatingFilterChanged) {
        instance.onFloatingFilterChanged('contains', this.currentValue);
      }
    });
  }

  clearFilter(): void {
    // Save the term being cleared
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
      this.showDropdown = true;
    }
  }

  onBlur(): void {
    // Small delay so click on dropdown item registers before hiding
    this.blurTimeout = setTimeout(() => {
      this.showDropdown = false;
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

    // Remove duplicate if present, then prepend
    this.searchHistory = this.searchHistory.filter(
      (t) => t.toLowerCase() !== trimmed.toLowerCase()
    );
    this.searchHistory.unshift(trimmed);

    // Cap at max
    if (this.searchHistory.length > MAX_HISTORY) {
      this.searchHistory = this.searchHistory.slice(0, MAX_HISTORY);
    }

    localStorage.setItem(HISTORY_KEY, JSON.stringify(this.searchHistory));
  }
}
