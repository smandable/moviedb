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

  // client-side only
  workingBaseName?: string;
  userEdited?: boolean;
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

  allSelected: boolean = true;

  constructor(public activeModal: NgbActiveModal) {}

  ngOnInit(): void {
    this.files.forEach((f) => {
      f.exclude = false;
      f.userEdited = false;

      // Show the *actual* on-disk name (without extension) in the left input
      f.workingBaseName = this.stripExtension(f.originalFileName);

      // Compute preview + needsNormalization based on the working name
      this.recomputePreview(f);
    });

    // Sort ascending by the name we’re going to rename TO (or working name)
    this.files.sort((a, b) =>
      (a.newFileName || a.workingBaseName || '').localeCompare(
        b.newFileName || b.workingBaseName || '',
        undefined,
        { sensitivity: 'base' }
      )
    );
  }

  /**
   * Called whenever the user edits the left-hand "working" name.
   * Rebuilds the newFileName using the same normalization rules as PHP.
   */

  onWorkingNameChange(file: NormalizedFile): void {
    file.userEdited = true;
    this.recomputePreview(file);
  }
  private recomputePreview(file: NormalizedFile): void {
    const originalBase = this.stripExtension(file.originalFileName);
    const workingBase = (file.workingBaseName ?? originalBase).trim();

    // Always compute the TARGET base name from the working text.
    // If user edited, we respect their casing choices.
    const targetBase = this.normalizeBaseName(workingBase, !!file.userEdited);

    const targetFull = file.fileExtension
      ? `${targetBase}.${file.fileExtension}`
      : targetBase;

    // Only “no work to do” if the TARGET full name equals the ORIGINAL full name
    if (!targetBase || targetFull === file.originalFileName) {
      file.needsNormalization = false;
      file.newFileName = '';
    } else {
      file.needsNormalization = true;
      file.newFileName = targetFull;
    }
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
        file.newFileName !== file.originalFileName
    );

    this.renameFilesEvent.emit(filesToRename);
    this.activeModal.close();
  }

  autoResize(event: Event): void {
    const textarea = event.target as HTMLTextAreaElement;
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
  }

  // =====================
  // Normalization helpers
  // =====================

  private stripExtension(name: string): string {
    const lastDot = name.lastIndexOf('.');
    return lastDot > 0 ? name.slice(0, lastDot) : name;
  }

 private buildNewFileName(file: NormalizedFile): string {
  const base = file.workingBaseName ?? '';
  const normalizedBase = this.normalizeBaseName(base, !!file.userEdited);
  return file.fileExtension
    ? `${normalizedBase}.${file.fileExtension}`
    : normalizedBase;
}

  private normalizeBaseName(
    fileName: string,
    respectUserCasing = false
  ): string {
    let name = fileName ?? '';
    name = this.basicFunctions(name);
    name = this.titleCase(name, respectUserCasing);
    name = this.cleanupFunctions(name);
    name = this.sceneNormalization(name);
    name = this.finalCleanup(name);
    return name;
  }

  private basicFunctions(fileName: string): string {
    let name = fileName.trim();

    // Use a placeholder that does NOT contain "_" so it survives the underscore replacement.
    const SCENE_MARKER = 'SCENETEMPXXMARKER';

    // Preserve "Scene_" by marking it first
    name = name.replace(/Scene_/g, SCENE_MARKER);

    // Periods, brackets, underscores → spaces
    name = name.replace(/\./g, ' ');
    name = name.replace(/\[|\]/g, ' ');
    name = name.replace(/_/g, ' ');

    // IMPORTANT: don't strip hyphens anymore (we want "All-Stars" to survive)
    // name = name.replace(/-/g, ' ');

    // Put " - " for triple spaces
    name = name.replace(/\s{3}/g, ' - ');

    // Collapse multiple spaces
    name = name.replace(/\s+/g, ' ');

    // Restore "Scene_"
    name = name.replace(new RegExp(SCENE_MARKER, 'g'), 'Scene_');

    // Legacy period cleanup (mostly harmless at this point)
    name = name.replace(/\.+/g, '.');
    name = name.replace(/^\.+/, '');

    return name.trim();
  }

  private titleCase(fileName: string, respectUserCasing = false): string {
    const delimiters = [' '];

    const lowercaseExceptions = [
      'the',
      'a',
      'an',
      'and',
      'as',
      'at',
      'be',
      'but',
      'by',
      'for',
      'in',
      'it',
      'is',
      'of',
      'off',
      'on',
      'or',
      'per',
      'to',
      'up',
      'via',
      'with',
      'vs',
    ];

    const uppercaseExceptions = ['BBC', 'CD', 'MILF', 'XXX', 'AJ'];

    const mixedCaseExceptions: Record<string, string> = {
      labeau: 'LaBeau',
      deville: 'DeVille',
    };

    let result = fileName;

    for (const delimiter of delimiters) {
      const words = result.split(delimiter);

      for (let i = 0; i < words.length; i++) {
        const original = words[i];
        const lower = original.toLowerCase();
        const upper = original.toUpperCase();
        const isAllLower = original === lower;

        // 1) Mixed-case special words (only keyed by lowercase)
        if (mixedCaseExceptions[lower]) {
          words[i] = mixedCaseExceptions[lower];
          continue;
        }

        // 2) Always-uppercase acronyms
        if (uppercaseExceptions.includes(upper)) {
          words[i] = upper;
          continue;
        }

        // 3) Preserve user casing for non-lower words:
        //    - If respectUserCasing = true  → keep ANY non-all-lower as typed (including small words)
        //    - If respectUserCasing = false → keep only non-small words as typed
        if (!isAllLower) {
          if (respectUserCasing || !lowercaseExceptions.includes(lower)) {
            words[i] = original;
            continue;
          }
        }

        // 4) Small words: lowercase (unless first or after "-"),
        //    but only in *auto* mode (not when respecting user casing)
        const prevWord = words[i - 1];
        if (
          !respectUserCasing &&
          i > 0 &&
          lowercaseExceptions.includes(lower) &&
          prevWord !== '-'
        ) {
          words[i] = lower;
          continue;
        }

        // 5) Otherwise, normal Title Case
        words[i] = lower.charAt(0).toUpperCase() + lower.slice(1);
      }

      result = words.join(delimiter);
    }

    return result;
  }

  private cleanupFunctions(fileName: string): string {
    let name = fileName.trim();

    // Strip quality/codec/etc
    const removePatterns = [
      /2160p/gi,
      /4k/gi,
      /1080p/gi,
      /720p/gi,
      /480p/gi,
      /360p/gi,
      /DVDRip/gi,
      /h264/gi,
      /x264/gi,
      /WEBRip/gi,
      /XXX/gi,
      /MP4/gi,
      /xvid/gi,
    ];
    removePatterns.forEach((re) => (name = name.replace(re, '')));

    // "X vs Y" normalize spacing
    name = name.replace(/(\s+)vs(\s+)/gi, ' vs. ');

    // disc/disk/cd variants -> CD / " - CD"
    name = name.replace(/disc/gi, 'CD');
    name = name.replace(/disk(\s*)/gi, 'CD');
    name = name.replace(/\bcd\b/gi, 'CD');
    name = name.replace(/\b(\s|\.)cd/gi, ' - CD');

    name = name.trim();

    // "#07" or "#   07" → "# 07"
    name = name.replace(/#\s*(\d+)/g, '# $1');

    // Handle "Vol4", "Vol 4", "Vol.4", "Vol#4", "Vol #4"
    name = name.replace(/\bVol\.?\s*#?\s*(\d+)\b/gi, (_m, num: string) => {
      const padded = num.length === 1 ? '0' + num : num;
      return `# ${padded}`;
    });

    // Numbers immediately before " - Scene_": "6 - Scene_1" → "# 06 - Scene_1"
    name = name.replace(
      /\b(\d+)(?=\s-\sScene_)/g,
      (match, num: string, offset: number, full: string) => {
        // If already "# " before it, leave it alone
        if (offset >= 2 && full.slice(offset - 2, offset) === '# ') {
          return match;
        }
        const padded = num.length === 1 ? '0' + num : num;
        return `# ${padded}`;
      }
    );

    // Trailing numbers at the end
    name = name.replace(
      /\b(\d+)\b$/g,
      (match, num: string, offset: number, full: string) => {
        const before2 = full.slice(Math.max(0, offset - 2), offset);
        const before6 = full.slice(Math.max(0, offset - 6), offset);

        // If already "# " before it, or part of "Scene_", leave it alone
        if (before2 === '# ' || before6 === 'Scene_') {
          return match;
        }

        const padded = num.length === 1 ? '0' + num : num;
        return `# ${padded}`;
      }
    );

    // Ensure no redundant "# #"
    name = name.replace(/#\s+#/g, '# ');

    return name.trim();
  }

  private sceneNormalization(name: string): string {
    // Mirrors PHP:
    // /([Ss]cene_\d+)\s(?!- )([A-Za-z\-]+)/
    //   => "$1 - $2"
    return name.replace(/([Ss]cene_\d+)\s(?!- )([A-Za-z\-]+)/g, '$1 - $2');
  }

  private finalCleanup(name: string): string {
    // Mirrors PHP finalCleanup
    name = name.trim();

    // Multiple spaces -> single
    name = name.replace(/\s+/g, ' ');

    // Multiple periods -> single
    name = name.replace(/\.+/g, '.');

    // Leading periods removed
    name = name.replace(/^\.+/, '');

    return name;
  }
}
