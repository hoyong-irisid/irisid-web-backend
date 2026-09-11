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
    $provided = (string) $request->get_header('x-irisid-stats-key');
    if ($provided === '') {
        $provided = (string) $request->get_param('key');
    }
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

function irisid_get_site_settings_payload(): array
{
    $mode = 1;
    $image = '';
    $video = '';
    $chat = true;

    if (function_exists('get_field')) {
        $mode = irisid_admin_hero_mode(get_field('homepage_hero_mode', 'option'));
        $image = trim((string) (get_field('homepage_hero_image_url', 'option') ?: ''));
        $video = trim((string) (get_field('homepage_hero_video_url', 'option') ?: ''));
        $chat_raw = get_field('aslan_chat_enabled', 'option');
        $chat = $chat_raw === null ? true : irisid_admin_parse_bool($chat_raw, true);
    }

    return [
        'homepageHeroMode'      => $mode,
        'homepageHeroImageUrl'  => $image,
        'homepageHeroVideoUrl'  => $video,
        'aslanChatEnabled'      => $chat,
        'homepage_hero_mode'    => $mode,
        'homepage_hero_image_url' => $image,
        'homepage_hero_video_url' => $video,
        'aslan_chat_enabled'    => $chat,
    ];
}

function irisid_register_admin_rest_routes(): void
{
    register_rest_route('irisid/v1', '/site-settings', [
        'methods'             => 'GET',
        'callback'            => 'irisid_rest_get_site_settings',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('irisid/v1', '/admin/site-settings', [
        'methods'             => 'POST',
        'callback'            => 'irisid_rest_update_site_settings',
        'permission_callback' => 'irisid_admin_rest_check_key',
    ]);

    register_rest_route('irisid/v1', '/admin/events', [
        [
            'methods'             => 'GET',
            'callback'            => 'irisid_rest_list_admin_events',
            'permission_callback' => 'irisid_admin_rest_check_key',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'irisid_rest_upsert_admin_event',
            'permission_callback' => 'irisid_admin_rest_check_key',
        ],
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
}

function irisid_rest_get_dashboard_password(WP_REST_Request $request)
{
    $password = trim((string) get_option('irisid_admin_dashboard_password', ''));
    return new WP_REST_Response(['password' => $password], 200);
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
    if (strlen($password) < 4) {
        return new WP_Error('weak_password', 'Password must be at least 4 characters.', ['status' => 400]);
    }
    update_option('irisid_admin_dashboard_password', $password, false);
    return new WP_REST_Response(['ok' => true], 200);
}

function irisid_rest_get_site_settings(WP_REST_Request $request)
{
    return new WP_REST_Response(irisid_get_site_settings_payload(), 200);
}

function irisid_rest_update_site_settings(WP_REST_Request $request)
{
    if (!function_exists('update_field')) {
        return new WP_Error('acf_missing', 'ACF is required to save site settings.', ['status' => 500]);
    }

    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = [];
    }

    $mode = irisid_admin_hero_mode($body['homepage_hero_mode'] ?? $body['homepageHeroMode'] ?? 1);
    $image = esc_url_raw((string) ($body['homepage_hero_image_url'] ?? $body['homepageHeroImageUrl'] ?? ''));
    $video = esc_url_raw((string) ($body['homepage_hero_video_url'] ?? $body['homepageHeroVideoUrl'] ?? ''));
    $chat = irisid_admin_parse_bool($body['aslan_chat_enabled'] ?? $body['aslanChatEnabled'] ?? true, true);

    update_field('homepage_hero_mode', $mode, 'option');
    update_field('homepage_hero_image_url', $image, 'option');
    update_field('homepage_hero_video_url', $video, 'option');
    update_field('aslan_chat_enabled', $chat ? 1 : 0, 'option');

    return new WP_REST_Response(irisid_get_site_settings_payload(), 200);
}

function irisid_admin_events_term_id(): int
{
    $term = get_term_by('slug', 'events', 'resource_type');
    if ($term instanceof WP_Term) {
        return (int) $term->term_id;
    }
    $inserted = wp_insert_term('Events', 'resource_type', ['slug' => 'events']);
    if (is_wp_error($inserted)) {
        return 0;
    }
    return (int) ($inserted['term_id'] ?? 0);
}

function irisid_admin_find_notice_for_event(int $event_id): int
{
    $posts = get_posts([
        'post_type'      => 'site_notice',
        'post_status'    => ['publish', 'draft', 'future'],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => 'linked_event_id',
        'meta_value'     => (string) $event_id,
    ]);
    return isset($posts[0]) ? (int) $posts[0] : 0;
}

function irisid_admin_serialize_event(int $event_id): ?array
{
    $post = get_post($event_id);
    if (!$post || $post->post_type !== 'resource') {
        return null;
    }

    $slug = $post->post_name;
    $notice_id = irisid_admin_find_notice_for_event($event_id);
    $event_date = '';
    $notice_body = '';
    $starts = '';
    $ends = '';
    $cta_label = 'See more';
    $scope = 'sitewide';

    if (function_exists('get_field')) {
        $event_date = (string) (get_field('event_date', $event_id) ?: '');
        if ($notice_id) {
            $notice_body = wp_strip_all_tags((string) get_post_field('post_content', $notice_id));
            $starts = (string) (get_field('starts_at', $notice_id) ?: '');
            $ends = (string) (get_field('ends_at', $notice_id) ?: '');
            $cta_label = (string) (get_field('cta_label', $notice_id) ?: 'See more');
            $scope_raw = (string) (get_field('display_scope', $notice_id) ?: 'sitewide');
            $scope = $scope_raw === 'home' ? 'home' : 'sitewide';
        }
    }

    $detail = $slug ? '/resources/events/' . $slug . '/' : '';

    return [
        'id'              => $event_id,
        'noticeId'        => $notice_id ?: null,
        'title'           => html_entity_decode(get_the_title($event_id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'slug'            => $slug,
        'noticeBody'      => $notice_body,
        'eventDate'       => $event_date,
        'noticeStartsAt'  => $starts,
        'noticeEndsAt'    => $ends,
        'ctaLabel'        => $cta_label !== '' ? $cta_label : 'See more',
        'published'       => $post->post_status === 'publish',
        'detailUrl'       => $detail,
        'editUrl'         => get_edit_post_link($event_id, 'raw') ?: '',
        'displayScope'    => $scope,
    ];
}

function irisid_rest_list_admin_events(WP_REST_Request $request)
{
    $year = (int) ($request->get_param('year') ?: gmdate('Y'));
    $term_id = irisid_admin_events_term_id();

    $query = new WP_Query([
        'post_type'      => 'resource',
        'post_status'    => ['publish', 'draft', 'future'],
        'posts_per_page' => 100,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => $term_id
            ? [[
                'taxonomy' => 'resource_type',
                'field'    => 'term_id',
                'terms'    => [$term_id],
            ]]
            : [],
    ]);

    $events = [];
    foreach ($query->posts as $post) {
        $serialized = irisid_admin_serialize_event((int) $post->ID);
        if (!$serialized) {
            continue;
        }
        $event_year = $serialized['eventDate'] !== ''
            ? (int) substr($serialized['eventDate'], 0, 4)
            : (int) get_the_date('Y', $post);
        // Include current year and undated stubs so admins can finish setup.
        if ($serialized['eventDate'] === '' || $event_year === $year || $event_year === $year + 1) {
            $events[] = $serialized;
        }
    }

    return new WP_REST_Response(['events' => $events], 200);
}

function irisid_rest_upsert_admin_event(WP_REST_Request $request)
{
    $body = $request->get_json_params();
    if (!is_array($body)) {
        return new WP_Error('invalid_body', 'JSON body required.', ['status' => 400]);
    }

    $title = sanitize_text_field((string) ($body['title'] ?? ''));
    if ($title === '') {
        return new WP_Error('missing_title', 'Title is required.', ['status' => 400]);
    }

    $event_id = (int) ($body['id'] ?? 0);
    $notice_body = sanitize_textarea_field((string) ($body['noticeBody'] ?? $body['notice_body'] ?? ''));
    $event_date = sanitize_text_field((string) ($body['eventDate'] ?? $body['event_date'] ?? ''));
    $starts = sanitize_text_field((string) ($body['noticeStartsAt'] ?? $body['notice_starts_at'] ?? ''));
    $ends = sanitize_text_field((string) ($body['noticeEndsAt'] ?? $body['notice_ends_at'] ?? ''));
    $cta_label = sanitize_text_field((string) ($body['ctaLabel'] ?? $body['cta_label'] ?? 'See more'));
    if ($cta_label === '') {
        $cta_label = 'See more';
    }
    $published = irisid_admin_parse_bool($body['published'] ?? true, true);
    $scope = ((string) ($body['displayScope'] ?? $body['display_scope'] ?? 'sitewide')) === 'home'
        ? 'home'
        : 'sitewide';
    $status = $published ? 'publish' : 'draft';

    $term_id = irisid_admin_events_term_id();

    if ($event_id > 0) {
        $existing = get_post($event_id);
        if (!$existing || $existing->post_type !== 'resource') {
            return new WP_Error('not_found', 'Event resource not found.', ['status' => 404]);
        }
        wp_update_post([
            'ID'          => $event_id,
            'post_title'  => $title,
            'post_status' => $status,
        ]);
    } else {
        $event_id = wp_insert_post([
            'post_type'    => 'resource',
            'post_title'   => $title,
            'post_status'  => $status,
            'post_content' => '',
        ], true);
        if (is_wp_error($event_id)) {
            return $event_id;
        }
        $event_id = (int) $event_id;
    }

    if ($term_id) {
        wp_set_object_terms($event_id, [$term_id], 'resource_type', false);
    }

    if (function_exists('update_field') && $event_date !== '') {
        update_field('event_date', $event_date, $event_id);
    }

    $post = get_post($event_id);
    $slug = $post ? $post->post_name : '';
    $detail_url = $slug ? '/resources/events/' . $slug . '/' : '';

    $notice_id = irisid_admin_find_notice_for_event($event_id);
    $notice_payload = [
        'post_type'    => 'site_notice',
        'post_title'   => $title,
        'post_content' => $notice_body,
        'post_status'  => $status,
    ];
    if ($notice_id > 0) {
        $notice_payload['ID'] = $notice_id;
        wp_update_post($notice_payload);
    } else {
        $notice_id = wp_insert_post($notice_payload, true);
        if (is_wp_error($notice_id)) {
            return $notice_id;
        }
        $notice_id = (int) $notice_id;
    }

    if (function_exists('update_field')) {
        update_field('linked_event_id', $event_id, $notice_id);
        update_field('cta_enabled', 1, $notice_id);
        update_field('cta_label', $cta_label, $notice_id);
        update_field('cta_url', $detail_url, $notice_id);
        update_field('display_scope', $scope, $notice_id);
        update_field('display_style', 'top_bar', $notice_id);
        update_field('top_bar_layout', 'inline', $notice_id);
        update_field('text_align', 'left', $notice_id);
        if ($starts !== '') {
            update_field('starts_at', $starts, $notice_id);
        }
        if ($ends !== '') {
            update_field('ends_at', $ends, $notice_id);
        }
    }

    $serialized = irisid_admin_serialize_event($event_id);
    return new WP_REST_Response(['event' => $serialized], 200);
}
