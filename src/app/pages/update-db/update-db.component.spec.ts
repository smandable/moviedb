import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController, } from '@angular/common/http/testing';
import { RouterTestingModule } from '@angular/router/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { ChangeDetectorRef, PLATFORM_ID } from '@angular/core';
import { UpdateDbComponent } from './update-db.component';
import { FileService, NormalizedFile, ProcessFilesResponse, } from '@services/file.service';
import { environment } from '@env/environment';
import { of, Subject, throwError } from 'rxjs';

describe('UpdateDbComponent', () => {
  let component: UpdateDbComponent;
  let fixture: ComponentFixture<UpdateDbComponent>;
  let fileService: FileService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [
        UpdateDbComponent,
        HttpClientTestingModule,
        RouterTestingModule,
      ],
      providers: [
        provideTranslateService(),
        FileService,
        { provide: PLATFORM_ID, useValue: 'browser' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(UpdateDbComponent);
    component = fixture.componentInstance;
    fileService = TestBed.inject(FileService);
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should initialize directory from environment', () => {
    expect(component.directory).toBe(environment.defaultDirectory);
  });

  describe('processDirectory', () => {
    it('should alert if directory is empty', () => {
      vi.spyOn(window, 'alert').mockReturnValue(undefined as never);
      component.directory = '   ';
      component.processDirectory();
      expect(window.alert).toHaveBeenCalledWith('Please enter a valid directory path.');
    });

    it('should call checkFileNamesToNormalize and update totalItems', () => {
      const mockFiles = [
        {
          path: '/test',
          originalFileName: 'file.mp4',
          newFileName: '',
          fileExtension: 'mp4',
          fileNameNoExtension: 'file',
          needsNormalization: false,
          status: '',
        },
      ];

      vi.spyOn(fileService, 'checkFileNamesToNormalize').mockReturnValue(of({ files: mockFiles }));
      // Spy on openFilesModal to prevent modal from opening
      vi.spyOn(component, 'openFilesModal').mockReturnValue(undefined as never);

      component.directory = '/test';
      component.processDirectory();

      expect(fileService.checkFileNamesToNormalize).toHaveBeenCalledWith('/test');
      expect(component.totalItems).toBe(1);
      expect(component.isScanning).toBe(false);
      expect(component.openFilesModal).toHaveBeenCalledWith(mockFiles);
    });

    it('should alert on error', () => {
      vi.spyOn(fileService, 'checkFileNamesToNormalize').mockReturnValue(throwError(() => new Error('fail')));
      vi.spyOn(window, 'alert').mockReturnValue(undefined as never);
      vi.spyOn(console, 'error').mockReturnValue(undefined as never);

      component.directory = '/test';
      component.processDirectory();

      expect(window.alert).toHaveBeenCalledWith('Failed to process the directory. See console for details.');
      expect(component.isScanning).toBe(false);
    });

    it('repaints the button after a failed scan', () => {
      vi.spyOn(fileService, 'checkFileNamesToNormalize').mockReturnValue(throwError(() => new Error('fail')));
      vi.spyOn(window, 'alert').mockReturnValue(undefined as never);
      vi.spyOn(console, 'error').mockReturnValue(undefined as never);
      const detectChanges = vi.spyOn(component['cdr'] as ChangeDetectorRef, 'detectChanges').mockReturnValue(undefined as never);

      component.directory = '/test';
      component.processDirectory();

      // Zoneless: an HTTP error callback repaints nothing on its own, so the
      // button would stay disabled on "Scanning…".
      expect(detectChanges).toHaveBeenCalled();
    });

    it('shows the scan in flight on the button', () => {
      const scan = new Subject<{
        files: NormalizedFile[];
      }>();
      vi.spyOn(fileService, 'checkFileNamesToNormalize').mockReturnValue(scan);
      vi.spyOn(component, 'openFilesModal').mockReturnValue(undefined as never);
      fixture.detectChanges();
      const button = (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('button[title="Process Directory"]')!;
      expect(button.disabled).toBe(false);

      button.click();
      fixture.detectChanges();
      expect(button.disabled).toBe(true);
      expect(button.textContent).toContain('Scanning…');

      scan.next({ files: [] });
      scan.complete();
      // No fixture.detectChanges(): the response callback must repaint itself.
      expect(button.disabled).toBe(false);
      expect(button.textContent).toContain('Process Directory');
    });

    it('ignores clicks while a scan is still running', () => {
      // A drive waking from sleep can take ~20s to answer. A second click in
      // that window started a second scan, and each scan opened its own modal
      // over the same file list: renaming in the top one and letting it close
      // revealed the other, still listing names already gone from disk.
      const scan = new Subject<{
        files: NormalizedFile[];
      }>();
      vi.spyOn(fileService, 'checkFileNamesToNormalize').mockReturnValueOnce(scan).mockReturnValueOnce(of({ files: [] }));
      vi.spyOn(component, 'openFilesModal').mockReturnValue(undefined as never);

      component.directory = '/test';
      component.processDirectory();
      expect(component.isScanning).toBe(true);
      component.processDirectory();
      expect(fileService.checkFileNamesToNormalize).toHaveBeenCalledTimes(1);

      scan.next({ files: [] });
      scan.complete();
      expect(component.openFilesModal).toHaveBeenCalledTimes(1);
      expect(component.isScanning).toBe(false);

      // Once the scan has answered, the button works again.
      component.processDirectory();
      expect(fileService.checkFileNamesToNormalize).toHaveBeenCalledTimes(2);
    });
  });

  describe('performDatabaseOperations', () => {
    it('should alert if directory is empty', () => {
      vi.spyOn(window, 'alert').mockReturnValue(undefined as never);
      component.directory = '';
      component.performDatabaseOperations();
      expect(window.alert).toHaveBeenCalledWith('Please enter a valid directory path.');
    });

    it('should process response and update counts', () => {
      const mockResponse: ProcessFilesResponse = {
        message: 'Done',
        titles: [
          {
            title: 'New Movie',
            titleSize: 1048576,
            fileDimensions: '1920x1080',
            titleDuration: 3600,
            titlePath: '/test/new.mp4',
            duplicate: false,
          },
          {
            title: 'Dup Movie',
            titleSize: 2097152,
            fileDimensions: '1280x720',
            titleDuration: 7200,
            titlePath: '/test/dup.mp4',
            duplicate: true,
          },
        ],
      };

      vi.spyOn(fileService, 'processFilesForDB').mockReturnValue(of(mockResponse));

      component.directory = '/test';
      component.performDatabaseOperations();

      expect(component.totalItems).toBe(2);
      expect(component.newItemsCount).toBe(1);
      expect(component.duplicateItemsCount).toBe(1);
      expect(component.newItemsSize).toBe(1048576);
      expect(component.duplicateItemsSize).toBe(2097152);
      expect(component.processingComplete).toBe(true);
      expect(component.rowData.length).toBe(2);
    });

    it('should alert on success=false response', () => {
      const errorResponse: ProcessFilesResponse = {
        success: false,
        message: 'Bad directory',
        titles: [],
      };

      vi.spyOn(fileService, 'processFilesForDB').mockReturnValue(of(errorResponse));
      vi.spyOn(window, 'alert').mockReturnValue(undefined as never);

      component.directory = '/bad';
      component.performDatabaseOperations();

      expect(window.alert).toHaveBeenCalledWith('Error: Bad directory');
    });
  });

  describe('updateDB', () => {
    it('should call fileService.updateRow and clear flags on success', () => {
      const mockData = {
        id: 1,
        fileDimensions: '1920x1080',
        titleSize: 1048576,
        titleDuration: 3600,
        isLarger: 'isLarger',
        needsUpdateMissingMeta: true,
        needsUpdateFilesize: true,
      };

      vi.spyOn(fileService, 'updateRow').mockReturnValue(of({ success: true }));

      component.updateDB(mockData);

      expect(fileService.updateRow).toHaveBeenCalledWith(1, {
        dimensions: '1920x1080',
        filesize: 1048576,
        duration: 3600,
      });
      expect(mockData.isLarger).toBeNull();
      expect(mockData.needsUpdateMissingMeta).toBe(false);
      expect(mockData.needsUpdateFilesize).toBe(false);
    });

    it('should alert on failed update response', () => {
      vi.spyOn(fileService, 'updateRow').mockReturnValue(of({ success: false, message: 'DB error' }));
      vi.spyOn(window, 'alert').mockReturnValue(undefined as never);

      component.updateDB({
        id: 1,
        fileDimensions: '1920x1080',
        titleSize: 1048576,
        titleDuration: 3600,
      });

      expect(window.alert).toHaveBeenCalledWith('Failed to update database: DB error');
    });
  });

  describe('searchExternalDrives', () => {
    it('should call fileService.openExternalDriveSearch', () => {
      vi.spyOn(fileService, 'openExternalDriveSearch').mockReturnValue(of({ success: true }));

      component.searchExternalDrives('test query');

      expect(fileService.openExternalDriveSearch).toHaveBeenCalledWith('test query');
    });

    it('should alert on error', () => {
      vi.spyOn(fileService, 'openExternalDriveSearch').mockReturnValue(throwError(() => new Error('fail')));
      vi.spyOn(window, 'alert').mockReturnValue(undefined as never);
      vi.spyOn(console, 'error').mockReturnValue(undefined as never);

      component.searchExternalDrives('test');

      expect(window.alert).toHaveBeenCalledWith('Failed to search external drives. See console for details.');
    });
  });

  describe('formatFileSize', () => {
    it('should format bytes', () => {
      expect(component.formatFileSize(500)).toBe('500.00 Bytes');
    });

    it('should format KB', () => {
      expect(component.formatFileSize(1500)).toBe('1.50 KB');
    });

    it('should format MB', () => {
      expect(component.formatFileSize(1500000)).toBe('1.50 MB');
    });

    it('should format GB', () => {
      expect(component.formatFileSize(1500000000)).toBe('1.50 GB');
    });
  });

});
