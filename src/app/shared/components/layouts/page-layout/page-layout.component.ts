// Angular modules
import { Component }             from '@angular/core';

// Components
import { LayoutHeaderComponent } from '../layout-header/layout-header.component';

@Component({
  selector    : 'app-page-layout',
  templateUrl : './page-layout.component.html',
  styleUrls   : ['./page-layout.component.scss'],
  standalone  : true,
  imports     : [LayoutHeaderComponent]
})
export class PageLayoutComponent {}
