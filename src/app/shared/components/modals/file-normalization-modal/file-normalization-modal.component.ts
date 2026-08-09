import {
  ChangeDetectorRef,
  Component,
  Input,
  OnInit,
  OnDestroy,
} from '@angular/core';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  FileService,
  NormalizedFile,
  RenameResult,
} from '@services/file.service';
import { endsWithSceneNumber, getBaseTitle } from '@helpers/title';

// Status string emitted by server/renameTheFilesToNormalize.php
const RENAME_SUCCESS_STATUS = 'Renamed successfully';

// The characters finalCleanup() strips off the end of a name server-side —
// see the rtrim in server/normalize_helpers.php.
const DANGLING_TAIL = /[ \t\n\r\0\x0B\-._]+$/;

// Splits a base name into everything up to and including the scene number, and
// whatever cast follows it: "Ass Man - Scene_1 - Angel Long" -> both halves.
// Accepts the raw spellings too ("scene 1", "scene.2") — before the normalize
// rename runs, the working name is still un-canonical, and matching only
// "Scene_N" made sceneBaseOf() fall back to the WHOLE name (old cast included),
// so each keystroke in the cast field appended instead of replaced.
const SCENE_SPLIT = /^(.*?\bscene[\s._-]*\d{1,3})(?:\s*-\s*(.*))?$/i;

/**
 * Search URLs for the two metadata sites Sean uses. These open in HIS browser —
 * a person browsing, which both sites allow. Nothing here is ever fetched by the
 * app or by an agent: iafd.com's robots.txt disallows ClaudeBot and friends
 * outright, and adultdvdempire.com disallows its /search paths to crawlers.
 * NOTE: these URL shapes are unverified by me for that reason; if either site
 * changes its search route, it is a one-line fix here.
 */
const LOOKUP_SITES: ReadonlyArray<{ label: string; url: (q: string) => string }> = [
  {
    label: 'IAFD',
    url: (q) =>
      `https://www.iafd.com/results.asp?searchtype=comprehensive&searchstring=${encodeURIComponent(q)}`,
  },
  {
    label: 'ADE',
    url: (q) =>
      `https://www.adultdvdempire.com/allsearch/search?q=${encodeURIComponent(q)}`,
  },
];

