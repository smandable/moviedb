import { TestBed } from '@angular/core/testing';
import { PLATFORM_ID } from '@angular/core';
import { TranslateService, TranslateModule } from '@ngx-translate/core';
import { StoreService } from './store.service';
import { environment } from '@env/environment';

describe('StoreService', () => {
  let service: StoreService;
  let translateService: TranslateService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [TranslateModule.forRoot()],
      providers: [
        StoreService,
        { provide: PLATFORM_ID, useValue: 'browser' },
      ],
    });
    service = TestBed.inject(StoreService);
    translateService = TestBed.inject(TranslateService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('should initialize isServer to false for browser platform', () => {
    expect(service.isServer()).toBeFalse();
  });

  it('should initialize isLoading to true', () => {
    expect(service.isLoading()).toBeTrue();
  });

  it('should initialize pageTitle to the app name', () => {
    expect(service.pageTitle()).toBe(environment.appName);
  });

  describe('setPageTitle', () => {
    it('should set page title with translation by default', () => {
      spyOn(translateService, 'instant').and.returnValue('Translated Title');
      service.setPageTitle('some.key');
      expect(translateService.instant).toHaveBeenCalledWith('some.key');
      expect(service.pageTitle()).toBe('Translated Title');
    });

    it('should set page title without translation when translate=false', () => {
      service.setPageTitle('Raw Title', false);
      expect(service.pageTitle()).toBe('Raw Title');
    });
  });
});
