import { Injectable } from '@angular/core';
import {
  HttpClient,
  HttpErrorResponse,
  HttpHeaders,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';

import { environment } from 'src/environments/environment';

export interface DriveIndexStatus {
  exists: boolean;
  builtAt: string | null;
  fileCount: number;
  roots: string[];
  missingRoots: string[];
}

export interface DriveIndexFile {
  path: string;
  file: string;
  dir: string;
  size: number;
  mtime: number;
}

export interface DriveIndexGroup {
  base: string;
  files: DriveIndexFile[];
}

/**
 * Rebuild answers with the fresh index's stats. The contract said "same shape
 * as status", but the live endpoint omits `exists` (a successful rebuild
 * implies it) — so it is optional here and callers must default it.
 */
export interface DriveIndexRebuildResponse
  extends Omit<DriveIndexStatus, 'exists'> {
  success?: boolean;
  exists?: boolean;
}

export interface DriveIndexProgress {
  active: boolean;
  root?: string;
  rootsDone?: number;
  rootsTotal?: number;
  entries?: number;
}

export interface DriveIndexSearchResponse {
  /** One page of groups; totals cover the full match set. */
  groups: DriveIndexGroup[];
  totalGroups: number;
  totalFiles: number;
  /** Echoed by the server so a late response can't be applied to a new page. */
  offset?: number;
  pageSize?: number;
}

export interface DriveIndexRevealResponse {
  success: boolean;
  error?: string;
}

export interface DriveIndexTrashResult {
  path: string;
  trashed: boolean;
  /** Where the file went (the volume's Trash), when it moved. */
  to?: string;
  error?: string;
}

export interface DriveIndexTrashResponse {
  results: DriveIndexTrashResult[];
  /** false when files moved but the index rewrite failed (list is stale). */
  indexUpdated?: boolean;
}

@Injectable({
  providedIn: 'root',
})
export class DriveIndexService {
  private readonly baseUrl = environment.apiBaseUrl;

  private driveIndexUrl = `${this.baseUrl}driveIndex.php`;

  /** Pending auto-rebuild timer; reset by every new trash. */
  private rebuildTimer: ReturnType<typeof setTimeout> | undefined;

  /** 30s of quiet after the last trash before the index rebuilds itself. */
  static readonly REBUILD_DEBOUNCE_MS = 30_000;

  constructor(private http: HttpClient) {}

  /**
   * Trashing files signals an active clean-up session (drives mounted, files
   * moving in Finder too), so 30s after the LAST trash the index rebuilds
   * itself and sweeps up whatever else changed. The app stays running in the
   * background, so this root-provided service is a reliable place for the
   * timer; the server serializes writers, and a rebuild while some drives
   * are unmounted carries their entries forward (merge rebuild) — firing
   * "too early" can never lose index data.
   */
  scheduleDebouncedRebuild(): void {
    if (this.rebuildTimer) {
      clearTimeout(this.rebuildTimer);
    }
    this.rebuildTimer = setTimeout(() => {
      this.rebuildTimer = undefined;
      this.rebuild().subscribe({
        // Background maintenance: nothing to paint on success, and a failure
        // here must never surface as a user-facing error
        error: (err: Error) =>
          console.warn('Background index rebuild failed:', err.message),
      });
    }, DriveIndexService.REBUILD_DEBOUNCE_MS);
  }

  status(): Observable<DriveIndexStatus> {
    return this.driveIndexAction<DriveIndexStatus>({ action: 'status' });
  }

  search(query: string, offset = 0): Observable<DriveIndexSearchResponse> {
    return this.driveIndexAction<DriveIndexSearchResponse>({
      action: 'search',
      query,
      offset,
    });
  }

  rebuild(): Observable<DriveIndexRebuildResponse> {
    return this.driveIndexAction<DriveIndexRebuildResponse>({
      action: 'rebuild',
    });
  }

  /** The in-flight rebuild's progress ({active:false} when none is running). */
  progress(): Observable<DriveIndexProgress> {
    return this.driveIndexAction<DriveIndexProgress>({ action: 'progress' });
  }

  reveal(path: string): Observable<DriveIndexRevealResponse> {
    return this.driveIndexAction<DriveIndexRevealResponse>({
      action: 'reveal',
      path,
    });
  }

  trash(paths: string[]): Observable<DriveIndexTrashResponse> {
    return this.driveIndexAction<DriveIndexTrashResponse>({
      action: 'trash',
      paths,
    });
  }

  private driveIndexAction<T>(body: object): Observable<T> {
    const headers = new HttpHeaders({
      'Content-Type': 'application/json',
      // CSRF gate: the server refuses mutating actions without this header.
      // X-Requested-With specifically because httpd.conf's CORS
      // Access-Control-Allow-Headers already permits it — a novel header name
      // would fail the preflight and every request would "Failed to fetch".
      'X-Requested-With': 'XMLHttpRequest',
    });
    return this.http
      .post<T>(this.driveIndexUrl, body, { headers })
      .pipe(catchError(this.handleError));
  }

  private handleError(error: HttpErrorResponse) {
    console.error('DriveIndexService error:', error);
    const body = error.error;
    const serverMsg =
      (body && typeof body === 'object' && (body.message || body.error)) ||
      (typeof body === 'string' && body) ||
      error.message;
    return throwError(() => new Error(serverMsg));
  }
}
