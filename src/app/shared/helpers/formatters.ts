import { ValueFormatterParams } from 'ag-grid-community';

/**
 * Formats a size in bytes into a human-readable string (base-1000 units).
 * Shared by the grid's file-size column and the update-db totals row so they
 * format identically.
 * @param value - The size in bytes (number or numeric string).
 * @returns A formatted string such as "1.50 GB".
 */
export function formatBytes(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === 0) {
    return '0 Bytes'; // Handle empty, undefined, null, or zero values
  }

  // Ensure the value is a number
  const byteNumber = typeof value === 'string' ? parseInt(value, 10) : value;

  if (isNaN(byteNumber)) {
    console.error('formatBytes received invalid value:', value);
    return 'Invalid Size';
  }

  const units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
  const exponent = Math.min(
    Math.floor(Math.log(byteNumber) / Math.log(1000)),
    units.length - 1
  );
  const size = byteNumber / Math.pow(1000, exponent);

  return `${size.toFixed(2)}\u00A0${units[exponent]}`;
}

/**
 * AG Grid value formatter wrapper around {@link formatBytes}.
 * @param params - The parameters containing the value to format.
 * @returns A formatted string representing the file size.
 */
export function fileSizeFormatter(params: ValueFormatterParams): string {
  return formatBytes(params.value);
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

/**
 * Formats a duration in seconds for prose ("42s", "2m 41s", "1h 5m 3s"),
 * where the grid's clock style ("2:41") would read as a time of day.
 * @param totalSeconds - The duration in whole seconds.
 * @returns A formatted string such as "2m 41s".
 */
export function formatDurationHuman(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds));
  const hours = Math.floor(s / 3600);
  const minutes = Math.floor((s % 3600) / 60);
  const seconds = s % 60;

  if (hours > 0) {
    return `${hours}h ${minutes}m ${seconds}s`;
  }
  if (minutes > 0) {
    return `${minutes}m ${seconds}s`;
  }
  return `${seconds}s`;
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
