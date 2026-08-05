<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @return array<string, mixed> */
function irisid_stats_default_settings(): array
{
    return [
        'dashboard_password' => 'irisid-stats',
        'dashboard_url'      => 'https://staging.irisid.com/stats/',
        'collect_key'        => '',
        'widgets'            => [
            'overview'  => 1,
            'pages'     => 1,
            'devices'   => 1,
            'countries' => 1,
            'cold'      => 1,
        ],
        'stakeholders'       => '',
        'email_weekly'       => 1,
        'email_monthly'      => 1,
        'collect_enabled'    => 1,
    ];
}

/** @return array<string, mixed> */
function irisid_stats_get_settings(): array
{
    $stored = get_option('irisid_stats_settings', []);
    if (!is_array($stored)) {
        $stored = [];
    }
    $settings = array_merge(irisid_stats_default_settings(), $stored);
    $key = get_option('irisid_stats_collect_key', '');
    if (is_string($key) && $key !== '') {
        $settings['collect_key'] = $key;
    }
    if (!is_array($settings['widgets'])) {
        $settings['widgets'] = irisid_stats_default_settings()['widgets'];
    }
    return $settings;
}

function irisid_stats_parse_device(?string $ua): string
{
    $ua = strtolower((string) $ua);
    if ($ua === '') {
        return 'desktop';
    }
    if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $ua)) {
        return 'bot';
    }
    if (preg_match('/ipad|tablet|kindle|silk|(android(?!.*mobile))/i', $ua)) {
        return 'tablet';
    }
    if (preg_match('/mobi|iphone|ipod|android.*mobile|windows phone/i', $ua)) {
        return 'mobile';
    }
    return 'desktop';
}

function irisid_stats_normalize_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '/';
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    // Strip locale prefix: /en/... → /...
    $path = preg_replace('#^/(en|es|ko|ar|zh|ja|de|fr|it|tr|id)(/|$)#', '/', $path) ?? $path;
    if ($path === '') {
        $path = '/';
    }
    return substr($path, 0, 500);
}

/**
 * @param array<string, mixed> $args
 * @return array{from: string, to: string}
 */
function irisid_stats_range_bounds(string $range): array
{
    $now = current_time('timestamp');
    $to = gmdate('Y-m-d H:i:s', $now);
    switch ($range) {
        case 'week':
            $from = gmdate('Y-m-d H:i:s', $now - WEEK_IN_SECONDS);
            break;
        case 'month':
            $from = gmdate('Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS);
            break;
        case 'year':
            $from = gmdate('Y-m-d H:i:s', $now - YEAR_IN_SECONDS);
            break;
        case 'total':
        default:
            $from = '2000-01-01 00:00:00';
            break;
    }
    return ['from' => $from, 'to' => $to];
}

/**
 * @return array<string, mixed>
 */
function irisid_stats_build_summary(string $range = 'month'): array
{
    global $wpdb;
    $table = irisid_stats_table_name();
    $bounds = irisid_stats_range_bounds($range);
    $from = $bounds['from'];
    $to = $bounds['to'];

    $prev = irisid_stats_previous_bounds($range, $from, $to);

    $sessions = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_id) FROM {$table} WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot'",
        $from,
        $to
    ));
    $pageviews = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot'",
        $from,
        $to
    ));
    $users = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_id) FROM {$table} WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot'",
        $from,
        $to
    ));
    $new_users = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_id) FROM {$table} WHERE occurred_at >= %s AND occurred_at <= %s AND is_new_session = 1 AND device <> 'bot'",
        $from,
        $to
    ));

    $prev_sessions = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_id) FROM {$table} WHERE occurred_at >= %s AND occurred_at < %s AND device <> 'bot'",
        $prev['from'],
        $prev['to']
    ));
    $prev_pageviews = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE occurred_at >= %s AND occurred_at < %s AND device <> 'bot'",
        $prev['from'],
        $prev['to']
    ));

    $avg_duration = irisid_stats_avg_session_seconds($from, $to);
    $bounce = irisid_stats_bounce_rate($from, $to);

    $top_pages = $wpdb->get_results($wpdb->prepare(
        "SELECT path, MAX(title) AS title, COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
         FROM {$table}
         WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot'
         GROUP BY path
         ORDER BY views DESC
         LIMIT 15",
        $from,
        $to
    ), ARRAY_A) ?: [];

    $cold_pages = $wpdb->get_results($wpdb->prepare(
        "SELECT path, MAX(title) AS title, COUNT(*) AS views, MAX(occurred_at) AS last_seen
         FROM {$table}
         WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot'
         GROUP BY path
         HAVING views <= 3
         ORDER BY views ASC, last_seen ASC
         LIMIT 15",
        $from,
        $to
    ), ARRAY_A) ?: [];

    $devices = $wpdb->get_results($wpdb->prepare(
        "SELECT device, COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
         FROM {$table}
         WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot'
         GROUP BY device
         ORDER BY views DESC",
        $from,
        $to
    ), ARRAY_A) ?: [];

    $countries = $wpdb->get_results($wpdb->prepare(
        "SELECT country, COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
         FROM {$table}
         WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot' AND country <> ''
         GROUP BY country
         ORDER BY views DESC
         LIMIT 20",
        $from,
        $to
    ), ARRAY_A) ?: [];

    $timeseries = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(occurred_at) AS day, COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
         FROM {$table}
         WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot'
         GROUP BY DATE(occurred_at)
         ORDER BY day ASC
         LIMIT 400",
        $from,
        $to
    ), ARRAY_A) ?: [];

    $settings = irisid_stats_get_settings();

    return [
        'range' => $range,
        'from' => $from,
        'to' => $to,
        'widgets' => $settings['widgets'],
        'overview' => [
            'sessions' => $sessions,
            'pageviews' => $pageviews,
            'users' => $users,
            'newUsers' => $new_users,
            'avgSessionDuration' => $avg_duration,
            'bounceRate' => $bounce,
            'deltas' => [
                'sessions' => irisid_stats_delta_pct($sessions, $prev_sessions),
                'pageviews' => irisid_stats_delta_pct($pageviews, $prev_pageviews),
            ],
        ],
        'timeseries' => array_map(static function (array $row): array {
            return [
                'day' => (string) ($row['day'] ?? ''),
                'views' => (int) ($row['views'] ?? 0),
                'sessions' => (int) ($row['sessions'] ?? 0),
            ];
        }, $timeseries),
        'topPages' => array_map('irisid_stats_map_page_row', $top_pages),
        'coldPages' => array_map('irisid_stats_map_page_row', $cold_pages),
        'devices' => array_map(static function (array $row): array {
            return [
                'device' => (string) ($row['device'] ?? 'desktop'),
                'views' => (int) ($row['views'] ?? 0),
                'sessions' => (int) ($row['sessions'] ?? 0),
            ];
        }, $devices),
        'countries' => array_map(static function (array $row): array {
            return [
                'country' => (string) ($row['country'] ?? ''),
                'views' => (int) ($row['views'] ?? 0),
                'sessions' => (int) ($row['sessions'] ?? 0),
            ];
        }, $countries),
    ];
}

