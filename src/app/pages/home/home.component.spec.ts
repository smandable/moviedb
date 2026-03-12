import { ComponentFixture, TestBed, fakeAsync, tick } from '@angular/core/testing';
import { RouterTestingModule } from '@angular/router/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { TranslateModule } from '@ngx-translate/core';
import { PLATFORM_ID } from '@angular/core';
import { HomeComponent } from './home.component';
import { MovieService, Movie } from '@services/movie.service';
import { FileService } from '@services/file.service';
import { StoreService } from '@services/store.service';
import { environment } from 'src/environments/environment';
import { of, throwError } from 'rxjs';

describe('HomeComponent', () => {
  let component: HomeComponent;
  let fixture: ComponentFixture<HomeComponent>;
  let movieService: MovieService;
  let fileService: FileService;
  let httpMock: HttpTestingController;

  const mockMovies: Movie[] = [
    {
      id: 1,
      title: 'Test Movie',
      dimensions: '1920x1080',
      duration: 3600,
      filesize: 1048576,
      filepath: '/path/to/movie.mp4',
      date_created: '2024-01-01',
      duplicate: false,
      isLarger: '',
    },
    {
      id: 2,
      title: 'Another Movie',
      dimensions: '1280x720',
      duration: 7200,
      filesize: 2097152,
      filepath: '/path/to/another.mp4',
      date_created: '2024-01-02',
      duplicate: true,
      isLarger: 'isLarger',
    },
  ];

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [
        HomeComponent,
        HttpClientTestingModule,
        RouterTestingModule,
        TranslateModule.forRoot(),
      ],
      providers: [
        MovieService,
        FileService,
        StoreService,
        { provide: PLATFORM_ID, useValue: 'browser' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(HomeComponent);
    component = fixture.componentInstance;
    movieService = TestBed.inject(MovieService);
    fileService = TestBed.inject(FileService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should initialize with empty rowData', () => {
    expect(component.rowData).toEqual([]);
    expect(component.totalItems).toBe(0);
  });

  describe('loadMovies', () => {
    it('should populate rowData and totalItems on success', () => {
      spyOn(movieService, 'getAllMovies').and.returnValue(of(mockMovies));

      component.loadMovies();

      expect(component.rowData.length).toBe(2);
      expect(component.totalItems).toBe(2);
      expect(component.rowData[0].title).toBe('Test Movie');
    });

    it('should parse string filesize and duration values', () => {
      const moviesWithStrings = [
        {
          ...mockMovies[0],
          filesize: '2048' as any,
          duration: '90.5' as any,
        },
      ];
      spyOn(movieService, 'getAllMovies').and.returnValue(of(moviesWithStrings));

      component.loadMovies();

      expect(component.rowData[0].filesize).toBe(2048);
      expect(component.rowData[0].duration).toBe(91); // Math.round(90.5)
    });

    it('should log error on failure', () => {
      spyOn(movieService, 'getAllMovies').and.returnValue(
        throwError(() => new Error('Network error')),
      );
      spyOn(console, 'error');

      component.loadMovies();

      expect(console.error).toHaveBeenCalledWith(
        'Failed to load movies:',
        jasmine.any(Error),
      );
    });
  });

  describe('deleteRow', () => {
    beforeEach(() => {
      component.rowData = [...mockMovies];
      component.totalItems = mockMovies.length;
    });

    it('should not delete if user cancels confirm', () => {
      spyOn(window, 'confirm').and.returnValue(false);
      spyOn(movieService, 'deleteRow');

      component.deleteRow(mockMovies[0]);

      expect(movieService.deleteRow).not.toHaveBeenCalled();
    });

    it('should remove the movie from rowData on success', () => {
      spyOn(window, 'confirm').and.returnValue(true);
      spyOn(movieService, 'deleteRow').and.returnValue(of({ success: true }));

      component.deleteRow(mockMovies[0]);

      expect(component.rowData.length).toBe(1);
      expect(component.rowData[0].id).toBe(2);
      expect(component.totalItems).toBe(1);
    });

    it('should alert on delete failure', () => {
      spyOn(window, 'confirm').and.returnValue(true);
      spyOn(movieService, 'deleteRow').and.returnValue(
        throwError(() => new Error('Delete failed')),
      );
      spyOn(window, 'alert');
      spyOn(console, 'error');

      component.deleteRow(mockMovies[0]);

      expect(window.alert).toHaveBeenCalledWith(
        'Failed to delete row. See console for details.',
      );
    });
  });

  describe('onCellValueChanged', () => {
    it('should call updateRow when value changes', () => {
      spyOn(movieService, 'updateRow').and.returnValue(of({ success: true }));

      component.onCellValueChanged({
        data: { id: 1 },
        colDef: { field: 'title' },
        newValue: 'New Title',
        oldValue: 'Old Title',
      });

      expect(movieService.updateRow).toHaveBeenCalledWith(1, 'title', 'New Title');
    });

    it('should not call updateRow when value is unchanged', () => {
      spyOn(movieService, 'updateRow');

      component.onCellValueChanged({
        data: { id: 1 },
        colDef: { field: 'title' },
        newValue: 'Same',
        oldValue: 'Same',
      });

      expect(movieService.updateRow).not.toHaveBeenCalled();
    });
  });

  describe('ngOnDestroy', () => {
    it('should clean up resources', () => {
      component.ngOnInit();
      component.ngOnDestroy();
      // Should not throw
      expect(component).toBeTruthy();
    });
  });
});
