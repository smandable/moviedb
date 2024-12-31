import { Component, Input } from '@angular/core';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import { CommonModule } from '@angular/common';

export interface NormalizedFile {
  path: string;
  originalFileName: string;
  newFileName: string;
  fileExtension: string;
  fileNameNoExtension: string;
}

@Component({
  selector: 'app-file-normalization-modal',
  templateUrl: 'file-normalization-modal.component.html',
  styleUrls: ['file-normalization-modal.component.scss'],
  standalone: true,
  imports: [CommonModule],
})
export class FileNormalizationModalComponent {
  @Input() files: NormalizedFile[] = [];
  @Input() directory: string = '';

  constructor(public activeModal: NgbActiveModal) {}
}
