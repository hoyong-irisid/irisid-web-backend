# Phase 2 — Next.js 프론트 (`irisid-web-frontend`)

> **Repo:** `/Users/hoyonglee/Documents/irisid-web-frontend` — GitHub **`hoyong-irisid/irisid-web-frontend`**  
> **Staging:** `staging.irisid.com` → PM2 port 3000  
> **CMS:** `https://cms.irisid.com/graphql`

---

## 완료 (MVP)

| 항목 | 상태 |
|------|------|
| Next.js 16 App Router + Tailwind | ✅ |
| `trailingSlash: true` (live URL 정책) | ✅ |
| WPGraphQL server fetch | ✅ |
| `/company/contact-us/` + GF form id **1** | ✅ |
| `POST /api/forms/submit/` → `submitGfForm` | ✅ |
| Deploy 가이드 | ✅ `irisid-web-frontend/docs/DEPLOY-STAGING.md` |

---

## 로컬 실행

```bash
cd ../irisid-web-frontend
cp .env.example .env.local
npm install
npm run dev
```

- Home: http://localhost:3000/
- Contact: http://localhost:3000/company/contact-us/

---

## Staging 배포 (다음 수동 작업)

1. GitHub repo push
2. VPS clone + `npm ci && npm run build`
3. PM2 `ecosystem.config.cjs`
4. cPanel `staging.irisid.com` + Apache → `:3000`
5. Contact 제출 → cms **Forms → Entries** 확인

---

## Phase 2b (이후)

- Product / solution / page 라우트 + ISR
- `redirects-301.csv` → `next.config` redirects
- `/api/revalidate` + cms webhook
- Algolia search
- Cloudflare Turnstile on forms

---

## 1b와 병행

콘텐츠 마이그레이션(1b)은 cms에 계속 입력. 프론트는 GraphQL로 **있는 콘텐츠부터** 점진 렌더.
