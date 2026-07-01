#!/usr/bin/env bash
# Rename/create GitHub repos to match local folder names.
# Prerequisite: gh auth login
set -euo pipefail

export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"

BACKEND_DIR="${BACKEND_DIR:-$HOME/Documents/irisid-web-backend}"
FRONTEND_DIR="${FRONTEND_DIR:-$HOME/Documents/irisid-web-frontend}"

if ! gh auth status &>/dev/null; then
  echo "Run first: gh auth login"
  exit 1
fi

echo "=== 1. Rename monitoring → irisid-web-backend ==="
gh repo rename irisid-web-backend --repo hoyong-irisid/monitoring --yes 2>/dev/null || {
  if gh repo view hoyong-irisid/irisid-web-backend &>/dev/null; then
    echo "Already renamed to irisid-web-backend"
  else
    echo "Rename failed — do manually: github.com/hoyong-irisid/monitoring → Settings → Repository name"
    exit 1
  fi
}

echo "=== 2. Create irisid-web-frontend (if missing) ==="
if ! gh repo view hoyong-irisid/irisid-web-frontend &>/dev/null; then
  gh repo create hoyong-irisid/irisid-web-frontend --private --description "Iris ID Next.js frontend"
else
  echo "irisid-web-frontend already exists"
fi

echo "=== 3. Backend git remote ==="
cd "$BACKEND_DIR"
if [[ ! -d .git ]]; then
  git init
  git branch -M main
fi
git remote remove origin 2>/dev/null || true
git remote add origin "https://github.com/hoyong-irisid/irisid-web-backend.git"
echo "Backend remote: $(git remote get-url origin)"

echo "=== 4. Frontend git remote ==="
cd "$FRONTEND_DIR"
git remote remove origin 2>/dev/null || true
git remote add origin "https://github.com/hoyong-irisid/irisid-web-frontend.git"
echo "Frontend remote: $(git remote get-url origin)"

echo ""
echo "Done. Next:"
echo "  cd $BACKEND_DIR && git add -A && git commit -m '...' && git push -u origin main"
echo "  cd $FRONTEND_DIR && git add -A && git commit -m '...' && git push -u origin main"
