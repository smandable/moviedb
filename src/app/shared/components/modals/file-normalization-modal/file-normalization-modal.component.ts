import { Component, Input, Output, EventEmitter } from '@angular/core';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

export interface NormalizedFile {
  path: string;
  originalFileName: string;
  newFileName: string;
  fileExtension: string;
  fileNameNoExtension: string;
  needsNormalization: boolean;
  status: string;
  exclude?: boolean;
}

@Component({
  selector: 'app-file-normalization-modal',
  templateUrl: './file-normalization-modal.component.html',
  styleUrls: ['./file-normalization-modal.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule],
})
export class FileNormalizationModalComponent {
  @Input() files: NormalizedFile[] = [];
  @Input() directory: string = '';
  @Output() renameFilesEvent = new EventEmitter<NormalizedFile[]>();

  allSelected: boolean = false; // Track the master checkbox state

  constructor(public activeModal: NgbActiveModal) {}

  /**
   * Toggles all checkboxes based on the master checkbox state.
   */
  toggleAllCheckboxes(): void {
    this.files.forEach((file) => (file.exclude = this.allSelected));
  }

  /**
   * Determines if there are any files that need normalization.
   */
  get hasFilesToRename(): boolean {
    return this.files.some((file) => file.needsNormalization);
  }
  renameFiles(): void {
    const filesToRename = this.files.filter((file) => !file.exclude);
    this.renameFilesEvent.emit(filesToRename);
    this.activeModal.close();
  }
}
