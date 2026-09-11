<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Adds Duration + Status columns to the Site Notices list table (wp-admin). */

add_filter('manage_edit-site_notice_columns', 'irisid_site_notice_admin_columns');
add_action('manage_site_notice_posts_custom_column', 'irisid_site_notice_admin_column_content', 10, 2);

/**
 * @param array<string, string> $columns
 * @return array<string, string>
 */
function irisid_site_notice_admin_columns(array $columns): array
{
    $with_new = [];
    foreach ($columns as $key => $label) {
        $with_new[$key] = $label;
        if ($key === 'date') {
            $with_new['irisid_duration'] = 'Duration';
            $with_new['irisid_status'] = 'Status';
        }
    }
    // Safety net in case a future WP version ever drops the 'date' column.
    if (!isset($with_new['irisid_duration'])) {
        $with_new['irisid_duration'] = 'Duration';
        $with_new['irisid_status'] = 'Status';
    }
    return $with_new;
}

function irisid_site_notice_admin_column_content(string $column, int $post_id): void
{
    if ($column === 'irisid_duration') {
        echo esc_html(irisid_site_notice_duration_label($post_id));
        return;
    }
    if ($column === 'irisid_status') {
        [$label, $color] = irisid_site_notice_status_label($post_id);
        printf('<span style="color:%s;font-weight:600;">%s</span>', esc_attr($color), esc_html($label));
    }
}

function irisid_site_notice_et_datetime(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value, new DateTimeZone('America/New_York'));
    } catch (Exception $e) {
        return null;
    }
}

function irisid_site_notice_duration_label(int $post_id): string
{
    $starts = irisid_site_notice_et_datetime((string) get_post_meta($post_id, 'starts_at', true));
    $ends = irisid_site_notice_et_datetime((string) get_post_meta($post_id, 'ends_at', true));

    $format = static fn (DateTimeImmutable $d): string => $d->format('M j, Y g:ia') . ' ET';

    if ($starts && $ends) {
        return $format($starts) . ' – ' . $format($ends);
    }
    if ($starts) {
        return $format($starts) . ' – no end date';
    }
    if ($ends) {
        return 'from publish – ' . $format($ends);
    }
    return 'from publish, no end date';
}

/** @return array{0: string, 1: string} [label, CSS color] */
function irisid_site_notice_status_label(int $post_id): array
{
    $status = get_post_status($post_id);
    if ($status !== 'publish') {
        return [ucfirst((string) $status), '#71717a'];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $starts = irisid_site_notice_et_datetime((string) get_post_meta($post_id, 'starts_at', true));
    $ends = irisid_site_notice_et_datetime((string) get_post_meta($post_id, 'ends_at', true));

    if ($starts && $now < $starts) {
        return ['예정', '#2563eb'];
    }
    if ($ends && $now > $ends) {
        return ['게시끝', '#a1a1aa'];
    }
    return ['게시중', '#16a34a'];
}
