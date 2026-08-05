<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function irisid_stats_table_name(): string
{
    global $wpdb;
    return $wpdb->prefix . 'irisid_stats_events';
}

function irisid_stats_activate(): void
{
    irisid_stats_maybe_install();
}

function irisid_stats_maybe_install(): void
{
    $version = get_option('irisid_stats_db_version');
    if ($version === IRISID_STATS_VERSION) {
        return;
    }

    global $wpdb;
    $table = irisid_stats_table_name();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        occurred_at datetime NOT NULL,
        path varchar(500) NOT NULL DEFAULT '/',
        title varchar(255) NOT NULL DEFAULT '',
        locale varchar(16) NOT NULL DEFAULT '',
        referrer varchar(500) NOT NULL DEFAULT '',
        country varchar(8) NOT NULL DEFAULT '',
        region varchar(64) NOT NULL DEFAULT '',
        device varchar(32) NOT NULL DEFAULT 'desktop',
        session_id varchar(64) NOT NULL DEFAULT '',
        is_new_session tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY occurred_at (occurred_at),
        KEY path_idx (path(191)),
        KEY country_idx (country),
        KEY device_idx (device),
        KEY session_idx (session_id)
    ) {$charset};";

    dbDelta($sql);
    update_option('irisid_stats_db_version', IRISID_STATS_VERSION);

    if (!get_option('irisid_stats_collect_key')) {
        update_option('irisid_stats_collect_key', wp_generate_password(32, false, false));
    }
}
