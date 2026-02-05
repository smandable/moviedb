// Angular modules
import { Component, OnInit, OnDestroy, ChangeDetectorRef } from '@angular/core';
import { Router, NavigationEnd } from '@angular/router';

// Services
import { MovieService, Movie } from '@services/movie.service';

// Components
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
  IFilterComp,
} from 'ag-grid-community';

import { Subscription } from 'rxjs';

// Extend the standard GridApi with the client-side model
type ClientSideGridApi<TData> = GridApi<TData> & {
  setSortModel?(sortModel: { colId: string; sort: 'asc' | 'desc' }[]): void;
  getSortModel?(): any;
  isAnyFilterPresent(): boolean;
  // getFilterInstance now uses a callback and returns void
  getFilterInstance?(
    colKey: string,
    callback: (filter: IFilterComp | null) => void,
  ): void;
};

// Register AG Grid modules
ModuleRegistry.registerModules([AllCommunityModule, ClientSideRowModelModule]);

@Component({
  selector: 'app-home',
  templateUrl: './home.component.html',
  styleUrls: ['./home.component.scss'],
  standalone: true,
  imports: [PageLayoutComponent, AgGridAngular],
})
export class HomeComponent implements OnInit, OnDestroy {
  private routerSub?: Subscription;

  // Bind this property to the grid's rowData
  public rowData: Movie[] = [];
  public totalItems: number = 0; // Holds the total count
  public resultsCount: number = 0; // Search results

  // Track which row’s title is currently “copied” (for icon highlight)
  public mainCopiedId: number | null = null;

  private mainCopyResetTimeout: ReturnType<typeof setTimeout> | null = null;

  // Track whether the Title filter actually has text
  public isTitleFilterActive: boolean = false;

  private gridApi!: ClientSideGridApi<Movie>;

  // AG Grid configuration
  public gridOptions: GridOptions<Movie> = {
    theme: myTheme,
    rowSelection: {
      mode: 'singleRow',
      checkboxes: false, // ⬅️ no selection column
      enableClickSelection: true, // ⬅️ still let user select by clicking the row
    },
    suppressMultiSort: true,

    // Ensure each row has a unique ID
    getRowId: (params) => params.data.id.toString(),

    defaultColDef: {
      width: 155, // Set default column width
      sortable: true,
      filter: false,
      resizable: true, // Allow resizing of columns
      floatingFilter: false, // Disable floating filters by default
    },

    onFilterOpened: (params) => {
      if (params.column.getId() === 'title') {
        if (this.gridApi.getFilterInstance) {
          this.gridApi.getFilterInstance(
            'title',
            (filterInstance: IFilterComp | null) => {
              if (filterInstance) {
                let model = filterInstance.getModel() as any;

                if (!model) {
                  // No existing model, create one with condition1 and condition2
                  model = {
                    operator: 'AND',
                    condition1: { type: 'startsWith', filter: '' },
                    condition2: { type: 'contains', filter: '' }, // Empty filter
                  };
                } else {
                  // If condition2 is missing or incorrectly set, update it to 'contains'
                  if (
                    !model.condition2 ||
                    model.condition2.type === 'startsWith'
                  ) {
                    model.operator = 'AND';
                    model.condition2 = { type: 'contains', filter: '' }; // Empty filter
                  }
                }

                // Set the updated model
                filterInstance.setModel(model);

                // Apply the model if the method exists
                if ((filterInstance as any).applyModel) {
                  (filterInstance as any).applyModel();
                }

                // Notify the grid of the filter change
                this.gridApi.onFilterChanged();
              } else {
                console.warn("Filter instance for 'title' is null.");
              }
            },
          );
        } else {
          console.warn('getFilterInstance is not available on gridApi.');
        }
      }
    },

    // Event fired when the grid is ready
    onGridReady: (params: GridReadyEvent<Movie>) => {
      this.gridApi = params.api as ClientSideGridApi<Movie>;

      params.api.sizeColumnsToFit();

      this.loadMovies(); // Load data once the grid is ready

      // Apply initial sort by 'date_created' descending using applyColumnState
      (this.gridApi as any).applyColumnState({
        state: [{ colId: 'date_created', sort: 'desc', sortIndex: 0 }],
        defaultState: { sort: null },
      });
    },

    onFilterChanged: (params) => {
      const anyFilter = this.gridApi.isAnyFilterPresent();

      // Check the entire filter model
      const currentFilterModel: any = this.gridApi.getFilterModel?.() || {};

      // Detect if the Title filter actually has text
      const titleFilter = currentFilterModel['title'];
      let titleFilterActive = false;

      if (titleFilter) {
        // Combined model (operator / condition1 / condition2)
        if (titleFilter.operator) {
          titleFilterActive =
            !!titleFilter.condition1?.filter ||
            !!titleFilter.condition2?.filter;
        } else {
          // Simple text filter
          titleFilterActive = !!titleFilter.filter;
        }
      }

      this.isTitleFilterActive = titleFilterActive;

      // If the title filter is cleared, clear the “copied” highlight
      if (!titleFilterActive) {
        this.mainCopiedId = null;
      }

      // Refresh the Title column so icons show/hide / recolor
      this.gridApi.refreshCells({
        columns: ['title'],
        force: true,
      });

      if (anyFilter) {
        // Apply sort by 'title' ascending
        (this.gridApi as any).applyColumnState({
          state: [{ colId: 'title', sort: 'asc', sortIndex: 0 }],
          defaultState: { sort: null },
        });
      } else {
        // Apply sort by 'date_created' descending
        (this.gridApi as any).applyColumnState({
          state: [{ colId: 'date_created', sort: 'desc', sortIndex: 0 }],
          defaultState: { sort: null },
        });
      }
    },
    onModelUpdated: () => {
      // Always reflects current visible rows (after filter + after deletes)
      this.resultsCount = this.gridApi.getDisplayedRowCount();
      this.cdr.detectChanges();
    },
    // Handle cell edits
    onCellValueChanged: (event) => this.onCellValueChanged(event),
  };

