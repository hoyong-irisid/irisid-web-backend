#!/usr/bin/env bash
set -euo pipefail

SUMMARY=""
FAILED=0

run_step() {
  local name="$1"
  shift
  echo "==> $name"
  if "$@"; then
    SUMMARY="${SUMMARY}✅ ${name}"$'\n'
  else
    SUMMARY="${SUMMARY}❌ ${name}"$'\n'
    FAILED=1
  fi
}

if [ -f package.json ] && grep -q '"typecheck"' package.json; then
  run_step "typecheck" npm run typecheck
fi

if [ -f src/index.ts ]; then
  export PLAYWRIGHT_BROWSERS_PATH=0
  npx playwright install chromium >/dev/null 2>&1 || true
  if [ -d node_modules/playwright-core/.local-browsers ] || [ -d node_modules/playwright/.local-browsers ]; then
    run_step "monitor:dry" env DRY_RUN=true npm run monitor
  else
    SUMMARY="${SUMMARY}⏭ monitor:dry (Playwright not installed)"$'\n'
  fi
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  {
    echo "summary<<EOF"
    printf "%s" "$SUMMARY"
    echo "EOF"
  } >> "$GITHUB_OUTPUT"
fi

if [ "$FAILED" -ne 0 ]; then
  exit 1
fi

echo "All tests passed."
