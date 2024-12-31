import { Injectable } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';

export interface NormalizedFile {
  path: string;
  originalFileName: string;
  newFileName: string;
  fileExtension: string;
  fileNameNoExtension: string;
}

export interface RenameResult {
  originalFileName: string;
  newFileName: string;
  status: string;
}

@Injectable({
  providedIn: 'root',
})
export class FileService {
  private checkFilesUrl = 'http://localhost/moviedbnew/server/checkFileNamesToNormalize.php'; // Update with actual path
  private renameFilesUrl = 'http://localhost/moviedbnew/server/renameTheFilesToNormalize.php'; // Update with actual path

  constructor(private http: HttpClient) {}

  /**
   * Sends a directory path to the backend to check and normalize filenames.
   * @param directory The directory to process.
   * @returns An observable with the list of normalized files.
   */
  checkFileNamesToNormalize(directory: string): Observable<{ files: NormalizedFile[] }> {
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http.post<{ files: NormalizedFile[] }>(this.checkFilesUrl, { directory }, { headers })
      .pipe(
        catchError(this.handleError)
      );
  }

  /**
   * Sends a list of files to the backend to perform renaming.
   * @param files The list of files to rename.
   * @returns An observable with the renaming results.
   */
  renameTheFilesToNormalize(files: NormalizedFile[]): Observable<{ results: RenameResult[] }> {
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http.post<{ results: RenameResult[] }>(this.renameFilesUrl, { files }, { headers })
      .pipe(
        catchError(this.handleError)
      );
  }

  /**
   * Handles HTTP errors.
   * @param error The HTTP error.
   * @returns An observable that errors out.
   */
  private handleError(error: HttpErrorResponse) {
    console.error('FileService error:', error);
    return throwError(() => new Error('An error occurred while processing the request.'));
  }
}
