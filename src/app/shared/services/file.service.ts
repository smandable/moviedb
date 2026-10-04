import { Injectable } from '@angular/core';
import {
  HttpClient,
  HttpErrorResponse,
  HttpHeaders,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError, tap } from 'rxjs/operators';
import { environment } from 'src/environments/environment';

export interface NormalizedFile {
  path: string;
  originalFileName: string;
  newFileName: string;
  fileExtension: string;
  fileNameNoExtension: string;
  needsNormalization: boolean;
  status: string;
  exclude?: boolean;
  // client-side only
  workingBaseName?: string;
  userEdited?: boolean;
  renameError?: string;
}

export interface RenameResult {
  originalFileName: string;
  newFileName: string;
  status: string;
  /** Directory the renamed file was moved up into (needs-cast staging). */
  movedTo?: string;
  /** The rename landed, but the move up out of needs-cast staging failed. */
  moveError?: string;
}

export interface ProcessFilesResponse {
  success?: boolean;
  message: string;
  /** Catalog table the titles were checked against (echoed by updateRow). */
  table?: string;
  titles: Array<{
    title: string;
    titleSize: number;
    fileDimensions: string;
    titleDuration: number;
    titlePath: string;
    duplicate?: boolean;
    id?: number;
    dateCreatedInDB?: string;
    dimensionsInDB?: string;
    sizeInDB?: number | string; // MySQL often returns strings
    durationInDB?: number | string; // MySQL often returns strings
    needsUpdateFilesize?: boolean;
    isLarger?: string;
    // Numbering-mismatch helpers for UI
    baseTitle?: string;
    titleHasNumber?: boolean;
    dbHasUnnumberedVariant?: boolean;
    dbHasNumberedVariant?: boolean;
    dbHasOtherNumberedVariant?: boolean;
    needsUpdateMissingMeta?: boolean;
    needsExternalSearch?: boolean;
  }>;
}

/**
 * Every FileService POST sends the CSRF header: rename, process, edit, the
 * Finder search, and cast-name merges all refuse requests without it. It's
 * sent on the read-only calls too, so no call site has to pick.
 * X-Requested-With specifically because httpd.conf's CORS
 * Access-Control-Allow-Headers already permits it — a novel header name
 * would fail the preflight and every request would "Failed to fetch".
 */
function jsonHeaders(): HttpHeaders {
  return new HttpHeaders({
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  });
}

@Injectable({
  providedIn: 'root',
})
export class FileService {
  // Base URL now comes from environment.ts:
  // 'http://localhost:8888/moviedb/server/'
  private readonly baseUrl = environment.apiBaseUrl;

  private checkFilesUrl = `${this.baseUrl}checkFileNamesToNormalize.php`;
  private renameFilesUrl = `${this.baseUrl}renameTheFilesToNormalize.php`;
  private processFilesForDBUrl = `${this.baseUrl}processFilesForDB.php`;
  private updateRowUrl = `${this.baseUrl}editCurrentRow.php`;
  private openExternalDriveSearchUrl = `${this.baseUrl}openExternalDriveSearch.php`;
  private normalizeNameUrl = `${this.baseUrl}normalizeName.php`;
  private castNamesUrl = `${this.baseUrl}castNames.php`;

  /**
   * The catalog table the last processFilesForDB() ran against. "Update DB"
   * row writes echo it, so after a Settings → Catalog Table switch the server
   * refuses them instead of hitting the same-numbered row in the other table.
   */
  processedTable: string | null = null;

  constructor(private http: HttpClient) {}

  /**
   * Sends a request to check and normalize filenames.
   * @param directory The directory path to process.
   * @param videoOnly List video files only (the drive index's types) — for
   *   library folders, which also hold scripts and notes.
   * @returns An observable containing the list of files.
   */
  checkFileNamesToNormalize(
    directory: string,
    videoOnly = false,
  ): Observable<{ files: NormalizedFile[] }> {
    const headers = jsonHeaders();
    const body = videoOnly ? { directory, videoOnly } : { directory };
    return this.http
      .post<{
        files: NormalizedFile[];
      }>(this.checkFilesUrl, body, { headers })
      .pipe(catchError(this.handleError));
  }

