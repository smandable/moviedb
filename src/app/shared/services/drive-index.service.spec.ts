import { TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController, } from '@angular/common/http/testing';
import { environment } from '@env/environment';

import { DriveIndexService } from './drive-index.service';

describe('DriveIndexService', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });
  let service: DriveIndexService;
  let httpMock: HttpTestingController;

  const url = `${environment.apiBaseUrl}driveIndex.php`;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [HttpClientTestingModule],
    });
    service = TestBed.inject(DriveIndexService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('sends the CSRF header on every action', () => {
    service.status().subscribe();
    const req = httpMock.expectOne(url);
    expect(req.request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    req.flush({ exists: false, builtAt: null, fileCount: 0, roots: [], missingRoots: [] });
  });

  describe('scheduleDebouncedRebuild', () => {
    it('rebuilds once, 30s after the LAST call, not per call', async () => {
      service.scheduleDebouncedRebuild();
      await vi.advanceTimersByTimeAsync(20000);
      // A second trash inside the window resets the timer
      service.scheduleDebouncedRebuild();
      await vi.advanceTimersByTimeAsync(20000);
      // 40s wall time, but only 20s since the last call: nothing yet
      httpMock.expectNone(url);

      await vi.advanceTimersByTimeAsync(10000); // 30s since the last call
      const req = httpMock.expectOne((r) => r.url === url && r.body.action === 'rebuild');
      req.flush({
        success: true,
        builtAt: '2026-01-01T00:00:00+00:00',
        fileCount: 1,
        roots: [],
        missingRoots: [],
      });

      // And only once
      await vi.advanceTimersByTimeAsync(60000);
      httpMock.expectNone(url);
    });

    it('a background rebuild failure never throws', async () => {
      vi.spyOn(console, 'warn').mockReturnValue(undefined as never);
      service.scheduleDebouncedRebuild();
      await vi.advanceTimersByTimeAsync(30000);
      const req = httpMock.expectOne(url);
      req.flush({ success: false, message: 'drives asleep' }, { status: 500, statusText: 'Server Error' });
      expect(console.warn).toHaveBeenCalled();
  });
});
});
