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

  // Start with all checked (i.e., all WILL be renamed)
  allSelected: boolean = true;

  constructor(public activeModal: NgbActiveModal) {}

  ngOnInit(): void {
    // Ensure all files default to included (exclude = false)
    this.files.forEach((f) => (f.exclude = false));
  }

  /**
   * Master toggle: checked means "include/rename all",
   * so we set exclude to the inverse.
   */
  toggleAllCheckboxes(): void {
    const exclude = !this.allSelected;
    this.files.forEach((file) => (file.exclude = exclude));
  }

  get hasFilesToRename(): boolean {
    return this.files.some((file) => file.needsNormalization);
  }

  renameFiles(): void {
    // Checked rows (include) are where exclude === false
    const filesToRename = this.files.filter((file) => !file.exclude);
    this.renameFilesEvent.emit(filesToRename);
    this.activeModal.close();
  }
}
