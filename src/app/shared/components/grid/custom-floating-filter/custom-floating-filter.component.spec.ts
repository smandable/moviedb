import { CustomFloatingFilterComponent } from './custom-floating-filter.component';
import { IFloatingFilterParams, TextFilterModel } from 'ag-grid-community';

describe('CustomFloatingFilterComponent', () => {
  let component: CustomFloatingFilterComponent;
  let mockFilterInstance: any;
  let mockParams: IFloatingFilterParams<TextFilterModel>;

  beforeEach(() => {
    component = new CustomFloatingFilterComponent();
    mockFilterInstance = {
      onFloatingFilterChanged: jasmine.createSpy('onFloatingFilterChanged'),
    };
    mockParams = {
      parentFilterInstance: (callback: Function) => callback(mockFilterInstance),
    } as any;

    component.agInit(mockParams);
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
  });

  describe('clearFilter', () => {
    it('should reset currentValue and clear parent filter', () => {
      component.currentValue = 'something';
      component.clearFilter();
      expect(component.currentValue).toBe('');
      expect(mockFilterInstance.onFloatingFilterChanged).toHaveBeenCalledWith(null, null);
    });
  });
});
