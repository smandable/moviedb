import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { AgGridAngular } from 'ag-grid-angular';
import { PageLayoutComponent } from '@layouts/page-layout/page-layout.component';
import {
  FileService,
  NormalizedFile,
  ProcessFilesResponse,
} from '@services/file.service';
import { FormsModule } from '@angular/forms';
import { fileSizeFormatter, durationFormatter } from '@helpers/formatters';
import { myTheme } from '@helpers/grid-theme';
import {
  AllCommunityModule,
  ModuleRegistry,
  ClientSideRowModelModule,
  GridOptions,
  GridApi,
  ColDef,
  ICellRendererParams,
} from 'ag-grid-community';
import { NgbModal, NgbModalRef, NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { FileNormalizationModalComponent } from '@modals/file-normalization-modal/file-normalization-modal.component';
import { CommonModule } from '@angular/common';

ModuleRegistry.registerModules([AllCommunityModule, ClientSideRowModelModule]);

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
})
export class UpdateDbComponent implements OnInit {
  public directory: string = '/Volumes/Recorded 4/fixed/'; // Default value
  public totalItems: number = 0;
  public newItemsCount: number = 0;
  public duplicateItemsCount: number = 0;
  public newItemsSize: number = 0;
  public duplicateItemsSize: number = 0;

