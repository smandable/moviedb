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

import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';

@Injectable({
  providedIn: 'root',
})
export class MovieService {
  private getAllMoviesUrl = 'http://localhost/moviedbnew/server/getAllMovies.php';
  private updateMovieUrl = 'http://localhost/moviedbnew/server/editCurrentRow.php';
  private deleteMovieUrl = 'http://localhost/moviedbnew/server/deleteRow.php';

  constructor(private http: HttpClient) {}

  /**
   * Fetches all movies from the backend.
   * @returns An Observable of Movie array.
   */
  getAllMovies(): Observable<Movie[]> {
    return this.http.get<Movie[]>(this.getAllMoviesUrl);
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
    return this.http.post<any>(this.updateMovieUrl, body, { headers });
  }

  /**
   * Deletes a movie by ID.
   * @param id The ID of the movie to delete.
   * @returns An Observable of the delete response.
   */
  deleteRow(id: number): Observable<any> {
    return this.http.post(this.deleteMovieUrl, { id });
  }
  
}
