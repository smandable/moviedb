import { Injectable } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from 'src/environments/environment';

export interface Movie {
  id: number;
  title: string;
  dimensions: string;
  duration: number; // Updated field name and type
  filesize: number;
  filepath: string;
  date_created: string;
  duplicate: boolean;
  isLarger: string;
}

@Injectable({
  providedIn: 'root',
})
export class MovieService {
  // Base URL comes from environment.ts:
  // 'http://localhost:8888/moviedb/server/'
  private readonly baseUrl = environment.apiBaseUrl;

  private readonly getAllMoviesUrl = `${this.baseUrl}getAllMovies.php`;
  private readonly updateMovieUrl  = `${this.baseUrl}editCurrentRow.php`;
  private readonly deleteMovieUrl  = `${this.baseUrl}deleteRow.php`;

  constructor(private http: HttpClient) {}

  /**
   * Fetches all movies from the backend.
   * @returns An Observable of Movie array.
   */
  getAllMovies(): Observable<Movie[]> {
    return this.http.get<Movie[]>(this.getAllMoviesUrl).pipe(catchError(this.handleError));
  }

  /**
   * Updates a specific field of a movie.
   * @param id The ID of the movie to update.
   * @param field The field to update.
   * @param value The new value for the field.
   * @returns An Observable of the update response.
   */
  updateRow(id: number, field: string, value: any): Observable<any> {
    const body = { id, field, value };
    const headers = new HttpHeaders({ 'Content-Type': 'application/json' });
    return this.http.post<any>(this.updateMovieUrl, body, { headers }).pipe(catchError(this.handleError));
  }

  /**
   * Deletes a movie by ID.
   * @param id The ID of the movie to delete.
   * @returns An Observable of the delete response.
   */
  deleteRow(id: number): Observable<any> {
    return this.http.post(this.deleteMovieUrl, { id }).pipe(catchError(this.handleError));
  }

  private handleError(error: HttpErrorResponse) {
    console.error('MovieService error:', error);
    return throwError(() => new Error('An error occurred while processing the request.'));
  }
}
