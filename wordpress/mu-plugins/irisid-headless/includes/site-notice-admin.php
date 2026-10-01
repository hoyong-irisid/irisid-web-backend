<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Site Notices list columns + lean edit screen (OLM-style schedule helpers). */

add_filter('manage_edit-site_notice_columns', 'irisid_site_notice_admin_columns');
add_action('manage_site_notice_posts_custom_column', 'irisid_site_notice_admin_column_content', 10, 2);

/**
 * Drop Revisions + Slug meta boxes – they clutter the notice editor and are
 * unused for this CPT (supports no longer includes revisions).
 */
add_action('add_meta_boxes', 'irisid_remove_site_notice_clutter_metaboxes', 99);

function irisid_remove_site_notice_clutter_metaboxes(): void
{
    remove_meta_box('revisionsdiv', 'site_notice', 'normal');
    remove_meta_box('slugdiv', 'site_notice', 'normal');
    remove_meta_box('slugdiv', 'site_notice', 'side');
}

/**
 * @param array<string, string> $columns
 * @return array<string, string>
 */
function irisid_site_notice_admin_columns(array $columns): array
{
    $with_new = [];
    foreach ($columns as $key => $label) {
        $with_new[$key] = $label;
        if ($key === 'date') {
            $with_new['irisid_duration'] = 'Period';
            $with_new['irisid_status'] = 'Status';
        }
    }
    if (!isset($with_new['irisid_duration'])) {
        $with_new['irisid_duration'] = 'Period';
        $with_new['irisid_status'] = 'Status';
    }
    return $with_new;
}

function irisid_site_notice_admin_column_content(string $column, int $post_id): void
{
    if ($column === 'irisid_duration') {
        echo esc_html(irisid_site_notice_duration_label($post_id));
        return;
    }
    if ($column === 'irisid_status') {
        [$label, $color] = irisid_site_notice_status_label($post_id);
        printf('<span style="color:%s;font-weight:600;">%s</span>', esc_attr($color), esc_html($label));
    }
}

/** Parse ACF Y-m-d (or legacy Y-m-d H:i:s) as Eastern Time. */
function irisid_site_notice_et_date(string $value, string $edge = 'start'): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    // Date-only → start 00:00:00 / end 23:59:59 ET.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $value .= $edge === 'end' ? ' 23:59:59' : ' 00:00:00';
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('America/New_York'));
    } catch (Exception $e) {
        return null;
    }
}

function irisid_site_notice_duration_label(int $post_id): string
{
    $starts = irisid_site_notice_et_date((string) get_post_meta($post_id, 'starts_at', true), 'start');
    $ends = irisid_site_notice_et_date((string) get_post_meta($post_id, 'ends_at', true), 'end');

    $format = static fn (DateTimeImmutable $d): string => $d->format('M j, Y');

    if ($starts && $ends) {
        return $format($starts) . ' – ' . $format($ends);
    }
    if ($starts) {
        return $format($starts) . ' – no end';
    }
    if ($ends) {
        return 'Always until ' . $format($ends);
    }
    return 'Always (while published)';
}

/** @return array{0: string, 1: string} [label, CSS color] */
function irisid_site_notice_status_label(int $post_id): array
{
    $status = get_post_status($post_id);
    if ($status !== 'publish') {
        return [ucfirst((string) $status), '#71717a'];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $starts = irisid_site_notice_et_date((string) get_post_meta($post_id, 'starts_at', true), 'start');
    $ends = irisid_site_notice_et_date((string) get_post_meta($post_id, 'ends_at', true), 'end');

    if ($starts && $now < $starts) {
        return ['Scheduled', '#2563eb'];
    }
    if ($ends && $now > $ends) {
        return ['Ended', '#a1a1aa'];
    }
    return ['Live', '#16a34a'];
}

/**
 * Schedule quick-select buttons (Always / Today / 7 days / 30 days) next to
 * the Start date field – mirrors OLM Market 알림배너.
 */
add_action('admin_enqueue_scripts', 'irisid_site_notice_schedule_presets_script');

function irisid_site_notice_schedule_presets_script(string $hook): void
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'site_notice') {
        return;
    }

    wp_add_inline_script(
        'jquery-core',
        irisid_site_notice_schedule_presets_js(),
        'after'
    );
}

