<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function irisid_register_acf_options_pages(): void
{
    if (!function_exists('acf_add_options_page')) {
        return;
    }

    acf_add_options_page([
        'page_title'      => 'Site Settings',
        'menu_title'      => 'Site Settings',
        'menu_slug'       => 'irisid-site-settings',
        'capability'      => 'edit_posts',
        'redirect'        => false,
        'show_in_graphql' => true,
        'graphql_field_name' => 'siteSettings',
    ]);
}
