import {
    IFloatingFilter,
    IFloatingFilterParams,
    TextFilterModel,
  } from 'ag-grid-community';
  import { NgIf } from '@angular/common';
  import { Component } from '@angular/core';
  import { FormsModule } from '@angular/forms';
  
  @Component({
    selector: 'app-custom-floating-filter',
    template: `
      <div style="display: flex; align-items: center;">
        <input
          type="text"
          [(ngModel)]="currentValue"
          (input)="onInputChanged()"
          placeholder="Filter..."
          style="flex: 1; padding: 4px;"
        />
        <button
          *ngIf="currentValue"
          (click)="clearFilter()"
          style="margin-left: 4px; cursor: pointer; background: none; border: none;"
          title="Clear Filter"
        >
        <i class="fas fa-times clear-button fa-2x" style="color: red;"></i>
        </button>
      </div>
    `,
    styles: [`
      input {
        width: 100%;
        border: 1px solid #ccc;
        border-radius: 4px;
      }
      button {
        font-size: 14px;
      }
    `],
    standalone: true,
    imports: [FormsModule, NgIf],
  })
  export class CustomFloatingFilterComponent
    implements IFloatingFilter<TextFilterModel> {
    
    params!: IFloatingFilterParams<TextFilterModel>;
    currentValue: string = '';
  
    agInit(params: IFloatingFilterParams<TextFilterModel>): void {
      this.params = params;
    }
  
    onParentModelChanged(parentModel: TextFilterModel): void {
      this.currentValue = parentModel?.filter ?? '';
    }
  
    onInputChanged(): void {
        this.params.parentFilterInstance((instance: any) => {
          if (instance.onFloatingFilterChanged) {
            instance.onFloatingFilterChanged('startsWith', this.currentValue);
          }
        });
      }
  
      clearFilter(): void {
        this.currentValue = '';
        this.params.parentFilterInstance((instance: any) => {
          if (instance.onFloatingFilterChanged) {
            instance.onFloatingFilterChanged(null, null);
          }
        });
      }
  }
  