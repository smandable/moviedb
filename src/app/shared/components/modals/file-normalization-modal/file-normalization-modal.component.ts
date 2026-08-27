import {
  ChangeDetectorRef,
  Component,
  ElementRef,
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

// The filesystem refuses any single file name longer than this many UTF-8
// bytes (NAME_MAX). A long cast list can push a pending rename past it, so
// the row warns at typing time; renameTheFilesToNormalize.php enforces the
// same cap server-side.
const MAX_FILENAME_BYTES = 255;
const UTF8 = new TextEncoder();

// How every file list in this modal is ordered. `numeric` compares digit runs
// as numbers, so Scene_10 sorts after Scene_9, not between Scene_1 and
// Scene_2.
const NAME_ORDER = new Intl.Collator(undefined, {
  sensitivity: 'base',
  numeric: true,
});

// Splits a base name into everything up to and including the scene number, and
// whatever cast follows it: "Ass Man - Scene_1 - Angel Long" -> both halves.
// Accepts the raw spellings too ("scene 1", "scene.2") — before the normalize
// rename runs, the working name is still un-canonical, and matching only
// "Scene_N" made sceneBaseOf() fall back to the WHOLE name (old cast included),
// so each keystroke in the cast field appended instead of replaced.
const SCENE_SPLIT = /^(.*?\bscene[\s._-]*\d{1,3})(?:\s*-\s*(.*))?$/i;

/**
 * Separators a cast list may arrive with — typed, pasted, or copied off a site.
 * Kept as source text because the two call sites need different flags:
 * tidyCastInput() splits on it, castSegmentStart() scans for the LAST match with
 * a `g` twin. ONE rule, so the tidier and the autocomplete cannot drift on where
 * a name ends — that drift is exactly what left an uncomma'd second name with no
 * suggestions. The single deliberate exception is documented in
 * castSegmentStart(): a word separator with nothing after it ("...,  And") is
 * still being typed, so only the tidier treats it as a separator.
 */
const CAST_SEPARATOR_SRC = String.raw`\s*(?:,|&|;|\/|\n|\r|\band\b)\s*`;
const CAST_SEPARATOR = new RegExp(CAST_SEPARATOR_SRC, 'i');

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

  /**
   * Rows whose cast field received a DELIBERATE period: a small edit (at most
   * one character net) that raised the period count — typing "." after "St",
   * as opposed to pasting "Destiny St. Claire" wholesale. Normalization
   * strips a pasted period as usual, but a typed one is intent, so the
   * preview request asks the server to keep the cast tail's periods
   * (keepCastDots). Cleared when the cast no longer contains any period.
   */
  private castDotsTyped = new Set<NormalizedFile>();

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
    private host: ElementRef<HTMLElement>,
  ) {}

  /**
   * Tab steps between cast fields, skipping the working-name box and the group
   * header controls sitting between them: the Add Cast tab is worked one cast
   * name after another, and stopping at everything in between doubles the
   * keystrokes.
   *
   * The fields are read from the DOM on each press rather than cached, so rows
   * appearing or being dismissed can't leave a stale list. At either end the
   * default takes over — Tab still leaves the table, so focus is never trapped.
   */
  focusAdjacentCastInput(event: Event, direction: 1 | -1): void {
    const inputs = Array.from(
      this.host.nativeElement.querySelectorAll<HTMLInputElement>(
        'input.cast-input',
      ),
    );
    const index = inputs.indexOf(event.target as HTMLInputElement);
    const next = index === -1 ? undefined : inputs[index + direction];
    if (!next) {
      return;
    }
    event.preventDefault();
    next.focus();
    // Match what tabbing into a field normally does.
    next.select();
  }

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
      NAME_ORDER.compare(
        a.newFileName || a.workingBaseName || '',
        b.newFileName || b.workingBaseName || '',
      ),
    );
  }

  ngOnDestroy(): void {
    this.destroyed = true;
    this.previewTimers.forEach((t) => clearTimeout(t));
    this.previewTimers.clear();
    if (this.copiedTimer) {
      clearTimeout(this.copiedTimer);
    }
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

    this.fileService
      .normalizeName(workingBase, !!file.userEdited, this.castDotsTyped.has(file))
      .subscribe({
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

  readonly maxFileNameLength = MAX_FILENAME_BYTES;

  /**
   * UTF-8 byte length of the pending rename target — what the filesystem's
   * 255-byte cap is measured against. 0 when no rename is pending.
   */
  newNameLength(file: NormalizedFile): number {
    return file.newFileName ? UTF8.encode(file.newFileName).length : 0;
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
      .sort((a, b) => NAME_ORDER.compare(a.originalFileName, b.originalFileName));
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
      .map((f) => `${f.originalFileName}\u0000${f.workingBaseName ?? ''}\u0000${f.exclude ? 1 : 0}`)
      .join('\u0001');
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

  /** Title most recently copied to the clipboard, while its "✓" shows. */
  copiedTitle: string | null = null;
  private copiedTimer: ReturnType<typeof setTimeout> | null = null;

  /**
   * Copies a group's title for pasting into a search box elsewhere. The
   * button flips to a check for a moment as feedback; zoneless, so the async
   * clipboard promise has to trigger the repaint itself.
   */
  copyTitle(title: string): void {
    navigator.clipboard?.writeText(title).then(
      () => {
        this.copiedTitle = title;
        if (this.copiedTimer) {
          clearTimeout(this.copiedTimer);
        }
        this.copiedTimer = setTimeout(() => {
          this.copiedTimer = null;
          this.copiedTitle = null;
          this.refreshView();
        }, 1500);
        this.refreshView();
      },
      (err) => console.error('Copy failed:', err),
    );
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

  /**
   * Lowercased vocabulary, used to find name boundaries in a run of unseparated
   * names — both when tidying what was typed (segmentCastRun) and when deciding
   * which part of it the user is still typing (castSegmentStart). Set lookups
   * for whole names, plus a sorted copy for the "is anything still being typed
   * here?" prefix test, because both run on every keystroke.
   */
  private castNameSet = new Set<string>();
  private castNamesSorted: string[] = [];
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

  /**
   * Sets the cast half of the name, leaving "<title> - Scene_N" alone. Typing,
   * pasting a copied cast list, or picking an autocomplete suggestion all land
   * here, then flow through the same normalize preview as everything else.
   */
  setCast(file: NormalizedFile, cast: string): void {
    const previous = this.castInputValue(file);
    const previousDots = (previous.match(/\./g) ?? []).length;
    const nextDots = (cast.match(/\./g) ?? []).length;
    if (nextDots > previousDots && cast.length <= previous.length + 1) {
      this.castDotsTyped.add(file);
    } else if (nextDots === 0) {
      this.castDotsTyped.delete(file);
    }

    this.castDrafts.set(file, cast);
    this.updateCastSuggestions(cast);
    const tidied = this.tidyCastInput(cast);
    const base = this.sceneBaseOf(file);
    file.workingBaseName = tidied ? `${base} - ${tidied}` : base;
    this.onCastNameChange(file);
  }

  /**
   * Recompute the (small) suggestion list for the name being typed.
   *
   * The segment comes from castSegmentStart(), i.e. from the SAME notion of a
   * name boundary tidyCastInput()/segmentCastRun() use — so "Angel Long Jane
   * Wil" offers "Jane Wilde" even though no comma has been typed. Splitting on
   * commas alone (what this used to do) made the whole run one unmatchable
   * segment: the tidier had already understood the boundary well enough to put
   * a comma in the New Filename preview, while the autocomplete offered nothing.
   *
   * Each option carries the full composed value because the browser filters
   * datalist options against the input's ENTIRE value — a bare "Jane Wilde"
   * would never surface while "Angel Long, Ja" is in the field. The prefix is
   * spliced back in VERBATIM and is never re-punctuated: the comma the tidier is
   * about to insert is not in the field yet, so an option carrying one
   * ("Angel Long, Jane Wilde") is neither a prefix nor a substring of the typed
   * "Angel Long Jane Wil" and no filter rule would show it. Picking the raw
   * option runs it back through setCast(), where tidyCastInput() adds the comma.
   */
  private updateCastSuggestions(text: string): void {
    const raw = text ?? '';
    const { start, glued } = this.castSegmentStart(raw);
    const prefix = raw.slice(0, start);
    const segment = raw.slice(start).trim().toLowerCase();
    if (segment.length < 2) {
      this.castSuggestions = [];
      return;
    }
    const startsWith: string[] = [];
    const contains: string[] = [];
    for (const name of this.castNames) {
      // On a glued boundary (no separator typed) only a multi-word name may be
      // offered — the whole run has to survive TWO splitters, and one-word names
      // are where they disagree:
      //   * the TS tidier only segments a part of 4+ words, and segmentCastRun()
      //     only matches names of 2+ words, so it leaves "Angel Long Belladonna"
      //     whole and pair-GUESSES a 4-word run, writing a comma in the wrong
      //     place ("Belladonna Isabella, De Laa");
      //   * PHP (castDesquash) then re-splits a comma part of 3+ words that
      //     segments cleanly into store names — which does rescue the 3-word
      //     case, but it can only split parts, never re-join the tidier's bad
      //     guess, and it never splits a TWO-word part at all.
      // A measured sweep of 65k offers found 43 filenames that came out wrong
      // once one-word names were allowed here. Type the comma and they are
      // offered as usual, because then neither splitter has to find the seam.
      if (glued && !name.includes(' ')) {
        continue;
      }
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
   * Where the name currently being typed starts inside the raw text: past the
   * last separator tidyCastInput() understands, then past any leading words that
   * already resolve to known full names — the same boundaries segmentCastRun()
   * splits on. `glued` reports that the second half did the work, i.e. no
   * separator sits between the previous name and this one (which constrains what
   * updateCastSuggestions() may offer).
   *
   * Deliberately narrower than the tidier: it walks only names the vocabulary
   * confirms, never segmentCastRun()'s pair-guess fallback for unknown words.
   * That is broader than "the first name must be known" — ANY unknown name stops
   * the walk, and everything after it becomes one unmatchable segment, so
   * "Angel Long, <new performer> Jane W" offers nothing either. Fewer
   * completions than the tidier would split, never one it would leave un-split;
   * typing a separator after the unknown name restores suggestions.
   */
  private castSegmentStart(text: string): { start: number; glued: boolean } {
    let start = 0;
    // A fresh regex each call: a shared /g/ one carries lastIndex between calls.
    const separators = new RegExp(CAST_SEPARATOR_SRC, 'gi');
    for (let match = separators.exec(text); match; match = separators.exec(text)) {
      // A WORD separator with nothing after it may not be a separator at all —
      // "Kortney Kane, And" is how "Andi Rose" starts. Leave it in the segment
      // so it can still be completed; the next character settles which it is.
      if (match.index + match[0].length === text.length && /\p{L}$/u.test(match[0])) {
        break;
      }
      start = match.index + match[0].length;
    }

    const tokens = [...text.slice(start).matchAll(/\S+/g)];
    const words = tokens.map((token) => token[0]);
    let consumed = 0;
    while (consumed < words.length) {
      const take = this.knownNameLengthAt(words, consumed);
      // Stop before the trailing word(s): those are what's being typed, not a
      // finished name — a fully typed "Angel Long" must still suggest itself.
      if (!take || consumed + take >= words.length) {
        break;
      }
      // Stop too when a LONGER name starts with this one plus what follows:
      // "Aurora Snow" is a name and so is "Aurora Snow Pack", so with "Aurora
      // Snow Pac" in the field, consuming the short name would make "Pac" the
      // segment and offer completions for it instead of the name being typed.
      if (this.hasNameStartingWith(words.slice(consumed, consumed + take + 1).join(' '))) {
        break;
      }
      consumed += take;
    }

    return consumed
      ? { start: start + (tokens[consumed].index ?? 0), glued: true }
      : { start, glued: false };
  }

  /**
   * Is `prefix` the start of some known name? Binary search over the sorted
   * vocabulary — a Set answers "is this a name", not "could this become one",
   * and this runs inside the per-keystroke boundary walk.
   */
  private hasNameStartingWith(prefix: string): boolean {
    const needle = prefix.toLowerCase();
    let lo = 0;
    let hi = this.castNamesSorted.length;
    while (lo < hi) {
      const mid = (lo + hi) >> 1;
      if (this.castNamesSorted[mid] < needle) {
        lo = mid + 1;
      } else {
        hi = mid;
      }
    }
    return this.castNamesSorted[lo]?.startsWith(needle) ?? false;
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
      .split(CAST_SEPARATOR)
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
      let take = this.knownNameLengthAt(words, i);
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

  /**
   * How many words starting at `i` form a known full name — greedy longest match
   * against the vocabulary, so "anna claire clouds" wins over "anna claire".
   * 0 when nothing matches.
   *
   * Two words is the floor: mid-run, a one-word entry can't be told apart from
   * half of a two-word name ("Angel Long" would split at "Angel" if "Angel" were
   * a name in its own right). Both the tidier and the autocomplete ask this one
   * question, so they agree on where a name ends.
   */
  private knownNameLengthAt(words: string[], i: number): number {
    const remaining = words.length - i;
    for (let len = Math.min(this.castNameMaxWords, remaining); len >= 2; len--) {
      if (this.castNameSet.has(words.slice(i, i + len).join(' ').toLowerCase())) {
        return len;
      }
    }
    return 0;
  }

  /** Pull the autocomplete vocabulary, and feed newly-used names back into it. */
  private loadCastNames(add?: string[]): void {
    this.fileService.getCastNames(this.directory, add).subscribe({
      next: ({ names }) => {
        this.castNames = names ?? [];
        this.castNameSet = new Set(this.castNames.map((n) => n.toLowerCase()));
        this.castNamesSorted = [...this.castNameSet].sort();
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
