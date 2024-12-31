import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { AgGridAngular } from 'ag-grid-angular';
import { PageLayoutComponent } from '@layouts/page-layout/page-layout.component';
import { FileService, NormalizedFile, RenameResult } from '@services/file.service';
import { FormsModule } from '@angular/forms';
import { fileSizeFormatter, durationFormatter } from '@helpers/formatters';
import { myTheme } from '@helpers/grid-theme';
import { AllCommunityModule, ModuleRegistry, ClientSideRowModelModule, GridOptions, GridApi, ColDef, ICellRendererParams } from 'ag-grid-community';
import { NgbModal, NgbModalRef, NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { FileNormalizationModalComponent } from '@modals/file-normalization-modal/file-normalization-modal.component'; 

ModuleRegistry.registerModules([AllCommunityModule, ClientSideRowModelModule]);

@Component({
  selector: 'app-update-db',
  templateUrl: './update-db.component.html',
  styleUrls: ['./update-db.component.scss'],
  standalone: true,
  imports: [PageLayoutComponent, AgGridAngular, FormsModule, NgbModule, FileNormalizationModalComponent],
})
export class UpdateDbComponent implements OnInit {
  public directory: string = '/Volumes/Recorded 3/fixed/'; // Default value
  public totalItems: number = 0;

  public rowData: NormalizedFile[] = []; // For displaying normalized files
  public gridOptions: GridOptions = {
    theme: myTheme,
    rowSelection: 'single',

    getRowId: (params) => `${params.data.path}/${params.data.originalFileName}`,

    defaultColDef: {
      width: 155,
      sortable: true,
      filter: false,
      resizable: true,
    },

    columnDefs: [
      { field: 'originalFileName', headerName: 'Title', width: 300, editable: true },
      { field: 'dimensions', headerName: 'Dimensions', width: 150, editable: true },
      { field: 'duration', headerName: 'Duration', width: 150, valueFormatter: durationFormatter, editable: true },
      { field: 'fileSize', headerName: 'File Size', width: 150, valueFormatter: fileSizeFormatter, editable: true },
      { field: 'dateCreated', headerName: 'Date Created', width: 150 },
      { field: 'newFileName', headerName: 'New', width: 200, editable: true },
      {
        headerName: '',
        width: 100,
        cellRenderer: (params: ICellRendererParams<NormalizedFile>) => {
          const button = document.createElement('button');
          button.innerHTML = 'Update';
          button.className = 'btn btn-primary btn-sm';
          button.style.cursor = 'pointer';
          button.addEventListener('click', () => {
            if (params.data) { // Check if params.data is defined
              this.openUpdateModal(params.data);
            } else {
              console.error('No data available for this row.');
            }
          });
          return button;
        },
        
      },
    ],

    onGridReady: (params) => {
      this.gridApi = params.api;
      params.api.sizeColumnsToFit();
    },
  };

  private gridApi: GridApi<NormalizedFile> | undefined;

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

  this.fileService.checkFileNamesToNormalize(this.directory).subscribe({
    next: (response) => {
      // this.rowData = response.files; // Removed to keep the grid blank
      const files = response.files;
      this.totalItems = files.length;
      this.cdr.detectChanges();

      // Open the modal to display files
      this.openFilesModal(files);
    },
    error: (error) => {
      console.error('Error processing directory:', error);
      alert('Failed to process the directory. See console for details.');
    },
  });
}



  /**
   * Opens a modal to display original and new filenames.
   * @param files The list of files to display.
   */
  openFilesModal(files: NormalizedFile[]): void {
    const modalRef: NgbModalRef = this.modalService.open(FileNormalizationModalComponent, { size: 'lg' });
    modalRef.componentInstance.files = files;
    modalRef.componentInstance.directory = this.directory;

    modalRef.result.then((result) => {
      if (result === 'rename') {
        this.renameFiles(files);
      }
      // Handle other modal results if needed
    }, (reason) => {
      // Handle dismissal if needed
    });
  }

  /**
   * Opens a modal to update a single file's name.
   * @param file The file to update.
   */
  openUpdateModal(file: NormalizedFile): void {
    // Implement if you want to update individual files
    // For now, it's handled by the Process and Rename buttons
    console.log('Update clicked for:', file);
  }

  /**
   * Handles the Rename Files action.
   * Calls the backend to perform renaming.
   */
  renameFiles(files: NormalizedFile[]): void {
    this.fileService.renameTheFilesToNormalize(files).subscribe({
      next: (response) => {
        console.log('Rename Results:', response.results);
        alert('Files have been renamed successfully.');
        // Optionally, refresh the data
        this.processDirectory(); // Reload the files
      },
      error: (error) => {
        console.error('Error renaming files:', error);
        alert('Failed to rename files. See console for details.');
      },
    });
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
      <button type="button" class="btn-close" aria-label="Close" (click)="cancel()"></button>
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
      <button type="button" class="btn btn-success" (click)="rename()">Rename Files</button>
      <button type="button" class="btn btn-secondary" (click)="cancel()">Cancel</button>
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
