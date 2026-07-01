# AGENTS.md — Iris ID backend (`irisid-web-backend`)

## Purpose

Backend / ops for the Iris ID headless renewal:

- **cms** — WordPress mu-plugin (CPT, ACF, GraphQL, GF) deployed to `cms.irisid.com`
- **monitor** — Playwright health checks for live site via GitHub Actions
- **docs** — content model, migration, phase checklists

## Layout

- `wordpress/mu-plugins/` — headless WP plugin → deploy to cms
- `src/` — Playwright monitor (health, links, forms)
- `config/monitor.json` — URLs, forms, content-fix definitions
- `scripts/ci/` — Cursor CLI + test + commit + notify
- `docs/` — CONTENT-MODEL, Phase 1/1b/2, migration URLs
- `.github/workflows/` — `daily-monitor.yml`, `agent-from-issue.yml`

## Frontend (separate folder)

Next.js lives in **`../irisid-web-frontend`** (`Documents/irisid-web-frontend`).

| Task | Repo |
|------|------|
| Product pages, Contact UI, staging deploy | **irisid-web-frontend** |
| mu-plugin, migration docs, daily monitor | **irisid-web-backend** (this repo) |

See `docs/PHASE-2-NEXTJS.md` and `../irisid-web-frontend/AGENTS.md`.

## When running in CI (Cursor CLI)

- Follow the GitHub Issue literally; minimal diffs.
- Do **not** run `git`, `gh`, or deploy commands — CI commits.
- Do **not** edit `.env` or commit secrets.
- After edits, `npm run typecheck` must pass.

## Commands

```bash
npm run typecheck
npm run monitor:dry
npm run monitor
```
