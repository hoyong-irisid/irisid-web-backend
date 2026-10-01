<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notify Next.js to revalidate ISR cache when content changes.
 * Set in wp-config.php or env:
 *   define('IRISID_REVALIDATE_URL', 'https://irisid.com/api/revalidate');
 *   define('IRISID_REVALIDATE_SECRET', '...');
 */
add_action('save_post', 'irisid_trigger_revalidate', 20, 3);
add_action('transition_post_status', 'irisid_trigger_revalidate_on_status', 20, 3);
add_action('trashed_post', 'irisid_trigger_revalidate_for_id', 20);
add_action('deleted_post', 'irisid_trigger_revalidate_for_id', 20);
add_action('untrashed_post', 'irisid_trigger_revalidate_for_id', 20);

function irisid_trigger_revalidate(int $postId, WP_Post $post, bool $update): void
{
    unset($update);
    irisid_send_revalidate($post);
}

function irisid_trigger_revalidate_on_status(string $newStatus, string $oldStatus, WP_Post $post): void
{
    if ($newStatus === $oldStatus) {
        return;
    }
    // Publish, unpublish, trash, and restore all need the public cache dropped.
    if (!in_array($newStatus, ['publish', 'future', 'trash', 'draft', 'private'], true)
        && !in_array($oldStatus, ['publish', 'future'], true)) {
        return;
    }
    irisid_send_revalidate($post);
}

function irisid_trigger_revalidate_for_id(int $postId): void
{
    $post = get_post($postId);
    if ($post instanceof WP_Post) {
        irisid_send_revalidate($post);
    }
}

function irisid_send_revalidate(WP_Post $post): void
{
    if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
        return;
    }

    $url    = defined('IRISID_REVALIDATE_URL') ? IRISID_REVALIDATE_URL : getenv('IRISID_REVALIDATE_URL');
    $secret = defined('IRISID_REVALIDATE_SECRET') ? IRISID_REVALIDATE_SECRET : getenv('IRISID_REVALIDATE_SECRET');

    if (!$url || !$secret) {
        return;
    }

    $tag = irisid_revalidate_tag_for_post($post);

    wp_remote_post($url, [
        'timeout' => 5,
        'headers' => [
            'Content-Type'        => 'application/json',
            'x-revalidate-secret' => $secret,
        ],
        'body'    => wp_json_encode(['tag' => $tag]),
    ]);
}

function irisid_revalidate_tag_for_post(WP_Post $post): string
{
    $type = $post->post_type;
    $slug = $post->post_name;

    // switch (not match) – WP-CLI on the VPS may still be PHP 7.4.
    switch ($type) {
        case 'product':
            return "product:{$slug}";
        case 'solution':
            return "solution:{$slug}";
        case 'resource':
            return "resource:{$slug}";
        case 'download':
            return 'downloads';
        case 'faq':
            return 'faq';
        case 'page':
            return "page:{$slug}";
        default:
            return 'content';
    }
}
