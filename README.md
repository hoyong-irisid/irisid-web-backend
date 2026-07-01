# Iris ID Website Monitor & Automation

[irisid.com](https://irisid.com) **backend** repo — cms mu-plugin, migration docs, site monitoring.

**Sibling frontend:** `../irisid-web-frontend` (Next.js)

**Repository:** [hoyong-irisid/irisid-web-backend](https://github.com/hoyong-irisid/irisid-web-backend)

---

## 2단계 구조

| 단계 | 환경 | 하는 일 |
|------|------|---------|
| **1 — 조종실** | **Cursor IDE** | 에이전트로 개발·테스트·수동 커밋/푸시 |
| **2 — 24/7** | **iMac self-hosted runner** + GitHub Actions | 매일 모니터, 이슈 기반 Cursor CLI 자동 실행 |

자세한 다이어그램: **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**

---

## 흐름 1 — Issue로 작업 시키기

1. GitHub에서 **Agent task** 이슈 생성 (라벨 `agent-task` 자동)
2. Actions 워크플로 `Agent from GitHub Issue` 실행 (iMac runner)
3. **Cursor CLI** (`agent -p`)가 이슈 내용대로 코드 수정
4. `typecheck` + `monitor:dry`
5. `agent/issue-N-…` 브랜치에 **커밋·푸시**
6. **Resend** 이메일 + **Telegram** 알림

수동 실행: Actions → **Agent from GitHub Issue** → issue number 입력

---

## 흐름 2 — 매일 사이트 체크

cron **08:00 UTC** (`daily-monitor.yml`):

| 항목 | 내용 |
|------|------|
| 사이트 체크 | 우선순위 페이지 로드·응답 시간 |
| 깨진 링크 | 시드 URL에서 크롤 |
| 폼 | Contact 등 (기본: 제출 없이 필드만 검증) |
| 이슈 | 문제 시 `monitoring` repo에 GitHub Issue |
| 리포트 | Resend 이메일 + Telegram |

---

## 빠른 시작 (1단계 — 로컬 / Cursor)

```bash
cp .env.example .env   # 토큰 입력
npm install
PLAYWRIGHT_BROWSERS_PATH=0 npx playwright install chromium

npm run typecheck
npm run monitor:dry
```

---

## 빠른 시작 (2단계 — iMac runner)

```bash
# 안내 출력
bash scripts/setup-self-hosted-runner-mac.sh

# GitHub: Settings → Actions → Runners → macOS → labels: self-hosted, irisid-imac
```

Secrets (`gh secret set --repo hoyong-irisid/monitoring`):

| Secret | 용도 |
|--------|------|
| `CURSOR_API_KEY` | Cursor CLI ([대시보드](https://cursor.com/settings)) |
| `RESEND_API_KEY` | 이메일 리포트 |
| `TELEGRAM_BOT_TOKEN` | Telegram |
| `TELEGRAM_CHAT_ID` | Telegram |

Variables:

| Variable | 예시 |
|----------|------|
| `REPORT_EMAIL_FROM` | `Iris ID <monitor@mail.irisid.com>` |
| `REPORT_EMAIL_TO` | `ops@irisid.com` |
| `SITE_URL` | `https://irisid.com` |

저장소 푸시:

```bash
git remote add origin https://github.com/hoyong-irisid/monitoring.git
git push -u origin main
```

---

## 설정 파일

- `config/monitor.json` — 페이지, 폼, contentFixes, skip URL
- `AGENTS.md` — Cursor 에이전트용 규칙
- `.github/ISSUE_TEMPLATE/agent-task.yml` — 작업 이슈 템플릿

### 간단한 문구 수정 (contentFixes)

`config/monitor.json` → `contentFixes` 배열. 예시: `config/content-fixes.example.json`

---

## 워크플로

| 파일 | 트리거 |
|------|--------|
| `.github/workflows/agent-from-issue.yml` | 라벨 `agent-task` / 수동 |
| `.github/workflows/daily-monitor.yml` | 매일 cron / 수동 |

---

## runner 없이 테스트

워크플로의 `runs-on: [self-hosted, irisid-imac]` 를 `ubuntu-latest`로 바꾸면 GitHub 호스트에서도 동작하지만, Playwright·사이트 접근은 iMac runner를 권장합니다.

---

## License

Private — Iris ID internal use.
