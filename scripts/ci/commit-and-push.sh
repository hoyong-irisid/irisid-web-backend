#!/usr/bin/env bash
set -euo pipefail

ISSUE_NUMBER="${1:-0}"

if [ -n "${GH_TOKEN:-}" ] && [ -n "${GITHUB_REPOSITORY:-}" ]; then
  git remote set-url origin "https://x-access-token:${GH_TOKEN}@github.com/${GITHUB_REPOSITORY}.git"
fi

git config user.name "irisid-monitor[bot]"
git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

if git diff --quiet && git diff --cached --quiet; then
  echo "No changes to commit."
  if [ -n "${GITHUB_OUTPUT:-}" ]; then
    echo "summary=No file changes" >> "$GITHUB_OUTPUT"
  fi
  exit 0
fi

BRANCH="agent/issue-${ISSUE_NUMBER}-$(date +%Y%m%d-%H%M)"
git checkout -b "$BRANCH"
git add -A
git commit -m "$(cat <<EOF
fix: agent task for issue #${ISSUE_NUMBER}

Automated changes from Cursor CLI via GitHub Actions.
Issue: #${ISSUE_NUMBER}
EOF
)"

git push -u origin "$BRANCH"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "summary=Pushed branch \`${BRANCH}\` — open a PR on GitHub" >> "$GITHUB_OUTPUT"
fi

echo "Pushed $BRANCH"
