import {
  Directive,
  ElementRef,
  HostListener,
  OnDestroy,
  OnInit,
  Optional,
  Self,
} from '@angular/core';
import { NgControl } from '@angular/forms';
import { Subscription } from 'rxjs';

/**
 * Keeps a textarea exactly as tall as its text. Resizing only on (input)
 * left a wrapped name clipped to one row until it was typed in; NgModel's
 * valueChanges also fires when the list first fills and when the modal sets
 * a value itself (a rename result), after the new value is in the element.
 * A window resize changes where the text wraps, so it re-measures then too.
 */
@Directive({
  selector: 'textarea[appAutoGrow]',
  standalone: true,
})
export class AutoGrowDirective implements OnInit, OnDestroy {
  private subscription?: Subscription;

  constructor(
    private el: ElementRef<HTMLTextAreaElement>,
    @Optional() @Self() private ngControl: NgControl | null,
  ) {}

  ngOnInit(): void {
    this.subscription = this.ngControl?.valueChanges?.subscribe(() =>
      this.resize(),
    );
  }

  ngOnDestroy(): void {
    this.subscription?.unsubscribe();
  }

  @HostListener('input')
  @HostListener('window:resize')
  resize(): void {
    const textarea = this.el.nativeElement;
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
  }
}
