// Angular modules
import { ChangeDetectionStrategy } from '@angular/core';
import { Component }         from '@angular/core';
import { RouterLink }        from '@angular/router';
import { RouterLinkActive }  from '@angular/router';

// External modules
import { NgbCollapse }       from '@ng-bootstrap/ng-bootstrap';
import { TranslatePipe }     from '@ngx-translate/core';

// Internal modules
import { environment }       from '@env/environment';

@Component({
  selector    : 'app-layout-header',
  templateUrl : './layout-header.component.html',
  styleUrls   : ['./layout-header.component.scss'],
  standalone  : true,
  changeDetection : ChangeDetectionStrategy.Eager,
  imports     : [RouterLink, NgbCollapse, RouterLinkActive, TranslatePipe]
})
export class LayoutHeaderComponent
{
  public appName         : string  = environment.appName;
  public isMenuCollapsed : boolean = true;
}
