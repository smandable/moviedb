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
  /** Deleted names, kept from coming back from filenames. */
  blocked?: string[];
  added?: string;
  renamed?: string;
  /** true/false for one delete; how many for deleteMany. */
  deleted?: boolean | number;
  restored?: string;
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

/** One file a respelling would rename (castNamesAudit.php 'respellPreview'). */
export interface CastRespellFile {
  path: string;
  dir: string;
  file: string;
  newFile: string;
  /** Another indexed file already has the new name — it will be left alone. */
  conflict: boolean;
}

/** castNamesAudit.php 'respell'. */
export interface CastRespellResponse {
  results: { path: string; newFile: string; renamed: boolean; error?: string }[];
  renamed: number;
  failed: number;
  /** Files carrying a spelling that weren't in the preview, so were left alone. */
  notPreviewed: number;
  indexUpdated: boolean;
  /** The vocabulary afterwards. */
  names: string[];
  blocked?: string[];
  /** Whether the old spellings left the vocabulary (only once no file uses them). */
  removed: boolean;
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

  /** Delete (and block) several names at once. */
  deleteCastNames(names: string[]): Observable<CastNamesResponse> {
    return this.castNamesAction({ action: 'deleteMany', names });
  }

  /** Unblock a deleted name and put it back in the vocabulary. */
  restoreCastName(name: string): Observable<CastNamesResponse> {
    return this.castNamesAction({ action: 'restore', name });
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

  /** The renames respelling `from` as `to` would make (read-only). */
  previewCastRespell(from: string[], to: string): Observable<{ files: CastRespellFile[] }> {
    return this.castAuditAction({ action: 'respellPreview', from, to });
  }

  /** Rename the previewed `files` from the `from` spellings to `to`. */
  respellCastName(from: string[], to: string, files: string[]): Observable<CastRespellResponse> {
    return this.castAuditAction({ action: 'respell', from, to, files });
  }

  private castAuditAction<T>(body: object): Observable<T> {
    const headers = new HttpHeaders({
      'Content-Type': 'application/json',
      // CSRF gate — castNamesAudit.php refuses everything but 'run' without it
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
