#!/usr/bin/env bash
set -euo pipefail

if command -v agent >/dev/null 2>&1; then
  echo "Cursor CLI already installed: $(command -v agent)"
  agent --version 2>/dev/null || true
  exit 0
fi

curl -fsS https://cursor.com/install | bash
echo "$HOME/.cursor/bin" >> "$GITHUB_PATH"
export PATH="$HOME/.cursor/bin:$PATH"
agent --version 2>/dev/null || cursor-agent --version 2>/dev/null || true
