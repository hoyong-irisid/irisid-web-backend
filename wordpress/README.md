# Iris ID Headless WordPress (Phase 1)

`cms.irisid.com` 헤드리스 백엔드용 mu-plugin + ACF 필드 JSON.

## Deploy to VPS

```bash
# From repo root — set VPS path to cms WordPress install
export WP_ROOT=/home/irisid5/public_html/cms   # adjust via cPanel

rsync -avz --delete \
  wordpress/mu-plugins/ \
  user@173.231.221.180:${WP_ROOT}/wp-content/mu-plugins/
```

Or use `scripts/deploy-wordpress-mu-plugin.sh`.

## Structure

```
mu-plugins/
  irisid-headless.php          # loader
  irisid-headless/
    includes/                  # CPT, lockdown, webhook
    acf-json/                  # ACF field groups (auto-sync)
```

## After deploy

1. WP Admin → **Settings → Permalinks → Save** (flush rewrite rules)
2. **Custom Fields → Tools → Sync** (if field groups show "Sync available")
3. GraphiQL IDE: `https://cms.irisid.com/graphql`

### Site Notices

Admin menu **Site Notices** (`site_notice` CPT) drives the Next.js top bar / center modal.

1. Sync ACF group **Site Notice Fields** (Custom Fields → Field Groups → Sync available)
2. Edit a notice in the **document sidebar** (field group position: side) — scope, style, background color, top-bar layout (stacked / inline), text alignment (left / center / right), optional CTA + schedule
3. **Publish** now or **Schedule** via the WordPress publish box
4. After Update, reload the edit screen and confirm fields still show the values you chose
5. Frontend fetches REST `wp/v2/site_notice` (no-store + cache-bust)

See [docs/PHASE-1-HEADLESS-WP.md](../docs/PHASE-1-HEADLESS-WP.md) for full checklist.
