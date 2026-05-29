// Angular modules
import { Component }         from '@angular/core';
import { RouterLink }        from '@angular/router';
import { RouterLinkActive }  from '@angular/router';

// External modules
import { NgbCollapse }       from '@ng-bootstrap/ng-bootstrap';
import { TranslateModule }   from '@ngx-translate/core';

// Internal modules
import { environment }       from '@env/environment';

@Component({
  selector    : 'app-layout-header',
  templateUrl : './layout-header.component.html',
  styleUrls   : ['./layout-header.component.scss'],
  standalone  : true,
  imports     : [RouterLink, NgbCollapse, RouterLinkActive, TranslateModule]
})
export class LayoutHeaderComponent
{
  public appName         : string  = environment.appName;
  public isMenuCollapsed : boolean = true;
}
