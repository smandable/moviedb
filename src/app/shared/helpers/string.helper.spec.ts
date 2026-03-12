import { StringHelper } from './string.helper';

describe('StringHelper', () => {
  describe('interpolate', () => {
    it('should replace %s placeholders with arguments in order', () => {
      const result = StringHelper.interpolate('Hello %s, welcome to %s', ['Alice', 'Wonderland']);
      expect(result).toBe('Hello Alice, welcome to Wonderland');
    });

    it('should handle a single placeholder', () => {
      const result = StringHelper.interpolate('Hello %s', ['World']);
      expect(result).toBe('Hello World');
    });

    it('should leave extra placeholders if not enough arguments', () => {
      const result = StringHelper.interpolate('%s and %s', ['one']);
      expect(result).toBe('one and %s');
    });

    it('should ignore extra arguments', () => {
      const result = StringHelper.interpolate('Hello %s', ['World', 'extra']);
      expect(result).toBe('Hello World');
    });

    it('should return the string unchanged if no placeholders', () => {
      const result = StringHelper.interpolate('Hello World', ['ignored']);
      expect(result).toBe('Hello World');
    });
  });

  describe('buildURIParams', () => {
    it('should build a query string from an object', () => {
      const result = StringHelper.buildURIParams({ foo: 'bar', baz: '123' });
      expect(result).toBe('?foo=bar&baz=123');
    });

    it('should return just "?" for an empty object', () => {
      const result = StringHelper.buildURIParams({});
      expect(result).toBe('?');
    });

    it('should skip undefined keys or values', () => {
      const obj: any = { a: '1', b: undefined };
      const result = StringHelper.buildURIParams(obj);
      expect(result).toBe('?a=1');
    });

    it('should encode special characters', () => {
      const result = StringHelper.buildURIParams({ q: 'hello world' });
      expect(result).toBe('?q=hello+world');
    });
  });
});
