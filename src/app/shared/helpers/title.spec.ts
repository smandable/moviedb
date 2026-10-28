import { stripTrailingNumber, getBaseTitle, endsWithSceneNumber, } from './title';

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
    expect(stripTrailingNumber('Some Title # 07 - Cast')).toBe('Some Title # 07 - Cast');
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

  it('strips a trailing year/studio "(...)" tag', () => {
    expect(getBaseTitle('Some Title (2024)')).toBe('Some Title');
    expect(getBaseTitle('Some Title (Evil Angel)')).toBe('Some Title');
  });

  it('strips " # NN" even when a "(...)" tag follows it', () => {
    // Regression: the tag sits between the volume number and the end, so
    // " # NN" used to be left in place and the whole string came back.
    expect(getBaseTitle('Some Title # 03 (2024)')).toBe('Some Title');
    expect(getBaseTitle('Some Title # 03 (2024) - Scene_1')).toBe('Some Title');
    expect(getBaseTitle('Some Title # 03 (Evil Angel) - Cast')).toBe('Some Title');
  });

  it('keeps parentheses that are not a trailing tag', () => {
    expect(getBaseTitle('Some (Odd) Title')).toBe('Some (Odd) Title');
  });

  it('handles the tag sitting before the volume number', () => {
    expect(getBaseTitle('Oil Overload (Chris Streams) # 01')).toBe('Oil Overload');
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

describe('endsWithSceneNumber', () => {
  it('matches a base name ending in a canonical "Scene_N"', () => {
    expect(endsWithSceneNumber('Ass Man - Scene_1')).toBe(true);
    expect(endsWithSceneNumber('Some Title - Scene_12')).toBe(true);
    expect(endsWithSceneNumber('Some Title - Scene_3 ')).toBe(true);
  });

  it('matches un-normalized scene spellings', () => {
    expect(endsWithSceneNumber('some.title.scene 2')).toBe(true);
    expect(endsWithSceneNumber('Some Title Scene-3')).toBe(true);
    expect(endsWithSceneNumber('some.title.scene.4')).toBe(true);
    expect(endsWithSceneNumber('some title scene5')).toBe(true);
  });

  it('rejects names that already have a cast after the scene', () => {
    expect(endsWithSceneNumber('Ass Worship # 17 - Scene_1 - Kissa Sins')).toBe(false);
    expect(endsWithSceneNumber('Some Title - Scene_2 - Jane Doe')).toBe(false);
  });

  it('rejects names with no scene number', () => {
    expect(endsWithSceneNumber('Some Title')).toBe(false);
    expect(endsWithSceneNumber('Behind the Scenes')).toBe(false);
    expect(endsWithSceneNumber('Obscene_1')).toBe(false);
  });

  it('treats 4-digit numbers as years, not scene numbers', () => {
    expect(endsWithSceneNumber('Crime Scene 1999')).toBe(false);
    // ...but a real trailing scene still counts
    expect(endsWithSceneNumber('Crime Scene 1999 - Scene_2')).toBe(true);
  });

  it('handles empty/nullish input', () => {
    expect(endsWithSceneNumber('')).toBe(false);
    expect(endsWithSceneNumber(undefined as unknown as string)).toBe(false);
  });
});
