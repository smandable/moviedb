import { ComponentFixture, TestBed } from '@angular/core/testing';
import { RouterTestingModule } from '@angular/router/testing';
import { TranslateModule } from '@ngx-translate/core';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { environment } from 'src/environments/environment';

import {
  SettingsComponent,
} from './settings.component';

describe('SettingsComponent', () => {
  let component: SettingsComponent;
  let fixture: ComponentFixture<SettingsComponent>;
  let httpMock: HttpTestingController;

  const settingsUrl = `${environment.apiBaseUrl}appSettings.php`;
  const manageUrl = `${environment.apiBaseUrl}castNamesManage.php`;
  const driveIndexUrl = `${environment.apiBaseUrl}driveIndex.php`;

  const neverBuiltStatus = {
    exists: false,
    builtAt: null,
    fileCount: 0,
    roots: [],
    missingRoots: [],
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [
        SettingsComponent,
        HttpClientTestingModule,
        RouterTestingModule,
        TranslateModule.forRoot(),
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(SettingsComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges(); // triggers ngOnInit
  });

  afterEach(() => {
    httpMock.verify();
  });

  function flushInit(
    settings: object = {},
    names: string[] = ['Anna Example', 'Zoe Example'],
    indexStatus: object = neverBuiltStatus,
  ) {
    httpMock
      .expectOne(settingsUrl)
      .flush({ settings });
    httpMock
      .expectOne(manageUrl)
      .flush({ names });
    httpMock
      .expectOne(driveIndexUrl)
      .flush(indexStatus);
  }

  it('should create and load settings + cast names on init', () => {
    flushInit({ defaultDirectory: '/Volumes/Elsewhere/' });

    expect(component).toBeTruthy();
    expect(component.defaultDirectory).toBe('/Volumes/Elsewhere/');
    expect(component.castNames.length).toBe(2);
    expect(component.castNamesLoaded).toBeTrue();
  });

  it('falls back to the environment default directory when none is stored', () => {
    flushInit({});

    expect(component.defaultDirectory).toBe(environment.defaultDirectory);
  });

  it('filters names case-insensitively', () => {
    flushInit({}, ['Anna Example', 'Zoe Example', 'Annabelle Other']);

    component.filterText = 'anna';
    expect(component.filteredNames).toEqual([
      'Anna Example',
      'Annabelle Other',
    ]);
  });

  it('adds a name through the manage endpoint and clears the input', () => {
    flushInit({});

    component.newName = 'New Person';
    component.addName();

    const req = httpMock.expectOne(manageUrl);
    expect(req.request.body).toEqual({ action: 'add', name: 'New Person' });
    req.flush({
      names: ['Anna Example', 'New Person', 'Zoe Example'],
      added: 'New Person',
    });

    expect(component.castNames).toContain('New Person');
    expect(component.newName).toBe('');
  });

  it('saves the default directory and reports unmounted volumes', () => {
    flushInit({});

    component.defaultDirectory = '/Volumes/NotMounted/';
    component.saveDirectory();

    const req = httpMock.expectOne(settingsUrl);
    expect(req.request.body).toEqual({
      defaultDirectory: '/Volumes/NotMounted/',
    });
    req.flush({
      success: true,
      settings: { defaultDirectory: '/Volumes/NotMounted/' },
      directoryExists: false,
    });

    expect(component.directoryStatus).toBe('saved');
    expect(component.directoryMessage).toContain('unmounted');
  });

  describe('drive index card', () => {
    it('adopts the server\'s effective roots when none are stored', () => {
      // The server (drive_index_lib.php) owns the defaults; the status
      // endpoint reports the effective roots and the editor shows those.
      flushInit({}, undefined, {
        ...neverBuiltStatus,
        roots: ['/Volumes/Fixture A/recorded', '/Volumes/Fixture B/recorded'],
      });

      expect(component.driveIndexRoots).toEqual([
        '/Volumes/Fixture A/recorded',
        '/Volumes/Fixture B/recorded',
      ]);
    });

    it('stored roots win over the server\'s effective roots', () => {
      flushInit({ driveIndexRoots: ['/Volumes/Stored Root'] }, undefined, {
        ...neverBuiltStatus,
        roots: ['/Volumes/Server Root'],
      });

      expect(component.driveIndexRoots).toEqual(['/Volumes/Stored Root']);
    });

    it('loads stored roots from app settings', () => {
      flushInit({
        driveIndexRoots: ['/Volumes/Fixture A/recorded', '/Volumes/Fixture B'],
      });

      expect(component.driveIndexRoots).toEqual([
        '/Volumes/Fixture A/recorded',
        '/Volumes/Fixture B',
      ]);
    });

    it('reports "Never built" from the status endpoint', () => {
      flushInit({});

      expect(component.indexStatus?.exists).toBeFalse();
      expect(component.indexStatusLine).toBe('Never built');
    });

    it('saves the roots, trimming and dropping blank rows', () => {
      flushInit({});

      component.driveIndexRoots = [
        '/Volumes/Fixture A/recorded ',
        '   ',
        '/Volumes/Fixture B',
      ];
      component.saveRoots();

      const req = httpMock.expectOne(settingsUrl);
      expect(req.request.body).toEqual({
        driveIndexRoots: ['/Volumes/Fixture A/recorded', '/Volumes/Fixture B'],
      });
      req.flush({
        success: true,
        settings: {
          driveIndexRoots: [
            '/Volumes/Fixture A/recorded',
            '/Volumes/Fixture B',
          ],
        },
        directoryExists: null,
      });

      expect(component.rootsStatus).toBe('saved');
      expect(component.driveIndexRoots).toEqual([
        '/Volumes/Fixture A/recorded',
        '/Volumes/Fixture B',
      ]);
    });

    it('refuses to save when every root is blank', () => {
      flushInit({});

      component.driveIndexRoots = ['   ', ''];
      component.saveRoots();

      // No request goes out — afterEach's verify() would catch one
      expect(component.rootsStatus).toBe('error');
      expect(component.rootsMessage).toContain('At least one root');
    });

    it('rebuild posts to the endpoint and refreshes the shown status', () => {
      flushInit({});
      expect(component.indexStatusLine).toBe('Never built');

      component.rebuildIndex();
      expect(component.isRebuilding).toBeTrue();

      const req = httpMock.expectOne(driveIndexUrl);
      expect(req.request.body).toEqual({ action: 'rebuild' });
      // The live endpoint's success shape: no `exists` key — a successful
      // rebuild must still flip the status line off "Never built"
      req.flush({
        success: true,
        builtAt: '2026-08-08T10:00:00Z',
        fileCount: 42,
        roots: ['/Volumes/Fixture A/recorded'],
        missingRoots: [],
      });

      expect(component.isRebuilding).toBeFalse();
      expect(component.indexStatus?.exists).toBeTrue();
      expect(component.indexStatus?.fileCount).toBe(42);
      expect(component.indexStatusLine).toContain('Indexed 42 files');
    });

    it('surfaces a rebuild failure without wedging the button', () => {
      flushInit({});

      component.rebuildIndex();
      httpMock
        .expectOne(driveIndexUrl)
        .flush(
          { message: 'A rebuild is already running' },
          { status: 500, statusText: 'Server Error' },
        );

      expect(component.isRebuilding).toBeFalse();
      expect(component.rebuildError).toBe('A rebuild is already running');
    });
  });
});
