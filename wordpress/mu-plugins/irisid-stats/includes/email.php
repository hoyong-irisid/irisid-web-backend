<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function irisid_stats_send_weekly_email(): void
{
    $settings = irisid_stats_get_settings();
    if (empty($settings['email_weekly'])) {
        return;
    }
    irisid_stats_send_digest_email('week');
}

function irisid_stats_send_monthly_email(): void
{
    $settings = irisid_stats_get_settings();
    if (empty($settings['email_monthly'])) {
        return;
    }
    irisid_stats_send_digest_email('month');
}

function irisid_stats_stakeholder_emails(): array
{
    $settings = irisid_stats_get_settings();
    $raw = (string) ($settings['stakeholders'] ?? '');
    $parts = preg_split('/[\s,;]+/', $raw) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = sanitize_email($part);
        if (is_email($email)) {
            $emails[] = $email;
        }
    }
    return array_values(array_unique($emails));
}

/**
 * @param float|int|string $delta
 */
function irisid_stats_format_delta($delta): string
{
    $n = (float) $delta;
    $sign = $n > 0 ? '+' : '';
    return $sign . rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.') . '%';
}

/**
 * @param float|int|string $delta
 */
function irisid_stats_delta_color($delta): string
{
    $n = (float) $delta;
    if ($n > 0) {
        return '#0f7a3a';
    }
    if ($n < 0) {
        return '#b42318';
    }
    return '#71717a';
}

function irisid_stats_format_range_label(string $from, string $to): string
{
    $fromTs = strtotime($from . ' UTC') ?: strtotime($from);
    $toTs = strtotime($to . ' UTC') ?: strtotime($to);
    if (!$fromTs || !$toTs) {
        return $from . ' – ' . $to;
    }
    return gmdate('M j, Y', $fromTs) . ' – ' . gmdate('M j, Y', $toTs);
}

function irisid_stats_format_duration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    if ($m < 60) {
        return $s > 0 ? sprintf('%dm %ds', $m, $s) : sprintf('%dm', $m);
    }
    $h = intdiv($m, 60);
    $rm = $m % 60;
    return $rm > 0 ? sprintf('%dh %dm', $h, $rm) : sprintf('%dh', $h);
}

/**
 * @param array<string, mixed> $summary
 */
function irisid_stats_build_digest_text(array $summary, string $label, string $dashboard): string
{
    $ov = $summary['overview'];
    $lines = [
        sprintf('%s traffic summary (%s)', $label, irisid_stats_format_range_label((string) $summary['from'], (string) $summary['to'])),
        '',
        sprintf('Sessions: %s (%s vs prior)', number_format_i18n((int) $ov['sessions']), irisid_stats_format_delta($ov['deltas']['sessions'])),
        sprintf('Pageviews: %s (%s vs prior)', number_format_i18n((int) $ov['pageviews']), irisid_stats_format_delta($ov['deltas']['pageviews'])),
        sprintf('Users: %s', number_format_i18n((int) $ov['users'])),
        sprintf('New users: %s', number_format_i18n((int) $ov['newUsers'])),
        sprintf('Avg session duration: %s', irisid_stats_format_duration((int) $ov['avgSessionDuration'])),
        sprintf('Bounce rate: %s%%', (string) $ov['bounceRate']),
        '',
        'Top pages',
    ];

    foreach (array_slice($summary['topPages'], 0, 8) as $page) {
        $lines[] = sprintf(
            '- %s (%s) – %s views',
            $page['path'],
            $page['title'] !== '' ? $page['title'] : 'untitled',
            number_format_i18n((int) $page['views'])
        );
    }

    if ($dashboard !== '') {
        $lines[] = '';
        $lines[] = 'Full dashboard: ' . $dashboard;
    }

    return implode("\n", $lines);
}

/**
 * @param array<string, mixed> $summary
 */
