/**
 * Title-string helpers shared by the grid views.
 *
 * Titles in this app can carry a trailing sequence number (" # 07") and/or a
 * scene/cast suffix (" - Scene_1", " - Cast Names"). These helpers strip those
 * back to a base title for copying and external-drive searches.
 */

/**
 * Remove a trailing " # NN" sequence number from a title.
 * e.g. "Some Title # 07" -> "Some Title"
 */
export function stripTrailingNumber(title: string): string {
  return (title ?? '').replace(/\s+#\s+\d+$/, '');
}

/**
 * Reduce a title to its base by removing a trailing " # NN" and/or
 * " - <suffix>" (scene/cast), then trimming.
 * e.g. "Some Title # 03 - Cast" -> "Some Title"
 */
export function getBaseTitle(title: string): string {
  const raw = title ?? '';
  const match = raw.match(/^(.*?)(?:\s+#\s+\d+)?(?:\s+-\s+.*)?$/);
  return (match ? match[1] : raw).trim();
}
