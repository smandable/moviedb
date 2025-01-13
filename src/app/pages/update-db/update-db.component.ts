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
    FileNormalizationModalComponent,
  ],
})
export class UpdateDbComponent implements OnInit {
  public directory: string = '/Volumes/Recorded 3/fixed/'; // Default value
  public totalItems: number = 0;
  public newItemsCount: number = 0;
  public duplicateItemsCount: number = 0;
  public newItemsSize: number = 0;
  public duplicateItemsSize: number = 0;

  public rowData: any[] = []; // Updated to accommodate processing results
  public gridOptions: GridOptions = {
    theme: myTheme,
    rowSelection: 'single',
    context: { componentParent: this },
    getRowId: (params) => `${params.data.title}`,
    rowHeight: 35,

    defaultColDef: {
      width: 155,
      sortable: true,
      filter: false, // Enable filtering if desired
      resizable: true,
    },

    columnDefs: [
      { field: 'title', headerName: 'Title', width: 300 },
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
        field: 'isLarger',
        headerName: 'Larger',
        cellRenderer: (params: ICellRendererParams) => {
          const isLargerFlag = params.data.isLarger === 'isLarger';
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
            button.classList.add('btn', 'btn-primary', 'btn-sm');
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
    private modalService: NgbModal
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
            // alert(`Database updated successfully for "${data.title}".`);
            data.isLarger = null; // Clear the "Larger" flag
            data.needsUpdateMissingMeta = false;  // Clears the missing metadata flag
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
      { size: 'xl' }
    );
    modalRef.componentInstance.files = files;
    modalRef.componentInstance.directory = this.directory;

    modalRef.componentInstance.renameFilesEvent.subscribe(
      (filesToRename: NormalizedFile[]) => {
        this.renameFiles(filesToRename); // Call renameFiles with the filtered files
      }
    );

    modalRef.result.then(
      (result) => {
        if (result === 'rename') {
          console.log('Rename operation completed.');
        }
        // Set the button visibility after modal closes successfully
        this.showDatabaseOperationsButton = true;
        // Handle other modal results if needed
      },
      (reason) => {
        // Handle dismissal if needed
        this.showDatabaseOperationsButton = true;
      }
    );
  }

  /**
   * Handles the Rename Files action.
   * Calls the backend to perform renaming.
   */
  renameFiles(files: NormalizedFile[]): void {
    // Filter out files that should be renamed (not excluded and needing normalization)
    const filesToRename = files.filter(
      (file) => !file.exclude && file.needsNormalization
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
            console.log('Grid Node Data:', node.data)
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
          'Failed to perform database operations. See console for details.'
        );
      },
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

/**
 * Component for the modal content.
 * Displays original and new filenames, and provides action buttons.
 */
@Component({
  selector: 'files-modal-content',
  template: `
    <div class="modal-header">
      <h5 class="modal-title">Files to Normalize</h5>
      <button
        type="button"
        class="btn-close"
        aria-label="Close"
        (click)="cancel()"
      ></button>
    </div>
    <div class="modal-body">
      <table class="table table-bordered table-striped">
        <thead>
          <tr>
            <th>Original Filename</th>
            <th>New Filename</th>
          </tr>
        </thead>
        <tbody>
          <tr *ngFor="let file of files">
            <td>{{ file.originalFileName }}</td>
            <td>{{ file.newFileName }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-success" (click)="rename()">
        Rename Files
      </button>
      <button type="button" class="btn btn-secondary" (click)="cancel()">
        Cancel
      </button>
    </div>
  `,
})
export class FilesModalContent {
  public files: NormalizedFile[] = [];
  public directory: string = '';

  constructor(private modalRef: NgbModalRef) {}

  /**
   * Handles the Rename button click.
   */
  rename(): void {
    this.modalRef.close('rename');
  }

  /**
   * Handles the Cancel button click.
   */
  cancel(): void {
    this.modalRef.dismiss('cancel');
  }
}