/**
 * @return array{from: string, to: string}
 */
function irisid_stats_previous_bounds(string $range, string $from, string $to): array
{
    $fromTs = strtotime($from . ' UTC') ?: time();
    $toTs = strtotime($to . ' UTC') ?: time();
    $span = max(1, $toTs - $fromTs);
    return [
        'from' => gmdate('Y-m-d H:i:s', $fromTs - $span),
        'to' => gmdate('Y-m-d H:i:s', $fromTs),
    ];
}

function irisid_stats_delta_pct(int $current, int $previous): float
{
    if ($previous <= 0) {
        return $current > 0 ? 100.0 : 0.0;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

function irisid_stats_avg_session_seconds(string $from, string $to): int
{
    global $wpdb;
    $table = irisid_stats_table_name();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, MIN(occurred_at) AS first_hit, MAX(occurred_at) AS last_hit
         FROM {$table}
         WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot' AND session_id <> ''
         GROUP BY session_id
         HAVING COUNT(*) > 1
         LIMIT 5000",
        $from,
        $to
    ), ARRAY_A) ?: [];

    if ($rows === []) {
        return 0;
    }

    $total = 0;
    $count = 0;
    foreach ($rows as $row) {
        $start = strtotime((string) $row['first_hit'] . ' UTC');
        $end = strtotime((string) $row['last_hit'] . ' UTC');
        if ($start && $end && $end >= $start) {
            $total += ($end - $start);
            $count++;
        }
    }
    return $count > 0 ? (int) round($total / $count) : 0;
}

function irisid_stats_bounce_rate(string $from, string $to): float
{
    global $wpdb;
    $table = irisid_stats_table_name();
    $sessions = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_id) FROM {$table}
         WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot' AND session_id <> ''",
        $from,
        $to
    ));
    if ($sessions === 0) {
        return 0.0;
    }
    $bounces = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM (
            SELECT session_id FROM {$table}
            WHERE occurred_at >= %s AND occurred_at <= %s AND device <> 'bot' AND session_id <> ''
            GROUP BY session_id
            HAVING COUNT(*) = 1
         ) b",
        $from,
        $to
    ));
    return round(($bounces / $sessions) * 100, 1);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function irisid_stats_map_page_row(array $row): array
{
    return [
        'path' => (string) ($row['path'] ?? '/'),
        'title' => (string) ($row['title'] ?? ''),
        'views' => (int) ($row['views'] ?? 0),
        'sessions' => (int) ($row['sessions'] ?? 0),
        'lastSeen' => isset($row['last_seen']) ? (string) $row['last_seen'] : null,
    ];
}
