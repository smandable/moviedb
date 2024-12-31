export function fileSizeFormatter(params: { value: number }): string {
    if (!params.value) {
      return '0 Bytes'; // Handle empty or zero values
    }
  
    const units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(
      Math.floor(Math.log(params.value) / Math.log(1024)),
      units.length - 1
    );
    const size = params.value / Math.pow(1024, exponent);
  
    return `${size.toFixed(2)}\u00A0${units[exponent]}`;
  }
  
  export function durationFormatter(params: { value: number }): string {
    if (!params.value) {
      return '00:00:00'; // Handle empty or zero values
    }
  
    const totalSeconds = Math.floor(params.value);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
  
    const pad = (n: number) => n.toString().padStart(2, '0');
    return hours > 0
      ? `${hours}:${pad(minutes)}:${pad(seconds)}` // No leading zero for hours
      : `${pad(minutes)}:${pad(seconds)}`; // Skip hours if zero
  }
  