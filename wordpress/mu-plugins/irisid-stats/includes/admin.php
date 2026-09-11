<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function irisid_stats_register_admin_menu(): void
{
    add_menu_page(
        'Stats at a Glance',
        'Stats Glance',
        'manage_options',
        'irisid-stats',
        'irisid_stats_render_admin_page',
        'dashicons-chart-area',
        58
    );
}

function irisid_stats_register_settings(): void
{
    register_setting('irisid_stats', 'irisid_stats_settings', [
        'type'              => 'array',
        'sanitize_callback' => 'irisid_stats_sanitize_settings',
        'default'           => irisid_stats_default_settings(),
    ]);
}

/**
 * @param mixed $input
 * @return array<string, mixed>
 */
function irisid_stats_sanitize_settings($input): array
{
    $current = irisid_stats_get_settings();
    if (!is_array($input)) {
        return $current;
    }

    $widgets_in = isset($input['widgets']) && is_array($input['widgets']) ? $input['widgets'] : [];
    $widgets = [];
    foreach (['overview', 'pages', 'devices', 'countries', 'cold'] as $key) {
        $widgets[$key] = empty($widgets_in[$key]) ? 0 : 1;
    }

    $settings = [
        'dashboard_url'      => esc_url_raw((string) ($input['dashboard_url'] ?? $current['dashboard_url'])),
        'collect_key'        => (string) ($current['collect_key'] ?? ''),
        'widgets'            => $widgets,
        'stakeholders'       => sanitize_textarea_field((string) ($input['stakeholders'] ?? '')),
        'email_weekly'       => empty($input['email_weekly']) ? 0 : 1,
        'email_monthly'      => empty($input['email_monthly']) ? 0 : 1,
        'collect_enabled'    => empty($input['collect_enabled']) ? 0 : 1,
    ];

    if (!empty($input['rotate_key'])) {
        $new_key = wp_generate_password(32, false, false);
        update_option('irisid_stats_collect_key', $new_key);
        $settings['collect_key'] = $new_key;
    }

    // Password reset field is write-only: it is never pre-filled with the current
    // value (which is hashed anyway) and only touches the real /admin credential
    // when the WP admin actually types a new one here.
    $reset_password = trim((string) ($input['reset_dashboard_password'] ?? ''));
    if ($reset_password !== '') {
        if (strlen($reset_password) < 10 || !function_exists('irisid_store_dashboard_password')) {
            add_settings_error(
                'irisid_stats_settings',
                'irisid_stats_password_too_short',
                'Admin dashboard password was not changed: it must be at least 10 characters.',
                'error'
            );
        } else {
            irisid_store_dashboard_password($reset_password);
            add_settings_error(
                'irisid_stats_settings',
                'irisid_stats_password_reset',
                'Admin dashboard (/admin) password updated.',
                'success'
            );
        }
    }

    return $settings;
}

function irisid_stats_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = irisid_stats_get_settings();
    $summary = irisid_stats_build_summary('month');
    ?>
    <div class="wrap">
      <h1>Stats at a Glance</h1>
      <p>Configure the password-protected frontend dashboard and stakeholder email digests. Traffic is collected from the Next.js site via a secure beacon.</p>
      <?php settings_errors('irisid_stats_settings'); ?>

      <div class="card" style="max-width:720px;padding:16px;margin:16px 0;">
        <h2 style="margin-top:0;">Last 30 days (live)</h2>
        <p>
          Sessions: <strong><?php echo esc_html((string) $summary['overview']['sessions']); ?></strong>
          · Pageviews: <strong><?php echo esc_html((string) $summary['overview']['pageviews']); ?></strong>
          · Bounce: <strong><?php echo esc_html((string) $summary['overview']['bounceRate']); ?>%</strong>
        </p>
        <p>
          <a class="button button-primary" href="<?php echo esc_url((string) $settings['dashboard_url']); ?>" target="_blank" rel="noopener noreferrer">Open dashboard</a>
        </p>
      </div>

      <form method="post" action="options.php">
        <?php settings_fields('irisid_stats'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="irisid_stats_dashboard_url">Dashboard URL</label></th>
            <td>
              <input name="irisid_stats_settings[dashboard_url]" id="irisid_stats_dashboard_url" type="url" class="regular-text" value="<?php echo esc_attr((string) $settings['dashboard_url']); ?>" />
              <p class="description">Frontend Stats page (e.g. https://staging.irisid.com/stats/).</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="irisid_stats_reset_dashboard_password">Reset admin (/admin) password</label></th>
            <td>
              <input name="irisid_stats_settings[reset_dashboard_password]" id="irisid_stats_reset_dashboard_password" type="text" class="regular-text" autocomplete="off" value="" placeholder="Leave blank to keep the current password" />
              <p class="description">Write-only: sets a new password for the Next.js <code>/admin</code> login (min. 10 characters). Use this to recover access if the password is lost — the current value is never shown here.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Collect key</th>
            <td>
              <code><?php echo esc_html((string) $settings['collect_key']); ?></code>
              <p class="description">Set as <code>STATS_COLLECT_KEY</code> on Next.js. Optional: rotate below.</p>
              <label><input type="checkbox" name="irisid_stats_settings[rotate_key]" value="1" /> Rotate key on save</label>
            </td>
          </tr>
          <tr>
            <th scope="row">Collection</th>
            <td>
              <label><input type="checkbox" name="irisid_stats_settings[collect_enabled]" value="1" <?php checked(!empty($settings['collect_enabled'])); ?> /> Enable pageview collection</label>
            </td>
          </tr>
          <tr>
            <th scope="row">Dashboard widgets</th>
            <td>
              <?php foreach ([
                  'overview' => 'Overview metrics',
                  'pages' => 'Top pages',
                  'cold' => 'Low-traffic pages',
                  'devices' => 'Device breakdown',
                  'countries' => 'Country / region',
              ] as $key => $label) : ?>
                <label style="display:block;margin-bottom:4px;">
                  <input type="checkbox" name="irisid_stats_settings[widgets][<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($settings['widgets'][$key])); ?> />
                  <?php echo esc_html($label); ?>
                </label>
              <?php endforeach; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="irisid_stats_stakeholders">Stakeholder emails</label></th>
            <td>
              <textarea name="irisid_stats_settings[stakeholders]" id="irisid_stats_stakeholders" class="large-text" rows="4"><?php echo esc_textarea((string) $settings['stakeholders']); ?></textarea>
              <p class="description">One email per line. Used for weekly / monthly digests.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Email digests</th>
            <td>
              <label style="display:block;"><input type="checkbox" name="irisid_stats_settings[email_weekly]" value="1" <?php checked(!empty($settings['email_weekly'])); ?> /> Weekly summary (Mondays)</label>
              <label style="display:block;margin-top:4px;"><input type="checkbox" name="irisid_stats_settings[email_monthly]" value="1" <?php checked(!empty($settings['email_monthly'])); ?> /> Monthly summary (1st of month)</label>
            </td>
          </tr>
        </table>
        <?php submit_button('Save settings'); ?>
      </form>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:24px;">
        <?php wp_nonce_field('irisid_stats_test_email'); ?>
        <input type="hidden" name="action" value="irisid_stats_test_email" />
        <?php submit_button('Send test weekly email now', 'secondary'); ?>
      </form>
    </div>
    <?php
}

add_action('admin_post_irisid_stats_test_email', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden');
    }
    check_admin_referer('irisid_stats_test_email');
    irisid_stats_send_digest_email('week', true);
    wp_safe_redirect(add_query_arg('irisid_stats_emailed', '1', admin_url('admin.php?page=irisid-stats')));
    exit;
});
