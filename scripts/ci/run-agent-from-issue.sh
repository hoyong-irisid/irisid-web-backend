#!/usr/bin/env bash
# Cursor CLI agent — 파일 수정만 (git/PR은 CI가 처리)
set -euo pipefail

export PATH="$HOME/.cursor/bin:$PATH"

if [ -z "${CURSOR_API_KEY:-}" ]; then
  echo "CURSOR_API_KEY is required" >&2
  exit 1
fi

PROMPT_FILE="$(mktemp)"
{
  cat <<HEADER
You are the Iris ID website automation agent running in CI.

## GitHub Issue #${ISSUE_NUMBER}
**Title:** ${ISSUE_TITLE}
**URL:** ${ISSUE_URL}

## Task description
HEADER
  printf '%s\n' "${ISSUE_BODY:-_(no description)_}"
  cat <<'FOOTER'

## Rules (strict)
- Implement only what this issue asks for.
- Modify files in the working directory only.
- Do NOT run git, gh, npm publish, or deploy commands.
- Do NOT change unrelated files, dependencies, or secrets (.env).
- Match existing code style and conventions.
- Prefer minimal, focused diffs.
- If the task is unclear, make the smallest safe change and note assumptions in comments.

## Context
This repo monitors irisid.com (monitoring config, scripts, content-fix definitions).
Live WordPress is hosted separately; document WP copy changes in config/monitor.json when needed.
FOOTER
} > "$PROMPT_FILE"

AGENT_BIN="agent"
if ! command -v agent >/dev/null 2>&1; then
  AGENT_BIN="cursor-agent"
fi

MODEL="${CURSOR_AGENT_MODEL:-composer-2.5}"
echo "Running $AGENT_BIN (model=$MODEL)..."

set +e
"$AGENT_BIN" -p --force --model "$MODEL" "$(cat "$PROMPT_FILE")" | tee /tmp/agent-output.txt
AGENT_EXIT=$?
set -e

rm -f "$PROMPT_FILE"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  SUMMARY=$(tail -n 40 /tmp/agent-output.txt | head -c 3500)
  {
    echo "summary<<EOF"
    echo "$SUMMARY"
    echo "EOF"
  } >> "$GITHUB_OUTPUT"
fi

if [ "$AGENT_EXIT" -ne 0 ]; then
  echo "Agent exited with code $AGENT_EXIT" >&2
  exit "$AGENT_EXIT"
fi

echo "Agent finished."
