<?php
/**
 * Plugin Name: Iris ID Stats at a Glance
 * Description: Collects site traffic for the password-protected Stats dashboard and email digests.
 * Version: 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('IRISID_STATS_VERSION', '1.0.0');
define('IRISID_STATS_DIR', __DIR__ . '/irisid-stats');

require_once IRISID_STATS_DIR . '/includes/schema.php';
require_once IRISID_STATS_DIR . '/includes/helpers.php';
require_once IRISID_STATS_DIR . '/includes/rest.php';
require_once IRISID_STATS_DIR . '/includes/admin.php';
require_once IRISID_STATS_DIR . '/includes/email.php';

register_activation_hook(__FILE__, 'irisid_stats_activate');

add_action('plugins_loaded', static function (): void {
    // mu-plugins do not fire activation hooks reliably — ensure schema on load.
    irisid_stats_maybe_install();
});

add_action('rest_api_init', 'irisid_stats_register_rest_routes');
add_action('admin_menu', 'irisid_stats_register_admin_menu');
add_action('admin_init', 'irisid_stats_register_settings');
add_action('irisid_stats_weekly_email', 'irisid_stats_send_weekly_email');
add_action('irisid_stats_monthly_email', 'irisid_stats_send_monthly_email');

add_action('init', static function (): void {
    if (!wp_next_scheduled('irisid_stats_weekly_email')) {
        wp_schedule_event(strtotime('next monday 09:00:00'), 'weekly', 'irisid_stats_weekly_email');
    }
    if (!wp_next_scheduled('irisid_stats_monthly_email')) {
        wp_schedule_event(strtotime('first day of next month 09:00:00'), 'monthly', 'irisid_stats_monthly_email');
    }
});

add_filter('cron_schedules', static function (array $schedules): array {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => 'Once Weekly',
        ];
    }
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => 'Once Monthly',
        ];
    }
    return $schedules;
});
