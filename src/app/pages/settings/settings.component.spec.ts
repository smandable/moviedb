import { ComponentFixture, TestBed } from '@angular/core/testing';
import { RouterTestingModule } from '@angular/router/testing';
import { TranslateModule } from '@ngx-translate/core';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { environment } from 'src/environments/environment';

import { SettingsComponent } from './settings.component';

describe('SettingsComponent', () => {
  let component: SettingsComponent;
  let fixture: ComponentFixture<SettingsComponent>;
  let httpMock: HttpTestingController;

  const settingsUrl = `${environment.apiBaseUrl}appSettings.php`;
  const manageUrl = `${environment.apiBaseUrl}castNamesManage.php`;

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
  ) {
    httpMock
      .expectOne(settingsUrl)
      .flush({ settings });
    httpMock
      .expectOne(manageUrl)
      .flush({ names });
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
});
