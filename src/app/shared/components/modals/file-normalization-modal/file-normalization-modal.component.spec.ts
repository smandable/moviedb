import {
  ComponentFixture,
  TestBed,
  fakeAsync,
  tick,
} from '@angular/core/testing';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import { of } from 'rxjs';
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
      expect(component.files[0].showNormalizedPreview).toBeTrue();
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
      expect(file.showNormalizedPreview).toBeTrue();
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
    it('emits only included files whose name actually changes, then closes', () => {
      const a = makeFile({ originalFileName: 'a.mp4', newFileName: 'A.mp4', exclude: false });
      const b = makeFile({ originalFileName: 'b.mp4', newFileName: 'B.mp4', exclude: true });
      component.files = [a, b];

      const emitted: NormalizedFile[] = [];
      component.renameFilesEvent.subscribe((f) => emitted.push(...f));
      spyOn(component.activeModal, 'close');

      component.renameFiles();

      expect(emitted).toEqual([a]);
      expect(component.activeModal.close).toHaveBeenCalled();
    });
  });
});
