import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { NgbActiveModal } from '@ng-bootstrap/ng-bootstrap';
import { of, throwError } from 'rxjs';

import { DbTitleUpdatesModalComponent } from './db-title-updates-modal.component';
import {
  TitleRow,
  TitleUpdateItem,
  TitleUpdateService,
} from '@services/title-update.service';

describe('DbTitleUpdatesModalComponent', () => {
  let fixture: ComponentFixture<DbTitleUpdatesModalComponent>;
  let component: DbTitleUpdatesModalComponent;
  let service: TitleUpdateService;

  const row = (id: number, title: string, date = '2019-01-01'): TitleRow => ({
    id, title, dimensions: '1920 x 1080', filesize: '2000000000', duration: 5400, date_created: date,
  });
  const base = { table: 'movies_het', files: [], leftovers: [] as string[] };
  const update = (id: number, oldTitle: string, newTitle: string): TitleUpdateItem => ({
    ...base, oldTitle, newTitle, rows: [row(id, oldTitle)], status: 'update', row: row(id, oldTitle),
  });
  const mergeItem: TitleUpdateItem = {
    ...base, oldTitle: 'Sample One', newTitle: 'Sample 1',
    rows: [row(3, 'Sample One'), row(8, 'Sample 1', '2021-01-01')],
    status: 'merge', keep: row(3, 'Sample One'), drop: row(8, 'Sample 1', '2021-01-01'),
    merged: { source: 'files', filesize: '3000000000', dimensions: '1920 x 1080', duration: 5400, fileCount: 4, elsewhere: [] },
  };
  const skippedItem: TitleUpdateItem = {
    ...base, oldTitle: 'Gone Title', newTitle: 'Gone title', rows: [], status: 'missing',
    reason: 'No database row has the old or the new title.',
  };

  const open = (items: TitleUpdateItem[]) => {
    spyOn(service, 'preview').and.returnValue(of({ items }));
    component.renames = [{ path: '/v', originalFileName: 'a.mp4', newFileName: 'b.mp4' }];
    fixture.detectChanges();
  };
  const el = (sel: string) => fixture.nativeElement.querySelector(sel) as HTMLElement | null;
  const all = (sel: string) => Array.from(fixture.nativeElement.querySelectorAll(sel)) as HTMLElement[];

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DbTitleUpdatesModalComponent, HttpClientTestingModule],
      providers: [
        { provide: NgbActiveModal, useValue: { close: () => {}, dismiss: () => {} } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(DbTitleUpdatesModalComponent);
    component = fixture.componentInstance;
    service = TestBed.inject(TitleUpdateService);
  });

  it('previews the renames and sorts items into updates, merges and skipped', () => {
    open([update(5, 'Up And Away', 'Up and Away'), mergeItem, skippedItem]);

    expect(service.preview).toHaveBeenCalledOnceWith(component.renames);
    expect(all('.update-row').length).toBe(1);
    expect(all('.merge-item').length).toBe(1);
    expect(all('.skipped-item')[0].textContent).toContain('Not in the database');
    expect(el('.apply-button')!.textContent).toContain('Update 1 Title');
    expect(el('.merge-item')!.textContent).toContain('measured from 4 files');
  });

  it('applies only the ticked updates, guarded by the row title, and shows results', () => {
    const a = update(5, 'Up And Away', 'Up and Away');
    const b = update(6, 'Down And Out', 'Down and Out');
    open([a, b]);
    const apply = spyOn(service, 'apply').and.returnValue(
      of({ results: [{ id: 5, ok: true }] }),
    );

    component.toggle(b, false);
    component.applyUpdates();
    fixture.detectChanges();

    expect(apply).toHaveBeenCalledOnceWith([
      { table: 'movies_het', id: 5, from: 'Up And Away', to: 'Up and Away' },
    ]);
    expect(all('.update-row')[0].textContent).toContain('Updated');
    expect(component.pendingUpdates).toEqual([]);
    expect((el('.apply-button') as HTMLButtonElement).disabled).toBeTrue();
  });

  it('shows a per-row refusal from the server', () => {
    open([update(5, 'Up And Away', 'Up and Away')]);
    spyOn(service, 'apply').and.returnValue(
      of({ results: [{ id: 5, ok: false, error: 'Another row already has the new title.' }] }),
    );

    component.applyUpdates();
    fixture.detectChanges();

    expect(el('.update-row .text-danger')!.textContent).toContain('Another row already');
    expect(component.pendingUpdates.length).toBe(1); // still retryable
  });

  it('merges only after confirmation', () => {
    open([mergeItem]);
    const merge = spyOn(service, 'merge').and.returnValue(of({ ok: true, deleted: 8 }));
    const confirm = spyOn(window, 'confirm').and.returnValues(false, true);

    component.merge(component.merges[0]);
    expect(merge).not.toHaveBeenCalled();

    component.merge(component.merges[0]);
    fixture.detectChanges();
    expect(confirm.calls.mostRecent().args[0]).toContain('delete row 8');
    expect(merge).toHaveBeenCalledOnceWith(mergeItem);
    expect(el('.merge-item')!.textContent).toContain('row 8 deleted');
    expect((el('.merge-button') as HTMLButtonElement).disabled).toBeTrue();
  });

  it('reports a preview failure', () => {
    spyOn(service, 'preview').and.returnValue(throwError(() => new Error('boom')));
    fixture.detectChanges();
    expect(el('.load-error')!.textContent).toContain('boom');
  });

  it('gives the titles the full width: an outcome shows under its title once it exists', () => {
    const item = update(5, 'Up And Away', 'Up and Away');
    open([item]);
    expect(all('.update-row td').length).toBe(2); // checkbox + title, no outcome column
    expect(el('.update-row .outcome')).toBeNull();

    spyOn(service, 'apply').and.returnValue(of({ results: [{ id: 5, ok: true, logged: true }] }));
    component.applyUpdates(); // marks the OnPush view for check itself
    fixture.detectChanges();
    const outcome = el('.update-row td:nth-child(2) .outcome');
    expect(outcome?.textContent?.trim()).toBe(component.outcomes.get(item)!.text);
    expect(outcome?.classList).toContain('text-success');
  });

  it('maps results by position, so two items on one row both get an outcome', () => {
    const a = update(5, 'Up And Away', 'Up and Away');
    const b = update(5, 'Up And Away', 'Up & Away');
    open([a, b]);
    spyOn(service, 'apply').and.returnValue(
      of({ results: [{ id: 5, ok: true, logged: false }, { id: 5, ok: false, error: "The row's title changed" }] }),
    );

    component.applyUpdates();

    expect(component.outcomes.get(a)).toEqual({ ok: true, text: 'Updated (undo log not written!)' });
    expect(component.outcomes.get(b)!.ok).toBeFalse();
  });

  it('warns when the drive index is missing', () => {
    spyOn(service, 'preview').and.returnValue(of({ items: [], indexMissing: true }));
    fixture.detectChanges();
    expect(el('.index-missing')).not.toBeNull();
  });

  it('formats lengths and sizes', () => {
    expect(component.length(5400)).toBe('1h 30m');
    expect(component.length(3599)).toBe('1h 00m');
    expect(component.length(7199)).toBe('2h 00m');
    expect(component.length(89)).toBe('1m');
    expect(component.length(null)).toBe('—');
    expect(component.size(null)).toBe('—');
    expect(component.dims('1920 x 1080')).toBe('1920×1080');
  });
});
