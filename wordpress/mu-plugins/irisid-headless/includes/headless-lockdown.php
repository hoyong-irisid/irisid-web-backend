<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Block public theme rendering on cms.* — admin, GraphQL, REST, cron only.
 */
add_action('template_redirect', 'irisid_headless_block_frontend', 0);

function irisid_headless_block_frontend(): void
{
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }

    if (irisid_is_allowed_public_route()) {
        return;
    }

    status_header(403);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo "Iris ID CMS — headless backend. Public site: https://irisid.com\n";
    exit;
}

function irisid_is_allowed_public_route(): bool
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

    $allowedPrefixes = [
        '/graphql',
        '/wp-json/',
        '/wp-admin/',
        '/wp-login.php',
        '/wp-cron.php',
        '/?rest_route=',
    ];

    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($uri, $prefix)) {
            return true;
        }
    }

    return false;
}

add_action('send_headers', 'irisid_headless_noindex');

function irisid_headless_noindex(): void
{
    if (!is_admin()) {
        header('X-Robots-Tag: noindex, nofollow', true);
    }
}

add_filter('blog_public', '__return_zero');
