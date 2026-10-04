import { Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FormsModule } from '@angular/forms';

import { AutoGrowDirective } from './auto-grow.directive';

@Component({
  standalone: true,
  imports: [FormsModule, AutoGrowDirective],
  template: `<textarea
    appAutoGrow
    rows="1"
    style="width: 120px; font: 14px/20px sans-serif; padding: 0; border: 0; box-sizing: content-box"
    [(ngModel)]="text"
  ></textarea>`,
})
class HostComponent {
  text = '';
}

describe('AutoGrowDirective', () => {
  let fixture: ComponentFixture<HostComponent>;
  let textarea: HTMLTextAreaElement;

  const LONG = 'Velvet Gold Goes to the Seaside # 04 - Scene_3 - Jane Doe';

  const render = async (text: string) => {
    fixture.componentInstance.text = text;
    fixture.detectChanges();
    await fixture.whenStable(); // NgModel writes the element asynchronously
  };

  beforeEach(() => {
    fixture = TestBed.createComponent(HostComponent);
    textarea = fixture.nativeElement.querySelector('textarea');
    document.body.appendChild(fixture.nativeElement); // layout needs the DOM
  });

  afterEach(() => fixture.nativeElement.remove());

  it('fits a wrapped value when the list first fills, before any typing', async () => {
    await render(LONG);
    expect(textarea.scrollHeight).toBeGreaterThan(20); // it does wrap
    expect(textarea.clientHeight).toBe(textarea.scrollHeight);
  });

  it('shrinks back when the value is set shorter from code', async () => {
    await render(LONG);
    const tall = textarea.clientHeight;
    await render('Short');
    expect(textarea.clientHeight).toBeLessThan(tall);
    expect(textarea.clientHeight).toBe(textarea.scrollHeight);
  });

  it('re-measures on a window resize', async () => {
    await render(LONG);
    textarea.style.width = '400px';
    window.dispatchEvent(new Event('resize'));
    expect(textarea.clientHeight).toBe(textarea.scrollHeight);
  });
});
