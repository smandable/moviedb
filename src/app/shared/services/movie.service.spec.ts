import { TestBed } from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { MovieService, Movie } from './movie.service';
import { environment } from 'src/environments/environment';

describe('MovieService', () => {
  let service: MovieService;
  let httpMock: HttpTestingController;
  const baseUrl = environment.apiBaseUrl;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [HttpClientTestingModule],
      providers: [MovieService],
    });
    service = TestBed.inject(MovieService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify(); // Ensure no outstanding requests
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('sends the CSRF header on edits and deletes', () => {
    service.updateRow(1, 'title', 'Updated Title').subscribe();
    const edit = httpMock.expectOne(`${baseUrl}editCurrentRow.php`);
    expect(edit.request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    edit.flush({ success: true });

    service.deleteRow(5).subscribe();
    const del = httpMock.expectOne(`${baseUrl}deleteRow.php`);
    expect(del.request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    del.flush({ success: true });
  });

  describe('getAllMovies', () => {
    it('should fetch all movies via GET', () => {
      const mockMovies: Movie[] = [
        {
          id: 1,
          title: 'Test Movie',
          dimensions: '1920x1080',
          duration: 3600,
          filesize: 1048576,
          date_created: '2024-01-01',
          duplicate: false,
          isLarger: '',
        },
      ];

      service.getAllMovies().subscribe((movies) => {
        expect(movies.length).toBe(1);
        expect(movies[0].title).toBe('Test Movie');
      });

      const req = httpMock.expectOne(`${baseUrl}getAllMovies.php?withTable=1`);
      expect(req.request.method).toBe('GET');
      req.flush({ table: 'movies_bi', movies: mockMovies });
      expect(service.loadedTable).toBe('movies_bi');
    });
  });

  describe('updateRow', () => {
    it('should send a POST request with id, field, and value', () => {
      service.updateRow(1, 'title', 'Updated Title').subscribe((res) => {
        expect(res.success).toBeTrue();
      });

      const req = httpMock.expectOne(`${baseUrl}editCurrentRow.php`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({
        id: 1,
        field: 'title',
        value: 'Updated Title',
        table: null, // nothing loaded yet; the server refuses a missing table
      });
      expect(req.request.headers.get('Content-Type')).toBe('application/json');
      req.flush({ success: true });
    });
  });

  describe('deleteRow', () => {
    it('should send a POST request with the movie id', () => {
      service.deleteRow(5).subscribe((res) => {
        expect(res.success).toBeTrue();
      });

      const req = httpMock.expectOne(`${baseUrl}deleteRow.php`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ id: 5, table: null });
      req.flush({ success: true });
    });

    it('echoes the table the list was loaded from', () => {
      service.loadedTable = 'movies_het';
      service.deleteRow(5).subscribe();
      const req = httpMock.expectOne(`${baseUrl}deleteRow.php`);
      expect(req.request.body).toEqual({ id: 5, table: 'movies_het' });
      req.flush({ success: true });
    });

    it("surfaces the server's refusal after a table switch", (done) => {
      service.deleteRow(5).subscribe({
        error: (e: Error) => {
          expect(e.message).toContain('reload this list');
          done();
        },
      });
      httpMock.expectOne(`${baseUrl}deleteRow.php`).flush(
        { success: false, tableChanged: true, message: 'The catalog table is now movies_bi — reload this list before changing rows.' },
        { status: 409, statusText: 'Conflict' },
      );
    });
  });
});