function irisid_site_notice_schedule_presets_js(): string
{
    return <<<'JS'
    (function () {
        function etTodayYmd() {
            // Format "now" as America/New_York calendar date.
            try {
                var parts = new Intl.DateTimeFormat('en-CA', {
                    timeZone: 'America/New_York',
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit'
                }).formatToParts(new Date());
                var y = parts.find(function (p) { return p.type === 'year'; }).value;
                var m = parts.find(function (p) { return p.type === 'month'; }).value;
                var d = parts.find(function (p) { return p.type === 'day'; }).value;
                return y + '-' + m + '-' + d;
            } catch (e) {
                var n = new Date();
                var mm = String(n.getMonth() + 1).padStart(2, '0');
                var dd = String(n.getDate()).padStart(2, '0');
                return n.getFullYear() + '-' + mm + '-' + dd;
            }
        }

        function addDaysYmd(ymd, days) {
            var bits = ymd.split('-').map(Number);
            var dt = new Date(Date.UTC(bits[0], bits[1] - 1, bits[2]));
            dt.setUTCDate(dt.getUTCDate() + days);
            var y = dt.getUTCFullYear();
            var m = String(dt.getUTCMonth() + 1).padStart(2, '0');
            var d = String(dt.getUTCDate()).padStart(2, '0');
            return y + '-' + m + '-' + d;
        }

        function injectStyles() {
            if (document.getElementById('irisid-notice-preset-styles')) return;
            var style = document.createElement('style');
            style.id = 'irisid-notice-preset-styles';
            // Keep presets inside an ACF .acf-field so Schedule-tab hide/show
            // applies, and inherit the same horizontal padding as other fields.
            style.textContent =
                '.acf-field[data-key="field_irisid_notice_schedule_presets"] .irisid-notice-presets{' +
                'display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 0;}' +
                '.acf-field[data-key="field_irisid_notice_schedule_presets"] .irisid-notice-presets button{' +
                'appearance:none;border:1px solid #c3c4c7;background:#f6f7f7;' +
                'color:#1d2327;border-radius:3px;padding:4px 10px;font-size:12px;cursor:pointer;line-height:1.4;}' +
                '.acf-field[data-key="field_irisid_notice_schedule_presets"] .irisid-notice-presets button:hover{' +
                'background:#fff;border-color:#8c8f94;}' +
                '.acf-field[data-key="field_irisid_notice_schedule_presets"] .irisid-notice-presets button.is-active{' +
                'background:#2271b1;border-color:#2271b1;color:#fff;}' +
                '.acf-field[data-key="field_irisid_notice_schedule_presets"] .irisid-notice-period-hint{' +
                'margin:10px 0 0;padding:8px 10px;background:#f0f0f1;border-radius:3px;' +
                'font-size:12px;color:#50575e;}';
            document.head.appendChild(style);
        }

        function periodHint(starts, ends) {
            if (!starts && !ends) return 'Period: Always (while published)';
            if (starts && ends && starts === ends) return 'Period: ' + starts + ' only';
            if (starts && ends) return 'Period: ' + starts + ' – ' + ends;
            if (starts) return 'Period: from ' + starts + ' (no end)';
            return 'Period: until ' + ends;
        }

        function boot() {
            if (typeof acf === 'undefined') return;
            acf.addAction('ready', function () {
                var startsField = acf.getField('field_irisid_notice_starts_at');
                var endsField = acf.getField('field_irisid_notice_ends_at');
                if (!startsField || !endsField) return;
                if (document.getElementById('irisid-notice-presets')) return;

                // Mount inside the Schedule-tab "Exposure period" message field so
                // ACF's tab show/hide owns visibility (siblings of .acf-field stay
                // visible on Content / Appearance).
                var host = document.querySelector(
                    '.acf-field[data-key="field_irisid_notice_schedule_presets"] .acf-input'
                );
                if (!host) return;

                injectStyles();

                var wrap = document.createElement('div');
                wrap.id = 'irisid-notice-presets';
                wrap.className = 'irisid-notice-presets';
                wrap.setAttribute('role', 'group');
                wrap.setAttribute('aria-label', 'Exposure period presets');

                var presets = [
                    { id: 'always', label: 'Always', apply: function () { startsField.val(''); endsField.val(''); } },
                    { id: 'today', label: 'Today only', apply: function () {
                        var t = etTodayYmd();
                        startsField.val(t);
                        endsField.val(t);
                    }},
                    { id: '7d', label: '7 days from today', apply: function () {
                        var t = etTodayYmd();
                        startsField.val(t);
                        endsField.val(addDaysYmd(t, 6));
                    }},
                    { id: '30d', label: '30 days from today', apply: function () {
                        var t = etTodayYmd();
                        startsField.val(t);
                        endsField.val(addDaysYmd(t, 29));
                    }}
                ];

                var hint = document.createElement('div');
                hint.className = 'irisid-notice-period-hint';

                function refreshHint() {
                    hint.textContent = periodHint(
                        String(startsField.val() || '').trim(),
                        String(endsField.val() || '').trim()
                    );
                }

                presets.forEach(function (preset) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = preset.label;
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        preset.apply();
                        wrap.querySelectorAll('button').forEach(function (b) {
                            b.classList.remove('is-active');
                        });
                        btn.classList.add('is-active');
                        refreshHint();
                    });
                    wrap.appendChild(btn);
                });

                host.appendChild(wrap);
                host.appendChild(hint);

                startsField.on('change', refreshHint);
                endsField.on('change', refreshHint);
                refreshHint();
            });
        }

        if (window.acf) {
            boot();
        } else {
            document.addEventListener('DOMContentLoaded', boot);
        }
    })();
    JS;
}
