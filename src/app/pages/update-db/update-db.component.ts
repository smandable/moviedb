import { Component, OnInit, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
import { AgGridAngular } from 'ag-grid-angular';
import { PageLayoutComponent } from '@layouts/page-layout/page-layout.component';
import {
  FileService,
  NormalizedFile,
  ProcessFilesResponse,
} from '@services/file.service';
import { SettingsService } from '@services/settings.service';
import { FormsModule } from '@angular/forms';
import { fileSizeFormatter, durationFormatter, formatBytes } from '@helpers/formatters';
import { getBaseTitle } from '@helpers/title';
import { environment } from 'src/environments/environment';
import { myTheme } from '@helpers/grid-theme';
import {
  AllCommunityModule,
  ModuleRegistry,
  GridOptions,
  GridApi,
  ColDef,
  ICellRendererParams,
} from 'ag-grid-community';
import { NgbModal, NgbModalRef, NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { FileNormalizationModalComponent } from '@modals/file-normalization-modal/file-normalization-modal.component';
import { DriveIndexModalComponent } from '@modals/drive-index-modal/drive-index-modal.component';
import { CommonModule } from '@angular/common';

ModuleRegistry.registerModules([AllCommunityModule]);

@Component({
  selector: 'app-update-db',
  templateUrl: './update-db.component.html',
  styleUrls: ['./update-db.component.scss'],
  standalone: true,
  imports: [
    CommonModule, // Ensure CommonModule is imported
    PageLayoutComponent,
    AgGridAngular,
    FormsModule,
    NgbModule,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UpdateDbComponent implements OnInit {
  public directory: string = environment.defaultDirectory;
  public defaultDirectory: string = environment.defaultDirectory;
  public totalItems: number = 0;
  public newItemsCount: number = 0;
  public duplicateItemsCount: number = 0;
  public totalItemsSize: number = 0;
  public newItemsSize: number = 0;
  public duplicateItemsSize: number = 0;
  public replacementGainSize: number = 0;

  public rowData: any[] = []; // Updated to accommodate processing results
  public gridOptions: GridOptions = {
    theme: myTheme,
    rowSelection: {
      mode: 'singleRow',
      checkboxes: false, // ⬅️ no selection column
      enableClickSelection: true, // ⬅️ still let user select by clicking the row
    },
    context: { componentParent: this },
    getRowId: (p) => `${p.data.id ?? ''}::${p.data.title}::${p.data.titlePath ?? ''}`,
    rowHeight: 36,

    defaultColDef: {
      width: 155,
      sortable: true,
      filter: false, // Enable filtering if desired
      resizable: true,
    },

    columnDefs: [
      {
        field: 'title',
        headerName: 'Title',
        // Title soaks up all spare grid width: it is the only column
        // sizeColumnsToFit can grow, since every data column is capped
        // by min/maxWidth below (flex:1 sat at minWidth under ag-grid 33.0.3)
        minWidth: 300,
        editable: true,
        cellRenderer: (params: ICellRendererParams) => {
          const container = document.createElement('div');
          container.classList.add('title-cell-container');

          // Text span
          const textSpan = document.createElement('span');
          textSpan.classList.add('title-cell-text');
          textSpan.innerText = params.value ?? '';
          container.appendChild(textSpan);

          // Copy icon
          const icon = document.createElement('i');
          icon.classList.add('fa-regular', 'fa-copy', 'copy-title-icon');
          icon.setAttribute('role', 'button');
          icon.setAttribute('aria-label', 'Copy title');

          // If this row was copied before, keep it blue
          if (params.data?.titleCopied) {
            icon.classList.add('copied');
          }

          icon.addEventListener('click', (event) => {
            event.stopPropagation();

            const rawTitle: string = params.data?.title || '';
            // Strip " # NN", " - Scene_X", " - Cast" suffixes to get base title
            const baseTitle = getBaseTitle(rawTitle);

            if (!navigator.clipboard) {
              console.warn('Clipboard API not available');
              return;
            }

            navigator.clipboard
              .writeText(baseTitle)
              .then(() => {
                // Mark this row as copied so it stays blue
                params.data.titleCopied = true;
                icon.classList.add('copied');
                // IMPORTANT: no setTimeout, no clearing others
              })
              .catch((err) => {
                console.error('Failed to copy title:', err);
              });
          });

          container.appendChild(icon);

          return container;
        },
      },
      {
        field: 'titleDimensions',
        headerName: 'Dimensions',
        width: 120,
        minWidth: 120,
        maxWidth: 120,
      },
      {
        field: 'titleDuration',
        headerName: 'Duration',
        width: 100,
        minWidth: 100,
        maxWidth: 100,
        valueFormatter: durationFormatter,
      },
      {
        field: 'titleSize',
        headerName: 'File Size',
        width: 110,
        minWidth: 110,
        maxWidth: 110,
        valueFormatter: fileSizeFormatter,
      },
      {
        field: 'duplicate',
        headerName: 'Duplicate',
        width: 110,
        minWidth: 110,
        maxWidth: 110,
        sortable: true,
        valueGetter: (params) => (params.data.duplicate ? 'Yes' : 'No'), // Return 'Yes' for duplicate, 'No' otherwise
        cellRenderer: (params: { value: string }) => {
          const container = document.createElement('div');
          container.style.display = 'flex';
          container.style.alignItems = 'center';
          container.style.gap = '5px';

          const text = document.createElement('span');
          text.innerText = params.value; // Add "Yes" or "No"
          container.appendChild(text);

          const icon = document.createElement('span');
          icon.innerHTML =
            params.value === 'Yes'
              ? '<i class="fas fa-copy"></i>' // Icon for duplicate
              : '<i class="fas fa-file"></i>'; // Icon for non-duplicate
          container.appendChild(icon);

          return container;
        },
      },
      {
        headerName: 'Date Created',
        field: 'dateCreatedInDB',
        // Widest header text plus a sort arrow need ~160
        width: 160,
        minWidth: 160,
        maxWidth: 160,
        valueGetter: (params: any) => {
          const { duplicate, dateCreatedInDB } = params.data || {};
          if (!duplicate || !dateCreatedInDB) {
            return '';
          }
          // Guard against weird/empty dates from the DB
          if (
            dateCreatedInDB === '0000-00-00 00:00:00' ||
            dateCreatedInDB === '0000-00-00'
          ) {
            return '';
          }

          // dateCreatedInDB is probably "YYYY-MM-DD HH:MM:SS"
          const d = new Date(dateCreatedInDB);
          if (isNaN(d.getTime())) {
            // If parsing fails, just return the raw string
            return dateCreatedInDB;
          }

          // You can tweak this to show time if you want
          return d.toLocaleDateString();
        },
      },
      {
        field: 'isLarger',
        headerName: 'Larger',
        cellRenderer: (params: ICellRendererParams) => {
          const isLargerFlag =
            params.data.isLarger === 'isLarger' ||
            params.data.isLarger === 'isLargerZeroDBSize';
          const needsUpdateFileSizeFlag =
            params.data.needsUpdateFilesize === true;
          const needsUpdateMissingMetaFlag =
            params.data.needsUpdateMissingMeta === true;

          // If isLarger or needsUpdateFilesize or needsUpdateMissingMeta is true, show the button
          const needsUpdate =
            isLargerFlag ||
            needsUpdateFileSizeFlag ||
            needsUpdateMissingMetaFlag;

          if (needsUpdate) {
            const button = document.createElement('button');
            button.innerText = 'Update DB';
            button.classList.add('btn', 'btn-primary', 'btn-sm', 'larger-btn');
            button.addEventListener('click', () => {
              params.context.componentParent.updateDB(params.data);
            });
            return button;
          }
          return '';
        },
        sortable: true,
        filter: false,
        width: 120,
        minWidth: 120,
        maxWidth: 120,
      },
      {
        headerName: '',
        colId: 'externalSearch',
        // Two icons (Finder search + drive index) need more room than one
        width: 85,
        minWidth: 85,
        maxWidth: 85,
        sortable: false,
        filter: false,
        resizable: false,
        cellRenderer: (params: ICellRendererParams) => {
          if (!(params.data?.needsExternalSearch || params.data?.duplicate))
            return '';

          const container = document.createElement('div');
          container.style.display = 'flex';
          container.style.alignItems = 'center';
          container.style.gap = '10px';

          const icon = document.createElement('i');
          icon.classList.add(
            'fa-solid',
            'fa-magnifying-glass',
            'external-search-icon',
          );
          icon.setAttribute('role', 'button');
          icon.setAttribute('aria-label', 'Search external drives');

          // Initial state
          const clicked = !!params.data?.externalSearchClicked;
          icon.classList.add(clicked ? 'is-clicked' : 'is-pending');

          icon.title = clicked
            ? 'Searched (click to search again)'
            : 'Copy base title + search external drives';

          icon.addEventListener('click', (event) => {
            event.stopPropagation();

            const rawTitle: string = params.data?.title || '';
            // Strip " # NN", " - Scene_X", " - Cast" suffixes to get base title
            const baseTitle = getBaseTitle(rawTitle);

            navigator.clipboard?.writeText(baseTitle).catch(() => {});
            params.context.componentParent.searchExternalDrives(baseTitle);

            // Mark as clicked + update styling
            params.data.externalSearchClicked = true;
            icon.classList.remove('is-pending');
            icon.classList.add('is-clicked');
          });

          container.appendChild(icon);

          // Second icon: search the drive index (opens the in-app modal)
          const driveIcon = document.createElement('i');
          driveIcon.classList.add(
            'fa-solid',
            'fa-hard-drive',
            'external-search-icon',
            'is-pending',
          );
          driveIcon.setAttribute('role', 'button');
          driveIcon.setAttribute('aria-label', 'Search the drive index');
          driveIcon.title = 'Search the drive index';

          driveIcon.addEventListener('click', (event) => {
            event.stopPropagation();

            const rawTitle: string = params.data?.title || '';
            const baseTitle = getBaseTitle(rawTitle);
            params.context.componentParent.openDriveIndexModal(baseTitle);
          });

          container.appendChild(driveIcon);

          return container;
        },
      },

      // Add more columns as needed
    ],

    onGridReady: (params) => {
      this.gridApi = params.api;
      params.api.sizeColumnsToFit();
    },
    // Re-fit when the window/grid resizes so Title keeps absorbing the
    // spare width (every other column is pinned by min/maxWidth)
    onGridSizeChanged: (params) => {
      params.api.sizeColumnsToFit();
    },
  };

  private gridApi: GridApi<any> | undefined;

  isLoading: boolean = false;
  public showDatabaseOperationsButton: boolean = false;

  constructor(
    private fileService: FileService,
    private settingsService: SettingsService,
    private cdr: ChangeDetectorRef,
    private modalService: NgbModal,
  ) {}

  ngOnInit(): void {
    // Server-stored setting overrides the compiled-in default (Settings page)
    this.settingsService.getSettings().subscribe({
      next: ({ settings }) => {
        if (settings.defaultDirectory) {
          const untouched = this.directory === this.defaultDirectory;
          this.defaultDirectory = settings.defaultDirectory;
          if (untouched) {
            this.directory = settings.defaultDirectory;
          }
          this.cdr.markForCheck();
        }
      },
      error: () => {
        // Keep the environment default; the page still works
      },
    });
  }

  /**
   * Handles the Process button click.
   * Calls the backend to check and normalize filenames.
   */
  processDirectory(): void {
    if (!this.directory.trim()) {
      alert('Please enter a valid directory path.');
      return;
    }
    this.showDatabaseOperationsButton = false;

    this.isLoading = true;

    this.fileService.checkFileNamesToNormalize(this.directory).subscribe({
      next: (response) => {
        this.isLoading = false;
        const files = response.files;
        this.totalItems = files.length;
        this.cdr.detectChanges();

        // Open the modal to display files
        this.openFilesModal(files);
      },
      error: (error) => {
        console.error('Error processing directory:', error);
        alert('Failed to process the directory. See console for details.');
        this.isLoading = false;
      },
    });
  }
  /**
   * Handles the "Update DB" button click.
   * Updates the database for a file marked as larger.
   * @param data The row data associated with the file.
   */
  updateDB(data: any): void {
    this.fileService
      .updateRow(data.id, {
        dimensions: data.fileDimensions,
        filesize: data.titleSize,
        duration: data.titleDuration,
      })
      .subscribe({
        next: (response) => {
          if (response.success) {
            data.isLarger = null; // Clear the "Larger" flag
            data.needsUpdateMissingMeta = false; // Clears the missing metadata flag
            data.needsUpdateFilesize = false; // Clears needs update filesize flag
            this.gridApi?.refreshCells({ force: true }); // Refresh the grid
          } else {
            alert(`Failed to update database: ${response.message}`);
          }
        },
        error: (err) => {
          console.error('Update DB error:', err);
          alert('An error occurred while updating the database.');
        },
      });
  }
  /**
   * Opens a modal to display original and new filenames. The modal performs
   * the renames itself (then flips to its "Add Cast" tab); the page just
   * re-enables the Update Database button once the modal closes.
   * @param files The list of files to display.
   */
  openFilesModal(files: NormalizedFile[]): void {
    const modalRef: NgbModalRef = this.modalService.open(
      FileNormalizationModalComponent,
      {
        size: 'xl',
        scrollable: true,
        modalDialogClass: 'file-normalization-dialog',
      },
    );
    modalRef.componentInstance.files = files;
    modalRef.componentInstance.directory = this.directory;

    const onModalClosed = () => {
      this.showDatabaseOperationsButton = true;
      this.cdr.markForCheck();
    };
    modalRef.result.then(onModalClosed, onModalClosed);
  }

  public processingComplete: boolean = false;

  /**
   * Handles the "Update Database" button click.
   * Sends a request to process files for database operations.
   */
  performDatabaseOperations(): void {
    if (!this.directory.trim()) {
      alert('Please enter a valid directory path.');
      return;
    }

    this.isLoading = true;
    this.processingComplete = false; // Hide totals during processing

    this.fileService.processFilesForDB(this.directory).subscribe({
      next: (response: ProcessFilesResponse) => {
        this.isLoading = false;

        if (response.success === false) {
          alert(`Error: ${response.message}`);
          return;
        }
        // Reset counts
        this.totalItems = 0;
        this.totalItemsSize = 0;
        this.newItemsCount = 0;
        this.duplicateItemsCount = 0;
        this.newItemsSize = 0;
        this.duplicateItemsSize = 0;
        this.replacementGainSize = 0;

        // Calculate counts and sizes
        response.titles.forEach((title) => {
          this.totalItems++;
          this.totalItemsSize += title.titleSize || 0;
          if (title.duplicate) {
            this.duplicateItemsCount++;
            this.duplicateItemsSize += title.titleSize || 0;
            if (title.isLarger === 'isLarger' || title.isLarger === 'isLargerZeroDBSize') {
              const sizeInDB = Number(title.sizeInDB) || 0;
              this.replacementGainSize += (title.titleSize || 0) - sizeInDB;
            }
          } else {
            this.newItemsCount++;
            this.newItemsSize += title.titleSize || 0;
          }
        });

        // Update grid data
        this.rowData = response.titles.map((title) => ({
          ...title,
          titleSize:
            typeof title.titleSize === 'string'
              ? parseInt(title.titleSize, 10)
              : title.titleSize,
          titleDuration:
            typeof title.titleDuration === 'string'
              ? parseInt(title.titleDuration, 10)
              : title.titleDuration,
          titleDimensions: title.fileDimensions || '',
        }));

        // The grid is bound to [rowData], so reassigning it above already
        // refreshes the rows (diffed by getRowId) — no manual transaction needed.
        this.cdr.detectChanges();

        this.processingComplete = true; // Show totals
        this.cdr.markForCheck();
      },
      error: (error) => {
        this.isLoading = false;
        console.error('Error performing database operations:', error);
      },
    });
  }

  /**
   * Click action for the hard-drive icon: opens the drive-index search modal
   * prefilled with the row's base title.
   */
  public openDriveIndexModal(baseTitle: string): void {
    const modalRef: NgbModalRef = this.modalService.open(
      DriveIndexModalComponent,
      {
        size: 'xl',
        scrollable: true,
      },
    );
    modalRef.componentInstance.initialQuery = baseTitle;
  }

  /**
   * Click action for the magnifying-glass icon.
   * - Copies the base title (before " # NN")
   * - Opens Finder Smart Folder search across external volumes (via PHP endpoint)
   */
  public searchExternalDrives(baseTitle: string): void {
    // call your PHP endpoint that opens the .savedSearch
    this.fileService.openExternalDriveSearch(baseTitle).subscribe({
      error: (err) => {
        console.error('openExternalDriveSearch failed', err);
        alert('Failed to search external drives. See console for details.');
      },
    });
  }

  formatFileSize(sizeInBytes: number): string {
    return formatBytes(sizeInBytes);
  }
}
