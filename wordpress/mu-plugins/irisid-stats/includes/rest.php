<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function irisid_stats_register_rest_routes(): void
{
    register_rest_route('irisid/v1', '/stats/collect', [
        'methods'             => 'POST',
        'callback'            => 'irisid_stats_rest_collect',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('irisid/v1', '/stats/summary', [
        'methods'             => 'GET',
        'callback'            => 'irisid_stats_rest_summary',
        'permission_callback' => 'irisid_stats_rest_check_key',
        'args'                => [
            'range' => [
                'default'           => 'month',
                'sanitize_callback' => static function ($value): string {
                    $value = sanitize_key((string) $value);
                    return in_array($value, ['week', 'month', 'year', 'total'], true) ? $value : 'month';
                },
            ],
        ],
    ]);

    register_rest_route('irisid/v1', '/stats/settings', [
        'methods'             => 'GET',
        'callback'            => 'irisid_stats_rest_settings',
        'permission_callback' => 'irisid_stats_rest_check_key',
    ]);
}

function irisid_stats_rest_check_key(WP_REST_Request $request): bool
{
    $settings = irisid_stats_get_settings();
    $expected = (string) ($settings['collect_key'] ?? '');
    if ($expected === '') {
        return false;
    }
    // Header only — a query-string fallback would leak this secret into access/CDN logs.
    $provided = (string) $request->get_header('x-irisid-stats-key');
    return hash_equals($expected, $provided);
}

function irisid_stats_rest_collect(WP_REST_Request $request)
{
    $settings = irisid_stats_get_settings();
    if (empty($settings['collect_enabled'])) {
        return new WP_REST_Response(['ok' => true, 'skipped' => true], 200);
    }

    if (!irisid_stats_rest_check_key($request)) {
        return new WP_Error('forbidden', 'Invalid stats key', ['status' => 403]);
    }

    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = [];
    }

    $path = irisid_stats_normalize_path((string) ($body['path'] ?? '/'));
    $title = sanitize_text_field((string) ($body['title'] ?? ''));
    $locale = sanitize_text_field((string) ($body['locale'] ?? ''));
    $referrer = esc_url_raw((string) ($body['referrer'] ?? ''));
    $session_id = sanitize_text_field((string) ($body['sessionId'] ?? ''));
    $is_new = !empty($body['isNewSession']) ? 1 : 0;

    $ua = (string) ($body['userAgent'] ?? $request->get_header('user_agent'));
    $device = irisid_stats_parse_device($ua);
    if ($device === 'bot') {
        return new WP_REST_Response(['ok' => true, 'skipped' => 'bot'], 200);
    }

    $country = strtoupper(sanitize_text_field((string) (
        $body['country']
        ?? $request->get_header('cf-ipcountry')
        ?? ''
    )));
    if (strlen($country) > 8) {
        $country = substr($country, 0, 8);
    }
    $region = sanitize_text_field((string) ($body['region'] ?? ''));

    global $wpdb;
    $wpdb->insert(
        irisid_stats_table_name(),
        [
            'occurred_at'   => current_time('mysql', true),
            'path'          => $path,
            'title'         => substr($title, 0, 255),
            'locale'        => substr($locale, 0, 16),
            'referrer'      => substr((string) $referrer, 0, 500),
            'country'       => $country,
            'region'        => substr($region, 0, 64),
            'device'        => $device,
            'session_id'    => substr($session_id, 0, 64),
            'is_new_session'=> $is_new,
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
    );

    return new WP_REST_Response(['ok' => true], 201);
}

function irisid_stats_rest_summary(WP_REST_Request $request)
{
    $range = (string) $request->get_param('range');
    return new WP_REST_Response(irisid_stats_build_summary($range), 200);
}

function irisid_stats_rest_settings(WP_REST_Request $request)
{
    $settings = irisid_stats_get_settings();
    unset($settings['collect_key'], $settings['dashboard_password']);
    return new WP_REST_Response($settings, 200);
}
