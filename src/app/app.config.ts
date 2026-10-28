// Angular modules
import { withFetch }              from '@angular/common/http';
import { withInterceptorsFromDi } from '@angular/common/http';
import { provideHttpClient }      from '@angular/common/http';
import { ApplicationConfig }      from '@angular/core';
import { importProvidersFrom }    from '@angular/core';
import { BrowserModule }          from '@angular/platform-browser';
// import { provideClientHydration } from '@angular/platform-browser';
import { provideAnimations }      from '@angular/platform-browser/animations';
import { provideRouter }          from '@angular/router';
import { withInMemoryScrolling }  from '@angular/router';
import { withRouterConfig }       from '@angular/router';

// External modules
import { provideTranslateService }   from '@ngx-translate/core';
import { provideTranslateHttpLoader } from '@ngx-translate/http-loader';
import { AngularSvgIconModule }   from 'angular-svg-icon';

// Internal modules
import { environment }            from '@env/environment';
import { routes }                 from './app.routes';

export const appConfig : ApplicationConfig = {
  providers : [

    // Routing
    provideRouter(
      routes,
      withRouterConfig({
        onSameUrlNavigation : 'reload',
      }),
      withInMemoryScrolling({
        scrollPositionRestoration : 'enabled'
      }),
    ),

    importProvidersFrom(
      // Angular modules
      BrowserModule,

      // External modules
      AngularSvgIconModule.forRoot(),

      // Internal modules
    ),

    // v18 removed TranslateModule. fallbackLang (v17's rename of
    // defaultLanguage) is what triggers loading en.json; without it every key
    // falls through raw (navbar showed "HOME", not "Home")
    provideTranslateService({
      fallbackLang : environment.defaultLanguage,
    }),
    provideTranslateHttpLoader({ prefix: './assets/i18n/', suffix: '.json' }),

    provideHttpClient(withFetch(), withInterceptorsFromDi()),
    provideAnimations(),
    // provideClientHydration(),
  ]
};