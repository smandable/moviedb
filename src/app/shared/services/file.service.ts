import { Injectable } from '@angular/core';
import {
  HttpClient,
  HttpErrorResponse,
  HttpHeaders,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
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
}

export interface RenameResult {
  originalFileName: string;
  newFileName: string;
  status: string;
}

export interface ProcessFilesResponse {
  error: string;
  message: string;
  titles: Array<{
    title: string;
    titleSize: number;
    fileDimensions: string;
    titleDuration: string;
    titlePath: string;
    duplicate?: boolean;
    id?: number;
    dateCreatedInDB?: string;
    dimensionsInDB?: string;
    sizeInDB?: number;
    durationInDB?: string;
    isLarger?: string;
    // Numbering-mismatch helpers for UI
    baseTitle?: string;
    titleHasNumber?: boolean;
    dbHasUnnumberedVariant?: boolean;
    dbHasNumberedVariant?: boolean;
    needsExternalSearch?: boolean;
  }>;
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
  // private performDbOpsUrl    = `${this.baseUrl}performDatabaseOperations.php`;

  constructor(private http: HttpClient) {}

  /**
   * Sends a request to check and normalize filenames.
   * @param directory The directory path to process.
   * @returns An observable containing the list of files.
   */
  checkFileNamesToNormalize(
    directory: string
  ): Observable<{ files: NormalizedFile[] }> {
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http
      .post<{ files: NormalizedFile[] }>(
        this.checkFilesUrl,
        { directory },
        { headers }
      )
      .pipe(catchError(this.handleError));
  }

  /**
   * Sends a list of files to the backend to perform renaming.
   * @param files The list of files to rename.
   * @returns An observable with the renaming results.
   */
  renameTheFilesToNormalize(
    files: NormalizedFile[]
  ): Observable<{ results: RenameResult[] }> {
    console.log('Preparing to send files to rename:', files);
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http
      .post<{ results: RenameResult[] }>(
        this.renameFilesUrl,
        { files },
        { headers }
      )
      .pipe(catchError(this.handleError));
  }

  /**
   * Sends a request to process files for database operations.
   * @param directory The directory path to process.
   * @returns An observable containing the processing results.
   */
  processFilesForDB(directory: string): Observable<ProcessFilesResponse> {
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http.post<ProcessFilesResponse>(
      this.processFilesForDBUrl,
      { directory },
      { headers }
    );
  }

  /**
   * Performs database operations.
   * @returns An observable containing the operation results.
   */
  performDatabaseOperations(): Observable<any> {
    // Still a placeholder;
    return this.http.post<any>(
      'path/to/performDatabaseOperations.php',
      {},
      { headers: new HttpHeaders({ 'Content-Type': 'application/json' }) }
    );
  }


  /**
   * Opens a Finder Smart Folder search scoped to external volumes (server-side).
   */
  openExternalDriveSearch(query: string): Observable<any> {
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http
      .post<any>(this.openExternalDriveSearchUrl, { query }, { headers })
      .pipe(catchError(this.handleError));
  }

  /**
   * Handles HTTP errors.
   * @param error The HTTP error.
   * @returns An observable that errors out.
   */
  private handleError(error: HttpErrorResponse) {
    console.error('FileService error:', error);
    return throwError(
      () => new Error('An error occurred while processing the request.')
    );
  }
  updateRow(
    id: number,
    updateFields: { dimensions: string; filesize: number; duration: number }
  ): Observable<any> {
    const payload = { id, updateFields };
    console.log('Sending to server:', payload);
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http.post<any>(this.updateRowUrl, payload, { headers });
  }
}