  public rowData: any[] = []; // Updated to accommodate processing results
  public gridOptions: GridOptions = {
    theme: myTheme,
    rowSelection: {
      mode: 'singleRow',
      checkboxes: false, // ⬅️ no selection column
      enableClickSelection: true, // ⬅️ still let user select by clicking the row
    },
    context: { componentParent: this },
    getRowId: (params) => `${params.data.title}`,
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
        width: 300,
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

          // If this row was copied before, keep it blue
          if (params.data?.titleCopied) {
            icon.classList.add('copied');
          }

          icon.addEventListener('click', (event) => {
            event.stopPropagation();

            const rawTitle: string = params.data?.title || '';
            // Strip trailing " # 07" etc.
            const match = rawTitle.match(/^(.*?)(?:\s+#\s+\d+)?$/);
            const baseTitle = match ? match[1] : rawTitle;

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
      { field: 'titleDimensions', headerName: 'Dimensions', width: 150 },
      {
        field: 'titleDuration',
        headerName: 'Duration',
        width: 150,
        valueFormatter: durationFormatter,
      },
      {
        field: 'titleSize',
        headerName: 'File Size',
        width: 150,
        valueFormatter: fileSizeFormatter,
      },
      {
        field: 'duplicate',
        headerName: 'Duplicate',
        width: 150,
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
        width: 180,
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
        width: 150,
      },
      {
        headerName: '',
        colId: 'externalSearch',
        width: 55,
        sortable: false,
        filter: false,
        resizable: false,
        cellRenderer: (params: ICellRendererParams) => {
          if (!(params.data?.needsExternalSearch || params.data?.duplicate))
            return '';

          const icon = document.createElement('i');
          icon.classList.add(
            'fa-solid',
            'fa-magnifying-glass',
            'external-search-icon',
          );

          // Initial state
          const clicked = !!params.data?.externalSearchClicked;
          icon.classList.add(clicked ? 'is-clicked' : 'is-pending');

          icon.title = clicked
            ? 'Searched (click to search again)'
            : 'Copy base title + search external drives';

          icon.addEventListener('click', (event) => {
            event.stopPropagation();

            const rawTitle: string = params.data?.title || '';
            const match = rawTitle.match(/^(.*?)(?:\s+#\s+\d+)?$/);
            const baseTitle = (match ? match[1] : rawTitle).trim();

            navigator.clipboard?.writeText(baseTitle).catch(() => {});
            params.context.componentParent.searchExternalDrives(baseTitle);

            // Mark as clicked + update styling
            params.data.externalSearchClicked = true;
            icon.classList.remove('is-pending');
            icon.classList.add('is-clicked');
          });

          return icon;
        },
      },

      // Add more columns as needed
    ],

    onGridReady: (params) => {
      this.gridApi = params.api;
      params.api.sizeColumnsToFit();
    },
  };

  private gridApi: GridApi<any> | undefined;

  isLoading: boolean = false;
  public showDatabaseOperationsButton: boolean = false;

  constructor(
    private fileService: FileService,
    private cdr: ChangeDetectorRef,
    private modalService: NgbModal,
  ) {}

  ngOnInit(): void {}

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
    // if (confirm(`Are you sure you want to update the database for "${data.title}"?`)) {
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
    // }
  }
  /**
   * Opens a modal to display original and new filenames.
   * @param files The list of files to display.
   */
  openFilesModal(files: NormalizedFile[]): void {
    const modalRef: NgbModalRef = this.modalService.open(
      FileNormalizationModalComponent,
      { size: 'xl', scrollable: true },
    );
    modalRef.componentInstance.files = files;
    modalRef.componentInstance.directory = this.directory;

    modalRef.componentInstance.renameFilesEvent.subscribe(
      (filesToRename: NormalizedFile[]) => {
        this.renameFiles(filesToRename); // Call renameFiles with the filtered files
      },
    );

    modalRef.result.then(
      (result) => {
        if (result === 'rename') {
          console.log('Rename operation completed.');
        }
        // Set the button visibility after modal closes successfully
        this.showDatabaseOperationsButton = true;
      },
      (reason) => {
        // Handle dismissal if needed
        this.showDatabaseOperationsButton = true;
      },
    );
  }

  /**
   * Handles the Rename Files action.
   * Calls the backend to perform renaming.
   */
  renameFiles(files: NormalizedFile[]): void {
    // Filter out files that should be renamed (not excluded and needing normalization)
    const filesToRename = files.filter(
      (file) => !file.exclude && file.needsNormalization,
    );

    if (filesToRename.length === 0) {
      console.log('No files selected for renaming.');
      return;
    }

    this.isLoading = true;

    this.fileService.renameTheFilesToNormalize(filesToRename).subscribe({
      next: (response) => {
        this.isLoading = false;
        console.log('Rename Results:', response.results);
        console.log('Files have been renamed successfully.');
        // Close the modal
        this.modalService.dismissAll();
        // Show the new button
        this.showDatabaseOperationsButton = true;
      },
      error: (error) => {
        this.isLoading = false;
        console.error('Error renaming files:', error);
        alert('Failed to rename files. See console for details.');
      },
    });
  }

  public processingComplete: boolean = false;

  /**
   * Handles the "Perform Database Operations" button click.
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
        // console.log('Process Files For DB Response:', response);

        if (response.error) {
          alert(`Error: ${response.error}`);
          return;
        }
        // Reset counts
        this.totalItems = 0;
        this.newItemsCount = 0;
        this.duplicateItemsCount = 0;
        this.newItemsSize = 0;
        this.duplicateItemsSize = 0;

        // Calculate counts and sizes
        response.titles.forEach((title) => {
          this.totalItems++;
          if (title.duplicate) {
            this.duplicateItemsCount++;
            this.duplicateItemsSize += title.titleSize || 0;
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

        this.cdr.detectChanges();
        // console.log('Row Data:', this.rowData); // Verify the data structure and values

        if (this.gridApi) {
          // Remove all existing rows
          const allRowData: any[] = [];
          this.gridApi.forEachNode((node) => allRowData.push(node.data));
          this.gridApi.applyTransaction({ remove: allRowData });

          // Add new data
          this.gridApi.applyTransaction({ add: this.rowData });
          this.gridApi.forEachNode((node) =>
            console.log('Grid Node Data:', node.data),
          );
        }

        this.processingComplete = true; // Show totals
        console.log('Process Complete');
        console.log(response.message);
      },
      error: (error) => {
        this.isLoading = false;
        console.error('Error performing database operations:', error);
        console.log(
          'Failed to perform database operations. See console for details.',
        );
      },
    });
  }

  private getBaseTitleStrict(rawTitle: string): string {
    const title = (rawTitle || '').trim();

    // Strict: only treat numbered titles as " ... # 01" (spaces required)
    const match = title.match(/^(.*?)(?:\s+#\s+\d+)?$/);
    return (match ? match[1] : title).trim();
  }

  /**
   * Click action for the magnifying-glass icon.
   * - Copies the base title (before " # NN")
   * - Opens Finder Smart Folder search across external volumes (via PHP endpoint)
   */
  public searchExternalDrives(baseTitle: string): void {
    // call your PHP endpoint that opens the .savedSearch
    this.fileService.openExternalDriveSearch(baseTitle).subscribe({
      error: (err) => console.error('openExternalDriveSearch failed', err),
    });
  }

  formatFileSize(sizeInBytes: number): string {
    if (sizeInBytes >= 1e9) {
      return (sizeInBytes / 1e9).toFixed(2) + ' GB';
    } else if (sizeInBytes >= 1e6) {
      return (sizeInBytes / 1e6).toFixed(2) + ' MB';
    } else if (sizeInBytes >= 1e3) {
      return (sizeInBytes / 1e3).toFixed(2) + ' KB';
    } else {
      return sizeInBytes + ' bytes';
    }
  }
}