  /**
   * Sends a list of files to the backend to perform renaming.
   * @param files The list of files to rename.
   * @returns An observable with the renaming results.
   */
  renameTheFilesToNormalize(
    files: NormalizedFile[],
  ): Observable<{ results: RenameResult[] }> {
    const headers = jsonHeaders();
    return this.http
      .post<{
        results: RenameResult[];
      }>(this.renameFilesUrl, { files }, { headers })
      .pipe(catchError(this.handleError));
  }

  /**
   * Sends a request to process files for database operations.
   * @param directory The directory path to process.
   * @returns An observable containing the processing results.
   */
  processFilesForDB(directory: string): Observable<ProcessFilesResponse> {
    const headers = jsonHeaders();
    return this.http
      .post<ProcessFilesResponse>(
        this.processFilesForDBUrl,
        { directory },
        { headers },
      )
      .pipe(
        tap((res) => (this.processedTable = res?.table ?? this.processedTable)),
        catchError(this.handleError),
      );
  }

  /**
   * Opens a Finder Smart Folder search scoped to external volumes (server-side).
   */
  openExternalDriveSearch(query: string): Observable<any> {
    const headers = jsonHeaders();
    return this.http
      .post<any>(this.openExternalDriveSearchUrl, { query }, { headers })
      .pipe(catchError(this.handleError));
  }

  /**
   * Normalizes a single file base name server-side (single source of truth for
   * the rename preview — same pipeline used by checkFileNamesToNormalize).
   * @param name The working base name (no extension).
   * @param respectUserCasing Preserve the user's casing when they've edited.
   * @param keepCastDots Keep periods in the cast tail — set when the user
   *   deliberately typed one, which the default sweep would remove.
   */
  normalizeName(
    name: string,
    respectUserCasing: boolean,
    keepCastDots: boolean = false,
  ): Observable<{ normalized: string }> {
    const headers = jsonHeaders();
    return this.http
      .post<{ normalized: string }>(
        this.normalizeNameUrl,
        { name, respectUserCasing, keepCastDots },
        { headers },
      )
      .pipe(catchError(this.handleError));
  }

  /**
   * Cast-name vocabulary for the Add Cast tab's autocomplete: names already used
   * in the scanned directory's filenames, unioned with a persisted store so the
   * list survives a batch moving off the staging drive.
   * @param directory Scan this directory's filenames for names already in use.
   * @param add Names newly used, to merge into the store.
   */
  getCastNames(directory?: string, add?: string[]): Observable<{ names: string[] }> {
    const headers = jsonHeaders();
    return this.http
      .post<{ names: string[] }>(this.castNamesUrl, { directory, add }, { headers })
      .pipe(catchError(this.handleError));
  }

  /**
   * Handles HTTP errors.
   * @param error The HTTP error.
   * @returns An observable that errors out.
   */
  private handleError(error: HttpErrorResponse) {
    console.error('FileService error:', error);
    const serverMsg =
      (error.error && typeof error.error === 'object' && error.error.message) ||
      (typeof error.error === 'string' && error.error) ||
      error.message ||
      'An error occurred while processing the request.';
    return throwError(() => new Error(serverMsg));
  }
  updateRow(
    id: number,
    updateFields: { dimensions: string; filesize: number; duration: number },
  ): Observable<any> {
    const payload = { id, updateFields, table: this.processedTable };
    const headers = jsonHeaders();
    // catchError: surface the server's message (e.g. the 409 "reload this
    // list" after a Catalog Table switch) rather than Angular's generic one
    return this.http
      .post<any>(this.updateRowUrl, payload, { headers })
      .pipe(catchError(this.handleError));
  }
}
