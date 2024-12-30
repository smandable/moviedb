// movie.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface Movie {
  id: string;
  title: string;
  dimensions: string;
  filesize: string;
  duration: string;
  date_created: string;
}

@Injectable({ providedIn: 'root' })
export class MovieService {
  private apiUrl = 'http://localhost/moviedbnew/server/getAllMovies.php';
  private deleteUrl = 'http://localhost/moviedbnew/server/deleteRow.php';

  constructor(private http: HttpClient) {}

  getAllMovies(): Observable<Movie[]> {
    return this.http.get<Movie[]>(this.apiUrl);
  }

  deleteRow(id: string): Observable<any> {
    const formData = new FormData();
    formData.append('id', id);
    return this.http.post<any>(this.deleteUrl, formData);
  }
  updateRow(id: string, columnToUpdate: string, valueToUpdate: any): Observable<any> {
    const payload = new FormData();
    payload.append('id', id);
    payload.append('columnToUpdate', columnToUpdate);
    payload.append('valueToUpdate', valueToUpdate);
  
    const url = 'http://localhost/moviedbnew/server/editCurrentRow.php';
    return this.http.post<any>(url, payload);
  }
  
}
