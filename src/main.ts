/// <reference types="@angular/localize" />

// Angular modules
import { enableProdMode } from '@angular/core';
import { importProvidersFrom } from '@angular/core';
import { HttpClientModule } from '@angular/common/http';
import { bootstrapApplication } from '@angular/platform-browser';

// External modules
import { appConfig } from './app/app.config';

// Internal modules
import { environment } from './environments/environment';

// Components
import { AppComponent } from './app/app.component';

if (environment.production) {
  enableProdMode();
}

// Add HttpClientModule to the appConfig
const extendedAppConfig = {
  ...appConfig,
  providers: [
    ...(appConfig.providers || []), // Preserve existing providers
    importProvidersFrom(HttpClientModule), // Add HttpClientModule
  ],
};

bootstrapApplication(
  AppComponent,
  extendedAppConfig // Use the extended appConfig
)
.catch(err => console.error(err));
