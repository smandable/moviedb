import { StorageHelper } from './storage.helper';

describe('StorageHelper', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  // The prefix is based on environment: 'MovieDB_<version>_'
  // We test behavior rather than the exact prefix value.

  describe('setItem / getItem', () => {
    it('should store and retrieve a value with prefix', () => {
      StorageHelper.setItem('testKey', { hello: 'world' });
      const result = StorageHelper.getItem('testKey');
      expect(result).toEqual({ hello: 'world' });
    });

    it('should store and retrieve a string value', () => {
      StorageHelper.setItem('str', 'simple string');
      expect(StorageHelper.getItem('str')).toBe('simple string');
    });

    it('should return null for a missing key', () => {
      const result = StorageHelper.getItem('nonexistent');
      expect(result).toBeNull();
    });

    it('should store without prefix when prefix is false', () => {
      StorageHelper.setItem('rawKey', 'rawValue', false);
      expect(localStorage.getItem('rawKey')).toBe('"rawValue"');
      expect(StorageHelper.getItem('rawKey', false)).toBe('rawValue');
    });
  });

  describe('removeItem', () => {
    it('should remove a stored item', () => {
      StorageHelper.setItem('toRemove', 'value');
      expect(StorageHelper.getItem('toRemove')).toBe('value');

      StorageHelper.removeItem('toRemove');
      expect(StorageHelper.getItem('toRemove')).toBeNull();
    });
  });

  describe('getKeys', () => {
    it('should return only prefixed keys by default', () => {
      StorageHelper.setItem('key1', 'v1');
      StorageHelper.setItem('key2', 'v2');
      localStorage.setItem('foreign_key', 'other');

      const keys = StorageHelper.getKeys();
      expect(keys.length).toBe(2);
      expect(keys.every(k => k.includes('key1') || k.includes('key2'))).toBeTrue();
    });

    it('should return all keys when all=true', () => {
      StorageHelper.setItem('key1', 'v1');
      localStorage.setItem('foreign_key', 'other');

      const keys = StorageHelper.getKeys(true);
      expect(keys.length).toBeGreaterThanOrEqual(2);
    });
  });

  describe('clearItems', () => {
    it('should clear only prefixed keys by default', () => {
      StorageHelper.setItem('key1', 'v1');
      localStorage.setItem('foreign_key', 'other');

      StorageHelper.clearItems();

      expect(StorageHelper.getItem('key1')).toBeNull();
      expect(localStorage.getItem('foreign_key')).toBe('other');
    });

    it('should clear all keys when all=true', () => {
      StorageHelper.setItem('key1', 'v1');
      localStorage.setItem('foreign_key', 'other');

      StorageHelper.clearItems(true);

      expect(localStorage.length).toBe(0);
    });
  });

  describe('clearItemsWithoutCurrentPrefix', () => {
    it('should remove non-prefixed keys and keep prefixed ones', () => {
      StorageHelper.setItem('keep', 'value');
      localStorage.setItem('foreign_key', 'other');

      StorageHelper.clearItemsWithoutCurrentPrefix();

      expect(StorageHelper.getItem('keep')).toBe('value');
      expect(localStorage.getItem('foreign_key')).toBeNull();
    });
  });
});