export type NormalizationModalTab = 'normalize' | 'cast';

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

  allSelected: boolean = true;

  // Tabs are freely navigable; a rename always lands on "Add Cast".
  activeTab: NormalizationModalTab = 'normalize';

  isRenaming: boolean = false;
  renameSummary: { renamed: number; failed: number } | null = null;

  /**
   * Rows the user has started editing on the Add Cast tab. Typing a cast name
   * makes a row stop qualifying for the list, so without this it would vanish
   * mid-keystroke. Cleared once the row is successfully renamed.
   */
  private castEdited = new Set<NormalizedFile>();

  private destroyed = false;

  /** The row whose name input currently has focus, if any. */
  private focusedFile: NormalizedFile | null = null;

  // Per-file debounce timers for the live (server-driven) preview.
  private previewTimers = new Map<
    NormalizedFile,
    ReturnType<typeof setTimeout>
  >();

  constructor(
    public activeModal: NgbActiveModal,
    private fileService: FileService,
    private cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.files.forEach((f) => {
      f.exclude = false;
      f.userEdited = false;

      // Show the *actual* on-disk name (without extension) in the left input.
      // checkFileNamesToNormalize already normalized each name server-side, so
      // the initial preview comes straight from its response — no extra calls.
      f.workingBaseName = this.stripExtension(f.originalFileName);
    });

    this.loadCastNames();

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
    this.destroyed = true;
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
   * Repaints this modal's view. The app runs zoneless, so an async callback has
   * to do this itself. detectChanges() rather than markForCheck() because the
   * latter only queues work for the scheduler, whose tick rides on
   * requestAnimationFrame — which does not fire while the tab is hidden, so a
   * preview could sit unrendered until the next user event.
   */
  private refreshView(): void {
    if (!this.destroyed) {
      this.cdr.detectChanges();
    }
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
        } else {
          file.needsNormalization = true;
          // The column shows the name the file WILL get, so it must be filled
          // whenever a rename is pending — including when the user has already
          // typed the normalized form (targetFull === workingFull). Gating it on
          // "normalization changed the text" made the column blank out on every
          // ordinary keystroke and only reappear on a space or dangling dash,
          // which normalization strips.
          file.newFileName = normalizationChangesName ? targetFull : workingFull;
        }
        this.refreshView();
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

  /**
   * The name a file will have once "Rename Files" runs: the pending new name
   * for included files, otherwise the current on-disk name. Extension-free —
   * the UI only ever shows base names.
   */
  effectiveBaseName(file: NormalizedFile): string {
    const name =
      !file.exclude && file.newFileName
        ? file.newFileName
        : file.originalFileName;
    return this.stripExtension(name);
  }

  /**
   * The pending rename target, extension stripped, for the preview column.
   *
   * While the row's input has focus, a trailing separator the server strips
   * ("Scene_1 - ") is put back for display only, so the preview doesn't look
   * like it swallowed the " - " you just typed. Blur snaps it to the true
   * normalized form. `newFileName` — what a rename actually uses — is never
   * touched by this.
   */
  previewBaseName(file: NormalizedFile): string {
    const base = this.stripExtension(file.newFileName);
    if (!base || file !== this.focusedFile) {
      return base;
    }
    const tail = (file.workingBaseName ?? '').match(DANGLING_TAIL)?.[0] ?? '';
    return tail && !base.endsWith(tail) ? base + tail : base;
  }

  onNameFocus(file: NormalizedFile): void {
    this.focusedFile = file;
  }

  onNameBlur(file: NormalizedFile): void {
    if (this.focusedFile === file) {
      this.focusedFile = null;
    }
    // Snap the cast field from what was typed to the tidied form.
    this.castDrafts.delete(file);
  }

  /**
   * Scene files that still need a cast: the base name ends in a scene number
   * ("Ass Man - Scene_1") with nothing named after it. Anything already
   * carrying a cast ("… - Scene_1 - Kissa Sins") is done and drops off.
   *
   * Judged on the effective name so pending renames count, and sorted by the
   * on-disk name — a key that can't change under the user's cursor, so rows
   * never reorder mid-edit.
   */
  get castFiles(): NormalizedFile[] {
    return this.files
      .filter(
        (file) =>
          (this.castEdited.has(file) ||
            endsWithSceneNumber(this.effectiveBaseName(file))) &&
          !this.dismissedTitles.has(this.lookupQuery(file).toLowerCase()),
      )
      .sort((a, b) =>
        a.originalFileName.localeCompare(b.originalFileName, undefined, {
          sensitivity: 'base',
        }),
      );
  }

  /**
   * Titles set aside via the group-header checkbox — cast info couldn't be
   * found, so the whole group leaves the Add Cast list (and stops counting
   * as remaining work for the close-on-done logic). View-state only, per
   * modal session: it never touches `exclude`, so a dismissed file keeps any
   * pending *normalization* rename on the other tab.
   */
  readonly dismissedTitles = new Set<string>();

  dismissGroup(group: { title: string }): void {
    this.dismissedTitles.add(group.title.toLowerCase());
  }

  get hasPendingCastRenames(): boolean {
    return this.castFiles.some((file) => !file.exclude && !!file.newFileName);
  }

  private castGroupsCache: {
    fingerprint: string;
    groups: Array<{ title: string; files: NormalizedFile[] }>;
  } | null = null;

  /**
   * castFiles grouped by movie title, so related scenes (Ass Man - Scene_1,
   * Scene_2, …) sit under one header carrying a single set of lookup links —
   * one search covers the whole collection. Sean clicks the link; the search
   * happens in his browser, as himself.
   *
   * Memoized: this getter runs on every change-detection pass, and handing
   * *ngFor a fresh array of fresh group objects each time made it tear down
   * and rebuild every row — 60+ datalist-linked inputs — per pass, which froze
   * the page outright the first time the tab was opened against a real batch.
   * The template's trackBy is the second half of the same defense.
   */
  get castFileGroups(): Array<{ title: string; files: NormalizedFile[] }> {
    const files = this.castFiles;
    const fingerprint = files
      .map((f) => `${f.originalFileName} ${f.workingBaseName ?? ''} ${f.exclude ? 1 : 0}`)
      .join('');
    if (this.castGroupsCache?.fingerprint === fingerprint) {
      return this.castGroupsCache.groups;
    }

    const groups = new Map<string, { title: string; files: NormalizedFile[] }>();
    for (const file of files) {
      const title = this.lookupQuery(file);
      const key = title.toLowerCase();
      const group = groups.get(key);
      if (group) {
        group.files.push(file);
      } else {
        groups.set(key, { title, files: [file] });
      }
    }
    this.castGroupsCache = { fingerprint, groups: [...groups.values()] };
    return this.castGroupsCache.groups;
  }

  trackGroup(_index: number, group: { title: string }): string {
    return group.title;
  }

  trackFile(_index: number, file: NormalizedFile): NormalizedFile {
    return file;
  }

  lookupUrlForTitle(site: { url: (q: string) => string }, title: string): string {
    return site.url(title);
  }

  /**
   * Edit from the Add Cast tab. Feeds the same server-side normalize preview
   * as the other tab, so "Rename Files" applies cast names too.
   */
  onCastNameChange(file: NormalizedFile): void {
    this.castEdited.add(file);
    this.onWorkingNameChange(file);
  }

  readonly lookupSites = LOOKUP_SITES;

  /** Known performer names, for the Add Cast autocomplete. */
  castNames: string[] = [];

  /** Lowercased vocabulary for segmenting pasted runs of unseparated names. */
  private castNameSet = new Set<string>();
  private castNameMaxWords = 2;

  /**
   * Raw text as typed into a row's cast field, kept while the user works so
   * the input never rewrites itself mid-keystroke — binding the tidied value
   * straight back would eat a trailing comma the moment it was typed. Cleared
   * on blur (the field then snaps to the tidied form) and on rename success.
   */
  private castDrafts = new Map<NormalizedFile, string>();

  /** What the row's cast input displays: the in-progress draft, else truth. */
  castInputValue(file: NormalizedFile): string {
    return this.castDrafts.get(file) ?? this.castOf(file);
  }

  /**
   * The datalist options currently offered (max 12). The full vocabulary is
   * ~3k names; rendering them all as static <option>s meant every row rebuild
   * relinked a 3k-node list — part of the tab-open freeze. Instead the list
   * holds only matches for what's being typed, recomputed per keystroke.
   */
  castSuggestions: string[] = [];

  /** The part of the name up to and including "Scene_N". */
  sceneBaseOf(file: NormalizedFile): string {
    const working = file.workingBaseName ?? '';
    return working.match(SCENE_SPLIT)?.[1] ?? working;
  }

  /** Whatever cast has been typed after the scene number ('' when none yet). */
  castOf(file: NormalizedFile): string {
    return (file.workingBaseName ?? '').match(SCENE_SPLIT)?.[2] ?? '';
  }

  /**
   * The title to search for on IAFD/ADE — the scene number and any cast are
   * noise to those sites, so search the movie title alone.
   */
  lookupQuery(file: NormalizedFile): string {
    const base = this.sceneBaseOf(file).replace(/\s*-\s*Scene_\d{1,3}\s*$/i, '');
    return getBaseTitle(base) || base;
  }

  lookupUrl(site: { url: (q: string) => string }, file: NormalizedFile): string {
    return site.url(this.lookupQuery(file));
  }

  /**
   * Sets the cast half of the name, leaving "<title> - Scene_N" alone. Typing,
   * pasting a copied cast list, or picking an autocomplete suggestion all land
   * here, then flow through the same normalize preview as everything else.
   */
  setCast(file: NormalizedFile, cast: string): void {
    this.castDrafts.set(file, cast);
    this.updateCastSuggestions(cast);
    const tidied = this.tidyCastInput(cast);
    const base = this.sceneBaseOf(file);
    file.workingBaseName = tidied ? `${base} - ${tidied}` : base;
    this.onCastNameChange(file);
  }

  /**
   * Recompute the (small) suggestion list for the text being typed. Matches
   * the segment after the last comma, but each option carries the full
   * composed value ("Angel Long, Jane Wilde") because the browser filters
   * datalist options against the input's ENTIRE value — a bare "Jane Wilde"
   * option would never surface while "Angel Long, Ja" is in the field.
   */
  private updateCastSuggestions(text: string): void {
    const parts = (text ?? '').split(',');
    const segment = (parts.pop() ?? '').trim().toLowerCase();
    const prefix = parts.length ? parts.map((p) => p.trim()).join(', ') + ', ' : '';
    if (segment.length < 2) {
      this.castSuggestions = [];
      return;
    }
    const startsWith: string[] = [];
    const contains: string[] = [];
    for (const name of this.castNames) {
      const lower = name.toLowerCase();
      if (lower.startsWith(segment)) {
        startsWith.push(prefix + name);
        if (startsWith.length >= 12) {
          break;
        }
      } else if (contains.length < 12 && lower.includes(segment)) {
        contains.push(prefix + name);
      }
    }
    this.castSuggestions = [...startsWith, ...contains].slice(0, 12);
  }

  /**
   * Cleans a pasted cast list into "Name, Name": collapses whitespace, accepts
   * the separators these sites use (&, "and", newlines, semicolons), and drops
   * wrapping punctuation. A part with no separators but 4+ words is a pasted
   * run of unseparated names ("angel long paige owens") and gets segmented.
   * Deliberately does NOT title-case — the PHP pipeline owns casing, same as
   * every other name in this modal.
   */
  private tidyCastInput(raw: string): string {
    return (raw ?? '')
      .split(/\s*(?:,|&|;|\/|\n|\r|\band\b)\s*/i)
      .map((part) => part.replace(/\s+/g, ' ').trim())
      .map((part) => part.replace(/^[-_.,;:|'"()[\]]+|[-_.,;:|'"()[\]]+$/g, '').trim())
      .filter((part) => /\p{L}/u.test(part))
      .flatMap((part) => {
        const words = part.split(' ');
        return words.length >= 4 ? this.segmentCastRun(words) : [part];
      })
      .join(', ');
  }

  /**
   * Split a run of unseparated names on name boundaries: greedy longest match
   * against the known vocabulary first (so "anna claire clouds" stays one
   * name), pairs of words as the fallback, and a single leftover word joins
   * the name before it rather than standing alone.
   */
  private segmentCastRun(words: string[]): string[] {
    const out: string[] = [];
    let i = 0;
    while (i < words.length) {
      const remaining = words.length - i;
      let take = 0;
      for (let len = Math.min(this.castNameMaxWords, remaining); len >= 2; len--) {
        if (this.castNameSet.has(words.slice(i, i + len).join(' ').toLowerCase())) {
          take = len;
          break;
        }
      }
      if (!take) {
        if (remaining === 1 && out.length) {
          out[out.length - 1] += ` ${words[i]}`;
          i++;
          continue;
        }
        take = Math.min(2, remaining);
      }
      out.push(words.slice(i, i + take).join(' '));
      i += take;
    }
    return out;
  }

  /** Pull the autocomplete vocabulary, and feed newly-used names back into it. */
  private loadCastNames(add?: string[]): void {
    this.fileService.getCastNames(this.directory, add).subscribe({
      next: ({ names }) => {
        this.castNames = names ?? [];
        this.castNameSet = new Set(this.castNames.map((n) => n.toLowerCase()));
        this.castNameMaxWords = this.castNames.reduce(
          (max, n) => Math.max(max, n.split(' ').length),
          2,
        );
        this.refreshView();
      },
      error: () => {
        // Autocomplete is a convenience — a failure here must not break the modal.
      },
    });
  }

  /**
   * Renames the included files on the server, then lands on the "Add Cast"
   * tab so scene files can be worked on — unless nothing needs normalizing
   * AND no scene file is waiting for a cast, in which case the work is done
   * and the modal closes itself.
   */
  renameFiles(): void {
    if (this.isRenaming) {
      return;
    }

    const filesToRename = this.files.filter(
      (file) =>
        !file.exclude &&
        !!file.newFileName &&
        file.newFileName !== file.originalFileName,
    );

    if (filesToRename.length === 0) {
      this.finishRenamePass();
      return;
    }

    this.isRenaming = true;
    this.fileService.renameTheFilesToNormalize(filesToRename).subscribe({
      next: ({ results }) => {
        this.isRenaming = false;
        this.applyRenameResults(results ?? []);
        this.finishRenamePass();
        this.refreshView();
      },
      error: (error) => {
        this.isRenaming = false;
        this.refreshView();
        console.error('Error renaming files:', error);
        alert('Failed to rename files. See console for details.');
      },
    });
  }

  /**
   * Where "Rename Files" lands: the Add Cast tab while any work remains —
   * pending normalizations (failed renames keep theirs, so errors stay
   * visible) or scene files without a cast. With both lists empty the modal
   * closes; the parent page re-enables Update Database on close.
   */
  private finishRenamePass(): void {
    if (!this.hasFilesToRename && this.castFiles.length === 0) {
      this.activeModal.close('all-done');
      return;
    }
    this.activeTab = 'cast';
  }

  /**
   * Folds the server's per-file results back into the list: successes become
   * the new on-disk name; failures keep their pending rename (retryable) and
   * show the server's status.
   */
  private applyRenameResults(results: RenameResult[]): void {
    const byOriginalName = new Map(results.map((r) => [r.originalFileName, r]));
    const castLanded: string[] = [];
    let renamed = 0;
    let failed = 0;

    this.files.forEach((file) => {
      const result = byOriginalName.get(file.originalFileName);
      if (!result) {
        return;
      }

      file.status = result.status;
      if (result.status === RENAME_SUCCESS_STATUS) {
        renamed++;
        file.originalFileName = result.newFileName;
        file.workingBaseName = this.stripExtension(result.newFileName);
        file.newFileName = '';
        file.needsNormalization = false;
        file.userEdited = false;
        file.renameError = undefined;
        // Remember any cast that actually landed on disk, so it autocompletes
        // next time — including after this batch leaves the staging drive.
        // Read AFTER workingBaseName is updated, so it reflects the new name.
        const cast = this.castOf(file);
        if (cast) {
          castLanded.push(cast);
        }
        // The edit landed on disk; let the row leave the Add Cast list if it
        // now carries a cast name, and let its input show the on-disk truth.
        this.castEdited.delete(file);
        this.castDrafts.delete(file);
      } else {
        failed++;
        file.renameError = result.status;
      }
    });

    this.renameSummary = { renamed, failed };

    if (castLanded.length) {
      this.loadCastNames(castLanded);
    }
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
