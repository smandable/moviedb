import { Injectable } from '@angular/core';
import {
  HttpClient,
  HttpErrorResponse,
  HttpHeaders,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';

import { environment } from 'src/environments/environment';

/** One landed rename, as the normalize modal reports it. */
export interface TitleRename {
  /** Folder the file now lives in. */
  path: string;
  originalFileName: string;
  newFileName: string;
}

export interface TitleRow {
  id: number;
  title: string;
  dimensions: string | null;
  filesize: string | null;
  duration: number | null;
  date_created: string | null;
}

export type TitleUpdateStatus =
  | 'update'
  | 'merge'
  | 'current'
  | 'missing'
  | 'partial'
  | 'conflict'
  | 'ambiguous';

/** What a merge would leave in the kept row. */
export interface TitleMergeValues {
  /** 'files': measured from the files; 'rows': the kept row, blanks filled. */
  source: 'files' | 'rows';
  filesize: string | null;
  dimensions: string | null;
  duration: number | string | null;
  fileCount: number;
  /** Copies with this title on other indexed drives (why 'rows' was used). */
  elsewhere: string[];
}

/** One title change: see moviedb_title_update_classify in title_update_lib.php. */
export interface TitleUpdateItem {
  table: string;
  oldTitle: string;
  newTitle: string;
  files: string[];
  rows: TitleRow[];
  leftovers: string[];
  status: TitleUpdateStatus;
  reason?: string;
  note?: string | null;
  /** status 'update': the row to rename. */
  row?: TitleRow;
  /** status 'merge': the earliest-catalogued row stays, the other goes. */
  keep?: TitleRow;
  drop?: TitleRow;
  merged?: TitleMergeValues;
}

export interface TitleUpdateResult {
  id: number;
  ok: boolean;
  error?: string;
  row?: TitleRow;
  /** false when the change landed but the undo log couldn't be written. */
  logged?: boolean;
}

export interface TitleMergeResult {
  ok: boolean;
  error?: string;
  row?: TitleRow;
  deleted?: number;
  logged?: boolean;
}

export interface TitlePreviewResponse {
  items: TitleUpdateItem[];
  /** No drive index: copies on other drives couldn't be checked. */
  indexMissing?: boolean;
}

/**
 * Database titles after library renames (server/titleUpdates.php): preview
 * which rows a batch of renames affects, rename them, or merge a row into
 * the one that already carries the new title.
 */
@Injectable({ providedIn: 'root' })
export class TitleUpdateService {
  private readonly url = `${environment.apiBaseUrl}titleUpdates.php`;

  constructor(private http: HttpClient) {}

  preview(renames: TitleRename[]): Observable<TitlePreviewResponse> {
    return this.post({ action: 'preview', renames });
  }

  apply(
    updates: { table: string; id: number; from: string; to: string }[],
  ): Observable<{ results: TitleUpdateResult[] }> {
    return this.post({ action: 'apply', updates });
  }

  merge(item: TitleUpdateItem): Observable<TitleMergeResult> {
    const merged = item.merged!;
    return this.post({
      action: 'merge',
      table: item.table,
      keep: item.keep,
      drop: item.drop,
      oldTitle: item.oldTitle,
      title: item.newTitle,
      values: {
        filesize: merged.filesize,
        dimensions: merged.dimensions,
        duration: merged.duration,
      },
    });
  }

  private post<T>(body: object): Observable<T> {
    const headers = new HttpHeaders({
      'Content-Type': 'application/json',
      // CSRF gate: the server refuses apply/merge without this header.
      // X-Requested-With specifically because httpd.conf's CORS
      // Access-Control-Allow-Headers already permits it — a novel header name
      // would fail the preflight and every request would "Failed to fetch".
      'X-Requested-With': 'XMLHttpRequest',
    });
    return this.http
      .post<T>(this.url, body, { headers })
      .pipe(catchError(this.handleError));
  }

  private handleError(error: HttpErrorResponse) {
    const body = error.error;
    const serverMsg =
      (body && typeof body === 'object' && (body.message || body.error)) ||
      (typeof body === 'string' && body) ||
      error.message;
    return throwError(() => new Error(serverMsg));
  }
}
