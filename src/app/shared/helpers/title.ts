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

/**
 * Does a base name END with a scene number ("Ass Man - Scene_1")? That means
 * a scene file with nothing named after the scene yet — i.e. no cast appended.
 * "Ass Worship # 17 - Scene_1 - Kissa Sins" already has a cast name, so it is
 * false; so is anything without a scene number at all.
 *
 * Un-normalized spellings count ("scene 2", "Scene-3", "scene.4", "Scene5"),
 * mirroring the canonicalizer in server/normalize_helpers.php — including its
 * year guard: 4+ digits are a year, not a scene number ("Crime Scene 1999").
 * Pass a base name with the extension already stripped.
 */
export function endsWithSceneNumber(baseName: string): boolean {
  return /\bscene[\s._-]*\d{1,3}\s*$/i.test(baseName ?? '');
}
