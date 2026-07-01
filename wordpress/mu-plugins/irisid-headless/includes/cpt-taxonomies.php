<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function irisid_register_content_types(): void
{
    irisid_register_taxonomies();
    irisid_register_post_types();
    irisid_seed_resource_type_terms();
}

function irisid_register_taxonomies(): void
{
    $common = [
        'public'            => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_in_graphql'   => true,
        'hierarchical'      => true,
    ];

    register_taxonomy('product_type', ['product'], array_merge($common, [
        'labels'              => irisid_tax_labels('Product Type', 'Product Types'),
        'rewrite'             => ['slug' => 'products', 'with_front' => false, 'hierarchical' => true],
        'graphql_single_name' => 'productType',
        'graphql_plural_name' => 'productTypes',
    ]));

    register_taxonomy('solution_tax', ['product'], array_merge($common, [
        'labels'              => irisid_tax_labels('Solution', 'Solutions (Product)'),
        'rewrite'             => ['slug' => 'solution-tax', 'with_front' => false],
        'graphql_single_name' => 'solutionTax',
        'graphql_plural_name' => 'solutionTaxes',
    ]));

    register_taxonomy('resource_type', ['resource'], array_merge($common, [
        'labels'              => irisid_tax_labels('Resource Type', 'Resource Types'),
        'rewrite'             => ['slug' => 'resources', 'with_front' => false, 'hierarchical' => true],
        'graphql_single_name' => 'resourceType',
        'graphql_plural_name' => 'resourceTypes',
    ]));

    register_taxonomy('download_type', ['download'], array_merge($common, [
        'labels'              => irisid_tax_labels('Download Type', 'Download Types'),
        'rewrite'             => ['slug' => 'download-type', 'with_front' => false],
        'graphql_single_name' => 'downloadType',
        'graphql_plural_name' => 'downloadTypes',
    ]));

    register_taxonomy('faq_category', ['faq'], array_merge($common, [
        'labels'              => irisid_tax_labels('FAQ Category', 'FAQ Categories'),
        'rewrite'             => ['slug' => 'faq-category', 'with_front' => false],
        'graphql_single_name' => 'faqCategory',
        'graphql_plural_name' => 'faqCategories',
    ]));
}

function irisid_register_post_types(): void
{
    $common = [
        'public'              => true,
        'show_ui'             => true,
        'show_in_rest'        => true,
        'show_in_graphql'     => true,
        'has_archive'         => false,
        'supports'            => ['title', 'editor', 'thumbnail', 'revisions', 'custom-fields'],
        'rewrite'             => ['with_front' => false],
    ];

    register_post_type('product', array_merge($common, [
        'labels'              => irisid_cpt_labels('Product', 'Products'),
        'menu_icon'           => 'dashicons-products',
        'rewrite'             => ['slug' => 'product', 'with_front' => false],
        'graphql_single_name' => 'product',
        'graphql_plural_name' => 'products',
    ]));

    register_post_type('solution', array_merge($common, [
        'labels'              => irisid_cpt_labels('Solution', 'Solutions'),
        'menu_icon'           => 'dashicons-lightbulb',
        'rewrite'             => ['slug' => 'solution', 'with_front' => false],
        'graphql_single_name' => 'solution',
        'graphql_plural_name' => 'solutions',
    ]));

    register_post_type('resource', array_merge($common, [
        'labels'              => irisid_cpt_labels('Resource', 'Resources'),
        'menu_icon'           => 'dashicons-media-document',
        'rewrite'             => ['slug' => 'resource', 'with_front' => false],
        'graphql_single_name' => 'resource',
        'graphql_plural_name' => 'resources',
    ]));

    register_post_type('download', array_merge($common, [
        'labels'              => irisid_cpt_labels('Download', 'Downloads'),
        'menu_icon'           => 'dashicons-download',
        'rewrite'             => ['slug' => 'download', 'with_front' => false],
        'graphql_single_name' => 'download',
        'graphql_plural_name' => 'downloads',
    ]));

    register_post_type('faq', array_merge($common, [
        'labels'              => irisid_cpt_labels('FAQ', 'FAQs'),
        'menu_icon'           => 'dashicons-editor-help',
        'rewrite'             => ['slug' => 'faq-item', 'with_front' => false],
        'graphql_single_name' => 'faq',
        'graphql_plural_name' => 'faqs',
    ]));
}

/** Seed resource_type terms matching live irisid.com archive slugs. */
function irisid_seed_resource_type_terms(): void
{
    if (get_option('irisid_resource_types_seeded')) {
        return;
    }

    $terms = [
        'news-media'    => 'News & Media',
        'press-release' => 'Press Release',
        'insights'      => 'Insights',
        'events'        => 'Events',
        'videos'        => 'Videos',
        'iris-id-talk'  => 'Iris ID Talk',
        'case-studies'  => 'Case Studies',
        'webinars'      => 'Webinars',
        'literature'    => 'Literature',
        'tip-sheets'    => 'Tip Sheets',
    ];

    foreach ($terms as $slug => $name) {
        if (!term_exists($slug, 'resource_type')) {
            wp_insert_term($name, 'resource_type', ['slug' => $slug]);
        }
    }

    $productTypes = [
        'hardware-products' => 'Hardware Products',
        'software-products' => 'Software Products',
    ];
    foreach ($productTypes as $slug => $name) {
        if (!term_exists($slug, 'product_type')) {
            wp_insert_term($name, 'product_type', ['slug' => $slug]);
        }
    }

    update_option('irisid_resource_types_seeded', 1, false);
}

/** @return array<string, string> */
function irisid_cpt_labels(string $singular, string $plural): array
{
    return [
        'name'          => $plural,
        'singular_name' => $singular,
        'add_new_item'  => "Add New {$singular}",
        'edit_item'     => "Edit {$singular}",
        'view_item'     => "View {$singular}",
        'search_items'  => "Search {$plural}",
    ];
}

/** @return array<string, string> */
function irisid_tax_labels(string $singular, string $plural): array
{
    return [
        'name'          => $plural,
        'singular_name' => $singular,
        'search_items'  => "Search {$plural}",
        'all_items'     => "All {$plural}",
        'edit_item'     => "Edit {$singular}",
        'add_new_item'  => "Add New {$singular}",
    ];
}
