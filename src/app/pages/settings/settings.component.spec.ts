import { ChangeDetectorRef } from '@angular/core';
import {
  ComponentFixture,
  TestBed,
  fakeAsync,
  tick,
} from '@angular/core/testing';
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
  const consolidateUrl = `${environment.apiBaseUrl}consolidateMovies.php`;

  const neverBuiltStatus = {
    exists: false,
    builtAt: null,
    fileCount: 0,
    roots: [],
    missingRoots: [],
  };

  const fixtureConsolidateSettings = {
    drives: ['/Volumes/Fixture A/recorded', '/Volumes/Fixture B/recorded'],
    balanceTo: '/Volumes/Fixture B/recorded',
    targetFreeGb: 100,
    reserveGb: 20,
    balance: true,
    recursive: false,
  };

  const idleConsolidateStatus = {
    running: false,
    pid: null,
    lastRun: null,
    settings: fixtureConsolidateSettings,
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
    consolidateStatus: object = idleConsolidateStatus,
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
    httpMock
      .expectOne(consolidateUrl)
      .flush(consolidateStatus);
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

    it('polls progress every 500ms during a rebuild and stops after', fakeAsync(() => {
      flushInit({});
      component.rebuildIndex();
      const rebuildReq = httpMock.expectOne(
        (r) => r.url === driveIndexUrl && r.body.action === 'rebuild',
      );

      tick(500);
      httpMock
        .expectOne((r) => r.url === driveIndexUrl && r.body.action === 'progress')
        .flush({
          active: true,
          root: '/Volumes/Fixture A/recorded',
          rootsDone: 1,
          rootsTotal: 5,
          entries: 12345,
        });
      expect(component.rebuildProgressLine).toContain('Fixture A/recorded');
      expect(component.rebuildProgressLine).toContain('(2/5)');
      expect(component.rebuildProgressLine).toContain('12,345');

      rebuildReq.flush({
        success: true,
        builtAt: '2026-08-08T10:00:00Z',
        fileCount: 42,
        roots: [],
        missingRoots: [],
      });
      expect(component.isRebuilding).toBeFalse();
      expect(component.rebuildProgress).toBeNull();

      // Polling must stop with the rebuild
      tick(2000);
      httpMock.expectNone(
        (r) => r.url === driveIndexUrl && r.body.action === 'progress',
      );
    }));

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

    it('collapses the roots editor by default and expands on toggle', () => {
      flushInit({ driveIndexRoots: ['/Volumes/Fixture A/recorded'] });
      fixture.detectChanges();

      const el: HTMLElement = fixture.nativeElement;
      expect(component.rootsExpanded).toBeFalse();
      expect(el.querySelector('.root-input')).toBeNull();
      const toggle = el.querySelector('.roots-toggle') as HTMLElement;
      expect(toggle.textContent).toContain('Library roots (1)');

      toggle.click();
      fixture.detectChanges();

      expect(component.rootsExpanded).toBeTrue();
      expect(el.querySelector('.root-input')).toBeTruthy();
    });
  });

  describe('consolidation card', () => {
    it('loads consolidate settings from the status endpoint into the form', () => {
      flushInit();

      expect(component.consolidateDrives).toEqual(
        fixtureConsolidateSettings.drives,
      );
      expect(component.consolidateBalanceTo).toBe(
        '/Volumes/Fixture B/recorded',
      );
      expect(component.consolidateTargetFreeGb).toBe(100);
      expect(component.consolidateReserveGb).toBe(20);
      expect(component.consolidateBalance).toBeTrue();
      expect(component.consolidateRecursive).toBeFalse();
      expect(component.isConsolidating).toBeFalse();
    });

    it('collapses Paths & options by default and expands on toggle', () => {
      flushInit();
      fixture.detectChanges();

      const el: HTMLElement = fixture.nativeElement;
      expect(component.consolidateOptionsExpanded).toBeFalse();
      expect(el.querySelector('.drive-input')).toBeNull();

      (el.querySelector('.consolidate-options-toggle') as HTMLElement).click();
      fixture.detectChanges();

      expect(component.consolidateOptionsExpanded).toBeTrue();
      expect(el.querySelectorAll('.drive-input').length).toBe(2);
    });

    it('saves consolidate settings under the "consolidate" key, trimming drives', () => {
      flushInit();

      component.consolidateDrives = [
        '/Volumes/Fixture A/recorded ',
        '   ',
        '/Volumes/Fixture C/recorded',
      ];
      component.consolidateBalanceTo = '/Volumes/Fixture C/recorded';
      component.consolidateTargetFreeGb = 150;
      component.consolidateReserveGb = 25;
      component.consolidateBalance = false;
      component.consolidateRecursive = true;
      component.saveConsolidateSettings();

      const expected = {
        drives: [
          '/Volumes/Fixture A/recorded',
          '/Volumes/Fixture C/recorded',
        ],
        balanceTo: '/Volumes/Fixture C/recorded',
        targetFreeGb: 150,
        reserveGb: 25,
        balance: false,
        recursive: true,
      };
      const req = httpMock.expectOne(settingsUrl);
      expect(req.request.body).toEqual({ consolidate: expected });
      req.flush({
        success: true,
        settings: { consolidate: expected },
        directoryExists: null,
      });

      expect(component.consolidateSaveStatus).toBe('saved');
      expect(component.consolidateDrives).toEqual(expected.drives);
    });

    it('refuses to save when balance-to is not one of the drives', () => {
      flushInit();

      component.consolidateBalanceTo = '/Volumes/Not A Drive';
      component.saveConsolidateSettings();

      // No request goes out — afterEach's verify() would catch one
      expect(component.consolidateSaveStatus).toBe('error');
      expect(component.consolidateSaveMessage).toContain('balance-to');
    });

    it('a dry run posts execute:false and polls progress every 1s', fakeAsync(() => {
      flushInit();

      component.dryRun = true;
      component.runConsolidate();

      const runReq = httpMock.expectOne(
        (r) => r.url === consolidateUrl && r.body.action === 'run',
      );
      expect(runReq.request.body).toEqual({ action: 'run', execute: false });
      expect(runReq.request.headers.get('X-Requested-With')).toBe(
        'XMLHttpRequest',
      );
      runReq.flush({ started: true, pid: 4242 });
      expect(component.isConsolidating).toBeTrue();

      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({
          active: true,
          phase: 'groups',
          group: 'Imaginary Serial Adventures',
          groupsDone: 2,
          groupsTotal: 10,
          currentSrc:
            '/Volumes/Fixture A/recorded/Imaginary Serial Adventures # 03.mp4',
          movedBytes: 1500000000,
          moved: 3,
          duped: 1,
          failed: 0,
          dryRun: true,
        });
      fixture.detectChanges();

      // Three short lines, not one that wraps several times mid-run
      expect(component.consolidatePhaseLine).toBe(
        'Consolidating groups — group 3 of 10:',
      );
      expect(component.consolidateGroupLine).toBe(
        'Imaginary Serial Adventures — Imaginary Serial Adventures # 03.mp4',
      );
      expect(component.consolidateStalledNote).toBe('');
      expect(component.consolidateCountsLine).toContain('3 moved');
      expect(component.consolidateCountsLine).toContain('1.50');

      const readout = fixture.nativeElement.querySelector(
        '.consolidate-progress',
      );
      expect(readout).toBeTruthy();
      expect(readout.textContent).toContain('group 3 of 10');
      expect(readout.textContent).toContain('DRY RUN');

      // Leaving the page stops the poll. (discardPeriodicTasks would not:
      // it only clears fakeAsync's bookkeeping, and this zone.js version's
      // fakeAsync exit-flush would fire the still-queued interval once more.)
      component.ngOnDestroy();
    }));

    it('stops polling when progress goes inactive and refreshes status', fakeAsync(() => {
      flushInit();

      component.dryRun = true;
      component.runConsolidate();
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'run')
        .flush({ started: true, pid: 4242 });

      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({ active: false });

      // Inactive progress ends the run: status is refreshed for the summary
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'status',
        )
        .flush({
          running: false,
          pid: null,
          lastRun: {
            finishedAt: '2026-08-09T12:00:00Z',
            durationSeconds: 154,
            dryRun: true,
            exitCode: 0,
            moved: 12,
            duped: 2,
            skipped: 1,
            failed: 0,
            movedBytes: 3500000000,
          },
          settings: fixtureConsolidateSettings,
        });

      expect(component.isConsolidating).toBeFalse();
      expect(component.consolidateLastRunLine).toContain('Dry run finished');
      expect(component.consolidateLastRunLine).toContain('(took 2m 34s)');
      expect(component.consolidateLastRunLine).toContain('12 moved');
      expect(component.consolidateLastRunLine).toContain('3.50');

      // Polling must stop with the run
      tick(3000);
      httpMock.expectNone(
        (r) => r.url === consolidateUrl && r.body.action === 'progress',
      );
    }));

    it('one inactive poll does NOT end the run while status says running (long mv)', fakeAsync(() => {
      flushInit();

      component.dryRun = true;
      component.runConsolidate();
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'run')
        .flush({ started: true, pid: 4242 });

      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({ active: false }); // e.g. sidecar read raced the spawn

      // The process is the completion oracle: still running -> resume polling
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'status')
        .flush({
          running: true,
          pid: 4242,
          lastRun: null,
          settings: fixtureConsolidateSettings,
        });
      expect(component.isConsolidating).toBeTrue();

      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({
          active: true,
          phase: 'groups',
          stalled: true,
          currentSrc: '/Volumes/Fixture A/recorded/Imaginary Serial Adventures # 03.mp4',
          moved: 1,
          movedBytes: 5,
          duped: 0,
          failed: 0,
          dryRun: false,
        });
      expect(component.consolidateStalledNote).toContain('Still copying');
      // …and it is its own line, not appended to the phase line
      expect(component.consolidatePhaseLine).not.toContain('Still copying');

      component.ngOnDestroy();
    }));

    it('skipped groups surface in the counts and last-run lines', fakeAsync(() => {
      flushInit();
      component.dryRun = true;
      component.runConsolidate();
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'run')
        .flush({ started: true, pid: 1 });
      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({
          active: true,
          phase: 'groups',
          moved: 3,
          movedBytes: 10,
          duped: 0,
          failed: 0,
          skipped: 2,
          dryRun: true,
        });
      expect(component.consolidateCountsLine).toContain('2 skipped');
      component.ngOnDestroy();

      const run = {
        finishedAt: '2026-08-09T12:00:00Z',
        dryRun: false,
        exitCode: 0,
        moved: 5,
        duped: 0,
        skipped: 3,
        failed: 0,
        movedBytes: 100,
      };
      component.consolidateStatus = {
        running: false,
        pid: null,
        lastRun: run,
        settings: fixtureConsolidateSettings,
      };
      expect(component.consolidateLastRunLine).toContain('3 groups skipped');
    }));

    it('# 01 renames surface in the counts and last-run lines', fakeAsync(() => {
      flushInit();
      component.dryRun = true;
      component.runConsolidate();
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'run')
        .flush({ started: true, pid: 1 });
      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({
          active: true,
          phase: 'groups',
          moved: 3,
          movedBytes: 10,
          duped: 0,
          failed: 0,
          renamed: 2,
          dryRun: true,
        });
      expect(component.consolidateCountsLine).toContain('2 renamed to # 01');
      component.ngOnDestroy();

      component.consolidateStatus = {
        running: false,
        pid: null,
        lastRun: {
          finishedAt: '2026-08-09T12:00:00Z',
          dryRun: true,
          exitCode: 0,
          moved: 46,
          duped: 0,
          skipped: 0,
          renamed: 1,
          failed: 0,
          movedBytes: 100,
        },
        settings: fixtureConsolidateSettings,
      };
      expect(component.consolidateLastRunLine).toContain('1 renamed to # 01');
      // A pre-durationSeconds .last file must not invent a took clause
      expect(component.consolidateLastRunLine).not.toContain('took');
      // renamed stays out of the line entirely when the run had none
      component.consolidateStatus.lastRun!.renamed = 0;
      expect(component.consolidateLastRunLine).not.toContain('renamed');
    }));

    it('the index-rebuild phase reads as its own step, not a stalled copy', fakeAsync(() => {
      flushInit();
      component.dryRun = true;
      component.runConsolidate();
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'run')
        .flush({ started: true, pid: 1 });
      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({
          active: true,
          phase: 'index',
          // Left over from the finished move phase — must not be echoed here
          group: 'Imaginary Serial Adventures',
          currentSrc: '/Volumes/Fixture A/recorded/whatever.mp4',
          groupsDone: 10,
          groupsTotal: 10,
          stalled: true,
          moved: 3,
          movedBytes: 10,
          duped: 0,
          failed: 0,
        });

      expect(component.consolidatePhaseLine).toBe('Rebuilding the drive index');
      // Stale move-phase detail must not resurface during the rebuild
      expect(component.consolidateGroupLine).toBe('');
      expect(component.consolidateStalledNote).toBe('This can take a minute.');

      component.ngOnDestroy();
    }));

    it('a finished run that rebuilt the index refreshes the index card', fakeAsync(() => {
      flushInit();
      // Roots the user is mid-edit — the refresh must not overwrite them
      component.driveIndexRoots = ['/Volumes/User Edited'];
      component.dryRun = true;
      component.runConsolidate();
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'run')
        .flush({ started: true, pid: 1 });
      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({ active: false });
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'status',
        )
        .flush({
          running: false,
          pid: null,
          lastRun: {
            finishedAt: '2026-08-09T12:00:00Z',
            durationSeconds: 10,
            indexRebuilt: true,
            dryRun: false,
            exitCode: 0,
            moved: 5,
            duped: 0,
            skipped: 0,
            failed: 0,
            movedBytes: 100,
          },
          settings: fixtureConsolidateSettings,
        });

      // The rebuilt index means the card's count/timestamp are stale
      const statusReq = httpMock.expectOne(
        (r) => r.url === driveIndexUrl && r.body.action === 'status',
      );
      statusReq.flush({
        exists: true,
        fileCount: 4242,
        builtAt: '2026-08-09T18:00:00Z',
        roots: ['/Volumes/Server Says'],
        missingRoots: [],
      });
      expect(component.indexStatus?.fileCount).toBe(4242);
      // Roots the user may be editing must NOT be overwritten by that refresh
      expect(component.driveIndexRoots).toEqual(['/Volumes/User Edited']);
      expect(component.consolidateLastRunLine).toContain('Drive index rebuilt');
    }));

    it('the post-run index refresh clears a stale error and repaints the card', fakeAsync(() => {
      flushInit();
      // An earlier status read failed; the card is hidden behind that error
      component.indexStatusError = 'Failed to fetch';
      const markForCheck = spyOn(
        component['cdr'] as ChangeDetectorRef,
        'markForCheck',
      ).and.callThrough();

      component.dryRun = true;
      component.runConsolidate();
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'run')
        .flush({ started: true, pid: 1 });
      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({ active: false });
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'status',
        )
        .flush({
          running: false,
          pid: null,
          lastRun: {
            finishedAt: '2026-08-09T12:00:00Z',
            indexRebuilt: true,
            dryRun: false,
            exitCode: 0,
            moved: 5,
            duped: 0,
            skipped: 0,
            failed: 0,
            movedBytes: 100,
          },
          settings: fixtureConsolidateSettings,
        });

      markForCheck.calls.reset();
      httpMock
        .expectOne(
          (r) => r.url === driveIndexUrl && r.body.action === 'status',
        )
        .flush({
          exists: true,
          fileCount: 30319,
          builtAt: '2026-08-09T18:00:00Z',
          roots: [],
          missingRoots: [],
        });

      expect(component.indexStatusError).toBe('');
      expect(component.indexStatus?.fileCount).toBe(30319);
      // Zoneless: without this the view keeps showing the old numbers
      expect(markForCheck).toHaveBeenCalled();

      fixture.detectChanges();
      const card = fixture.nativeElement.querySelector('.index-status-line');
      expect(card?.textContent ?? '').toContain('30319');
    }));

    it('Rebuild Index is disabled while a consolidation owns the index', () => {
      flushInit();
      fixture.detectChanges();
      const button = (
        fixture.nativeElement as HTMLElement
      ).querySelector<HTMLButtonElement>('.card button.btn-secondary');
      expect(button?.textContent).toContain('Rebuild Index');
      expect(button?.disabled).toBeFalse();

      component.isConsolidating = true;
      // OnPush: a field set from outside the component doesn't dirty ITS view
      (component['cdr'] as ChangeDetectorRef).markForCheck();
      fixture.detectChanges();
      expect(button?.disabled).toBeTrue();
      expect(
        fixture.nativeElement.querySelector('.consolidation-owns-index'),
      ).toBeTruthy();

      component.isConsolidating = false;
    });

    it('files needing review are called out in both lines', fakeAsync(() => {
      flushInit();
      component.dryRun = true;
      component.runConsolidate();
      httpMock
        .expectOne((r) => r.url === consolidateUrl && r.body.action === 'run')
        .flush({ started: true, pid: 1 });
      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({
          active: true,
          phase: 'groups',
          moved: 1,
          movedBytes: 10,
          duped: 0,
          failed: 0,
          flagged: 2,
        });
      expect(component.consolidateCountsLine).toContain('2 to review');
      component.ngOnDestroy();

      component.consolidateStatus = {
        running: false,
        pid: null,
        lastRun: {
          finishedAt: '2026-08-09T12:00:00Z',
          flagged: 1,
          dryRun: false,
          exitCode: 0,
          moved: 1,
          duped: 0,
          skipped: 0,
          failed: 0,
          movedBytes: 10,
        },
        settings: fixtureConsolidateSettings,
      };
      expect(component.consolidateLastRunLine).toContain('1 file needs review');
      expect(component.consolidateLastRunLine).toContain('RENAME_01_SKIP');
      // Silent when there is nothing to review
      component.consolidateStatus.lastRun!.flagged = 0;
      expect(component.consolidateLastRunLine).not.toContain('review');
    }));

    it('a failed index rebuild is called out in the last-run line', () => {
      flushInit();
      component.consolidateStatus = {
        running: false,
        pid: null,
        lastRun: {
          finishedAt: '2026-08-09T12:00:00Z',
          indexRebuilt: false,
          dryRun: false,
          exitCode: 0,
          moved: 5,
          duped: 0,
          skipped: 0,
          failed: 0,
          movedBytes: 100,
        },
        settings: fixtureConsolidateSettings,
      };
      expect(component.consolidateLastRunLine).toContain(
        'Drive index rebuild FAILED',
      );

      // Not attempted (dry run / no changes) says nothing at all
      component.consolidateStatus.lastRun!.indexRebuilt = null;
      expect(component.consolidateLastRunLine).not.toContain('Drive index');
    });

    it('balance rows render as drive checks, not as a file named "recorded"', () => {
      flushInit();
      component.consolidateLogLines = [
        [
          '2026-08-09T15:08:00-04:00',
          'DRYRUN',
          '',
          'BALANCE_CHECK',
          '/Volumes/Recorded 3/recorded',
          '/Volumes/Recorded 4/recorded',
          '349650000000',
          'SKIP',
          'free 349.65 GB >= target 100 GB — nothing to evacuate',
        ].join('\t'),
      ];

      const [row] = component.consolidateLogRows;
      expect(row.action).toBe('BALANCE_CHECK');
      // The drive root's basename would read as a file called "recorded"
      expect(row.srcName).toBe('');
      expect(row.srcDrive).toBe('Recorded 3');
      expect(row.destDrive).toBe('Recorded 4');
      expect(row.bytes).toContain('349.65');
      expect(row.message).toContain('nothing to evacuate');
      // and it must survive the default (actions-only) filter
      expect(component.visibleLogRows.length).toBe(1);
    });

    it('an all-bookkeeping log explains itself instead of saying "No log yet"', () => {
      flushInit();
      // No run has happened yet
      expect(component.consolidateLogEmptyMessage).toContain('No log yet');

      // A finished run whose rows are all GROUP_DONE: the actions view filters
      // them out, so the table is empty while the log is not
      component.consolidateStatus = {
        running: false,
        pid: null,
        lastRun: {
          finishedAt: '2026-08-09T19:24:41Z',
          durationSeconds: 56,
          dryRun: false,
          exitCode: 0,
          moved: 0,
          duped: 0,
          skipped: 0,
          failed: 0,
          movedBytes: 0,
        },
        settings: fixtureConsolidateSettings,
      };
      component.consolidateLogLines = [
        ['2026-08-09T19:24:41-04:00', 'EXEC', 'Some Title', 'GROUP_DONE', '', '', '0', 'OK', ''].join('\t'),
      ];
      expect(component.visibleLogRows.length).toBe(0);
      expect(component.consolidateLogEmptyMessage).toContain('no file actions');
      expect(component.consolidateLogEmptyMessage).toContain('Raw tail');

      // With the raw tail on, those rows are visible again
      component.logShowAll = true;
      expect(component.visibleLogRows.length).toBe(1);
    });

    it('unsaved consolidate edits disable Run until saved', () => {
      flushInit();

      expect(component.consolidateFormDirty).toBeFalse();
      component.consolidateReserveGb = 55;
      expect(component.consolidateFormDirty).toBeTrue();

      component.runConsolidate();
      // A dirty form must not fire a run — nothing hits the endpoint
      httpMock.expectNone(
        (r) => r.url === consolidateUrl && r.body.action === 'run',
      );

      component.saveConsolidateSettings();
      const req = httpMock.expectOne(settingsUrl);
      req.flush({
        success: true,
        settings: {
          consolidate: {
            ...fixtureConsolidateSettings,
            reserveGb: 55,
          },
        },
        directoryExists: null,
      });
      expect(component.consolidateFormDirty).toBeFalse();
    });

    it('a non-dry run requires confirmation; declining sends nothing', () => {
      flushInit();

      spyOn(window, 'confirm').and.returnValue(false);
      // A real run is the DEFAULT — dry-run is the opt-in
      expect(component.dryRun).toBeFalse();
      component.runConsolidate();

      expect(window.confirm).toHaveBeenCalled();
      // No run request goes out — afterEach's verify() would catch one
      httpMock.expectNone(
        (r) => r.url === consolidateUrl && r.body.action === 'run',
      );
      expect(component.isConsolidating).toBeFalse();
    });

    it('a confirmed non-dry run posts execute:true; a 409 refusal surfaces inline', () => {
      flushInit();

      spyOn(window, 'confirm').and.returnValue(true);
      component.dryRun = false;
      component.runConsolidate();

      const req = httpMock.expectOne(
        (r) => r.url === consolidateUrl && r.body.action === 'run',
      );
      expect(req.request.body).toEqual({ action: 'run', execute: true });
      req.flush(
        { message: 'A consolidation is already running' },
        { status: 409, statusText: 'Conflict' },
      );

      expect(component.isConsolidating).toBeFalse();
      expect(component.consolidateRunError).toBe(
        'A consolidation is already running',
      );
    });

    it('resumes polling on init when a run is already active', fakeAsync(() => {
      flushInit({}, undefined, neverBuiltStatus, {
        ...idleConsolidateStatus,
        running: true,
        pid: 999,
      });

      expect(component.isConsolidating).toBeTrue();

      tick(1000);
      httpMock
        .expectOne(
          (r) => r.url === consolidateUrl && r.body.action === 'progress',
        )
        .flush({ active: true, phase: 'scan', dryRun: false });
      expect(component.consolidatePhaseLine).toContain('Scanning drives');

      // Leaving the page stops the poll (see the dry-run test's note)
      component.ngOnDestroy();
    }));

    it('fetches the log tail when the log section expands and parses it into rows', () => {
      flushInit();

      component.toggleConsolidateLog();
      const req = httpMock.expectOne(
        (r) => r.url === consolidateUrl && r.body.action === 'logTail',
      );
      expect(req.request.body).toEqual({ action: 'logTail', lines: 400, actions: true });
      req.flush({
        lines: [
          'ts\tmode\tgroup\taction\tsrc\tdest\tbytes\tstatus\tmessage',
          '2026-08-09T12:16:43-04:00\tDRYRUN\tImaginary Serial Adventures\tMOVE' +
            '\t/Volumes/Fixture A/recorded/Imaginary Serial Adventures # 03.mp4' +
            '\t/Volumes/Fixture B/recorded/Imaginary Serial Adventures # 03.mp4' +
            '\t1500000000\tDRYRUN\t',
          '2026-08-09T12:16:44-04:00\tDRYRUN\tImaginary Serial Adventures\tGROUP_DONE' +
            '\t\t/Volumes/Fixture B/recorded\t1500000000\tOK\t',
        ],
      });

      // Header dropped; two data rows parsed
      expect(component.consolidateLogRows.length).toBe(2);
      const move = component.consolidateLogRows[0];
      expect(move.action).toBe('MOVE');
      expect(move.srcName).toBe('Imaginary Serial Adventures # 03.mp4');
      expect(move.srcDrive).toBe('Fixture A');
      expect(move.destDrive).toBe('Fixture B');
      expect(move.bytes).toContain('1.5');
      expect(move.status).toBe('DRYRUN');

      // Bookkeeping rows hidden by default, shown with the toggle
      expect(component.visibleLogRows.length).toBe(1);
      component.logShowAll = true;
      expect(component.visibleLogRows.length).toBe(2);

      // Collapsing must not refetch — afterEach's verify() would catch one
      component.toggleConsolidateLog();
      expect(component.consolidateLogExpanded).toBeFalse();
    });
  });
});
