#!/usr/bin/env bash
# Run uploads rsync ON the VPS via SSH (live → cms, same server).
# Usage:
#   export WP_SSH=irisid5@173.231.221.180
#   ./scripts/migration/sync-uploads-via-ssh.sh
# Optional dry-run:
#   DRY_RUN=1 ./scripts/migration/sync-uploads-via-ssh.sh
set -euo pipefail

WP_SSH="${WP_SSH:?Set WP_SSH e.g. irisid5@173.231.221.180}"
LIVE_UPLOADS="${LIVE_UPLOADS:-/home/irisid5/public_html/wp-content/uploads}"
CMS_UPLOADS="${CMS_UPLOADS:-/home/irisid5/cms.irisid.com/wp-content/uploads}"
DRY_RUN="${DRY_RUN:-0}"

RSYNC_FLAGS="-av --ignore-existing"
if [[ "$DRY_RUN" == "1" ]]; then
  RSYNC_FLAGS="-avn --ignore-existing"
  echo "DRY RUN — no files will be copied."
fi

echo "SSH → ${WP_SSH}"
echo "  LIVE: ${LIVE_UPLOADS}"
echo "  CMS:  ${CMS_UPLOADS}"
echo ""

ssh "$WP_SSH" "LIVE_UPLOADS=$(printf '%q' "$LIVE_UPLOADS") CMS_UPLOADS=$(printf '%q' "$CMS_UPLOADS") RSYNC_FLAGS=$(printf '%q' "$RSYNC_FLAGS") bash -s" <<'REMOTE'
set -euo pipefail
if [[ ! -d "$LIVE_UPLOADS" ]]; then
  echo "LIVE_UPLOADS not found: $LIVE_UPLOADS" >&2
  echo "List home docroots:" >&2
  ls -la "$(dirname "$(dirname "$LIVE_UPLOADS")")" 2>/dev/null || true
  exit 1
fi
mkdir -p "$CMS_UPLOADS"
# shellcheck disable=SC2086
rsync $RSYNC_FLAGS "$LIVE_UPLOADS/" "$CMS_UPLOADS/"
echo ""
echo "Done on server."
du -sh "$LIVE_UPLOADS" "$CMS_UPLOADS" 2>/dev/null || true
REMOTE

echo ""
echo "Next: cms WP Admin → Media → open a file under uploads/2024/ (or recent year)."
