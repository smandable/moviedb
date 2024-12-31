import { ComponentFixture, TestBed } from '@angular/core/testing';

import { UpdateDbComponent } from './update-db.component';

describe('UpdateDbComponent', () => {
  let component: UpdateDbComponent;
  let fixture: ComponentFixture<UpdateDbComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [UpdateDbComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(UpdateDbComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
