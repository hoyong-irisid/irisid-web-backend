# Licensing data import (CMS)

Headless port of live `irisid-key-check` (CF7 form 10). Frontend: Next.js `/licensing/` (not in main nav). CMS: CPTs `cd_keys` / `reference_numbers` + REST under `irisid/v1/licensing/*`.

## Prerequisites

1. Deploy updated mu-plugin (`irisid-headless`) so CPTs and REST routes exist.
2. Confirm ACF JSON groups synced (Custom Fields → Sync if prompted):
   - `group_irisid_cd_key.json` – CD Key Information
   - `group_irisid_reference_number.json` – Reference Number Information
3. Do **not** install the old `irisid-key-check` plugin on cms.irisid.com (would double-register CPTs).

## WXR files (`licensing-wxr/`)

| File | Contents |
|------|----------|
| `cd-keys.xml` | 2889 CD Key posts |
| `reference-numbers.xml` | 77 Reference Number posts |
| `acf-field-groups.xml` | ACF field group posts (optional if ACF JSON sync works) |
| `acf-fields.xml` | ACF field posts (optional if ACF JSON sync works) |

Prefer **ACF JSON sync** for field definitions. Use WXR ACF exports only if sync does not create the groups.

## Import order (WordPress admin on cms.irisid.com)

`cd-keys.xml` is ~6MB / ~2889 posts. The browser importer often fails with:

- **File is empty** → PHP `upload_max_filesize` / `post_max_size` too small
- **504 Gateway Time-out** → nginx/PHP timeout while importing thousands of posts

### A. Small file via UI (Reference Numbers)

1. **Tools → Import → WordPress**
2. Upload `reference-numbers.xml` (~181KB) only
3. Map author → existing admin; attachments **No**

### B. Large file via SSH + WP-CLI (CD Keys) – recommended

From your Mac (adjust `WP_ROOT` if needed):

```bash
# 1) Upload WXR to the server
scp docs/migration/licensing-wxr/cd-keys.xml \
  irisid5@173.231.221.180:/home/irisid5/tmp-cd-keys.xml

# 2) Import on the VPS (WordPress root = cms docroot)
ssh irisid5@173.231.221.180
cd /home/irisid5/cms.irisid.com   # or public_html/cms – same as WP_ROOT
wp import /home/irisid5/tmp-cd-keys.xml --authors=create
# If authors already exist, use: --authors=skip
# Or map: --authors=irisid5:admin
```

If `wp` is not in PATH, try `~/bin/wp` or `/usr/local/bin/wp`. Run in `screen`/`tmux` so SSH drops do not kill a long import.

### C. UI-only workaround (if no SSH)

In cPanel → **MultiPHP INI Editor** for `cms.irisid.com`:

- `upload_max_filesize = 64M`
- `post_max_size = 64M`
- `max_execution_time = 600`
- `memory_limit = 512M`

Also raise nginx/`ProxyTimeout` if you control it. Then retry Tools → Import. Still prefer WP-CLI for reliability.


## REST smoke tests

```bash
# CD key (first 10 chars of a known key)
curl -s -X POST https://cms.irisid.com/wp-json/irisid/v1/licensing/check-cd-key \
  -H 'Content-Type: application/json' \
  -d '{"cdKey":"XXXXXXXXXX"}'

# Reference number (full 14 chars)
curl -s -X POST https://cms.irisid.com/wp-json/irisid/v1/licensing/check-ref-number \
  -H 'Content-Type: application/json' \
  -d '{"refNumber":"XXXXXXXXXXXXXX"}'
```

Frontend proxies:

- `POST /api/licensing/lookup/` → CMS check endpoints
- `POST /api/licensing/submit/` → CMS submit (claims key + emails `licensing@irisid.com`)

## Admin UI (parity with live)

After deploy, WordPress admin shows **IrisID License Check** with:

| CPT | List columns |
|-----|----------------|
| CD Keys | Title, Product, Users, Claim Status, Date Claimed |
| Reference Numbers | Title, Product, License Parameter, Claim Status, Date Claimed |

Empty claim dates show `–`. Bulk actions are disabled (same as live).
ACF field groups include the same meta fields as live; sync JSON after mu-plugin deploy.

