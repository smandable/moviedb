import { CustomFloatingFilterComponent } from './custom-floating-filter.component';
import { IFloatingFilterParams, TextFilterModel } from 'ag-grid-community';

describe('CustomFloatingFilterComponent', () => {
  let component: CustomFloatingFilterComponent;
  let mockFilterInstance: any;
  let mockParams: IFloatingFilterParams<TextFilterModel>;

  beforeEach(() => {
    localStorage.clear();
    component = new CustomFloatingFilterComponent();
    mockFilterInstance = {
      onFloatingFilterChanged: jasmine.createSpy('onFloatingFilterChanged'),
    };
    mockParams = {
      parentFilterInstance: (callback: Function) => callback(mockFilterInstance),
    } as any;

    component.agInit(mockParams);
  });

  afterEach(() => {
    component.ngOnDestroy();
    localStorage.clear();
  });

  it('should initialize with empty currentValue', () => {
    expect(component.currentValue).toBe('');
  });

  it('should store params on agInit', () => {
    expect(component.params).toBe(mockParams);
  });

  describe('onParentModelChanged', () => {
    it('should update currentValue from parent model', () => {
      component.onParentModelChanged({ filter: 'test', type: 'contains' } as TextFilterModel);
      expect(component.currentValue).toBe('test');
    });

    it('should set empty string when parent model is null', () => {
      component.currentValue = 'previous';
      component.onParentModelChanged(null as any);
      expect(component.currentValue).toBe('');
    });
  });

  describe('onInputChanged', () => {
    it('should call parent filter with contains and current value', () => {
      component.currentValue = 'search term';
      component.onInputChanged();
      expect(mockFilterInstance.onFloatingFilterChanged).toHaveBeenCalledWith('contains', 'search term');
    });

    it('should hide the dropdown while typing', () => {
      component.showDropdown = true;
      component.currentValue = 'abc';
      component.onInputChanged();
      expect(component.showDropdown).toBe(false);
    });

    it('should save previous term when input is cleared manually', () => {
      component.currentValue = 'Seduce';
      component.onInputChanged(); // sets previousValue
      component.currentValue = '';
      component.onInputChanged(); // clears → saves 'Seduce'
      const history = JSON.parse(localStorage.getItem('filterHistory')!);
      expect(history).toContain('Seduce');
    });
  });

  describe('clearFilter', () => {
    it('should reset currentValue and clear parent filter', () => {
      component.currentValue = 'something';
      component.clearFilter();
      expect(component.currentValue).toBe('');
      expect(mockFilterInstance.onFloatingFilterChanged).toHaveBeenCalledWith(null, null);
    });

    it('should save the cleared term to history', () => {
      component.currentValue = 'College';
      component.clearFilter();
      const history = JSON.parse(localStorage.getItem('filterHistory')!);
      expect(history[0]).toBe('College');
    });

    it('should not save terms shorter than 2 characters', () => {
      component.currentValue = 'A';
      component.clearFilter();
      const raw = localStorage.getItem('filterHistory');
      const history = raw ? JSON.parse(raw) : [];
      expect(history.length).toBe(0);
    });
  });

  describe('search history', () => {
    it('should deduplicate and move repeated terms to front', () => {
      component.currentValue = 'Alpha';
      component.clearFilter();
      component.currentValue = 'Beta';
      component.clearFilter();
      component.currentValue = 'Alpha';
      component.clearFilter();
      const history = JSON.parse(localStorage.getItem('filterHistory')!);
      expect(history[0]).toBe('Alpha');
      expect(history.length).toBe(2);
    });

    it('should cap history at 10 items', () => {
      for (let i = 1; i <= 12; i++) {
        component.currentValue = 'Term' + i;
        component.clearFilter();
      }
      const history = JSON.parse(localStorage.getItem('filterHistory')!);
      expect(history.length).toBe(10);
      expect(history[0]).toBe('Term12');
    });

    it('should show dropdown on focus when history exists and input is empty', () => {
      localStorage.setItem('filterHistory', JSON.stringify(['Foo', 'Bar']));
      component.currentValue = '';
      component.onFocus();
      expect(component.showDropdown).toBe(true);
    });

    it('should not show dropdown on focus when input has a value', () => {
      localStorage.setItem('filterHistory', JSON.stringify(['Foo']));
      component.currentValue = 'something';
      component.onFocus();
      expect(component.showDropdown).toBe(false);
    });

    it('should select a term from history and trigger filter', () => {
      component.selectTerm('Seduce');
      expect(component.currentValue).toBe('Seduce');
      expect(component.showDropdown).toBe(false);
      expect(mockFilterInstance.onFloatingFilterChanged).toHaveBeenCalledWith('contains', 'Seduce');
    });

    it('should save current term on destroy', () => {
      component.currentValue = 'Barely';
      component.ngOnDestroy();
      const history = JSON.parse(localStorage.getItem('filterHistory')!);
      expect(history).toContain('Barely');
    });
  });
});
