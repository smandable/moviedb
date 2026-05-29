import {
  Component,
  Input,
  Output,
  EventEmitter,
  OnInit,
  OnDestroy,
} from '@angular/core';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { FileService, NormalizedFile } from '@services/file.service';

@Component({
  selector: 'app-file-normalization-modal',
  templateUrl: './file-normalization-modal.component.html',
  styleUrls: ['./file-normalization-modal.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule],
})
export class FileNormalizationModalComponent implements OnInit, OnDestroy {
  @Input() files: NormalizedFile[] = [];
  @Input() directory: string = '';
  @Output() renameFilesEvent = new EventEmitter<NormalizedFile[]>();

  allSelected: boolean = true;

  // Per-file debounce timers for the live (server-driven) preview.
  private previewTimers = new Map<
    NormalizedFile,
    ReturnType<typeof setTimeout>
  >();

  constructor(
    public activeModal: NgbActiveModal,
    private fileService: FileService,
  ) {}

  ngOnInit(): void {
    this.files.forEach((f) => {
      f.exclude = false;
      f.userEdited = false;

      // Show the *actual* on-disk name (without extension) in the left input
      f.workingBaseName = this.stripExtension(f.originalFileName);

      // checkFileNamesToNormalize already normalized each name server-side, so
      // the initial preview comes straight from its response — no extra calls.
      f.showNormalizedPreview = !!f.needsNormalization;
    });

    // Sort ascending by the name we’re going to rename TO (or working name)
    this.files.sort((a, b) =>
      (a.newFileName || a.workingBaseName || '').localeCompare(
        b.newFileName || b.workingBaseName || '',
        undefined,
        { sensitivity: 'base' },
      ),
    );
  }

  ngOnDestroy(): void {
    this.previewTimers.forEach((t) => clearTimeout(t));
    this.previewTimers.clear();
  }

  /**
   * Called whenever the user edits the left-hand "working" name. Debounced so
   * we hit the normalize endpoint once typing pauses, not on every keystroke.
   */
  onWorkingNameChange(file: NormalizedFile): void {
    file.userEdited = true;

    const existing = this.previewTimers.get(file);
    if (existing) {
      clearTimeout(existing);
    }
    this.previewTimers.set(
      file,
      setTimeout(() => {
        this.previewTimers.delete(file);
        this.recomputePreview(file);
      }, 250),
    );
  }

  /**
   * Asks the server to normalize the working name and refreshes the preview.
   * The server's normalizeFileBaseName() is the single source of truth, so the
   * preview always matches what a rename will actually produce.
   */
  private recomputePreview(file: NormalizedFile): void {
    const originalBase = this.stripExtension(file.originalFileName);
    const workingBase = (file.workingBaseName ?? originalBase).trim();

    this.fileService.normalizeName(workingBase, !!file.userEdited).subscribe({
      next: ({ normalized }) => {
        // Ignore stale responses if the user kept typing.
        if ((file.workingBaseName ?? originalBase).trim() !== workingBase) {
          return;
        }

        const targetBase = normalized;
        const workingFull = file.fileExtension
          ? `${workingBase}.${file.fileExtension}`
          : workingBase;
        const targetFull = file.fileExtension
          ? `${targetBase}.${file.fileExtension}`
          : targetBase;

        // Does normalization actually change what the user typed?
        const normalizationChangesName =
          !!targetBase && targetFull !== workingFull;
        // Does the file need to be renamed on disk at all?
        const requiresRename =
          !!targetBase &&
          (normalizationChangesName || workingFull !== file.originalFileName);

        if (!requiresRename) {
          file.needsNormalization = false;
          file.newFileName = '';
          file.showNormalizedPreview = false;
        } else {
          file.needsNormalization = true;
          file.newFileName = normalizationChangesName ? targetFull : workingFull;
          file.showNormalizedPreview = normalizationChangesName;
        }
      },
      error: () => {
        // Leave the previous preview in place on error.
      },
    });
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
    const filesToRename = this.files.filter(
      (file) =>
        !file.exclude &&
        !!file.newFileName &&
        file.newFileName !== file.originalFileName,
    );

    this.renameFilesEvent.emit(filesToRename);
    this.activeModal.close();
  }

  autoResize(event: Event): void {
    const textarea = event.target as HTMLTextAreaElement;
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
  }

  private stripExtension(name: string): string {
    const lastDot = name.lastIndexOf('.');
    return lastDot > 0 ? name.slice(0, lastDot) : name;
  }
}
