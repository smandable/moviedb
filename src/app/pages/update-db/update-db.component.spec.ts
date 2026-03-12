import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { RouterTestingModule } from '@angular/router/testing';
import { TranslateModule } from '@ngx-translate/core';
import { PLATFORM_ID } from '@angular/core';
import { UpdateDbComponent } from './update-db.component';
import { FileService, ProcessFilesResponse } from '@services/file.service';
import { StoreService } from '@services/store.service';
import { environment } from 'src/environments/environment';
import { of, throwError } from 'rxjs';

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
        TranslateModule.forRoot(),
      ],
      providers: [
        FileService,
        StoreService,
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
      spyOn(window, 'alert');
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

      spyOn(fileService, 'checkFileNamesToNormalize').and.returnValue(
        of({ files: mockFiles }),
      );
      // Spy on openFilesModal to prevent modal from opening
      spyOn(component, 'openFilesModal');

      component.directory = '/test';
      component.processDirectory();

      expect(fileService.checkFileNamesToNormalize).toHaveBeenCalledWith('/test');
      expect(component.totalItems).toBe(1);
      expect(component.isLoading).toBeFalse();
      expect(component.openFilesModal).toHaveBeenCalledWith(mockFiles);
    });

    it('should alert on error', () => {
      spyOn(fileService, 'checkFileNamesToNormalize').and.returnValue(
        throwError(() => new Error('fail')),
      );
      spyOn(window, 'alert');
      spyOn(console, 'error');

      component.directory = '/test';
      component.processDirectory();

      expect(window.alert).toHaveBeenCalledWith(
        'Failed to process the directory. See console for details.',
      );
      expect(component.isLoading).toBeFalse();
    });
  });

  describe('performDatabaseOperations', () => {
    it('should alert if directory is empty', () => {
      spyOn(window, 'alert');
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

      spyOn(fileService, 'processFilesForDB').and.returnValue(of(mockResponse));

      component.directory = '/test';
      component.performDatabaseOperations();

      expect(component.totalItems).toBe(2);
      expect(component.newItemsCount).toBe(1);
      expect(component.duplicateItemsCount).toBe(1);
      expect(component.newItemsSize).toBe(1048576);
      expect(component.duplicateItemsSize).toBe(2097152);
      expect(component.processingComplete).toBeTrue();
      expect(component.rowData.length).toBe(2);
    });

    it('should alert on success=false response', () => {
      const errorResponse: ProcessFilesResponse = {
        success: false,
        message: 'Bad directory',
        titles: [],
      };

      spyOn(fileService, 'processFilesForDB').and.returnValue(of(errorResponse));
      spyOn(window, 'alert');

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

      spyOn(fileService, 'updateRow').and.returnValue(
        of({ success: true }),
      );

      component.updateDB(mockData);

      expect(fileService.updateRow).toHaveBeenCalledWith(1, {
        dimensions: '1920x1080',
        filesize: 1048576,
        duration: 3600,
      });
      expect(mockData.isLarger).toBeNull();
      expect(mockData.needsUpdateMissingMeta).toBeFalse();
      expect(mockData.needsUpdateFilesize).toBeFalse();
    });

    it('should alert on failed update response', () => {
      spyOn(fileService, 'updateRow').and.returnValue(
        of({ success: false, message: 'DB error' }),
      );
      spyOn(window, 'alert');

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
      spyOn(fileService, 'openExternalDriveSearch').and.returnValue(of({ success: true }));

      component.searchExternalDrives('test query');

      expect(fileService.openExternalDriveSearch).toHaveBeenCalledWith('test query');
    });

    it('should alert on error', () => {
      spyOn(fileService, 'openExternalDriveSearch').and.returnValue(
        throwError(() => new Error('fail')),
      );
      spyOn(window, 'alert');
      spyOn(console, 'error');

      component.searchExternalDrives('test');

      expect(window.alert).toHaveBeenCalledWith(
        'Failed to search external drives. See console for details.',
      );
    });
  });

  describe('formatFileSize', () => {
    it('should format bytes', () => {
      expect(component.formatFileSize(500)).toBe('500 bytes');
    });

    it('should format KB', () => {
      expect(component.formatFileSize(1500)).toBe('1.50 KB');
    });

    it('should format MB', () => {
      expect(component.formatFileSize(1500000)).toBe('1.50 MB');
    });

    it('should format GB', () => {
      expect(component.formatFileSize(1500000000)).toBe('1.50 GB');
    });
  });

  describe('renameFiles', () => {
    it('should not call service if no files need renaming', () => {
      spyOn(fileService, 'renameTheFilesToNormalize');
      spyOn(console, 'log');

      component.renameFiles([
        {
          path: '/test',
          originalFileName: 'file.mp4',
          newFileName: '',
          fileExtension: 'mp4',
          fileNameNoExtension: 'file',
          needsNormalization: false,
          status: '',
        },
      ]);

      expect(fileService.renameTheFilesToNormalize).not.toHaveBeenCalled();
    });
  });
});
