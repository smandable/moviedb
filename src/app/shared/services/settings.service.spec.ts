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

  it('sends the CSRF header on settings saves', () => {
    service.saveSettings({ moveRenamedUpFromNeedsCast: true }).subscribe();
    const req = httpMock.expectOne(`${environment.apiBaseUrl}appSettings.php`);
    expect(req.request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    req.flush({ settings: {} });
  });
});
