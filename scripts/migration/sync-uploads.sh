#!/usr/bin/env bash
# Copy live wp-content/uploads → cms (same VPS). Edit paths before running.
set -euo pipefail

LIVE_UPLOADS="${LIVE_UPLOADS:-/home/irisid5/public_html/wp-content/uploads}"
CMS_UPLOADS="${CMS_UPLOADS:-/home/irisid5/cms.irisid.com/wp-content/uploads}"

if [[ ! -d "$LIVE_UPLOADS" ]]; then
  echo "LIVE_UPLOADS not found: $LIVE_UPLOADS" >&2
  echo "Set LIVE_UPLOADS and CMS_UPLOADS to your cPanel docroot paths." >&2
  exit 1
fi

mkdir -p "$CMS_UPLOADS"
rsync -av --ignore-existing "$LIVE_UPLOADS/" "$CMS_UPLOADS/"
echo "Done. Verify a sample image in cms Media Library."
