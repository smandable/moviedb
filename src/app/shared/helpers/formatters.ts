import { ValueFormatterParams } from 'ag-grid-community';

/**
 * Formats file size from bytes to a human-readable string.
 * @param params - The parameters containing the value to format.
 * @returns A formatted string representing the file size.
 */
export function fileSizeFormatter(params: ValueFormatterParams): string {
  const value = params.value;

  if (value === null || value === undefined || value === 0) {
    return '0 Bytes'; // Handle empty, undefined, null, or zero values
  }

  // Ensure the value is a number
  const byteNumber = typeof value === 'string' ? parseInt(value, 10) : value;

  if (isNaN(byteNumber)) {
    console.error('fileSizeFormatter received invalid value:', value);
    return 'Invalid Size';
  }

  const units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
  const exponent = Math.min(
    Math.floor(Math.log(byteNumber) / Math.log(1024)),
    units.length - 1
  );
  const size = byteNumber / Math.pow(1024, exponent);

  return `${size.toFixed(2)}\u00A0${units[exponent]}`;
}

/**
 * Formats duration from seconds to "HH:MM:SS".
 * @param params - The parameters containing the value to format.
 * @returns A formatted string representing the duration.
 */
export function durationFormatter(params: ValueFormatterParams): string {
  const value = params.value;

  if (value === null || value === undefined) {
    return 'N/A';
  }

  let totalSeconds: number;

  if (typeof value === 'number') {
    if (value === 0) {
      return '00:00:00';
    }
    totalSeconds = Math.floor(value);
  } else if (typeof value === 'string') {
    totalSeconds = parseFloat(value);
    if (isNaN(totalSeconds) || totalSeconds <= 0) {
      return 'Invalid Duration';
    }
  } else {
    console.error('durationFormatter received unsupported type:', typeof value);
    return 'Invalid Duration';
  }

  return formatSeconds(totalSeconds);
}

function formatSeconds(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  const pad = (n: number) => n.toString().padStart(2, '0');

  return hours > 0
    ? `${hours}:${pad(minutes)}:${pad(seconds)}`
    : `${pad(minutes)}:${pad(seconds)}`;
}
