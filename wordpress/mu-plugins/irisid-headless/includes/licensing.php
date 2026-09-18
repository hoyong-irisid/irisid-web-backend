<?php

declare(strict_types=1);

/**
 * Licensing key check (headless port of irisid-key-check).
 * CPTs: cd_keys, reference_numbers
 * Public REST: irisid/v1/licensing/*
 */

if (!defined('ABSPATH')) {
    exit;
}

function irisid_register_licensing_post_types(): void
{
    $common = [
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'show_in_rest'        => true,
        'has_archive'         => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'supports'            => ['title', 'custom-fields'],
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
    ];

    register_post_type('cd_keys', array_merge($common, [
        'labels' => irisid_cpt_labels('CD Key', 'CD Keys'),
    ]));

    register_post_type('reference_numbers', array_merge($common, [
        'labels' => irisid_cpt_labels('Reference Number', 'Reference Numbers'),
    ]));
}

function irisid_licensing_admin_menu(): void
{
    add_menu_page(
        'IrisID License Check',
        'IrisID License Check',
        'edit_posts',
        'edit.php?post_type=cd_keys',
        '',
        'dashicons-schedule',
        40
    );

    add_submenu_page(
        'edit.php?post_type=cd_keys',
        'CD Keys',
        'CD Keys',
        'edit_posts',
        'edit.php?post_type=cd_keys'
    );

    add_submenu_page(
        'edit.php?post_type=cd_keys',
        'Reference Numbers',
        'Reference Numbers',
        'edit_posts',
        'edit.php?post_type=reference_numbers'
    );
}

add_action('admin_menu', 'irisid_licensing_admin_menu');

/** @param array<string, string> $columns */
function irisid_licensing_remove_date_column(array $columns): array
{
    unset($columns['date']);
    return $columns;
}

add_filter('manage_cd_keys_posts_columns', 'irisid_licensing_remove_date_column');
add_filter('manage_reference_numbers_posts_columns', 'irisid_licensing_remove_date_column');

function irisid_licensing_meta_value(int $post_id, string $key): string
{
    if (function_exists('get_field')) {
        $value = get_field($key, $post_id);
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }
        if ($value !== null && $value !== false && $value !== '') {
            return trim((string) $value);
        }
    }
    $raw = get_post_meta($post_id, $key, true);
    if (is_array($raw)) {
        $raw = $raw[0] ?? '';
    }
    return trim((string) $raw);
}

/**
 * @return array{id:int,key:string,product:string,users:string,licenseParameter:string,claimStatus:string}|null
 */
function irisid_licensing_find_cd_key(string $input): ?array
{
    global $wpdb;
    $input = strtoupper(preg_replace('/\s+/', '', $input) ?? '');
    if ($input === '') {
        return null;
    }
    $prefix = substr($input, 0, 10);
    $post_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'cd_keys'
           AND post_status = 'publish'
           AND SUBSTRING(post_title, 1, 10) = %s
         LIMIT 1",
        $prefix
    ));
    if ($post_id <= 0) {
        return null;
    }
    $post = get_post($post_id);
    if (!$post) {
        return null;
    }
    return [
        'id' => $post_id,
        'key' => (string) $post->post_title,
        'product' => irisid_licensing_meta_value($post_id, 'product'),
        'users' => irisid_licensing_meta_value($post_id, 'users'),
        'licenseParameter' => '',
        'claimStatus' => irisid_licensing_meta_value($post_id, 'claim_status') ?: 'Unclaimed',
    ];
}

/**
 * @return array{id:int,key:string,product:string,users:string,licenseParameter:string,claimStatus:string}|null
 */
