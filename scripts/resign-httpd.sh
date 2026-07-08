#!/bin/bash
# Re-sign Homebrew httpd with a stable Developer ID so macOS Full Disk Access —
# granted once to this code identity — keeps applying after `brew upgrade httpd`.
#
# Why this exists: Homebrew ships httpd ad-hoc signed. TCC pins ad-hoc/unsigned
# binaries by path+cdhash, so every upgrade (new Cellar path, new hash) orphans
# the Full Disk Access grant and MovieDB's /Volumes/* scans start failing with
# `scandir(): (errno 1): Operation not permitted`. Signing with a real Developer
# ID makes TCC key the grant to the code requirement (identifier + Team ID),
# which survives path/hash changes as long as we re-sign each new binary with the
# SAME identity. Re-granting FDA is GUI-only and can't be scripted; re-signing can.
#
# Triggered automatically by ~/Library/LaunchAgents/com.moviedb.resign-httpd.plist
# (WatchPaths on the httpd version symlink), or run manually after an upgrade.
set -euo pipefail

CERT="Developer ID Application: Sean Mandable (7VP76365KX)"
IDENT="httpd"
LABEL="homebrew.mxcl.httpd"
LINK="/opt/homebrew/opt/httpd/bin/httpd"

HTTPD="$(/usr/bin/readlink -f "$LINK" 2>/dev/null || echo "$LINK")"
[ -x "$HTTPD" ] || { echo "httpd not found at $HTTPD"; exit 0; }

# Already carrying our Team ID? Nothing to do (keeps WatchPaths idempotent).
if /usr/bin/codesign -dv "$HTTPD" 2>&1 | grep -q "TeamIdentifier=7VP76365KX"; then
  echo "$(date '+%F %T') httpd already signed (7VP76365KX); no action"
  exit 0
fi

echo "$(date '+%F %T') re-signing $HTTPD with Developer ID"
/usr/bin/codesign -f -s "$CERT" --timestamp=none -i "$IDENT" "$HTTPD"
/usr/bin/codesign --verify --verbose=1 "$HTTPD"

# Restart the service so the freshly-signed binary is the one running.
/bin/launchctl kickstart -k "gui/$(id -u)/$LABEL" 2>/dev/null || true
echo "$(date '+%F %T') done; httpd restarted"
