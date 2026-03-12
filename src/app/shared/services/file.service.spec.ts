import { TestBed } from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import {
  FileService,
  NormalizedFile,
  ProcessFilesResponse,
} from './file.service';
import { environment } from 'src/environments/environment';

describe('FileService', () => {
  let service: FileService;
  let httpMock: HttpTestingController;
  const baseUrl = environment.apiBaseUrl;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [HttpClientTestingModule],
      providers: [FileService],
    });
    service = TestBed.inject(FileService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  describe('checkFileNamesToNormalize', () => {
    it('should POST directory and return normalized files', () => {
      const mockResponse = {
        files: [
          {
            path: '/test',
            originalFileName: 'My File.mp4',
            newFileName: 'my-file.mp4',
            fileExtension: 'mp4',
            fileNameNoExtension: 'my-file',
            needsNormalization: true,
            status: 'Needs Renaming',
          },
        ] as NormalizedFile[],
      };

      service.checkFileNamesToNormalize('/test').subscribe((res) => {
        expect(res.files.length).toBe(1);
        expect(res.files[0].needsNormalization).toBeTrue();
      });

      const req = httpMock.expectOne(`${baseUrl}checkFileNamesToNormalize.php`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ directory: '/test' });
      req.flush(mockResponse);
    });

    it('should handle HTTP errors via catchError', () => {
      service.checkFileNamesToNormalize('/bad').subscribe({
        error: (err) => {
          expect(err.message).toContain('error occurred');
        },
      });

      const req = httpMock.expectOne(`${baseUrl}checkFileNamesToNormalize.php`);
      req.flush('Server error', { status: 500, statusText: 'Internal Server Error' });
    });
  });

  describe('renameTheFilesToNormalize', () => {
    it('should POST files and return rename results', () => {
      const files: NormalizedFile[] = [
        {
          path: '/test',
          originalFileName: 'Old Name.mp4',
          newFileName: 'new-name.mp4',
          fileExtension: 'mp4',
          fileNameNoExtension: 'new-name',
          needsNormalization: true,
          status: 'Needs Renaming',
        },
      ];

      const mockResponse = {
        results: [
          {
            originalFileName: 'Old Name.mp4',
            newFileName: 'new-name.mp4',
            status: 'Renamed successfully',
          },
        ],
      };

      service.renameTheFilesToNormalize(files).subscribe((res) => {
        expect(res.results.length).toBe(1);
        expect(res.results[0].status).toBe('Renamed successfully');
      });

      const req = httpMock.expectOne(`${baseUrl}renameTheFilesToNormalize.php`);
      expect(req.request.method).toBe('POST');
      req.flush(mockResponse);
    });
  });

  describe('processFilesForDB', () => {
    it('should POST directory and return processing results', () => {
      const mockResponse: ProcessFilesResponse = {
        message: 'Processed',
        titles: [
          {
            title: 'Test Movie',
            titleSize: 1048576,
            fileDimensions: '1920x1080',
            titleDuration: 3600,
            titlePath: '/test/test-movie.mp4',
          },
        ],
      };

      service.processFilesForDB('/test').subscribe((res) => {
        expect(res.titles.length).toBe(1);
        expect(res.titles[0].title).toBe('Test Movie');
      });

      const req = httpMock.expectOne(`${baseUrl}processFilesForDB.php`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ directory: '/test' });
      req.flush(mockResponse);
    });
  });

  describe('openExternalDriveSearch', () => {
    it('should POST query and return response', () => {
      service.openExternalDriveSearch('test query').subscribe((res) => {
        expect(res.success).toBeTrue();
      });

      const req = httpMock.expectOne(`${baseUrl}openExternalDriveSearch.php`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ query: 'test query' });
      req.flush({ success: true });
    });
  });

  describe('updateRow', () => {
    it('should POST id and updateFields', () => {
      const updateFields = { dimensions: '1920x1080', filesize: 1048576, duration: 3600 };

      service.updateRow(1, updateFields).subscribe((res) => {
        expect(res.success).toBeTrue();
      });

      const req = httpMock.expectOne(`${baseUrl}editCurrentRow.php`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ id: 1, updateFields });
      req.flush({ success: true });
    });
  });
});