function irisid_licensing_find_ref_number(string $input): ?array
{
    global $wpdb;
    $input = strtoupper(preg_replace('/\s+/', '', $input) ?? '');
    if (strlen($input) !== 14) {
        return null;
    }
    $post_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'reference_numbers'
           AND post_status = 'publish'
           AND post_title = %s
         LIMIT 1",
        $input
    ));
    if ($post_id <= 0) {
        return null;
    }
    $post = get_post($post_id);
    if (!$post) {
        return null;
    }
    $parameter = irisid_licensing_meta_value($post_id, 'license_parameter');
    return [
        'id' => $post_id,
        'key' => (string) $post->post_title,
        'product' => irisid_licensing_meta_value($post_id, 'product'),
        'users' => $parameter,
        'licenseParameter' => $parameter,
        'claimStatus' => irisid_licensing_meta_value($post_id, 'claim_status') ?: 'Unclaimed',
    ];
}

function irisid_licensing_mark_claimed(int $post_id): bool
{
    $status = irisid_licensing_meta_value($post_id, 'claim_status');
    if ($status === 'Claimed') {
        return false;
    }
    date_default_timezone_set('America/New_York');
    $now = date('m/d/Y g:i a');
    if (function_exists('update_field')) {
        update_field('claim_status', 'Claimed', $post_id);
        update_field('date_claimed', $now, $post_id);
    } else {
        update_post_meta($post_id, 'claim_status', 'Claimed');
        update_post_meta($post_id, 'date_claimed', $now);
    }
    return true;
}

function irisid_register_licensing_rest_routes(): void
{
    register_rest_route('irisid/v1', '/licensing/check-cd-key', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'irisid_rest_licensing_check_cd_key',
        'args'                => [
            'cdKey' => [
                'required' => true,
                'type'     => 'string',
            ],
        ],
    ]);

    register_rest_route('irisid/v1', '/licensing/check-ref-number', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'irisid_rest_licensing_check_ref_number',
        'args'                => [
            'refNumber' => [
                'required' => true,
                'type'     => 'string',
            ],
        ],
    ]);

    register_rest_route('irisid/v1', '/licensing/submit', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'irisid_rest_licensing_submit',
    ]);
}

function irisid_rest_licensing_check_cd_key(WP_REST_Request $request): WP_REST_Response
{
    $cd_key = (string) $request->get_param('cdKey');
    $len = strlen(preg_replace('/\s+/', '', $cd_key) ?? '');
    if ($len !== 10 && $len !== 25) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Enter at least the first 10 characters of the CD Key.',
        ], 400);
    }
    $match = irisid_licensing_find_cd_key($cd_key);
    if (!$match) {
        return new WP_REST_Response([
            'ok' => false,
            'found' => false,
            'error' => 'CD Key not found. Please re-enter, or submit this key.',
        ], 200);
    }
    return new WP_REST_Response([
        'ok' => true,
        'found' => true,
        'key' => $match['key'],
        'product' => $match['product'],
        'users' => $match['users'],
        'claimStatus' => $match['claimStatus'],
    ], 200);
}

function irisid_rest_licensing_check_ref_number(WP_REST_Request $request): WP_REST_Response
{
    $ref = (string) $request->get_param('refNumber');
    $clean = strtoupper(preg_replace('/\s+/', '', $ref) ?? '');
    if (strlen($clean) !== 14) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Enter the full 14-character reference number.',
        ], 400);
    }
    $match = irisid_licensing_find_ref_number($clean);
    if (!$match) {
        return new WP_REST_Response([
            'ok' => false,
            'found' => false,
            'error' => 'Reference Number not found. Please re-enter, or submit this reference number.',
        ], 200);
    }
    return new WP_REST_Response([
        'ok' => true,
        'found' => true,
        'key' => $match['key'],
        'product' => $match['product'],
        'licenseParameter' => $match['licenseParameter'],
        'users' => $match['licenseParameter'],
        'claimStatus' => $match['claimStatus'],
    ], 200);
}

