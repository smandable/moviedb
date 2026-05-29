import { stripTrailingNumber, getBaseTitle } from './title';

describe('stripTrailingNumber', () => {
  it('strips a trailing " # NN" sequence number', () => {
    expect(stripTrailingNumber('Some Title # 07')).toBe('Some Title');
    expect(stripTrailingNumber('Some Title # 7')).toBe('Some Title');
  });

  it('leaves titles without a trailing number untouched', () => {
    expect(stripTrailingNumber('Some Title')).toBe('Some Title');
  });

  it('only strips when the number is at the end', () => {
    // " # 07" is mid-string here (followed by a suffix), so it is preserved
    expect(stripTrailingNumber('Some Title # 07 - Cast')).toBe(
      'Some Title # 07 - Cast',
    );
  });

  it('requires spaces around the "#" (does not strip "#7")', () => {
    expect(stripTrailingNumber('Some Title #7')).toBe('Some Title #7');
  });

  it('handles empty/nullish input', () => {
    expect(stripTrailingNumber('')).toBe('');
    expect(stripTrailingNumber(undefined as unknown as string)).toBe('');
  });
});

describe('getBaseTitle', () => {
  it('strips a trailing " # NN"', () => {
    expect(getBaseTitle('Some Title # 03')).toBe('Some Title');
  });

  it('strips " # NN" plus a cast/scene suffix', () => {
    expect(getBaseTitle('Some Title # 03 - Cast Names')).toBe('Some Title');
    expect(getBaseTitle('Some Title - Scene_1 - Cast')).toBe('Some Title');
  });

  it('leaves a plain title untouched', () => {
    expect(getBaseTitle('Some Title')).toBe('Some Title');
  });

  it('trims leading whitespace', () => {
    expect(getBaseTitle('  Some Title # 03')).toBe('Some Title');
  });

  it('handles empty/nullish input', () => {
    expect(getBaseTitle('')).toBe('');
    expect(getBaseTitle(undefined as unknown as string)).toBe('');
  });
});
