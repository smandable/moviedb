// Packages
import packageInfo from '../../package.json';

const scheme = 'http://';
const host   = 'localhost';
const port   = ':8888';
const path   = '/moviedb/server/';

const baseUrl = scheme + host + port + path;

export const environment = {
  production      : true,
  version         : packageInfo.version,
  appName         : 'MovieDB',
  defaultLanguage : 'en',
  apiBaseUrl         : baseUrl,
  defaultDirectory   : '/Volumes/Download/fixed/',
};