function irisid_rest_licensing_submit(WP_REST_Request $request): WP_REST_Response
{
    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = [];
    }

    $license_type = trim((string) ($body['licenseType'] ?? ''));
    $cd_key = strtoupper(preg_replace('/\s+/', '', (string) ($body['cdKey'] ?? '')) ?? '');
    $ref_number = strtoupper(preg_replace('/\s+/', '', (string) ($body['refNumber'] ?? '')) ?? '');
    $name = sanitize_text_field((string) ($body['name'] ?? ''));
    $company = sanitize_text_field((string) ($body['company'] ?? ''));
    $account_email = sanitize_email((string) ($body['accountEmail'] ?? ''));
    $contact_email = sanitize_email((string) ($body['contactEmail'] ?? ''));
    $phone = sanitize_text_field((string) ($body['phone'] ?? ''));
    $notes = sanitize_textarea_field((string) ($body['notes'] ?? ''));
    $physical = sanitize_text_field((string) ($body['physicalMachines'] ?? ''));
    $virtual = sanitize_text_field((string) ($body['virtualMachines'] ?? ''));
    $float_server = sanitize_text_field((string) ($body['floatLicenseServer'] ?? ''));
    $product = sanitize_text_field((string) ($body['product'] ?? ''));
    $users = sanitize_text_field((string) ($body['users'] ?? ''));

    if ($license_type === '' || $name === '' || $company === '' || $account_email === '' || $contact_email === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'Missing required fields.'], 400);
    }
    if (!is_email($account_email) || !is_email($contact_email)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Invalid email address.'], 400);
    }

    $is_upgrade = $license_type === 'EAC Software Upgrade';
    $is_new = $license_type === 'New License';
    if (!$is_upgrade && !$is_new) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Invalid license type.'], 400);
    }

    $match = null;
    $key_label = '';
    if ($is_upgrade) {
        if ($cd_key === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'CD Key is required.'], 400);
        }
        $match = irisid_licensing_find_cd_key($cd_key);
        $key_label = 'CD Key';
        $key_value = $match['key'] ?? $cd_key;
    } else {
        if (strlen($ref_number) !== 14) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Reference number is required.'], 400);
        }
        $match = irisid_licensing_find_ref_number($ref_number);
        $key_label = 'Reference Number';
        $key_value = $match['key'] ?? $ref_number;
    }

    $warning = '';
    $claimed_now = false;
    if (!$match) {
        $warning = '*** THIS ' . strtoupper($key_label) . ' WAS NOT FOUND IN THE DATABASE ***';
    } elseif (($match['claimStatus'] ?? '') === 'Claimed') {
        $warning = '*** THIS ' . strtoupper($key_label) . ' HAS BEEN PREVIOUSLY CLAIMED ***';
    } else {
        $claimed_now = irisid_licensing_mark_claimed((int) $match['id']);
    }

    if ($product === '' && $match) {
        $product = $match['product'];
    }
    if ($users === '' && $match) {
        $users = $match['users'] !== '' ? $match['users'] : $match['licenseParameter'];
    }

    $lines = [
        $warning !== '' ? $warning : null,
        'License Type: ' . $license_type,
        $key_label . ': ' . $key_value,
        'Product: ' . $product,
        'Users / License Parameter: ' . $users,
        'Physical machines: ' . $physical,
        'Virtual machines: ' . $virtual,
        'Float license server: ' . $float_server,
        'Name: ' . $name,
        'Company: ' . $company,
        'Account email: ' . $account_email,
        'Contact email: ' . $contact_email,
        'Phone: ' . $phone,
        'Notes: ' . $notes,
        'Claimed on submit: ' . ($claimed_now ? 'yes' : 'no'),
        'Source: headless /licensing/',
    ];
    $body_text = implode("\n", array_values(array_filter($lines, static fn ($line) => $line !== null && $line !== '')));

    $to = 'licensing@irisid.com';
    $subject = sprintf('[Licensing] %s – %s', $license_type, $company);
    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $contact_email,
    ];
    $sent = wp_mail($to, $subject, $body_text, $headers);

    return new WP_REST_Response([
        'ok' => true,
        'emailSent' => (bool) $sent,
        'claimed' => $claimed_now,
        'warning' => $warning,
        'message' => 'Your licensing request was submitted. You will receive follow-up by email.',
    ], 200);
}
