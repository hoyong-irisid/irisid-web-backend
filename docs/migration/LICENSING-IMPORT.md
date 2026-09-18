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

1. **Tools → Import → WordPress** (install importer if needed).
2. Import `cd-keys.xml`, then `reference-numbers.xml`.
   - Map authors to an existing CMS admin.
   - Download/import attachments: **No** (licensing posts are titles + meta only).
3. If ACF groups are missing after mu-plugin deploy: sync from JSON, or import `acf-field-groups.xml` then `acf-fields.xml`.
4. Spot-check:
   - **IrisID License Check → CD Keys** – titles look like CD keys; ACF Product / Users / Claim Status present.
   - **Reference Numbers** – 14-char titles; Product / License Parameter / Claim Status present.

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

## Behavior parity notes

- CD Key lookup at **10 or 25** characters (prefix match on first 10).
- Reference Number lookup at exact **14** characters.
- Submit still accepted if key not found or already claimed; email body includes a warning; claim only when found and Unclaimed.
- Claim timestamp uses America/New_York (`m/d/Y g:i a`), matching live.
