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

// Load field groups from the mu-plugin acf-json folder. Do not write JSON
// back from WP Admin – duplicate/empty saves have wiped groups on cms before.
// Field definitions are owned by git; deploy + acf_import_field_group to update.
add_filter('acf/settings/save_json', '__return_false');
add_filter('acf/settings/load_json', 'irisid_acf_json_load_paths');
add_filter('acf/load_field_groups', 'irisid_dedupe_acf_field_groups', 99);

/** @param array<int, string> $paths */
function irisid_acf_json_load_paths(array $paths): array
{
    $paths[] = IRISID_HEADLESS_DIR . '/acf-json';
    return $paths;
}

/**
 * Keep one field group per key (highest ID wins).
 * Repeated acf_import without a stable ID used to stack duplicate Resource groups
 * and spam "Edit field group" cogs on the Resource editor.
 *
 * @param array<int, array<string, mixed>> $groups
 * @return array<int, array<string, mixed>>
 */
function irisid_dedupe_acf_field_groups(array $groups): array
{
    $bestByKey = [];
    $order = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $key = (string) ($group['key'] ?? '');
        if ($key === '') {
            $order[] = ['_anon_' . count($order), $group];
            continue;
        }
        $id = (int) ($group['ID'] ?? 0);
        $prev = $bestByKey[$key] ?? null;
        $prevId = is_array($prev) ? (int) ($prev['ID'] ?? 0) : -1;
        if ($prev === null || $id >= $prevId) {
            if ($prev === null) {
                $order[] = [$key, null];
            }
            $bestByKey[$key] = $group;
        }
    }

    $out = [];
    $seen = [];
    foreach ($order as [$key, $anon]) {
        if ($anon !== null) {
            $out[] = $anon;
            continue;
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if (isset($bestByKey[$key])) {
            $out[] = $bestByKey[$key];
        }
    }
    return $out;
}
