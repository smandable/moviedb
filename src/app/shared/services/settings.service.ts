import { Injectable } from '@angular/core';
import {
  HttpClient,
  HttpErrorResponse,
  HttpHeaders,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';

import { environment } from 'src/environments/environment';
import { ConsolidateSettings } from './consolidate.service';

export interface AppSettings {
  defaultDirectory?: string;
  /** Library roots the drive index walks (Settings page, driveIndex.php). */
  driveIndexRoots?: string[];
  /** Consolidation options (Settings page, consolidateMovies.php). */
  consolidate?: ConsolidateSettings;
  /**
   * Move a file renamed inside a needs-cast staging folder up one level once
   * it carries a cast (read by renameTheFilesToNormalize.php; absent = ON).
   */
  moveRenamedUpFromNeedsCast?: boolean;
}

export interface SaveSettingsResponse {
  success: boolean;
  settings: AppSettings;
  /** null when the save didn't touch defaultDirectory */
  directoryExists: boolean | null;
}

export interface CastNamesResponse {
  names: string[];
  added?: string;
  renamed?: string;
  deleted?: boolean;
}

@Injectable({
  providedIn: 'root',
})
export class SettingsService {
  private readonly baseUrl = environment.apiBaseUrl;

  private settingsUrl = `${this.baseUrl}appSettings.php`;
  private castNamesManageUrl = `${this.baseUrl}castNamesManage.php`;

  constructor(private http: HttpClient) {}

  getSettings(): Observable<{ settings: AppSettings }> {
    return this.http
      .get<{ settings: AppSettings }>(this.settingsUrl)
      .pipe(catchError(this.handleError));
  }

  saveSettings(settings: AppSettings): Observable<SaveSettingsResponse> {
    const headers = new HttpHeaders({
      'Content-Type': 'application/json',
      // CSRF gate — the server refuses settings writes without this header
      // (X-Requested-With: already in httpd.conf's CORS allow-list)
      'X-Requested-With': 'XMLHttpRequest',
    });
    return this.http
      .post<SaveSettingsResponse>(this.settingsUrl, settings, { headers })
      .pipe(catchError(this.handleError));
  }

  listCastNames(): Observable<CastNamesResponse> {
    return this.castNamesAction({ action: 'list' });
  }

  addCastName(name: string): Observable<CastNamesResponse> {
    return this.castNamesAction({ action: 'add', name });
  }

  renameCastName(name: string, newName: string): Observable<CastNamesResponse> {
    return this.castNamesAction({ action: 'rename', name, newName });
  }

  deleteCastName(name: string): Observable<CastNamesResponse> {
    return this.castNamesAction({ action: 'delete', name });
  }

  private castNamesAction(body: object): Observable<CastNamesResponse> {
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http
      .post<CastNamesResponse>(this.castNamesManageUrl, body, { headers })
      .pipe(catchError(this.handleError));
  }

  private handleError(error: HttpErrorResponse) {
    console.error('SettingsService error:', error);
    const serverMsg =
      (error.error && typeof error.error === 'object' && error.error.message) ||
      (typeof error.error === 'string' && error.error) ||
      error.message;
    return throwError(() => new Error(serverMsg));
  }
}
