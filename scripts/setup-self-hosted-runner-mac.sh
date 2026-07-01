#!/usr/bin/env bash
# iMac용 GitHub Actions self-hosted runner 설치 안내
set -euo pipefail

REPO="${1:-hoyong-irisid/monitoring}"
LABELS="${2:-self-hosted,irisid-imac}"

cat <<EOF
╔══════════════════════════════════════════════════════════════╗
║  Iris ID — GitHub self-hosted runner (macOS / iMac)          ║
╚══════════════════════════════════════════════════════════════╝

Repository: https://github.com/${REPO}
Labels:     ${LABELS}

## 1) GitHub에서 registration token 받기

  https://github.com/${REPO}/settings/actions/runners/new?arch=arm64

  (Intel Mac이면 x64 선택)

## 2) runner 디렉터리 (예: ~/actions-runner)

  mkdir -p ~/actions-runner && cd ~/actions-runner

  # GitHub 페이지에 표시된 curl | tar 명령 실행 후:

  ./config.sh --url https://github.com/${REPO} --token <REGISTRATION_TOKEN> --labels ${LABELS}

  ./run.sh          # 포그라운드 테스트
  ./svc.sh install  # macOS 서비스 등록
  ./svc.sh start

## 3) iMac 준비

  - Node.js 22+:  brew install node@22
  - GitHub CLI:   brew install gh
  - 절전 끄기 (Energy Saver)

## 4) Secrets (repo Settings → Secrets)

  CURSOR_API_KEY, RESEND_API_KEY, TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID

## 5) 테스트

  - Issue 생성 (template: Agent task) + label agent-task
  - 또는 Actions → "Agent from GitHub Issue" → Run workflow

EOF
