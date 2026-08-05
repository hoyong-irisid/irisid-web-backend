# Stats at a Glance

Password-protected traffic dashboard for Iris ID stakeholders.

## Pieces

| Piece | Location |
|---|---|
| WP mu-plugin | `wordpress/mu-plugins/irisid-stats.php` + `irisid-stats/` |
| Frontend page | `/stats/` (Next.js) |
| Collect beacon | `StatsBeacon` in locale layout → `POST /api/stats/collect` → CMS REST |
| Email digests | WP-Cron weekly (Mon) / monthly (1st) via `wp_mail` |

## CMS setup

1. Upload `irisid-stats.php` and the `irisid-stats/` folder into `wp-content/mu-plugins/` on cms.irisid.com (same place as `irisid-headless`).
2. Visit **WP Admin → Stats Glance**.
3. Copy **Collect key** into the frontend env as `STATS_COLLECT_KEY`.
4. Set **Dashboard password** and match it on Next as `STATS_DASHBOARD_PASSWORD`.
5. Set **Dashboard URL** (e.g. `https://staging.irisid.com/stats/`).
6. Add stakeholder emails (one per line) and enable weekly/monthly digests.
7. Toggle which widgets appear on the dashboard.

## Frontend env

```bash
STATS_DASHBOARD_PASSWORD=your-shared-password
STATS_COLLECT_KEY=paste-from-wp-admin
# optional if not derived from WPGRAPHQL_URL:
# WORDPRESS_CMS_REST_URL=https://cms.irisid.com/wp-json
```

Open `/stats/` and unlock with the dashboard password.

## What is collected

Anonymous pageviews: path, title, locale, referrer, device (UA), country (CF-IPCountry / Vercel when available), session id.

Bots are skipped. This is first-party analytics for the headless site – not a GA4 replacement, but works without MonsterInsights API keys.

## Email

Uses WordPress `wp_mail` (Resend/SMTP already on CMS). Use **Send test weekly email now** on the Stats Glance settings screen after stakeholders are saved.
