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

/**
 * CMS resource_type taxonomy slug → public Next.js board slug.
 *
 * @return array<string, string>
 */
function irisid_resource_type_public_board_map(): array
{
    return [
        'news-media'              => 'news-media',
        'press-release'           => 'press-release',
        'blog'                    => 'insights',
        'events'                  => 'events',
        'videos'                  => 'videos',
        'iris-id-talk'            => 'iris-id-talk',
        'case-studies'            => 'case-studies',
        'webinars'                => 'webinars',
        'tip-sheets'              => 'tip-sheets',
        'use-cases-white-papers'  => 'use-cases-white-papers',
        'literature'              => 'literature',
        'data-sheets'             => 'data-sheets',
        'architect-engineer-consulting' => 'architect-engineer-consulting',
    ];
}

/**
 * Public site paths to purge for this post (trailing slash).
 *
 * @return list<string>
 */
function irisid_revalidate_paths_for_post(WP_Post $post): array
{
    $slug = $post->post_name;
    if ($slug === '') {
        return [];
    }

    switch ($post->post_type) {
        case 'resource':
            $paths = [];
            $terms = get_the_terms($post->ID, 'resource_type');
            $map = irisid_resource_type_public_board_map();
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if (!($term instanceof WP_Term)) {
                        continue;
                    }
                    $board = $map[$term->slug] ?? $term->slug;
                    if ($board === '') {
                        continue;
                    }
                    $paths[] = "/resources/{$board}/{$slug}/";
                    $paths[] = "/resources/{$board}/";
                }
            }
            if ($paths === []) {
                // Unknown type – still drop news-media style guess so editors aren't stuck.
                $paths[] = "/resources/news-media/{$slug}/";
            }
            $paths[] = '/resources/';
            $paths[] = '/resources/newsroom/';
            return array_values(array_unique($paths));

        case 'product':
            return ["/products/hardware-products/{$slug}/", '/products/', '/products/hardware-products/'];

        case 'solution':
            return ["/solutions/{$slug}/", '/solutions/'];

        case 'page':
            return $slug === 'home' || $slug === 'front-page' ? ['/'] : ["/{$slug}/"];

        default:
            return [];
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
    $paths = irisid_revalidate_paths_for_post($post);

    // Wait long enough for Next to purge the specific page nginx entries.
    wp_remote_post($url, [
        'timeout' => 20,
        'headers' => [
            'Content-Type'        => 'application/json',
            'x-revalidate-secret' => $secret,
        ],
        'body'    => wp_json_encode([
            'tag'   => $tag,
            'paths' => $paths,
        ]),
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
