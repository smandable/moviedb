import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TranslateModule } from '@ngx-translate/core';
import { FormConfirmComponent } from './form-confirm.component';

describe('FormConfirmComponent', () => {
  let component: FormConfirmComponent;
  let fixture: ComponentFixture<FormConfirmComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [FormConfirmComponent, TranslateModule.forRoot()],
    }).compileComponents();

    fixture = TestBed.createComponent(FormConfirmComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should emit submitData with true when onClickSubmit is called', async () => {
    spyOn(component.submitData, 'emit');
    await component.onClickSubmit();
    expect(component.submitData.emit).toHaveBeenCalledWith(true);
  });

  it('should emit submitClose when onClickClose is called', () => {
    spyOn(component.submitClose, 'emit');
    component.onClickClose();
    expect(component.submitClose.emit).toHaveBeenCalled();
  });
});
