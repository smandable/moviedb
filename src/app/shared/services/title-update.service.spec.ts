import { TestBed } from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { environment } from 'src/environments/environment';

import { TitleUpdateItem, TitleUpdateService } from './title-update.service';

describe('TitleUpdateService', () => {
  let service: TitleUpdateService;
  let httpMock: HttpTestingController;

  const url = `${environment.apiBaseUrl}titleUpdates.php`;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HttpClientTestingModule] });
    service = TestBed.inject(TitleUpdateService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('previews the landed renames, with the CSRF header', () => {
    const renames = [
      { path: '/Volumes/X', originalFileName: 'A And B.mp4', newFileName: 'A and B.mp4' },
    ];
    service.preview(renames).subscribe();
    const req = httpMock.expectOne(url);
    expect(req.request.body).toEqual({ action: 'preview', renames });
    expect(req.request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    req.flush({ items: [] });
  });

  it('applies updates', () => {
    const updates = [{ table: 'movies_het', id: 4, from: 'A And B', to: 'A and B' }];
    service.apply(updates).subscribe();
    const req = httpMock.expectOne(url);
    expect(req.request.body).toEqual({ action: 'apply', updates });
    req.flush({ results: [] });
  });

  it('sends a merge with the rows as previewed and the merged values', () => {
    const keep = { id: 1, title: 'Old', dimensions: '640 x 480', filesize: '1', duration: 60, date_created: '2019-01-01' };
    const drop = { id: 2, title: 'New', dimensions: '1920 x 1080', filesize: '2', duration: 61, date_created: '2020-01-01' };
    const item: TitleUpdateItem = {
      table: 'movies_bi', oldTitle: 'Old', newTitle: 'New', files: [], rows: [keep, drop], leftovers: [],
      status: 'merge', keep, drop,
      merged: { source: 'files', filesize: '5', dimensions: '1920 x 1080', duration: 61, fileCount: 1, elsewhere: [] },
    };
    service.merge(item).subscribe();
    const req = httpMock.expectOne(url);
    expect(req.request.body).toEqual({
      action: 'merge', table: 'movies_bi', keep, drop, oldTitle: 'Old', title: 'New',
      values: { filesize: '5', dimensions: '1920 x 1080', duration: 61 },
    });
    req.flush({ ok: true });
  });

  it("surfaces the server's message on errors", (done) => {
    service.apply([]).subscribe({
      error: (e: Error) => {
        expect(e.message).toBe('Missing X-Requested-With header');
        done();
      },
    });
    httpMock.expectOne(url).flush(
      { success: false, message: 'Missing X-Requested-With header' },
      { status: 403, statusText: 'Forbidden' },
    );
  });
});
