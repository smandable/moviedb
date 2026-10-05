import { TestBed } from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { environment } from 'src/environments/environment';

import { SettingsService } from './settings.service';

describe('SettingsService', () => {
  let service: SettingsService;
  let httpMock: HttpTestingController;

  const castUrl = `${environment.apiBaseUrl}castNamesManage.php`;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [HttpClientTestingModule],
    });
    service = TestBed.inject(SettingsService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('sends the CSRF header on every cast-name action', () => {
    const calls: Array<[string, () => void]> = [
      ['list', () => service.listCastNames().subscribe()],
      ['add', () => service.addCastName('Marla Vex').subscribe()],
      ['rename', () => service.renameCastName('Marla Vex', 'Marla Vexx').subscribe()],
      ['delete', () => service.deleteCastName('Marla Vexx').subscribe()],
      ['deleteMany', () => service.deleteCastNames(['Marla Vexx']).subscribe()],
      ['restore', () => service.restoreCastName('Marla Vexx').subscribe()],
    ];
    for (const [action, call] of calls) {
      call();
      const req = httpMock.expectOne(castUrl);
      expect(req.request.body.action).toBe(action);
      expect(req.request.headers.get('X-Requested-With'))
        .withContext(action)
        .toBe('XMLHttpRequest');
      req.flush({ names: [] });
    }
  });

  it('posts each cast-audit action with the CSRF header', () => {
    const auditUrl = `${environment.apiBaseUrl}castNamesAudit.php`;
    const calls: Array<[object, () => void]> = [
      [{ action: 'run' }, () => service.auditCastNames().subscribe()],
      [
        { action: 'dismiss', key: 'junk|intro' },
        () => service.dismissCastAuditFinding('junk|intro').subscribe(),
      ],
      [{ action: 'run', includeHidden: true }, () => service.auditCastNames(true).subscribe()],
      [
        { action: 'undismiss', key: 'junk|intro' },
        () => service.undismissCastAuditFinding('junk|intro').subscribe(),
      ],
      [
        { action: 'respellPreview', from: ['Marla Vexx'], to: 'Marla Vex' },
        () => service.previewCastRespell(['Marla Vexx'], 'Marla Vex').subscribe(),
      ],
      [
        { action: 'respell', from: ['Marla Vexx'], to: 'Marla Vex', files: ['/Volumes/X/a.mp4'] },
        () => service.respellCastName(['Marla Vexx'], 'Marla Vex', ['/Volumes/X/a.mp4']).subscribe(),
      ],
    ];
    for (const [body, call] of calls) {
      call();
      const req = httpMock.expectOne(auditUrl);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual(body);
      expect(req.request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
      req.flush({ success: true });
    }
  });

  it('surfaces the server message when the audit fails', () => {
    let message = '';
    service.auditCastNames().subscribe({ error: (err: Error) => (message = err.message) });
    httpMock
      .expectOne(`${environment.apiBaseUrl}castNamesAudit.php`)
      .flush({ message: 'Unknown action' }, { status: 400, statusText: 'Bad Request' });
    expect(message).toBe('Unknown action');
  });

  it('sends the CSRF header on settings saves', () => {
    service.saveSettings({ moveRenamedUpFromNeedsCast: true }).subscribe();
    const req = httpMock.expectOne(`${environment.apiBaseUrl}appSettings.php`);
    expect(req.request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    req.flush({ settings: {} });
  });
});
