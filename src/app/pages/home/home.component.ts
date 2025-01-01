// Angular modules
import { NgIf } from '@angular/common';
import { Component, OnInit, ViewChild, ChangeDetectorRef } from '@angular/core';
import { ActivatedRoute, Router, NavigationEnd } from '@angular/router';

// Services
import { StoreService } from '@services/store.service';
import { MovieService, Movie } from '@services/movie.service';

// Components
import { ProgressBarComponent } from '@blocks/progress-bar/progress-bar.component';
import { PageLayoutComponent } from '@layouts/page-layout/page-layout.component';
import { AgGridAngular } from 'ag-grid-angular';
import { CustomFloatingFilterComponent } from '@components/grid/custom-floating-filter/custom-floating-filter.component';

// Utilities
import { fileSizeFormatter, durationFormatter } from '@helpers/formatters';
import { myTheme } from '@helpers/grid-theme';

// AG Grid Imports
import {
  AllCommunityModule,
  ModuleRegistry,
  ClientSideRowModelModule,
  GridOptions,
  GridReadyEvent,
  GridApi,
  ICellRendererParams,
  ColDef,
} from 'ag-grid-community';

// Register AG Grid modules
ModuleRegistry.registerModules([AllCommunityModule, ClientSideRowModelModule]);

@Component({
  selector: 'app-home',
  templateUrl: './home.component.html',
  styleUrls: ['./home.component.scss'],
  standalone: true,
  imports: [PageLayoutComponent, NgIf, ProgressBarComponent, AgGridAngular],
})
export class HomeComponent implements OnInit {
  // Bind this property to the grid's rowData
  public rowData: Movie[] = [];
  public totalItems: number = 0; // Holds the total count

  // Reference to the AG Grid API
  private gridApi: GridApi<Movie> | undefined;

  // Reference to the AgGridAngular component in the template
  @ViewChild('agGrid') agGrid!: AgGridAngular<Movie>;

  // AG Grid configuration
  public gridOptions: GridOptions<Movie> = {
    theme: myTheme,
    rowSelection: 'single',

    // Ensure each row has a unique ID
    getRowId: (params) => params.data.id.toString(),

    defaultColDef: {
      width: 155, // Set default column width
      sortable: true,
      filter: false,
      resizable: true, // Allow resizing of columns
      floatingFilter: false, // Enable floating filters
    },

    // Event fired when the grid is ready
    onGridReady: (params: GridReadyEvent<Movie>) => {
      console.log('Grid is ready!');
      this.gridApi = params.api;
      params.api.sizeColumnsToFit();

      this.loadMovies(); // Load data once the grid is ready
    },

    // Handle cell edits
    onCellValueChanged: (event) => this.onCellValueChanged(event),
  };

  // Define the columns for the grid
  columnDefs: ColDef<Movie>[] = [
    {
      field: 'title',
      headerName: 'Title',
      width: 600,
      editable: true,
      filter: 'agTextColumnFilter', // Use a text filter
      floatingFilter: true,
      floatingFilterComponent: CustomFloatingFilterComponent,
      filterParams: {
        filterOptions: ['startsWith', 'contains', 'endsWith'],
        debounceMs: 300,
        caseSensitive: false,
        defaultOption: 'startsWith', // Default filter option in the menu
      },
    },
    {
      field: 'dimensions',
      headerName: 'Dimensions',
      width: 150,
      editable: true,
    },
    {
      field: 'duration',
      headerName: 'Duration',
      width: 150,
      valueFormatter: durationFormatter,
      editable: true,
    },
    {
      field: 'filesize',
      headerName: 'File Size',
      width: 150,
      valueFormatter: fileSizeFormatter,
      editable: true,
    },
    { field: 'date_created', headerName: 'Date Created', width: 150 },
    {
      headerName: '', // No heading
      width: 100, // Narrow column
      filter: false, // Disable filter
      sortable: false, // Disable sorting

      // Renderer for the delete icon
      cellRenderer: (params: ICellRendererParams<Movie>) => {
        const icon = document.createElement('span');
        icon.innerHTML = '🗑️';
        icon.style.cursor = 'pointer';
        icon.title = 'Delete this row';

        // Attach click event to delete the row
        icon.addEventListener('click', () => {
          const rowData = params.data as Movie;
          this.deleteRow(rowData);
        });

        return icon;
      },
    },
  ];

  constructor(
    public storeService: StoreService,
    private movieService: MovieService,
    private router: Router,
    private route: ActivatedRoute,
    private cdr: ChangeDetectorRef // Inject ChangeDetectorRef for manual change detection
  ) {}

  ngOnInit(): void {
    // Subscribe to router events to detect navigation back to '/home'
    this.router.events.subscribe((event) => {
      if (event instanceof NavigationEnd && event.url === '/home') {
        this.loadMovies(); // Reload data when navigating back to Home
      }
    });
  }

  /**
   * Loads movies from the backend and updates the grid and total count.
   */
  loadMovies(): void {
    this.movieService.getAllMovies().subscribe({
      next: (movies: Movie[]) => {
        // console.log('Movies loaded:', movies);
        this.rowData = movies.map((movie) => ({
          ...movie,
          titleSize:
            typeof movie.filesize === 'string'
              ? parseInt(movie.filesize, 10)
              : movie.filesize,
          duration:
            typeof movie.duration === 'string'
              ? Math.round(parseFloat(movie.duration))
              : movie.duration,
        }));
        this.totalItems = movies.length;

        // Trigger change detection to update the view
        this.cdr.detectChanges();

        // Loading indicator logic
        setTimeout(() => {
          this.storeService.isLoading.set(false);
        }, 500);
      },
      error: (error) => {
        console.error('Failed to load movies:', error);
      },
    });
  }

  /**
   * Deletes a movie both from the backend and the grid.
   * @param movie The movie to delete.
   */
  deleteRow(movie: Movie) {
    if (!confirm(`Are you sure you want to delete "${movie.title}"?`)) {
      return;
    }

    this.movieService.deleteRow(movie.id).subscribe({
      next: (response) => {
        console.log('Delete success:', response);

        // Remove the movie from the rowData array
        this.rowData = this.rowData.filter((m) => m.id !== movie.id);
        this.totalItems--;

        console.log('New totalItems:', this.totalItems);

        // Trigger change detection to update the view
        this.cdr.detectChanges();
      },
      error: (error) => {
        console.error('Delete failed:', error);
        alert('Failed to delete row. See console for details.');
      },
    });
  }

  /**
   * Handles changes to cell values and updates the backend accordingly.
   * @param event The cell value change event.
   */
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

    // Send the update request to the backend
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
