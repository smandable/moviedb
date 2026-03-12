import { fileSizeFormatter, durationFormatter } from './formatters';
import { ValueFormatterParams } from 'ag-grid-community';

// Helper to create a minimal ValueFormatterParams with the given value
function params(value: any): ValueFormatterParams {
  return { value } as ValueFormatterParams;
}

describe('fileSizeFormatter', () => {
  it('should return "0 Bytes" for null, undefined, or zero', () => {
    expect(fileSizeFormatter(params(null))).toBe('0 Bytes');
    expect(fileSizeFormatter(params(undefined))).toBe('0 Bytes');
    expect(fileSizeFormatter(params(0))).toBe('0 Bytes');
  });

  it('should format bytes', () => {
    expect(fileSizeFormatter(params(500))).toBe('500.00\u00A0Bytes');
  });

  it('should format kilobytes', () => {
    expect(fileSizeFormatter(params(1024))).toBe('1.00\u00A0KB');
    expect(fileSizeFormatter(params(2048))).toBe('2.00\u00A0KB');
  });

  it('should format megabytes', () => {
    expect(fileSizeFormatter(params(1048576))).toBe('1.00\u00A0MB');
  });

  it('should format gigabytes', () => {
    expect(fileSizeFormatter(params(1073741824))).toBe('1.00\u00A0GB');
  });

  it('should format terabytes', () => {
    expect(fileSizeFormatter(params(1099511627776))).toBe('1.00\u00A0TB');
  });

  it('should handle string values by parsing them', () => {
    expect(fileSizeFormatter(params('1048576'))).toBe('1.00\u00A0MB');
  });

  it('should return "Invalid Size" for non-numeric strings', () => {
    expect(fileSizeFormatter(params('abc'))).toBe('Invalid Size');
  });
});

describe('durationFormatter', () => {
  it('should return "N/A" for null or undefined', () => {
    expect(durationFormatter(params(null))).toBe('N/A');
    expect(durationFormatter(params(undefined))).toBe('N/A');
  });

  it('should return "00:00:00" for zero', () => {
    expect(durationFormatter(params(0))).toBe('00:00:00');
  });

  it('should format seconds only', () => {
    expect(durationFormatter(params(45))).toBe('00:45');
  });

  it('should format minutes and seconds', () => {
    expect(durationFormatter(params(125))).toBe('02:05');
  });

  it('should format hours, minutes, and seconds', () => {
    expect(durationFormatter(params(3661))).toBe('1:01:01');
  });

  it('should handle string values', () => {
    expect(durationFormatter(params('3600'))).toBe('1:00:00');
  });

  it('should return "Invalid Duration" for invalid strings', () => {
    expect(durationFormatter(params('abc'))).toBe('Invalid Duration');
  });

  it('should return "Invalid Duration" for negative string values', () => {
    expect(durationFormatter(params('-5'))).toBe('Invalid Duration');
  });

  it('should return "Invalid Duration" for unsupported types', () => {
    expect(durationFormatter(params({}))).toBe('Invalid Duration');
  });
});
