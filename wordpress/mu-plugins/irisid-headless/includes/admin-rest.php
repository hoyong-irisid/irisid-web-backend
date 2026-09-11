<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function irisid_admin_expected_key(): string
{
    if (function_exists('irisid_stats_get_settings')) {
        $settings = irisid_stats_get_settings();
        $key = trim((string) ($settings['collect_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
    }

    $from_option = trim((string) get_option('irisid_admin_api_key', ''));
    if ($from_option !== '') {
        return $from_option;
    }

    $from_env = trim((string) (getenv('IRISID_ADMIN_API_KEY') ?: ''));
    return $from_env;
}

function irisid_admin_rest_check_key(WP_REST_Request $request): bool
{
    $expected = irisid_admin_expected_key();
    if ($expected === '') {
        return false;
    }
    // Header only — a query-string fallback would leak this secret into access/CDN logs.
    $provided = (string) $request->get_header('x-irisid-stats-key');
    return hash_equals($expected, $provided);
}

function irisid_admin_parse_bool($value, bool $default = true): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int) $value) === 1;
    }
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
}

function irisid_admin_hero_mode($value): int
{
    $mode = (int) $value;
    return in_array($mode, [1, 2, 3, 4, 5], true) ? $mode : 1;
}

function irisid_admin_sanitize_media_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $clean = esc_url_raw($value);
    // Keep absolute http(s) URLs; otherwise allow same-origin relative paths.
    if ($clean !== '') {
        return $clean;
    }
    if (str_starts_with($value, '/')) {
        return sanitize_text_field($value);
    }
    return '';
}

/**
 * Runtime site settings live in a plain WP option so /admin saves work even when
 * ACF field groups are out of sync (update_field would otherwise fail silently).
 */
function irisid_site_settings_defaults(): array
{
    return [
        'homepage_hero_mode'      => 1,
        'homepage_hero_image_url' => '',
        'homepage_hero_video_url' => '',
        'aslan_chat_enabled'      => true,
    ];
}

function irisid_normalize_site_settings_array($raw): array
{
    $defaults = irisid_site_settings_defaults();
    if (!is_array($raw)) {
        $raw = [];
    }

    return [
        'homepage_hero_mode'      => irisid_admin_hero_mode(
            $raw['homepage_hero_mode'] ?? $raw['homepageHeroMode'] ?? $defaults['homepage_hero_mode']
        ),
        'homepage_hero_image_url' => irisid_admin_sanitize_media_url(
            (string) ($raw['homepage_hero_image_url'] ?? $raw['homepageHeroImageUrl'] ?? '')
        ),
        'homepage_hero_video_url' => irisid_admin_sanitize_media_url(
            (string) ($raw['homepage_hero_video_url'] ?? $raw['homepageHeroVideoUrl'] ?? '')
        ),
        'aslan_chat_enabled'      => irisid_admin_parse_bool(
            $raw['aslan_chat_enabled'] ?? $raw['aslanChatEnabled'] ?? $defaults['aslan_chat_enabled'],
            true
        ),
    ];
}

function irisid_read_stored_site_settings(): ?array
{
    $stored = get_option('irisid_public_site_settings', null);
    if (!is_array($stored)) {
        return null;
    }
    return irisid_normalize_site_settings_array($stored);
}

function irisid_read_acf_site_settings(): ?array
{
    if (!function_exists('get_field')) {
        return null;
    }

    $mode = get_field('homepage_hero_mode', 'option');
    $image = get_field('homepage_hero_image_url', 'option');
    $video = get_field('homepage_hero_video_url', 'option');
    $chat = get_field('aslan_chat_enabled', 'option');

    // Treat "all empty / unset" as no ACF data yet.
    if ($mode === null && ($image === null || $image === '') && ($video === null || $video === '') && $chat === null) {
        return null;
    }

    return irisid_normalize_site_settings_array([
        'homepage_hero_mode'      => $mode ?? 1,
        'homepage_hero_image_url' => $image ?? '',
        'homepage_hero_video_url' => $video ?? '',
        'aslan_chat_enabled'      => $chat === null ? true : $chat,
    ]);
}

