import { themeQuartz } from 'ag-grid-community';

export const myTheme = themeQuartz.withParams({
  backgroundColor: '#2D2F32',
  browserColorScheme: 'dark',
  cellHorizontalPaddingScale: 1,
  chromeBackgroundColor: {
    ref: 'foregroundColor',
    mix: 0.07,
    onto: 'backgroundColor',
  },
  fontFamily: {
    googleFont: 'Lato',
  },
  fontSize: 14,
  foregroundColor: '#FFF',
  headerFontFamily: 'inherit',
  headerFontSize: 16,
  headerFontWeight: 700,
  rowVerticalPaddingScale: 0.5,
});
