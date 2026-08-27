import {
  ComponentFixture,
  TestBed,
  fakeAsync,
  tick,
} from '@angular/core/testing';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import { of, throwError } from 'rxjs';
import { FileNormalizationModalComponent } from './file-normalization-modal.component';
import { FileService, NormalizedFile } from '@services/file.service';

describe('FileNormalizationModalComponent', () => {
  let component: FileNormalizationModalComponent;
  let fixture: ComponentFixture<FileNormalizationModalComponent>;
  let fileService: FileService;

  const makeFile = (over: Partial<NormalizedFile> = {}): NormalizedFile => ({
    path: '/test',
    originalFileName: 'the.matrix.mp4',
    newFileName: 'The Matrix.mp4',
    fileExtension: 'mp4',
    fileNameNoExtension: 'The Matrix',
    needsNormalization: true,
    status: 'Needs Renaming',
    ...over,
  });

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [FileNormalizationModalComponent, HttpClientTestingModule],
      providers: [
        FileService,
        { provide: NgbActiveModal, useValue: { close: () => {}, dismiss: () => {} } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(FileNormalizationModalComponent);
    component = fixture.componentInstance;
    fileService = TestBed.inject(FileService);
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  describe('ngOnInit', () => {
    it('seeds the working name + preview from server data without extra calls', () => {
      const spy = spyOn(fileService, 'normalizeName');
      component.files = [makeFile()];

      component.ngOnInit();

      expect(component.files[0].workingBaseName).toBe('the.matrix');
      expect(component.files[0].needsNormalization).toBeTrue();
      expect(component.files[0].exclude).toBeFalse();
      expect(spy).not.toHaveBeenCalled();
    });
  });

  describe('onWorkingNameChange', () => {
    it('debounces, normalizes server-side, and updates the preview', fakeAsync(() => {
      const spy = spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'The Matrix' }),
      );
      const file = makeFile({
        workingBaseName: 'the matrix',
        needsNormalization: false,
        newFileName: '',
      });
      component.files = [file];

      component.onWorkingNameChange(file);
      expect(file.userEdited).toBeTrue();
      expect(spy).not.toHaveBeenCalled(); // still within debounce window

      tick(250);

      expect(spy).toHaveBeenCalledWith('the matrix', true);
      expect(file.needsNormalization).toBeTrue();
      expect(file.newFileName).toBe('The Matrix.mp4');
    }));

    // Regression: the New Filename column used to be gated on "normalization
    // changed the text", so typing an already-correct cast name blanked it out
    // and it only reappeared on a space or dangling dash.
    it('still shows the pending name when the typed text is already normalized', fakeAsync(() => {
      const typed = 'Ass Man - Scene_1 - Kissa';
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: typed }), // server returns it unchanged
      );
      const file = makeFile({
        originalFileName: 'Ass Man - Scene_1.mp4',
        newFileName: '',
        needsNormalization: false,
        workingBaseName: typed,
      });
      component.files = [file];

      component.onWorkingNameChange(file);
      tick(250);

      expect(file.needsNormalization).toBeTrue();
      expect(file.newFileName).toBe(`${typed}.mp4`);
      expect(component.previewBaseName(file)).toBe(typed);
    }));

    it('keeps a trailing separator in the preview while the input has focus', fakeAsync(() => {
      // The server strips a dangling " - "; while typing we put it back for
      // display so the preview doesn't look like it ate the separator.
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'Ass Man - Scene_1' }),
      );
      const file = makeFile({
        originalFileName: 'ass man.scene 1.mp4',
        workingBaseName: 'Ass Man - Scene_1 - ',
      });
      component.files = [file];

      component.onNameFocus(file);
      component.onWorkingNameChange(file);
      tick(250);

      expect(component.previewBaseName(file)).toBe('Ass Man - Scene_1 - ');
      // ...but the actual rename target stays the true normalized name
      expect(file.newFileName).toBe('Ass Man - Scene_1.mp4');

      component.onNameBlur(file);
      expect(component.previewBaseName(file)).toBe('Ass Man - Scene_1');
    }));

    it('does not double up a separator the server kept', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'Ass Man - Scene_1 - K' }),
      );
      const file = makeFile({
        originalFileName: 'ass man.scene 1.mp4',
        workingBaseName: 'Ass Man - Scene_1 - K',
      });
      component.files = [file];

      component.onNameFocus(file);
      component.onWorkingNameChange(file);
      tick(250);

      expect(component.previewBaseName(file)).toBe('Ass Man - Scene_1 - K');
    }));

    it('leaves other rows unaffected while one has focus', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'Ass Man - Scene_2' }),
      );
      const focused = makeFile({ workingBaseName: 'Ass Man - Scene_1 - ' });
      const other = makeFile({
        originalFileName: 'ass man.scene 2.mp4',
        workingBaseName: 'Ass Man - Scene_2 - ',
        newFileName: 'Ass Man - Scene_2.mp4',
      });
      component.files = [focused, other];

      component.onNameFocus(focused);

      expect(component.previewBaseName(other)).toBe('Ass Man - Scene_2');
    }));

    it('clears the column when the typed name already matches the file on disk', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'The Matrix' }),
      );
      const file = makeFile({
        originalFileName: 'The Matrix.mp4',
        workingBaseName: 'The Matrix',
      });
      component.files = [file];

      component.onWorkingNameChange(file);
      tick(250);

      expect(file.needsNormalization).toBeFalse();
      expect(file.newFileName).toBe('');
    }));

    it('collapses rapid edits into a single server call', fakeAsync(() => {
      const spy = spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'The Matrix' }),
      );
      const file = makeFile();
      component.files = [file];

      component.onWorkingNameChange(file);
      tick(100);
      component.onWorkingNameChange(file);
      tick(100);
      component.onWorkingNameChange(file);
      tick(250);

      expect(spy).toHaveBeenCalledTimes(1);
    }));
  });

  describe('renameFiles', () => {
    it('renames only included files whose name changes, stays open, and lands on Add Cast', () => {
      const a = makeFile({
        originalFileName: 'a scene_1.mp4',
        newFileName: 'A - Scene_1.mp4',
        exclude: false,
      });
      const b = makeFile({
        originalFileName: 'b.mp4',
        newFileName: 'B.mp4',
        exclude: true,
      });
      component.files = [a, b];

      const spy = spyOn(
        fileService,
        'renameTheFilesToNormalize',
      ).and.returnValue(
        of({
          results: [
            {
              originalFileName: 'a scene_1.mp4',
              newFileName: 'A - Scene_1.mp4',
              status: 'Renamed successfully',
            },
          ],
        }),
      );
      spyOn(component.activeModal, 'close');

      component.renameFiles();

      expect(spy).toHaveBeenCalledWith([a]);
      expect(component.activeModal.close).not.toHaveBeenCalled();
      expect(component.activeTab).toBe('cast');

      // The renamed file now reflects its on-disk name
      expect(a.originalFileName).toBe('A - Scene_1.mp4');
      expect(a.workingBaseName).toBe('A - Scene_1');
      expect(a.newFileName).toBe('');
      expect(a.needsNormalization).toBeFalse();
      expect(component.renameSummary).toEqual({ renamed: 1, failed: 0 });
    });

    it('marks failures with the server status and keeps them renameable', () => {
      const a = makeFile({
        originalFileName: 'a.mp4',
        newFileName: 'A.mp4',
        exclude: false,
      });
      component.files = [a];

      spyOn(fileService, 'renameTheFilesToNormalize').and.returnValue(
        of({
          results: [
            {
              originalFileName: 'a.mp4',
              newFileName: 'A.mp4',
              status: 'New file name already exists',
            },
          ],
        }),
      );

      component.renameFiles();

      expect(a.originalFileName).toBe('a.mp4');
      expect(a.renameError).toBe('New file name already exists');
      expect(a.needsNormalization).toBeTrue();
      expect(component.renameSummary).toEqual({ renamed: 0, failed: 1 });
      expect(component.activeTab).toBe('cast');
    });

    it('still lands on Add Cast when nothing is selected for renaming', () => {
      const a = makeFile({ exclude: true });
      component.files = [a];
      const spy = spyOn(fileService, 'renameTheFilesToNormalize');

      component.renameFiles();

      expect(spy).not.toHaveBeenCalled();
      expect(component.activeTab).toBe('cast');
      expect(component.renameSummary).toBeNull();
    });

    it('closes the modal when the rename leaves nothing to normalize and no cast work', () => {
      const a = makeFile({
        originalFileName: 'a.mp4',
        newFileName: 'A.mp4', // renamed name has no scene suffix -> no cast row
        exclude: false,
      });
      component.files = [a];
      spyOn(fileService, 'renameTheFilesToNormalize').and.returnValue(
        of({
          results: [
            {
              originalFileName: 'a.mp4',
              newFileName: 'A.mp4',
              status: 'Renamed successfully',
            },
          ],
        }),
      );
      const close = spyOn(component.activeModal, 'close');

      component.renameFiles();

      expect(close).toHaveBeenCalledWith('all-done');
    });

    it('closes immediately when nothing needs renaming and no cast work remains', () => {
      const a = makeFile({
        originalFileName: 'Done Movie.mp4',
        newFileName: '',
        needsNormalization: false,
      });
      component.files = [a];
      const spy = spyOn(fileService, 'renameTheFilesToNormalize');
      const close = spyOn(component.activeModal, 'close');

      component.renameFiles();

      expect(spy).not.toHaveBeenCalled();
      expect(close).toHaveBeenCalledWith('all-done');
    });
  });

  describe('file-name length cap', () => {
    // The filesystem refuses names over 255 bytes (NAME_MAX); the modal warns
    // at the same threshold so the user sees it before a rename ever runs.

    it('measures the pending name in UTF-8 bytes, 0 when none is pending', () => {
      // "é" is one character but two UTF-8 bytes.
      expect(component.newNameLength(makeFile({ newFileName: 'Renée.mp4' }))).toBe(10);
      expect(component.newNameLength(makeFile({ newFileName: '' }))).toBe(0);
    });

    it('warns on the normalize tab only for rows over the limit', () => {
      const over = makeFile({
        originalFileName: 'long.mp4',
        newFileName: `${'A'.repeat(255)}.mp4`, // 259 bytes — over by 4
      });
      const under = makeFile();
      component.files = [over, under];
      fixture.detectChanges();

      const text = (fixture.nativeElement as HTMLElement).textContent!;
      expect((text.match(/File name too long/g) || []).length).toBe(1);
      expect(text).toContain('limit by 4');
    });

    it('warns while typing a cast list that pushes the name over the limit', fakeAsync(() => {
      const base = 'Ass Man - Scene_1';
      const cast = 'A'.repeat(250);
      const file = makeFile({
        originalFileName: `${base}.mp4`,
        newFileName: '',
        needsNormalization: false,
      });
      component.files = [file];
      fixture.detectChanges(); // runs ngOnInit
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: `${base} - ${cast}` }),
      );

      component.activeTab = 'cast';
      component.setCast(file, cast);
      tick(250);
      fixture.detectChanges();

      expect(component.newNameLength(file)).toBeGreaterThan(255);
      expect((fixture.nativeElement as HTMLElement).textContent).toContain(
        'File name too long',
      );
    }));
  });

  describe('scene-number ordering', () => {
    // Regression: plain localeCompare is lexicographic, so Scene_10 sorted
    // between Scene_1 and Scene_2. Digit runs must compare as numbers.
    const scene = (n: number) =>
      makeFile({
        originalFileName: `Sample Reel # 02 - Scene_${n}.mp4`,
        newFileName: '',
        needsNormalization: false,
      });

    it('orders Scene_10 last on the normalize tab', () => {
      component.files = [scene(10), scene(1), scene(9), scene(2)];

      component.ngOnInit();

      expect(component.files.map((f) => f.workingBaseName)).toEqual([
        'Sample Reel # 02 - Scene_1',
        'Sample Reel # 02 - Scene_2',
        'Sample Reel # 02 - Scene_9',
        'Sample Reel # 02 - Scene_10',
      ]);
    });

    it('orders Scene_10 last in the Add Cast list', () => {
      component.files = [scene(10), scene(2), scene(1)];

      expect(component.castFiles.map((f) => f.originalFileName)).toEqual([
        'Sample Reel # 02 - Scene_1.mp4',
        'Sample Reel # 02 - Scene_2.mp4',
        'Sample Reel # 02 - Scene_10.mp4',
      ]);
    });
  });

  describe('group dismissal', () => {
    const sceneFile = (base: string) =>
      makeFile({
        originalFileName: `${base}.mp4`,
        workingBaseName: base,
        newFileName: '',
        needsNormalization: false,
      });

    it('removes the group from the cast lists without touching exclude', () => {
      const a = sceneFile('Ass Man - Scene_1');
      const b = sceneFile('Other Movie - Scene_1');
      component.files = [a, b];

      const group = component.castFileGroups.find((g) => g.title === 'Ass Man');
      expect(group).toBeDefined();
      component.dismissGroup(group!);

      expect(component.castFiles).not.toContain(a);
      expect(component.castFiles).toContain(b);
      expect(
        component.castFileGroups.some((g) => g.title === 'Ass Man'),
      ).toBeFalse();
      // View-state only: a dismissed file keeps its rename inclusion
      expect(a.exclude).toBeUndefined();
    });

    it('dismissed groups no longer hold the modal open after a rename pass', () => {
      const a = sceneFile('Ass Man - Scene_1');
      component.files = [a];
      component.dismissGroup({ title: 'Ass Man' });
      const spy = spyOn(fileService, 'renameTheFilesToNormalize');
      const close = spyOn(component.activeModal, 'close');

      component.renameFiles();

      expect(spy).not.toHaveBeenCalled();
      expect(close).toHaveBeenCalledWith('all-done');
    });

    it('renders the dismiss checkbox on headers and no checkbox on scene rows', () => {
      component.files = [
        sceneFile('Ass Man - Scene_1'),
        sceneFile('Ass Man - Scene_2'),
      ];
      component.activeTab = 'cast';
      fixture.detectChanges();

      const el = fixture.nativeElement as HTMLElement;
      expect(
        el.querySelectorAll('.cast-group-row input.group-dismiss').length,
      ).toBe(1);
      expect(
        el.querySelectorAll('tbody tr:not(.cast-group-row) input[type="checkbox"]')
          .length,
      ).toBe(0);
    });

    it('unchecking the header checkbox removes the group from the DOM', () => {
      component.files = [sceneFile('Ass Man - Scene_1')];
      component.activeTab = 'cast';
      fixture.detectChanges();

      const el = fixture.nativeElement as HTMLElement;
      const checkbox = el.querySelector(
        '.cast-group-row input.group-dismiss',
      ) as HTMLInputElement;
      checkbox.click();
      fixture.detectChanges();

      expect(el.querySelector('.cast-group-row')).toBeNull();
      expect(el.textContent).toContain(
        'No scene files are waiting for a cast name.',
      );
    });
  });

  describe('cast column', () => {
    it('splits the name into scene base and cast', () => {
      const file = makeFile({
        workingBaseName: 'Ass Man - Scene_1 - Angel Long, Paige Owens',
      });

      expect(component.sceneBaseOf(file)).toBe('Ass Man - Scene_1');
      expect(component.castOf(file)).toBe('Angel Long, Paige Owens');
    });

    it('reports an empty cast when none has been typed yet', () => {
      const file = makeFile({ workingBaseName: 'Ass Man - Scene_1' });

      expect(component.sceneBaseOf(file)).toBe('Ass Man - Scene_1');
      expect(component.castOf(file)).toBe('');
    });

    it('setCast rewrites only the cast half', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'Ass Man - Scene_1 - Angel Long' }),
      );
      const file = makeFile({ workingBaseName: 'Ass Man - Scene_1' });
      component.files = [file];

      component.setCast(file, 'Angel Long');
      expect(file.workingBaseName).toBe('Ass Man - Scene_1 - Angel Long');

      // clearing it puts the name back to the bare scene
      component.setCast(file, '');
      expect(file.workingBaseName).toBe('Ass Man - Scene_1');
      tick(250);
    }));

    it('tidies a pasted cast list into "Name, Name"', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(of({ normalized: 'x' }));
      const file = makeFile({ workingBaseName: 'Ass Man - Scene_1' });
      component.files = [file];

      component.setCast(file, '  Angel Long  &  Paige Owens\n');
      expect(file.workingBaseName).toBe('Ass Man - Scene_1 - Angel Long, Paige Owens');

      component.setCast(file, 'Jane Wilde and Blake Blossom');
      expect(file.workingBaseName).toBe('Ass Man - Scene_1 - Jane Wilde, Blake Blossom');

      component.setCast(file, '(Lisa Ann); Kianna Dior,');
      expect(file.workingBaseName).toBe('Ass Man - Scene_1 - Lisa Ann, Kianna Dior');
      tick(250);
    }));

    // Regression: fresh group objects on every change-detection pass made
    // *ngFor rebuild all rows (with their datalist inputs) per pass — the
    // page froze the first time the tab was opened against a real batch.
    it('returns the SAME groups array while nothing changed (memoized)', () => {
      const s1 = makeFile({ workingBaseName: 'Ass Man - Scene_1', originalFileName: 'Ass Man - Scene_1.mp4', newFileName: '', needsNormalization: false });
      const s2 = makeFile({ workingBaseName: 'Ass Man - Scene_2', originalFileName: 'Ass Man - Scene_2.mp4', newFileName: '', needsNormalization: false });
      component.files = [s1, s2];

      const first = component.castFileGroups;
      expect(component.castFileGroups).toBe(first);

      // a real change invalidates the cache
      s1.workingBaseName = 'Ass Man - Scene_1 - Jane Doe';
      expect(component.castFileGroups).not.toBe(first);
    });

    it('suggests at most 12 composed names for the segment being typed', () => {
      component.castNames = ['Jane Wilde', 'Jane Doe', 'Janet Mason', 'Angel Long'];

      component.castSuggestions = [];
      const file = makeFile({ workingBaseName: 'Ass Man - Scene_1' });
      component.files = [file];
      spyOn(fileService, 'normalizeName').and.returnValue(of({ normalized: 'x' }));

      component.setCast(file, 'Angel Long, jan');
      expect(component.castSuggestions).toEqual([
        'Angel Long, Jane Wilde',
        'Angel Long, Jane Doe',
        'Angel Long, Janet Mason',
      ]);

      component.setCast(file, 'a');
      expect(component.castSuggestions).toEqual([]); // under 2 chars
    });

    it('caps the suggestion list at 12, prefix matches before substring ones', fakeAsync(() => {
      component.castNames = [
        ...Array.from({ length: 14 }, (_, i) => `Jane Fictionelle ${i}`),
        'Marisol Janeway', // a substring match, so it queues behind the 12
      ];
      const file = makeFile({ workingBaseName: 'Ass Man - Scene_1' });
      component.files = [file];
      spyOn(fileService, 'normalizeName').and.returnValue(of({ normalized: 'x' }));

      component.setCast(file, 'jane');

      expect(component.castSuggestions.length).toBe(12);
      expect(component.castSuggestions[0]).toBe('Jane Fictionelle 0');
      expect(component.castSuggestions).not.toContain('Marisol Janeway');
      tick(250);
    }));

    // Regression: typing a second name WITHOUT a comma produced no suggestions
    // at all, even though the New Filename preview already showed the comma.
    // updateCastSuggestions() split on commas only, so "Angel Long Jane Wil" was
    // one unmatchable segment, while tidyCastInput() had already segmented the
    // same text on vocabulary boundaries. Two parsers, two answers.
    describe('suggestions for a name typed without a separator', () => {
      // "Angel Long" / "Jane Wilde" are the pair from the reported repro (both
      // real store entries); everything else here is invented.
      const vocabulary = [
        'Angel Long',
        'Jane Wilde',
        'Marla Quintessa',
        'Vex Harrowgate',
        'Bly', // a one-word stage name — see the 4-word wrinkle below
        'Andra Fell', // starts with the word "and", which is also a separator
        'Rowan Vale', // and the longer name below starts with THIS one
        'Rowan Vale Ashby',
      ];
      let file: NormalizedFile;

      beforeEach(() => {
        spyOn(fileService, 'getCastNames').and.returnValue(of({ names: vocabulary }));
        spyOn(fileService, 'normalizeName').and.returnValue(of({ normalized: 'x' }));
        // ngOnInit re-derives workingBaseName from originalFileName and loads
        // the vocabulary (castNames AND the lowercased set the parsers share).
        file = makeFile({ originalFileName: 'Ass Man - Scene_1.mp4' });
        component.files = [file];
        component.ngOnInit();
      });

      it('offers the second name as soon as two of its characters are typed', fakeAsync(() => {
        component.setCast(file, 'Angel Long J');
        expect(component.castSuggestions).toEqual([]); // one char so far

        component.setCast(file, 'Angel Long Jane W');
        expect(component.castSuggestions).toEqual(['Angel Long Jane Wilde']);

        component.setCast(file, 'Angel Long Jane Wil');
        expect(component.castSuggestions).toEqual(['Angel Long Jane Wilde']);
        tick(250);
      }));

      it('builds the option from the raw text and lets the tidier add the comma', fakeAsync(() => {
        component.setCast(file, 'Angel Long Jane Wil');
        const [option] = component.castSuggestions;

        // No comma in the option: the browser filters datalist options against
        // the input's whole value, so it has to prefix-match what was typed.
        expect(option).toBe('Angel Long Jane Wilde');
        expect(option.toLowerCase().startsWith('angel long jane wil')).toBeTrue();

        // Picking it runs back through setCast, and tidyCastInput puts the
        // comma in — so the filename still ends up separated.
        component.setCast(file, option);
        expect(component.castOf(file)).toBe('Angel Long, Jane Wilde');
        expect(file.workingBaseName).toBe(
          'Ass Man - Scene_1 - Angel Long, Jane Wilde',
        );
        tick(250);
      }));

      it('finds the third name in an unseparated run', fakeAsync(() => {
        component.setCast(file, 'Angel Long Jane Wilde Marla Q');
        expect(component.castSuggestions).toEqual([
          'Angel Long Jane Wilde Marla Quintessa',
        ]);

        component.setCast(file, 'Angel Long Jane Wilde Marla Quintessa');
        expect(component.castOf(file)).toBe(
          'Angel Long, Jane Wilde, Marla Quintessa',
        );
        tick(250);
      }));

      it('still matches after an explicit separator, reusing the raw spacing', fakeAsync(() => {
        component.setCast(file, 'Angel Long, Jane W');
        expect(component.castSuggestions).toEqual(['Angel Long, Jane Wilde']);

        // No space after the comma: the prefix is spliced back verbatim, so the
        // option still prefix-matches the field.
        component.setCast(file, 'Angel Long,Jane W');
        expect(component.castSuggestions).toEqual(['Angel Long,Jane Wilde']);

        // "and" is a separator tidyCastInput understands, so it is one here too.
        component.setCast(file, 'Angel Long and Vex H');
        expect(component.castSuggestions).toEqual(['Angel Long and Vex Harrowgate']);
        tick(250);
      }));

      it('keeps the two-character minimum for the new segment', fakeAsync(() => {
        component.setCast(file, 'Angel Long V');
        expect(component.castSuggestions).toEqual([]);

        component.setCast(file, 'Angel Long Ve');
        expect(component.castSuggestions).toEqual(['Angel Long Vex Harrowgate']);
        tick(250);
      }));

      // Regression: the boundary walk read a finished name off the front of the
      // name still being typed. "Rowan Vale" is a name AND the start of "Rowan
      // Vale Ashby", so consuming the short one made "As" the segment and
      // offered completions for that instead of the name in the field.
      it('keeps a longer name that starts with a shorter one offerable', fakeAsync(() => {
        component.setCast(file, 'Angel Long Rowan Vale As');
        expect(component.castSuggestions).toEqual(['Angel Long Rowan Vale Ashby']);

        // same one keystroke earlier, where both names are still candidates
        component.setCast(file, 'Angel Long Rowan Val');
        expect(component.castSuggestions).toEqual([
          'Angel Long Rowan Vale',
          'Angel Long Rowan Vale Ashby',
        ]);

        // the comma path had this right before and must not have moved
        component.setCast(file, 'Angel Long, Rowan Vale As');
        expect(component.castSuggestions).toEqual(['Angel Long, Rowan Vale Ashby']);

        // once the longer name is complete, the NEXT name is found again
        component.setCast(file, 'Angel Long Rowan Vale Ashby Jane W');
        expect(component.castSuggestions).toEqual([
          'Angel Long Rowan Vale Ashby Jane Wilde',
        ]);
        tick(250);
      }));

      // Regression: "and" is a separator, so a trailing "And" was treated as
      // one and the segment came out empty — typing the first three letters of
      // "Andra Fell" blanked the list for exactly that keystroke.
      it('treats a trailing "and" as a name being typed, not a separator', fakeAsync(() => {
        // (a two-letter segment also picks up substring matches, hence toContain)
        component.setCast(file, 'Angel Long, An');
        expect(component.castSuggestions).toContain('Angel Long, Andra Fell');

        // this keystroke used to come back empty — "And" ate itself
        component.setCast(file, 'Angel Long, And');
        expect(component.castSuggestions).toContain('Angel Long, Andra Fell');

        component.setCast(file, 'Angel Long, Andr');
        expect(component.castSuggestions).toEqual(['Angel Long, Andra Fell']);

        // and the same on a glued boundary
        component.setCast(file, 'Angel Long And');
        expect(component.castSuggestions).toEqual(['Angel Long Andra Fell']);

        // with a space after it, it really is the separator again
        component.setCast(file, 'Angel Long and Vex H');
        expect(component.castSuggestions).toEqual(['Angel Long and Vex Harrowgate']);
        tick(250);
      }));

      // The wrinkle, and it is NOT free: the TS tidier only segments a part of
      // 4+ words and only matches names of 2+ words, so it leaves "Angel Long
      // Bly" whole and pair-GUESSES longer runs. PHP (castDesquash) re-splits a
      // 3+-word part that segments cleanly into store names, so the 3-word case
      // does survive — but it can never re-join a wrong guess, and it never
      // splits a two-word part. Allowing one-word names here produced wrong
      // filenames in a measured sweep, so on a glued boundary they are withheld.
      it('withholds a one-word name where the tidier would not split it', fakeAsync(() => {
        component.setCast(file, 'Angel Long Bl');
        expect(component.castSuggestions).toEqual([]);

        // why: even fully typed, that run keeps no comma
        component.setCast(file, 'Angel Long Bly');
        expect(component.castOf(file)).toBe('Angel Long Bly');

        // an explicit separator makes it a segment of its own again
        component.setCast(file, 'Angel Long, Bl');
        expect(component.castSuggestions).toEqual(['Angel Long, Bly']);
        component.setCast(file, 'Angel Long, Bly');
        expect(component.castOf(file)).toBe('Angel Long, Bly');
        tick(250);
      }));

      // Deliberate divergence, the safe way round: the walk trusts only names
      // the vocabulary confirms, never segmentCastRun's pair-guess fallback. An
      // unknown name ANYWHERE — not just first — stops the walk, and everything
      // after it is one unmatchable segment, so no suggestions (rather than
      // wrong ones) until a separator is typed after it.
      it('stays quiet around a name it does not know, wherever it sits', fakeAsync(() => {
        component.setCast(file, 'Nadia Volkova Jane W');
        expect(component.castSuggestions).toEqual([]);

        // known name first, unknown one second: still nothing, even though the
        // preview has already put a comma after "Angel Long"
        component.setCast(file, 'Angel Long Nadia Volkova Jane W');
        expect(component.castSuggestions).toEqual([]);
        expect(component.castOf(file)).toBe('Angel Long, Nadia Volkova, Jane W');

        // a separator after the unknown name is what brings them back
        component.setCast(file, 'Nadia Volkova, Jane W');
        expect(component.castSuggestions).toEqual(['Nadia Volkova, Jane Wilde']);
        tick(250);
      }));
    });

    it('groups related scenes under one title with one lookup', () => {
      const s1 = makeFile({ workingBaseName: 'Ass Man - Scene_1', originalFileName: 'Ass Man - Scene_1.mp4', newFileName: '', needsNormalization: false });
      const s2 = makeFile({ workingBaseName: 'Ass Man - Scene_2', originalFileName: 'Ass Man - Scene_2.mp4', newFileName: '', needsNormalization: false });
      const other = makeFile({ workingBaseName: 'Anal Massage - Scene_1', originalFileName: 'Anal Massage - Scene_1.mp4', newFileName: '', needsNormalization: false });
      component.files = [s1, other, s2];

      const groups = component.castFileGroups;
      expect(groups.length).toBe(2);
      const assMan = groups.find((g) => g.title === 'Ass Man')!;
      expect(assMan.files).toEqual([s1, s2]);
      expect(groups.find((g) => g.title === 'Anal Massage')!.files).toEqual([other]);
    });

    // Regression: binding the tidied value straight back into the input ate a
    // trailing comma the moment it was typed — you could never type "X, Y".
    it('keeps an in-progress trailing comma in the input while typing', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(of({ normalized: 'x' }));
      const file = makeFile({ workingBaseName: 'Ass Man - Scene_1' });
      component.files = [file];

      component.setCast(file, 'Angel Long, ');

      // the visible input keeps the comma; the filename is tidied
      expect(component.castInputValue(file)).toBe('Angel Long, ');
      expect(file.workingBaseName).toBe('Ass Man - Scene_1 - Angel Long');

      // blur snaps the input to the tidied form
      component.onNameBlur(file);
      expect(component.castInputValue(file)).toBe('Angel Long');
      tick(250);
    }));

    it('segments a pasted run of unseparated names using the vocabulary', fakeAsync(() => {
      spyOn(fileService, 'getCastNames').and.returnValue(
        of({ names: ['Angel Long', 'Paige Owens', 'Anna Claire Clouds'] }),
      );
      spyOn(fileService, 'normalizeName').and.returnValue(of({ normalized: 'x' }));
      // ngOnInit re-derives workingBaseName from originalFileName, so the
      // fixture needs its scene marker there, not just in workingBaseName.
      const file = makeFile({ originalFileName: 'Ass Man - Scene_1.mp4' });
      component.files = [file];
      component.ngOnInit();

      // two known 2-word names, no separator
      component.setCast(file, 'angel long paige owens');
      expect(component.castOf(file)).toBe('angel long, paige owens');

      // a known 3-word name is not chopped at two
      component.setCast(file, 'anna claire clouds paige owens');
      expect(component.castOf(file)).toBe('anna claire clouds, paige owens');

      // unknown names fall back to pairs
      component.setCast(file, 'zz aa bb cc');
      expect(component.castOf(file)).toBe('zz aa, bb cc');
      tick(250);
    }));

    // Regression: SCENE_SPLIT only knew canonical "Scene_N", so on a raw name
    // ("test movie.scene 1") sceneBaseOf fell back to the whole name and each
    // keystroke APPENDED the cast again: "... - Angel Long - Angel Long - ...".
    it('replaces, never accumulates, cast on a raw un-normalized scene name', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(of({ normalized: 'x' }));
      const file = makeFile({
        originalFileName: 'test movie.scene 1.mp4',
        workingBaseName: 'test movie.scene 1',
      });
      component.files = [file];

      component.setCast(file, 'Angel Long');
      component.setCast(file, 'Angel Long, Pai');
      component.setCast(file, 'Angel Long, Paige Owens');

      expect(file.workingBaseName).toBe(
        'test movie.scene 1 - Angel Long, Paige Owens',
      );
      expect(component.castOf(file)).toBe('Angel Long, Paige Owens');
      expect(component.sceneBaseOf(file)).toBe('test movie.scene 1');
      tick(250);
    }));

    it('does not segment while a short name is being typed', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(of({ normalized: 'x' }));
      const file = makeFile({ workingBaseName: 'Ass Man - Scene_1' });
      component.files = [file];

      // 3 words stay whole — could be one name still being typed
      component.setCast(file, 'anna claire clouds');
      expect(component.castOf(file)).toBe('anna claire clouds');
      tick(250);
    }));

    it('searches the movie title only, without scene number or cast', () => {
      const file = makeFile({
        workingBaseName: 'Ass Man # 03 - Scene_1 - Angel Long',
      });

      // The template searches the GROUP title, and lookupQuery is what builds
      // that title — so assert the same composition the app performs.
      expect(component.lookupQuery(file)).toBe('Ass Man');
      const iafd = component.lookupSites.find((s) => s.label === 'IAFD')!;
      const url = component.lookupUrlForTitle(iafd, component.lookupQuery(file));
      expect(url).toContain('Ass%20Man');
      expect(url).not.toContain('Scene');
    });

    describe('tabbing between cast fields', () => {
      function renderCastRows(): HTMLInputElement[] {
        component.files = [
          makeFile({ workingBaseName: 'Ass Man - Scene_1', originalFileName: 'Ass Man - Scene_1.mp4', newFileName: '', needsNormalization: false }),
          makeFile({ workingBaseName: 'Ass Man - Scene_2', originalFileName: 'Ass Man - Scene_2.mp4', newFileName: '', needsNormalization: false }),
          makeFile({ workingBaseName: 'Anal Massage - Scene_1', originalFileName: 'Anal Massage - Scene_1.mp4', newFileName: '', needsNormalization: false }),
        ];
        component.activeTab = 'cast';
        fixture.detectChanges();
        return Array.from(
          fixture.nativeElement.querySelectorAll('input.cast-input'),
        );
      }

      it('tab skips the working-name box and lands on the next cast field', () => {
        const inputs = renderCastRows();
        expect(inputs.length).toBe(3);
        inputs[0].focus();

        const event = new KeyboardEvent('keydown', { key: 'Tab', cancelable: true, bubbles: true });
        inputs[0].dispatchEvent(event);

        expect(event.defaultPrevented).toBeTrue();
        expect(document.activeElement).toBe(inputs[1]);
      });

      it('shift+tab goes back to the previous cast field', () => {
        const inputs = renderCastRows();
        inputs[2].focus();

        const event = new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, cancelable: true, bubbles: true });
        inputs[2].dispatchEvent(event);

        expect(event.defaultPrevented).toBeTrue();
        expect(document.activeElement).toBe(inputs[1]);
      });

      // Focus must never be trapped in the table: at either end the browser's
      // own tab order takes over so the footer buttons stay reachable.
      it('does not trap focus at either end of the list', () => {
        const inputs = renderCastRows();

        const last = new KeyboardEvent('keydown', { key: 'Tab', cancelable: true, bubbles: true });
        inputs[2].dispatchEvent(last);
        expect(last.defaultPrevented).toBeFalse();

        const first = new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, cancelable: true, bubbles: true });
        inputs[0].dispatchEvent(first);
        expect(first.defaultPrevented).toBeFalse();
      });
    });

    it('leaves the lookup links to the browser, with noopener', () => {
      // A plain anchor on purpose — see the comment in the template. Nothing
      // may intercept the click: window.open can only produce a tab (no
      // features) or a popup (features), never a normal window, and a popup
      // has no tab strip, so links opened from it land back in the main window.
      component.files = [
        makeFile({
          workingBaseName: 'Ass Man # 03 - Scene_1',
          originalFileName: 'Ass Man # 03 - Scene_1.mp4',
          newFileName: '',
          needsNormalization: false,
        }),
      ];
      component.activeTab = 'cast';
      fixture.detectChanges();

      const links: HTMLAnchorElement[] = Array.from(
        fixture.nativeElement.querySelectorAll('a.lookup-link'),
      );
      expect(links.length).toBeGreaterThan(0);
      for (const link of links) {
        expect(link.target).toBe('_blank');
        expect(link.rel).toBe('noopener noreferrer');
        expect(link.href).toContain('Ass%20Man');
      }
    });

    it('loads the cast vocabulary on init', () => {
      const spy = spyOn(fileService, 'getCastNames').and.returnValue(
        of({ names: ['Angel Long', 'Jane Wilde'] }),
      );
      component.files = [makeFile()];
      component.directory = '/Volumes/Download/fixed';

      component.ngOnInit();

      expect(spy).toHaveBeenCalledWith('/Volumes/Download/fixed', undefined);
      expect(component.castNames).toEqual(['Angel Long', 'Jane Wilde']);
    });

    it('feeds cast that landed on disk back into the vocabulary', () => {
      const spy = spyOn(fileService, 'getCastNames').and.returnValue(
        of({ names: [] }),
      );
      const file = makeFile({
        originalFileName: 'Ass Man - Scene_1.mp4',
        newFileName: 'Ass Man - Scene_1 - Angel Long.mp4',
      });
      component.files = [file];
      spyOn(fileService, 'renameTheFilesToNormalize').and.returnValue(
        of({
          results: [
            {
              originalFileName: 'Ass Man - Scene_1.mp4',
              newFileName: 'Ass Man - Scene_1 - Angel Long.mp4',
              status: 'Renamed successfully',
            },
          ],
        }),
      );

      component.directory = '/Volumes/Download/fixed';

      component.renameFiles();

      expect(spy).toHaveBeenCalledWith('/Volumes/Download/fixed', ['Angel Long']);
    });

    it('survives a vocabulary fetch failure', () => {
      spyOn(fileService, 'getCastNames').and.returnValue(
        throwError(() => new Error('offline')),
      );
      component.files = [makeFile()];

      expect(() => component.ngOnInit()).not.toThrow();
      expect(component.castNames).toEqual([]);
    });
  });

  describe('tabs and castFiles', () => {
    it('starts on the normalize tab and can switch freely before renaming', () => {
      expect(component.activeTab).toBe('normalize');
      component.activeTab = 'cast';
      expect(component.activeTab).toBe('cast');
    });

    it('lists scene files that have no cast yet, and skips the rest', () => {
      const needsCast = makeFile({
        originalFileName: 'Ass Man - Scene_1.mp4',
        newFileName: '',
        needsNormalization: false,
      });
      const hasCast = makeFile({
        originalFileName: 'Ass Worship # 17 - Scene_1 - Kissa Sins.mp4',
        newFileName: '',
        needsNormalization: false,
      });
      const noScene = makeFile({
        originalFileName: 'Plain Title.mp4',
        newFileName: '',
        needsNormalization: false,
      });
      component.files = [noScene, hasCast, needsCast];

      expect(component.castFiles).toEqual([needsCast]);
    });

    it('shows names without extensions', () => {
      const file = makeFile({
        originalFileName: 'Ass Man - Scene_1.mp4',
        newFileName: 'Ass Man - Scene_1 - Jane Doe.mp4',
      });

      expect(component.effectiveBaseName(file)).toBe(
        'Ass Man - Scene_1 - Jane Doe',
      );
      expect(component.previewBaseName(file)).toBe(
        'Ass Man - Scene_1 - Jane Doe',
      );
    });

    it('uses the pending new name for included files and the on-disk name for excluded ones', () => {
      // Included: raw name has no canonical marker yet, but the pending
      // rename ends in one → counts under its future name.
      const pending = makeFile({
        originalFileName: 'title.sc4.mp4',
        newFileName: 'Title - Scene_4.mp4',
        exclude: false,
      });
      // Excluded: pending name would add a scene number, but it won't be
      // applied → judged (and shown) by its current on-disk name.
      const excluded = makeFile({
        originalFileName: 'other title.mp4',
        newFileName: 'Other Title - Scene_1.mp4',
        exclude: true,
      });
      component.files = [pending, excluded];

      expect(component.effectiveBaseName(pending)).toBe('Title - Scene_4');
      expect(component.effectiveBaseName(excluded)).toBe('other title');
      expect(component.castFiles).toEqual([pending]);
    });

    it('keeps a row in the list while a cast name is being typed into it', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'Ass Man - Scene_1 - Jane Doe' }),
      );
      const file = makeFile({
        originalFileName: 'Ass Man - Scene_1.mp4',
        newFileName: '',
        needsNormalization: false,
      });
      component.files = [file];
      expect(component.castFiles).toEqual([file]);

      // Typing a cast name makes it stop qualifying — it must not vanish
      file.workingBaseName = 'Ass Man - Scene_1 - Jane Doe';
      component.onCastNameChange(file);
      tick(250);

      expect(component.castFiles).toEqual([file]);
      expect(file.newFileName).toBe('Ass Man - Scene_1 - Jane Doe.mp4');
    }));

    it('drops a row once its cast name is renamed onto disk', fakeAsync(() => {
      spyOn(fileService, 'normalizeName').and.returnValue(
        of({ normalized: 'Ass Man - Scene_1 - Jane Doe' }),
      );
      spyOn(fileService, 'renameTheFilesToNormalize').and.returnValue(
        of({
          results: [
            {
              originalFileName: 'Ass Man - Scene_1.mp4',
              newFileName: 'Ass Man - Scene_1 - Jane Doe.mp4',
              status: 'Renamed successfully',
            },
          ],
        }),
      );
      const file = makeFile({
        originalFileName: 'Ass Man - Scene_1.mp4',
        newFileName: '',
        needsNormalization: false,
      });
      component.files = [file];

      file.workingBaseName = 'Ass Man - Scene_1 - Jane Doe';
      component.onCastNameChange(file);
      tick(250);
      component.renameFiles();

      expect(file.originalFileName).toBe('Ass Man - Scene_1 - Jane Doe.mp4');
      expect(component.castFiles).toEqual([]);
    }));
  });
});
