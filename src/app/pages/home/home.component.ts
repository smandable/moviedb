// Angular modules
import { NgIf } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { HttpClientModule } from '@angular/common/http';

// Services
import { StoreService } from '@services/store.service';
import { MovieService, Movie } from '@services/movie.service';

// Components
import { ProgressBarComponent } from '@blocks/progress-bar/progress-bar.component';
import { PageLayoutComponent } from '@layouts/page-layout/page-layout.component';
import { AgGridAngular } from 'ag-grid-angular';

import {
  AllCommunityModule,
  ModuleRegistry,
  GridOptions,
  GridReadyEvent,
  ICellRendererParams,
  ColDef,
  themeQuartz,
} from 'ag-grid-community';



ModuleRegistry.registerModules([AllCommunityModule]);

// Custom theme created with theme builder: https://www.ag-grid.com/theme-builder/
const myTheme = themeQuartz.withParams({
  backgroundColor: '#2D2F32',
  browserColorScheme: 'dark',
  cellHorizontalPaddingScale: 1,
  chromeBackgroundColor: {
    ref: 'foregroundColor',
    mix: 0.07,
    onto: 'backgroundColor',
  },
  fontFamily: {
    googleFont: 'Lato',
  },
  fontSize: 14,
  foregroundColor: '#FFF',
  headerFontFamily: 'inherit',
  headerFontSize: 18,
  headerFontWeight: 600,
  rowVerticalPaddingScale: 0.5,
});

@Component({
  selector: 'app-home',
  templateUrl: './home.component.html',
  styleUrls: ['./home.component.scss'],
  standalone: true,
  imports: [PageLayoutComponent, NgIf, ProgressBarComponent, AgGridAngular],
})

export class HomeComponent implements OnInit {
  private gridApi: GridReadyEvent['api'] | undefined;

  public gridOptions: GridOptions = {
    theme: myTheme,
    rowSelection: 'single',
    
    defaultColDef: {
      width: 155, // Set default column width
      sortable: true,
      filter: true,
      resizable: true, // Allow resizing of columns
    },
    onGridReady: (params: GridReadyEvent) => {
      console.log('Grid is ready!');
      this.gridApi = params.api;
      params.api.sizeColumnsToFit();
    },
    onCellValueChanged: (event) => this.onCellValueChanged(event), // Handle cell edits

  };
  columnDefs: ColDef[] = [
    { field: 'title', headerName: 'Title', width: 600, editable: true },
    { field: 'dimensions', headerName: 'Dimensions', width: 150, editable: true },
    { field: 'filesize', headerName: 'File Size', width: 150, valueFormatter: fileSizeFormatter, editable: true },
    { field: 'duration', headerName: 'Duration', width: 150, valueFormatter: durationFormatter, editable: true},
    { field: 'date_created', headerName: 'Date Created', width: 150 },
    {
      headerName: '',        // No heading
      width: 100,             // Narrow column
      filter: false, // Disable filter
    sortable: false, // Disable sorting

      cellRenderer: (params: ICellRendererParams) => {
        // Create an HTML element for the trash icon
        const icon = document.createElement('span');
        icon.innerHTML = '🗑️';           // or use <i class="fa fa-trash"></i>, etc.
        icon.style.cursor = 'pointer';
        icon.title = 'Delete this row';

        // Listen for clicks on the icon
        icon.addEventListener('click', () => {
          const rowData = params.data as Movie; // Cast params.data to Movie type
          this.deleteRow(rowData);
        });

        return icon;
      },
    },
  ];

  constructor(public storeService : StoreService, private movieService: MovieService) {}

  ngOnInit(): void {
    // Fetch data from the PHP endpoint
    this.movieService.getAllMovies().subscribe((movies: Movie[]) => {
      // Provide the data to AG Grid
      this.gridOptions.rowData = movies;
      // console.log('Movies:', movies);
  
      // Loading indicator logic
      setTimeout(() => {
        this.storeService.isLoading.set(false);
      }, 500);
    }); 
  }
  deleteRow(movie: Movie) {
    if (!confirm(`Are you sure you want to delete "${movie.title}"?`)) {
      return;
    }

    this.movieService.deleteRow(movie.id).subscribe({
      next: (response) => {
        console.log('Delete success:', response);
        if (this.gridApi) {
          this.gridApi.applyTransaction({ remove: [movie] });
        }
      },
      error: (error) => {
        console.error('Delete failed:', error);
        alert('Failed to delete row. See console for details.');
      },
    });
  }
  onCellValueChanged(event: any): void {
    const { data, colDef, newValue } = event;
  
    // Prevent unnecessary requests if the value hasn't changed
    if (newValue === event.oldValue) {
      return;
    }
  
    // Extract the necessary fields for the request
    const id = data.id; // Ensure `id` is part of your row data
    const columnToUpdate = colDef.field; // This maps to the column field
    const valueToUpdate = newValue;
  
    // Send the update request to the PHP backend
    this.movieService.updateRow(id, columnToUpdate, valueToUpdate).subscribe({
      next: (response) => {
        if (response.success) {
          console.log('Update successful:', response.message);
        } else {
          console.error('Update failed:', response.error);
        }
      },
      error: (error) => {
        console.error('Update request failed:', error);
      },
    });
  }
  
}
// Utility function to format file sizes with a space between the number and the units
function fileSizeFormatter(params: { value: number }): string {
  if (!params.value) {
    return '0 Bytes'; // Handle empty or zero values
  }

  const units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
  const exponent = Math.min(
    Math.floor(Math.log(params.value) / Math.log(1024)),
    units.length - 1
  );
  const size = params.value / Math.pow(1024, exponent);
  
  const output = `${size.toFixed(2)}\u00A0${units[exponent]}`;
  // console.log('Formatted file size:', `[${output}]`); 
  return output;
}
// Utility function to format durations
function durationFormatter(params: { value: number }): string {
  if (!params.value) {
    return '00:00:00'; // Handle empty or zero values
  }

  const totalSeconds = Math.floor(params.value);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  const pad = (n: number) => n.toString().padStart(2, '0');
  return hours > 0
    ? `${hours}:${pad(minutes)}:${pad(seconds)}` // No leading zero for hours
    : `${pad(minutes)}:${pad(seconds)}`; // Skip hours if zero
}



