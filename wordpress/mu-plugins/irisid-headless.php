<?php
/**
 * Plugin Name: Iris ID Headless CMS
 * Description: CPT/taxonomies, GraphQL exposure, headless front-end lockdown, ISR webhook.
 * Version: 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('IRISID_HEADLESS_VERSION', '1.1.0');
define('IRISID_HEADLESS_DIR', __DIR__ . '/irisid-headless');

require_once IRISID_HEADLESS_DIR . '/includes/cpt-taxonomies.php';
require_once IRISID_HEADLESS_DIR . '/includes/licensing.php';
require_once IRISID_HEADLESS_DIR . '/includes/acf-options.php';
require_once IRISID_HEADLESS_DIR . '/includes/admin-rest.php';
require_once IRISID_HEADLESS_DIR . '/includes/headless-lockdown.php';
require_once IRISID_HEADLESS_DIR . '/includes/revalidate-webhook.php';
require_once IRISID_HEADLESS_DIR . '/includes/graphql-upload.php';
require_once IRISID_HEADLESS_DIR . '/includes/gf-captcha-headless.php';
require_once IRISID_HEADLESS_DIR . '/includes/site-notice-admin.php';
require_once IRISID_HEADLESS_DIR . '/includes/content-admin.php';

add_action('init', 'irisid_register_content_types', 5);
add_action('acf/init', 'irisid_register_acf_options_pages');
add_action('rest_api_init', 'irisid_register_admin_rest_routes');
add_action('rest_api_init', 'irisid_register_licensing_rest_routes');

add_filter('acf/settings/save_json', 'irisid_acf_json_save_path');
add_filter('acf/settings/load_json', 'irisid_acf_json_load_paths');

function irisid_acf_json_save_path(string $path): string
{
    return IRISID_HEADLESS_DIR . '/acf-json';
}

/** @param array<int, string> $paths */
function irisid_acf_json_load_paths(array $paths): array
{
    $paths[] = IRISID_HEADLESS_DIR . '/acf-json';
    return $paths;
}
