#!/usr/bin/env bash
# Deploy Iris ID headless mu-plugin to cms WordPress on InMotion VPS.
# Usage: WP_SSH=user@host WP_ROOT=/path/to/cms WP_SSH=... ./scripts/deploy-wordpress-mu-plugin.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_SSH="${WP_SSH:?Set WP_SSH e.g. irisid5@173.231.221.180}"
WP_ROOT="${WP_ROOT:?Set WP_ROOT e.g. /home/irisid5/public_html/cms}"

echo "Deploying mu-plugin to ${WP_SSH}:${WP_ROOT}/wp-content/mu-plugins/"

rsync -avz --delete \
  "${REPO_ROOT}/wordpress/mu-plugins/" \
  "${WP_SSH}:${WP_ROOT}/wp-content/mu-plugins/"

echo "Done. Flush permalinks in WP Admin and sync ACF field groups."
