import { Injectable } from '@angular/core';
import {
  HttpClient,
  HttpErrorResponse,
  HttpHeaders,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';

import { environment } from 'src/environments/environment';

/** Options persisted under app settings' "consolidate" key. */
export interface ConsolidateSettings {
  drives: string[];
  /** Free-space sink for evacuation/balancing; must be one of `drives`. */
  balanceTo: string;
  targetFreeGb: number;
  reserveGb: number;
  balance: boolean;
  recursive: boolean;
}

export interface ConsolidateLastRun {
  finishedAt: string | number;
  /** Wall-clock run time; absent in .last files from before the field existed. */
  durationSeconds?: number;
  /** true/false once attempted; null when not attempted (dry run / no changes). */
  indexRebuilt?: boolean | null;
  /** Unnumbered files left alone because volume 1 was ambiguous — needs a look. */
  flagged?: number;
  dryRun: boolean;
  exitCode: number;
  moved: number;
  duped: number;
  skipped: number;
  renamed?: number;
  failed: number;
  movedBytes: number;
}

export interface ConsolidateStatus {
  running: boolean;
  pid: number | null;
  lastRun: ConsolidateLastRun | null;
  /** Effective settings (stored overrides merged over server defaults). */
  settings: ConsolidateSettings;
}

export interface ConsolidateRunResponse {
  started: boolean;
  pid?: number;
  message?: string;
}

export interface ConsolidateProgress {
  active: boolean;
  at?: number;
  phase?: 'scan' | 'groups' | 'balance' | 'index' | 'done';
  group?: string;
  groupsDone?: number;
  groupsTotal?: number;
  currentSrc?: string;
  currentDest?: string;
  movedBytes?: number;
  moved?: number;
  duped?: number;
  failed?: number;
  dryRun?: boolean;
  driveFree?: Record<string, number>;
  skipped?: number;
  renamed?: number;
  flagged?: number;
  /** True while the sidecar is quiet but the run provably lives (long mv). */
  stalled?: boolean;
}

export interface ConsolidateLogTailResponse {
  lines: string[];
}

@Injectable({
  providedIn: 'root',
})
export class ConsolidateService {
  private readonly baseUrl = environment.apiBaseUrl;

  private consolidateUrl = `${this.baseUrl}consolidateMovies.php`;

  constructor(private http: HttpClient) {}

  status(): Observable<ConsolidateStatus> {
    return this.consolidateAction<ConsolidateStatus>({ action: 'status' });
  }

  /** Kick off a run; execute=false plans only (dry run, moves nothing). */
  run(execute: boolean): Observable<ConsolidateRunResponse> {
    return this.consolidateAction<ConsolidateRunResponse>({
      action: 'run',
      execute,
    });
  }

  /** The in-flight run's progress ({active:false} when none is running). */
  progress(): Observable<ConsolidateProgress> {
    return this.consolidateAction<ConsolidateProgress>({ action: 'progress' });
  }

  /** Last N lines of the run log (server default 40, max 400). */
  logTail(
    lines?: number,
    actionsOnly = false,
  ): Observable<ConsolidateLogTailResponse> {
    return this.consolidateAction<ConsolidateLogTailResponse>({
      action: 'logTail',
      ...(lines !== undefined ? { lines } : {}),
      ...(actionsOnly ? { actions: true } : {}),
    });
  }

  private consolidateAction<T>(body: object): Observable<T> {
    const headers = new HttpHeaders({
      'Content-Type': 'application/json',
      // CSRF gate: the server refuses 'run' without this header (harmless on
      // reads). X-Requested-With specifically because httpd.conf's CORS
      // Access-Control-Allow-Headers already permits it — a novel header name
      // would fail the preflight and every request would "Failed to fetch".
      'X-Requested-With': 'XMLHttpRequest',
    });
    return this.http
      .post<T>(this.consolidateUrl, body, { headers })
      .pipe(catchError(this.handleError));
  }

  private handleError(error: HttpErrorResponse) {
    console.error('ConsolidateService error:', error);
    const body = error.error;
    const serverMsg =
      (body && typeof body === 'object' && (body.message || body.error)) ||
      (typeof body === 'string' && body) ||
      error.message;
    return throwError(() => new Error(serverMsg));
  }
}
