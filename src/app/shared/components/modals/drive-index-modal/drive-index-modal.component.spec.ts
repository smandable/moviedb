import {
  ComponentFixture,
  TestBed,
  fakeAsync,
  tick,
} from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import { environment } from 'src/environments/environment';

import { DriveIndexModalComponent } from './drive-index-modal.component';
import {
  DriveIndexSearchResponse,
  DriveIndexStatus,
} from '@services/drive-index.service';

describe('DriveIndexModalComponent', () => {
  let component: DriveIndexModalComponent;
  let fixture: ComponentFixture<DriveIndexModalComponent>;
  let httpMock: HttpTestingController;

  const driveIndexUrl = `${environment.apiBaseUrl}driveIndex.php`;

  const statusResponse: DriveIndexStatus = {
    exists: true,
    builtAt: '2026-08-08T09:30:00Z',
    fileCount: 1234,
    roots: ['/Volumes/Fixture A/recorded'],
    missingRoots: [],
  };

  const searchResponse: DriveIndexSearchResponse = {
    groups: [
      {
        base: 'Galaxy Quest Chronicles',
        files: [
          {
            path: '/Volumes/Fixture A/recorded/Galaxy Quest Chronicles - Scene_1 - Vera Nova.mp4',
            file: 'Galaxy Quest Chronicles - Scene_1 - Vera Nova.mp4',
            dir: '/Volumes/Fixture A/recorded',
            size: 1500000000,
            mtime: 1723100000,
          },
          {
            path: '/Volumes/Fixture A/recorded/Galaxy Quest Chronicles - Scene_2 - Rex Halloway.mp4',
            file: 'Galaxy Quest Chronicles - Scene_2 - Rex Halloway.mp4',
            dir: '/Volumes/Fixture A/recorded',
            size: 500000000,
            mtime: 1723100001,
          },
        ],
      },
    ],
    totalGroups: 1,
    totalFiles: 2,
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DriveIndexModalComponent, HttpClientTestingModule],
      providers: [
        {
          provide: NgbActiveModal,
          useValue: { close: () => {}, dismiss: () => {} },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(DriveIndexModalComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
    // Most tests want the init-time search; an empty initial query skips it
    // (covered by its own spec below)
    component.initialQuery = 'Galaxy Quest Chronicles';
  });

  afterEach(() => {
    httpMock.verify();
  });

  function flushInit(
    status: DriveIndexStatus = statusResponse,
    search: DriveIndexSearchResponse = searchResponse,
  ) {
    httpMock
      .expectOne((r) => r.url === driveIndexUrl && r.body.action === 'status')
      .flush(status);
    httpMock
      .expectOne((r) => r.url === driveIndexUrl && r.body.action === 'search')
      .flush(search);
  }

  it('fetches status and searches the initial query on init, rendering groups', () => {
    component.initialQuery = 'Galaxy Quest Chronicles';
    fixture.detectChanges(); // ngOnInit

    httpMock
      .expectOne((r) => r.url === driveIndexUrl && r.body.action === 'status')
      .flush(statusResponse);
    const searchReq = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'search',
    );
    expect(searchReq.request.body).toEqual({
      action: 'search',
      query: 'Galaxy Quest Chronicles',
      offset: 0,
    });
    searchReq.flush(searchResponse);
    fixture.detectChanges();

    expect(component.query).toBe('Galaxy Quest Chronicles');
    expect(component.groups.length).toBe(1);

    const el = fixture.nativeElement as HTMLElement;
    // Group header: title, file count, combined size (2,000,000,000 → 2.00 GB)
    expect(el.textContent).toContain('Galaxy Quest Chronicles');
    expect(el.textContent).toContain('2 files');
    expect(el.textContent).toContain('2.00');
    // Per-file rows show filename and dir
    expect(el.textContent).toContain(
      'Galaxy Quest Chronicles - Scene_1 - Vera Nova.mp4',
    );
    expect(el.textContent).toContain('/Volumes/Fixture A/recorded');
    // Index-status footer line
    expect(el.textContent).toContain('Indexed 1234 files');
  });

  it('pages through results, keeping the query and totals', () => {
    component.initialQuery = 'Bush';
    fixture.detectChanges();
    httpMock
      .expectOne((r) => r.url === driveIndexUrl && r.body.action === 'status')
      .flush(statusResponse);
    const page1 = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'search',
    );
    expect(page1.request.body.offset).toBe(0);
    page1.flush({
      groups: [{ base: 'Bush', files: [] }],
      totalGroups: 79,
      totalFiles: 138,
      offset: 0,
      pageSize: 50,
    });
    fixture.detectChanges();

    // The range names its unit, and 138 is flagged as the whole match set
    expect(component.resultSummary).toBe('Titles 1–50 of 79 — 138 files matched');
    expect(component.currentPage).toBe(1);
    expect(component.pageCount).toBe(2);
    expect(component.hasPrevPage).toBeFalse();
    expect(component.hasNextPage).toBeTrue();
    expect(component.pageRangeEnd).toBe(50);
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.index-pager'),
    ).toBeTruthy();

    component.nextPage();
    const page2 = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'search',
    );
    // The query must survive paging; only the offset moves
    expect(page2.request.body).toEqual({
      action: 'search',
      query: 'Bush',
      offset: 50,
    });
    page2.flush({
      groups: [{ base: 'Bush # 10', files: [] }],
      totalGroups: 79,
      totalFiles: 138,
      offset: 50,
      pageSize: 50,
    });
    fixture.detectChanges();

    expect(component.resultSummary).toBe('Titles 51–79 of 79 — 138 files matched');
    expect(component.currentPage).toBe(2);
    expect(component.hasNextPage).toBeFalse();
    // "51-79 of 79", not "51-100"
    expect(component.pageRangeEnd).toBe(79);
  });

  it('a new query resets to page 1', fakeAsync(() => {
    component.initialQuery = 'Bush';
    fixture.detectChanges();
    flushInit(statusResponse, {
      groups: [{ base: 'Bush', files: [] }],
      totalGroups: 79,
      totalFiles: 138,
      offset: 0,
      pageSize: 50,
    } as DriveIndexSearchResponse);

    component.nextPage();
    httpMock
      .expectOne((r) => r.url === driveIndexUrl && r.body.action === 'search')
      .flush({
        groups: [],
        totalGroups: 79,
        totalFiles: 138,
        offset: 50,
        pageSize: 50,
      });
    expect(component.offset).toBe(50);

    // Typing a different query must not ask the server for page 2 of it
    component.query = 'Something Else';
    component.onQueryChange();
    tick(300);
    const req = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'search',
    );
    expect(req.request.body.offset).toBe(0);
    req.flush({ groups: [], totalGroups: 0, totalFiles: 0, offset: 0, pageSize: 50 });
    expect(component.offset).toBe(0);

    component.ngOnDestroy();
  }));

  it('a page response that lands after the user paged again is ignored', () => {
    component.initialQuery = 'Bush';
    fixture.detectChanges();
    flushInit(statusResponse, {
      groups: [{ base: 'page one', files: [] }],
      totalGroups: 200,
      totalFiles: 200,
      offset: 0,
      pageSize: 50,
    } as DriveIndexSearchResponse);

    component.nextPage(); // offset 50
    const slow = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'search',
    );
    component.nextPage(); // offset 100 — issued before page 2 answers
    const fast = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'search',
    );
    fast.flush({
      groups: [{ base: 'page three', files: [] }],
      totalGroups: 200,
      totalFiles: 200,
      offset: 100,
      pageSize: 50,
    });
    // The stale page-2 response must not overwrite page 3
    slow.flush({
      groups: [{ base: 'page two', files: [] }],
      totalGroups: 200,
      totalFiles: 200,
      offset: 50,
      pageSize: 50,
    });

    expect(component.groups[0].base).toBe('page three');
    expect(component.currentPage).toBe(3);
  });

  it('shows the empty state without a server call when the query is empty', () => {
    component.initialQuery = '';
    fixture.detectChanges(); // ngOnInit

    httpMock
      .expectOne((r) => r.url === driveIndexUrl && r.body.action === 'status')
      .flush(statusResponse);
    // Empty query must NOT hit the search endpoint (it would 400)
    httpMock.expectNone(
      (r) => r.url === driveIndexUrl && r.body.action === 'search',
    );
    fixture.detectChanges();

    expect(component.groups).toEqual([]);
    expect(component.searchError).toBe('');
    expect(component.hasSearched).toBeFalse();
  });

  it('clearing the search returns to the empty state instead of erroring', () => {
    fixture.detectChanges();
    flushInit();
    fixture.detectChanges();

    component.query = '';
    component.searchNow();
    httpMock.expectNone(
      (r) => r.url === driveIndexUrl && r.body.action === 'search',
    );
    fixture.detectChanges();

    expect(component.groups).toEqual([]);
    expect(component.searchError).toBe('');
    const el = fixture.nativeElement as HTMLElement;
    expect(el.textContent).not.toContain('Http failure');
  });

  it('trashes a file behind a confirm naming it, and drops its row', () => {
    fixture.detectChanges();
    flushInit();
    fixture.detectChanges();

    const confirmSpy = spyOn(window, 'confirm').and.returnValue(true);
    const file = component.groups[0].files[0];
    component.trashFile(file);

    expect(confirmSpy).toHaveBeenCalled();
    expect(confirmSpy.calls.mostRecent().args[0]).toContain(file.file);

    const req = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'trash',
    );
    expect(req.request.body).toEqual({ action: 'trash', paths: [file.path] });
    req.flush({ results: [{ path: file.path, trashed: true }] });
    fixture.detectChanges();

    expect(component.groups[0].files.length).toBe(1);
    expect(component.groups[0].files[0].file).toContain('Scene_2');
    expect(component.actionMessage).toContain('Moved 1 file');
    const el = fixture.nativeElement as HTMLElement;
    expect(el.textContent).not.toContain('Scene_1 - Vera Nova');
  });

  it('does not call the endpoint when the confirm is declined', () => {
    fixture.detectChanges();
    flushInit();

    spyOn(window, 'confirm').and.returnValue(false);
    component.trashFile(component.groups[0].files[0]);

    // afterEach's httpMock.verify() fails if a trash request was issued
    expect(component.groups[0].files.length).toBe(2);
  });

  it('group trash confirms with title and count, then removes the group', () => {
    fixture.detectChanges();
    flushInit();

    const confirmSpy = spyOn(window, 'confirm').and.returnValue(true);
    const group = component.groups[0];
    component.trashGroup(group);

    const prompt = confirmSpy.calls.mostRecent().args[0] as string;
    expect(prompt).toContain('Galaxy Quest Chronicles');
    expect(prompt).toContain('2');

    const req = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'trash',
    );
    expect(req.request.body).toEqual({
      action: 'trash',
      paths: group.files.map((f) => f.path),
    });
    req.flush({
      results: group.files.map((f) => ({ path: f.path, trashed: true })),
    });
    fixture.detectChanges();

    expect(component.groups.length).toBe(0);
    expect(component.actionMessage).toContain('Moved 2 files');
  });

  it('keeps a failed trash row and shows the server error inline', () => {
    fixture.detectChanges();
    flushInit();

    spyOn(window, 'confirm').and.returnValue(true);
    const file = component.groups[0].files[0];
    component.trashFile(file);

    httpMock
      .expectOne((r) => r.url === driveIndexUrl && r.body.action === 'trash')
      .flush({
        results: [
          { path: file.path, trashed: false, error: 'Operation not permitted' },
        ],
      });
    fixture.detectChanges();

    expect(component.groups[0].files.length).toBe(2);
    expect(component.fileErrors.get(file.path)).toBe('Operation not permitted');
    const el = fixture.nativeElement as HTMLElement;
    expect(el.textContent).toContain('Operation not permitted');
  });

  it('shows the empty state when nothing matches', () => {
    component.initialQuery = 'Unmatched Fixture Title';
    fixture.detectChanges();
    flushInit(statusResponse, { groups: [], totalGroups: 0, totalFiles: 0 });
    fixture.detectChanges();

    const el = fixture.nativeElement as HTMLElement;
    expect(el.textContent).toContain('No indexed files match');
  });

  it('warns in the footer when the index has not been built', () => {
    fixture.detectChanges();
    flushInit(
      { exists: false, builtAt: null, fileCount: 0, roots: [], missingRoots: [] },
      { groups: [], totalGroups: 0, totalFiles: 0 },
    );
    fixture.detectChanges();

    const el = fixture.nativeElement as HTMLElement;
    expect(el.textContent).toContain('has not been built');
  });

  it('reveals a file via the endpoint and reports an inline error on failure', () => {
    fixture.detectChanges();
    flushInit();

    const file = component.groups[0].files[0];
    component.reveal(file);

    const req = httpMock.expectOne(
      (r) => r.url === driveIndexUrl && r.body.action === 'reveal',
    );
    expect(req.request.body).toEqual({ action: 'reveal', path: file.path });
    req.flush({ success: false, error: 'File not found' });

    expect(component.fileErrors.get(file.path)).toBe('File not found');
  });
});
