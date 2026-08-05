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

    $ov = $summary['overview'];
    $lines = [
        sprintf('%s traffic summary (%s → %s)', $label, $summary['from'], $summary['to']),
        '',
        sprintf('Sessions: %s (%s%% vs prior)', number_format_i18n((int) $ov['sessions']), (string) $ov['deltas']['sessions']),
        sprintf('Pageviews: %s (%s%% vs prior)', number_format_i18n((int) $ov['pageviews']), (string) $ov['deltas']['pageviews']),
        sprintf('Users: %s · New users: %s', number_format_i18n((int) $ov['users']), number_format_i18n((int) $ov['newUsers'])),
        sprintf('Avg session: %ss · Bounce rate: %s%%', (string) $ov['avgSessionDuration'], (string) $ov['bounceRate']),
        '',
        'Top pages:',
    ];

    foreach (array_slice($summary['topPages'], 0, 8) as $page) {
        $lines[] = sprintf(
            '- %s (%s) — %s views',
            $page['path'],
            $page['title'] !== '' ? $page['title'] : 'untitled',
            number_format_i18n((int) $page['views'])
        );
    }

    if ($dashboard !== '') {
        $lines[] = '';
        $lines[] = 'Full dashboard: ' . $dashboard;
    }

    $body = implode("\n", $lines);
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    $ok = true;
    foreach ($emails as $email) {
        $sent = wp_mail($email, $subject, $body, $headers);
        $ok = $ok && $sent;
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