function irisid_sync_site_settings_to_acf(array $settings): void
{
    if (!function_exists('update_field')) {
        return;
    }

    // Prefer field keys so ACF persists even when the local JSON group is not synced.
    $map = [
        'field_irisid_opt_hero_mode'      => (string) $settings['homepage_hero_mode'],
        'field_irisid_opt_hero_image_url' => $settings['homepage_hero_image_url'],
        'field_irisid_opt_hero_video_url' => $settings['homepage_hero_video_url'],
        'field_irisid_opt_aslan_chat'     => $settings['aslan_chat_enabled'] ? 1 : 0,
    ];

    foreach ($map as $key => $value) {
        update_field($key, $value, 'option');
    }
}

function irisid_get_site_settings_payload(): array
{
    $settings = irisid_read_stored_site_settings();
    if ($settings === null) {
        $settings = irisid_read_acf_site_settings() ?? irisid_site_settings_defaults();
    }

    $mode = (int) $settings['homepage_hero_mode'];
    $image = (string) $settings['homepage_hero_image_url'];
    $video = (string) $settings['homepage_hero_video_url'];
    $chat = (bool) $settings['aslan_chat_enabled'];

    return [
        'homepageHeroMode'        => $mode,
        'homepageHeroImageUrl'    => $image,
        'homepageHeroVideoUrl'    => $video,
        'aslanChatEnabled'        => $chat,
        'homepage_hero_mode'      => $mode,
        'homepage_hero_image_url' => $image,
        'homepage_hero_video_url' => $video,
        'aslan_chat_enabled'      => $chat,
    ];
}

function irisid_persist_site_settings(array $settings): array
{
    $normalized = irisid_normalize_site_settings_array($settings);
    update_option('irisid_public_site_settings', $normalized, false);
    wp_cache_delete('irisid_public_site_settings', 'options');
    wp_cache_delete('alloptions', 'options');

    // Best-effort mirror into ACF for the WP Site Settings screen.
    irisid_sync_site_settings_to_acf($normalized);

    $stored = irisid_read_stored_site_settings();
    if ($stored === null) {
        return $normalized;
    }
    return $stored;
}

