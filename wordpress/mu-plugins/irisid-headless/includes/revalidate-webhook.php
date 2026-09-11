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

function irisid_trigger_revalidate(int $postId, WP_Post $post, bool $update): void
{
    if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
        return;
    }

    if (!in_array($post->post_status, ['publish', 'future'], true)) {
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
            'Content-Type'       => 'application/json',
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
