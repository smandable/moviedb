// Angular modules
import { ChangeDetectionStrategy } from '@angular/core';
import { Component }       from '@angular/core';
import { RouterLink }      from '@angular/router';

// External modules
import { TranslatePipe }   from '@ngx-translate/core';

@Component({
  selector    : 'app-not-found',
  templateUrl : './not-found.component.html',
  styleUrls   : ['./not-found.component.scss'],
  standalone  : true,
  changeDetection : ChangeDetectionStrategy.Eager,
  imports     : [RouterLink, TranslatePipe]
})
export class NotFoundComponent {}