function irisid_register_admin_rest_routes(): void
{
    register_rest_route('irisid/v1', '/site-settings', [
        'methods'             => 'GET',
        'callback'            => 'irisid_rest_get_site_settings',
        'permission_callback' => '__return_true',
    ]);

    // Fresh path – nginx was serving a stale HIT for /site-settings.
    register_rest_route('irisid/v1', '/site-config', [
        'methods'             => 'GET',
        'callback'            => 'irisid_rest_get_site_settings',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('irisid/v1', '/admin/site-settings', [
        'methods'             => 'POST',
        'callback'            => 'irisid_rest_update_site_settings',
        'permission_callback' => 'irisid_admin_rest_check_key',
    ]);

    register_rest_route('irisid/v1', '/admin/dashboard-password', [
        [
            'methods'             => 'GET',
            'callback'            => 'irisid_rest_get_dashboard_password',
            'permission_callback' => 'irisid_admin_rest_check_key',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'irisid_rest_set_dashboard_password',
            'permission_callback' => 'irisid_admin_rest_check_key',
        ],
    ]);

    register_rest_route('irisid/v1', '/admin/dashboard-password/verify', [
        'methods'             => 'POST',
        'callback'            => 'irisid_rest_verify_dashboard_password',
        'permission_callback' => 'irisid_admin_rest_check_key',
    ]);
}

/**
 * The dashboard password is hashed at rest (password_hash/PASSWORD_DEFAULT) and is
 * never returned in plaintext over REST. irisid_verify_dashboard_password() accepts
 * a legacy plaintext value once (pre-hashing installs) and transparently upgrades it.
 */
function irisid_dashboard_password_default(): string
{
    return trim((string) (getenv('IRISID_DASHBOARD_DEFAULT_PASSWORD') ?: 'irisid-stats'));
}

function irisid_store_dashboard_password(string $password): void
{
    update_option('irisid_admin_dashboard_password', password_hash($password, PASSWORD_DEFAULT), false);
    // Bust object-cache copies so subsequent verifies see the new value immediately.
    wp_cache_delete('irisid_admin_dashboard_password', 'options');
    wp_cache_delete('alloptions', 'options');
}

function irisid_verify_dashboard_password(string $candidate): bool
{
    if ($candidate === '') {
        return false;
    }

    $stored = trim((string) get_option('irisid_admin_dashboard_password', ''));
    if ($stored === '') {
        // Never configured yet — accept the documented default so first login works.
        return hash_equals(irisid_dashboard_password_default(), $candidate);
    }

    $is_hashed = str_starts_with($stored, '$2y$')
        || str_starts_with($stored, '$2a$')
        || str_starts_with($stored, '$2b$');

    if (!$is_hashed) {
        // Legacy plaintext value from before hashing was introduced.
        $valid = hash_equals($stored, $candidate);
        if ($valid) {
            irisid_store_dashboard_password($candidate);
        }
        return $valid;
    }

    return password_verify($candidate, $stored);
}

function irisid_rest_get_dashboard_password(WP_REST_Request $request)
{
    $stored = trim((string) get_option('irisid_admin_dashboard_password', ''));
    return new WP_REST_Response(['configured' => $stored !== ''], 200);
}

function irisid_rest_verify_dashboard_password(WP_REST_Request $request)
{
    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = [];
    }
    $candidate = trim((string) ($body['password'] ?? ''));
    return new WP_REST_Response(['valid' => irisid_verify_dashboard_password($candidate)], 200);
}

function irisid_rest_set_dashboard_password(WP_REST_Request $request)
{
    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = [];
    }
    $password = trim((string) ($body['password'] ?? ''));
    if ($password === '') {
        return new WP_Error('missing_password', 'Password is required.', ['status' => 400]);
    }
    if (strlen($password) < 10) {
        return new WP_Error('weak_password', 'Password must be at least 10 characters.', ['status' => 400]);
    }

    irisid_store_dashboard_password($password);

    if (!irisid_verify_dashboard_password($password)) {
        return new WP_Error(
            'password_persist_failed',
            'Password could not be persisted.',
            ['status' => 500]
        );
    }

    return new WP_REST_Response(['ok' => true], 200);
}

function irisid_rest_site_settings_response(array $payload, int $status = 200): WP_REST_Response
{
    $response = new WP_REST_Response($payload, $status);
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');
    $response->header('CDN-Cache-Control', 'no-store');
    return $response;
}

function irisid_rest_get_site_settings(WP_REST_Request $request)
{
    return irisid_rest_site_settings_response(irisid_get_site_settings_payload());
}

function irisid_rest_update_site_settings(WP_REST_Request $request)
{
    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = [];
    }

    $persisted = irisid_persist_site_settings($body);
    $payload = [
        'homepageHeroMode'        => (int) $persisted['homepage_hero_mode'],
        'homepageHeroImageUrl'    => (string) $persisted['homepage_hero_image_url'],
        'homepageHeroVideoUrl'    => (string) $persisted['homepage_hero_video_url'],
        'aslanChatEnabled'        => (bool) $persisted['aslan_chat_enabled'],
        'homepage_hero_mode'      => (int) $persisted['homepage_hero_mode'],
        'homepage_hero_image_url' => (string) $persisted['homepage_hero_image_url'],
        'homepage_hero_video_url' => (string) $persisted['homepage_hero_video_url'],
        'aslan_chat_enabled'      => (bool) $persisted['aslan_chat_enabled'],
    ];

    // Verify the option round-trips; otherwise admin UI looked "saved" while public stayed default.
    $expected_mode = irisid_admin_hero_mode($body['homepage_hero_mode'] ?? $body['homepageHeroMode'] ?? 1);
    $expected_chat = irisid_admin_parse_bool($body['aslan_chat_enabled'] ?? $body['aslanChatEnabled'] ?? true, true);
    if (
        (int) $payload['homepageHeroMode'] !== (int) $expected_mode
        || (bool) $payload['aslanChatEnabled'] !== (bool) $expected_chat
    ) {
        return new WP_Error(
            'settings_persist_failed',
            'Settings could not be persisted to WordPress options.',
            ['status' => 500]
        );
    }

    return irisid_rest_site_settings_response($payload);
}

