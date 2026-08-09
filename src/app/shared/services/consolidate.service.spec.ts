import { TestBed } from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { environment } from 'src/environments/environment';

import { ConsolidateService } from './consolidate.service';

describe('ConsolidateService', () => {
  let service: ConsolidateService;
  let httpMock: HttpTestingController;

  const url = `${environment.apiBaseUrl}consolidateMovies.php`;

  const idleStatus = {
    running: false,
    pid: null,
    lastRun: null,
    settings: {
      drives: ['/Volumes/Fixture A/recorded'],
      balanceTo: '/Volumes/Fixture A/recorded',
      targetFreeGb: 100,
      reserveGb: 20,
      balance: true,
      recursive: false,
    },
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [HttpClientTestingModule],
    });
    service = TestBed.inject(ConsolidateService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('sends the CSRF header on every action', () => {
    service.status().subscribe();
    const req = httpMock.expectOne(url);
    expect(req.request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    expect(req.request.body).toEqual({ action: 'status' });
    req.flush(idleStatus);
  });

  it('run posts the execute flag verbatim', () => {
    service.run(false).subscribe();
    const dry = httpMock.expectOne(url);
    expect(dry.request.body).toEqual({ action: 'run', execute: false });
    dry.flush({ started: true, pid: 4242 });

    service.run(true).subscribe();
    const real = httpMock.expectOne(url);
    expect(real.request.body).toEqual({ action: 'run', execute: true });
    real.flush({ started: true, pid: 4243 });
  });

  it('logTail omits the lines key by default and sends it when given', () => {
    service.logTail().subscribe();
    const bare = httpMock.expectOne(url);
    expect(bare.request.body).toEqual({ action: 'logTail' });
    bare.flush({ lines: [] });

    service.logTail(100).subscribe();
    const sized = httpMock.expectOne(url);
    expect(sized.request.body).toEqual({ action: 'logTail', lines: 100 });
    sized.flush({ lines: [] });
  });

  it('unwraps the server error message on a refusal', () => {
    spyOn(console, 'error');
    let message = '';
    service.run(true).subscribe({
      error: (err: Error) => (message = err.message),
    });
    httpMock
      .expectOne(url)
      .flush(
        { message: 'A consolidation is already running' },
        { status: 409, statusText: 'Conflict' },
      );
    expect(message).toBe('A consolidation is already running');
  });
});
