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
  /**
   * Folders the Settings page offers to normalize; absent means the
   * drive-index roots.
   */
  normalizeRoots?: string[];
  /** Catalog table override (overrides DB_TABLE; see server/db_tables.php). */
  dbTable?: string;
}

/** The catalog table in effect and the choices (appSettings.php GET + POST). */
export interface DbTableInfo {
  activeDbTable?: string;
  dbTables?: string[];
}

export interface SaveSettingsResponse extends DbTableInfo {
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

/** One spelling in a cast-audit finding, with how many indexed files use it. */
export interface CastAuditName {
  name: string;
  uses: number;
  /** A few of those files' paths. */
  files: string[];
}

export interface CastAuditFinding {
  /** Stable id — what a dismissal remembers. */
  key: string;
  kind: 'duplicate' | 'variant' | 'junk' | 'male';
  reason: string;
  names: CastAuditName[];
}

/** castNamesAudit.php 'run'. */
export interface CastAuditResponse {
  findings: CastAuditFinding[];
  /** Findings dismissed earlier and left out of this list. */
  hidden: number;
  total: number;
  /** The drive index the use counts came from; null when none is built. */
  index: { builtAt: string | null; roots: string[]; fileCount: number } | null;
}

@Injectable({
  providedIn: 'root',
})
export class SettingsService {
  private readonly baseUrl = environment.apiBaseUrl;

  private settingsUrl = `${this.baseUrl}appSettings.php`;
  private castNamesManageUrl = `${this.baseUrl}castNamesManage.php`;
  private castNamesAuditUrl = `${this.baseUrl}castNamesAudit.php`;

  constructor(private http: HttpClient) {}

  getSettings(): Observable<{ settings: AppSettings } & DbTableInfo> {
    return this.http
      .get<{ settings: AppSettings } & DbTableInfo>(this.settingsUrl)
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

  /** Look for junk and duplicates in the vocabulary (read-only). */
  auditCastNames(): Observable<CastAuditResponse> {
    return this.castAuditAction<CastAuditResponse>({ action: 'run' });
  }

  /** Hide one audit finding from future runs. */
  dismissCastAuditFinding(key: string): Observable<{ success: boolean }> {
    return this.castAuditAction({ action: 'dismiss', key });
  }

  /** Show every dismissed audit finding again. */
  resetCastAuditDismissals(): Observable<{ success: boolean }> {
    return this.castAuditAction({ action: 'reset' });
  }

  private castAuditAction<T>(body: object): Observable<T> {
    const headers = new HttpHeaders({
      'Content-Type': 'application/json',
      // CSRF gate — castNamesAudit.php refuses dismiss/reset without it
      'X-Requested-With': 'XMLHttpRequest',
    });
    return this.http
      .post<T>(this.castNamesAuditUrl, body, { headers })
      .pipe(catchError(this.handleError));
  }

  private castNamesAction(body: object): Observable<CastNamesResponse> {
    const headers = new HttpHeaders({
      'Content-Type': 'application/json',
      // CSRF gate — castNamesManage.php refuses add/rename/delete without it
      'X-Requested-With': 'XMLHttpRequest',
    });
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