function irisid_stats_build_digest_html(array $summary, string $label, string $dashboard): string
{
    $ov = $summary['overview'];
    $rangeLabel = esc_html(irisid_stats_format_range_label((string) $summary['from'], (string) $summary['to']));
    $sessionsDelta = irisid_stats_format_delta($ov['deltas']['sessions']);
    $pageviewsDelta = irisid_stats_format_delta($ov['deltas']['pageviews']);
    $sessionsColor = irisid_stats_delta_color($ov['deltas']['sessions']);
    $pageviewsColor = irisid_stats_delta_color($ov['deltas']['pageviews']);

    $metrics = [
        [
            'label' => 'Sessions',
            'value' => number_format_i18n((int) $ov['sessions']),
            'meta' => $sessionsDelta . ' vs prior',
            'metaColor' => $sessionsColor,
        ],
        [
            'label' => 'Pageviews',
            'value' => number_format_i18n((int) $ov['pageviews']),
            'meta' => $pageviewsDelta . ' vs prior',
            'metaColor' => $pageviewsColor,
        ],
        [
            'label' => 'Users',
            'value' => number_format_i18n((int) $ov['users']),
            'meta' => number_format_i18n((int) $ov['newUsers']) . ' new',
            'metaColor' => '#71717a',
        ],
        [
            'label' => 'Avg session',
            'value' => irisid_stats_format_duration((int) $ov['avgSessionDuration']),
            'meta' => 'Bounce ' . (string) $ov['bounceRate'] . '%',
            'metaColor' => '#71717a',
        ],
    ];

    $metricCells = '';
    foreach (array_chunk($metrics, 2) as $row) {
        $metricCells .= '<tr>';
        foreach ($row as $i => $metric) {
            $pad = $i === 0 ? 'padding:0 8px 8px 0;' : 'padding:0 0 8px 8px;';
            $metricCells .= sprintf(
                '<td width="50%%" valign="top" style="%s">
                    <table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background:#fafafa;border:1px solid #e4e4e7;border-radius:8px;">
                      <tr>
                        <td style="padding:18px 16px;">
                          <div style="font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:#71717a;font-weight:600;">%s</div>
                          <div style="margin-top:8px;font-size:28px;line-height:1.1;font-weight:300;color:#0a0a0a;">%s</div>
                          <div style="margin-top:8px;font-size:13px;color:%s;">%s</div>
                        </td>
                      </tr>
                    </table>
                  </td>',
                $pad,
                esc_html($metric['label']),
                esc_html($metric['value']),
                esc_attr($metric['metaColor']),
                esc_html($metric['meta'])
            );
        }
        if (count($row) === 1) {
            $metricCells .= '<td width="50%"></td>';
        }
        $metricCells .= '</tr>';
    }

    $pageRows = '';
    $rank = 1;
    foreach (array_slice($summary['topPages'], 0, 8) as $page) {
        $title = $page['title'] !== '' ? (string) $page['title'] : 'untitled';
        $bg = $rank % 2 === 0 ? '#fafafa' : '#ffffff';
        $pageRows .= sprintf(
            '<tr>
                <td style="padding:12px 14px;border-bottom:1px solid #e4e4e7;background:%s;width:28px;color:#a1a1aa;font-size:12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">%02d</td>
                <td style="padding:12px 14px;border-bottom:1px solid #e4e4e7;background:%s;">
                  <div style="font-size:14px;color:#0a0a0a;font-weight:500;line-height:1.35;">%s</div>
                  <div style="margin-top:3px;font-size:12px;color:#71717a;word-break:break-all;">%s</div>
                </td>
                <td align="right" style="padding:12px 14px;border-bottom:1px solid #e4e4e7;background:%s;white-space:nowrap;font-size:14px;color:#0a0a0a;font-weight:600;">%s</td>
              </tr>',
            $bg,
            $rank,
            $bg,
            esc_html($title),
            esc_html((string) $page['path']),
            $bg,
            esc_html(number_format_i18n((int) $page['views']))
        );
        $rank++;
    }

    if ($pageRows === '') {
        $pageRows = '<tr><td colspan="3" style="padding:16px;color:#71717a;font-size:14px;">No pageviews in this period.</td></tr>';
    }

    $cta = '';
    if ($dashboard !== '') {
        $cta = sprintf(
            '<tr>
                <td style="padding:8px 32px 32px;">
                  <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="background:#0a0a0a;border-radius:4px;">
                        <a href="%s" style="display:inline-block;padding:12px 22px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;text-decoration:none;color:#ffffff;font-weight:600;">
                          Open full dashboard
                        </a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>',
            esc_url($dashboard)
        );
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Iris ID ' . esc_html($label) . ' stats</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;color:#0a0a0a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #e4e4e7;border-radius:12px;overflow:hidden;">
          <tr>
            <td style="padding:28px 32px 20px;border-bottom:1px solid #e4e4e7;background:#0a0a0a;">
              <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#a1a1aa;font-weight:600;">Iris ID · Stats at a Glance</div>
              <div style="margin-top:10px;font-size:26px;line-height:1.2;font-weight:300;color:#ffffff;">' . esc_html($label) . ' traffic summary</div>
              <div style="margin-top:8px;font-size:14px;color:#a1a1aa;">' . $rangeLabel . '</div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px 8px;">
              <div style="font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:#71717a;font-weight:600;margin-bottom:14px;">Overview</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                ' . $metricCells . '
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 32px 8px;">
              <div style="font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:#71717a;font-weight:600;margin-bottom:12px;">Top pages</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;">
                <tr>
                  <td style="padding:10px 14px;background:#f4f4f5;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#71717a;font-weight:600;border-bottom:1px solid #e4e4e7;">#</td>
                  <td style="padding:10px 14px;background:#f4f4f5;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#71717a;font-weight:600;border-bottom:1px solid #e4e4e7;">Page</td>
                  <td align="right" style="padding:10px 14px;background:#f4f4f5;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#71717a;font-weight:600;border-bottom:1px solid #e4e4e7;">Views</td>
                </tr>
                ' . $pageRows . '
              </table>
            </td>
          </tr>
          ' . $cta . '
          <tr>
            <td style="padding:18px 32px 24px;border-top:1px solid #e4e4e7;background:#fafafa;">
              <div style="font-size:12px;line-height:1.5;color:#71717a;">
                First-party staging analytics for Iris ID stakeholders. Reply if you need a widget or range adjusted.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function irisid_stats_send_digest_email(string $range = 'week', bool $force = false): bool
{
    $emails = irisid_stats_stakeholder_emails();
    if ($emails === []) {
        return false;
    }

    $summary = irisid_stats_build_summary($range);
    $settings = irisid_stats_get_settings();
    $label = $range === 'month' ? 'Monthly' : 'Weekly';
    $subject = sprintf('[Iris ID] %s stats summary', $label);
    $dashboard = (string) ($settings['dashboard_url'] ?? '');

    $textBody = irisid_stats_build_digest_text($summary, $label, $dashboard);
    $htmlBody = irisid_stats_build_digest_html($summary, $label, $dashboard);

    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $phpmailerCallback = static function ($phpmailer) use ($textBody): void {
        if (is_object($phpmailer) && property_exists($phpmailer, 'AltBody')) {
            $phpmailer->AltBody = $textBody;
        }
    };
    add_action('phpmailer_init', $phpmailerCallback);

    $ok = true;
    try {
        foreach ($emails as $email) {
            $sent = wp_mail($email, $subject, $htmlBody, $headers);
            $ok = $ok && $sent;
        }
    } finally {
        remove_action('phpmailer_init', $phpmailerCallback);
    }

    if ($force || $ok) {
        update_option('irisid_stats_last_email_' . $range, [
            'at' => current_time('mysql', true),
            'recipients' => $emails,
            'ok' => $ok,
        ]);
    }

    return $ok;
}
