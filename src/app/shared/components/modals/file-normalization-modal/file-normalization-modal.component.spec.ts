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

      expect(component.lookupQuery(file)).toBe('Ass Man');
      const iafd = component.lookupSites.find((s) => s.label === 'IAFD')!;
      expect(component.lookupUrl(iafd, file)).toContain('Ass%20Man');
      expect(component.lookupUrl(iafd, file)).not.toContain('Scene');
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