  ciCollator = new Intl.Collator(undefined, {
    sensitivity: 'base', // case-insensitive (and accent-insensitive)
    numeric: true, // natural sorting for embedded numbers
  });

  // Define the columns for the grid
  columnDefs: ColDef<Movie>[] = [
    {
      field: 'title',
      colId: 'title', // Explicitly set colId to match field
      headerName: 'Title',
      width: 600,
      editable: true,
      filter: 'agTextColumnFilter', // Use a text filter
      floatingFilter: true,
      floatingFilterComponent: CustomFloatingFilterComponent,
      filterParams: {
        filterOptions: ['startsWith', 'contains', 'endsWith'],
        alwaysShowBothConditions: true,
        defaultJoinOperator: 'AND',
        debounceMs: 300,
        caseSensitive: false, // filter ignores case
        defaultOption: 'startsWith', // Default filter option in the menu
      },
      comparator: (a: string | null, b: string | null) => {
        const aStr = (a ?? '').toString();
        const bStr = (b ?? '').toString();
        // Case-insensitive + natural numeric sort
        return this.ciCollator.compare(aStr, bStr);
      },

      // ---- NEW: text + copy icon renderer ----
      cellRenderer: (params: ICellRendererParams<Movie>) => {
        const container = document.createElement('div');
        container.classList.add('home-title-cell-container');

        const textSpan = document.createElement('span');
        textSpan.classList.add('home-title-text');
        textSpan.innerText = params.value ?? '';

        const icon = document.createElement('i');
        icon.classList.add('fa-regular', 'fa-copy', 'home-title-copy-icon');

        // Reference back to component
        const comp = this as HomeComponent;

        // Only show the icon when the Title filter has some text
        // if (!comp.isTitleFilterActive) {
        //   icon.style.display = 'none';
        // }

        // Highlight the icon if this row is the “last copied”
        if (comp.mainCopiedId === params.data?.id) {
          icon.classList.add('active');
        }

        icon.addEventListener('click', (event) => {
          event.stopPropagation();

          const rawTitle: string = params.data?.title || '';

          // Strip trailing " # 07" etc when copying
          const copyValue = rawTitle.replace(/\s+#\s+\d+$/, '');

          navigator.clipboard
            .writeText(copyValue)
            .catch((err) => console.error('Clipboard error:', err));

          // Mark this row as the one with the “blue” icon
          comp.mainCopiedId = params.data?.id ?? null;

          // Re-render the Title column so icons recolor correctly
          comp.gridApi.refreshCells({
            columns: ['title'],
            force: true,
          });

          // 🔵 Auto-reset highlight after 3 seconds of no clicks
          if (comp.mainCopyResetTimeout) {
            clearTimeout(comp.mainCopyResetTimeout);
          }

          comp.mainCopyResetTimeout = setTimeout(() => {
            comp.mainCopiedId = null;
            comp.gridApi.refreshCells({
              columns: ['title'],
              force: true,
            });
          }, 3000);
        });

        container.appendChild(textSpan);
        container.appendChild(icon);

        return container;
      },
    },

    {
      field: 'dimensions',
      colId: 'dimensions',
      headerName: 'Dimensions',
      width: 150,
      editable: true,
    },
    {
      field: 'duration',
      colId: 'duration',
      headerName: 'Duration',
      width: 150,
      valueFormatter: durationFormatter,
      editable: true,
    },
    {
      field: 'filesize',
      colId: 'filesize',
      headerName: 'File Size',
      width: 150,
      valueFormatter: fileSizeFormatter,
      editable: true,
    },
    {
      field: 'date_created',
      colId: 'date_created',
      headerName: 'Date Created',
      width: 175,
    },
    {
      headerName: '', // No heading
      colId: 'delete', // Optional: set a colId for clarity
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
    private movieService: MovieService,
    private router: Router,
    private cdr: ChangeDetectorRef, // Inject ChangeDetectorRef for manual change detection
  ) {}

  ngOnInit(): void {
    this.routerSub = this.router.events.subscribe((event) => {
      if (
        event instanceof NavigationEnd &&
        event.urlAfterRedirects.startsWith('/home')
      ) {
        this.loadMovies();
      }
    });
  }

  ngOnDestroy(): void {
    if (this.mainCopyResetTimeout) {
      clearTimeout(this.mainCopyResetTimeout);
      this.mainCopyResetTimeout = null;
    }
    this.routerSub?.unsubscribe();
  }

  /**
   * Loads movies from the backend and updates the grid and total count.
   */
  loadMovies(): void {
    this.movieService.getAllMovies().subscribe({
      next: (movies: Movie[]) => {
        this.rowData = movies.map((movie) => ({
          ...movie,
          filesize:
            typeof movie.filesize === 'string'
              ? parseInt(movie.filesize, 10)
              : movie.filesize,
          duration:
            typeof movie.duration === 'string'
              ? Math.round(parseFloat(movie.duration))
              : movie.duration,
        }));
        this.totalItems = movies.length;
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
        // Remove the movie from the rowData array
        this.rowData = this.rowData.filter((m) => m.id !== movie.id);
        this.totalItems = this.rowData.length;
        this.resultsCount =
          this.gridApi?.getDisplayedRowCount?.() ?? this.totalItems;

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
        if (!response.success) {
          console.error('Update failed:', response.error);
        }
      },
      error: (error) => {
        console.error('Update request failed:', error);
      },
    });
  }
}
