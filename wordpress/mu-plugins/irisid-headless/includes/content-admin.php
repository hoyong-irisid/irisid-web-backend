<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keep wp-admin focused on the content that editors actually maintain.
 *
 * Products, solutions, pages, and the other legacy content types stay
 * registered because GraphQL and ACF relationships can still reference
 * existing records. Their screens are hidden from navigation because the
 * public website implementations are maintained in the Next.js codebase.
 */
add_action('admin_menu', 'irisid_simplify_content_admin_menu', 999);

function irisid_simplify_content_admin_menu(): void
{
    remove_menu_page('index.php');
    remove_menu_page('edit.php');
    remove_menu_page('edit.php?post_type=page');
    remove_menu_page('edit-comments.php');
    remove_menu_page('edit.php?post_type=product');
    remove_menu_page('edit.php?post_type=solution');
    remove_menu_page('edit.php?post_type=download');
    remove_menu_page('edit.php?post_type=faq');

    $parent = 'edit.php?post_type=resource';
    remove_submenu_page($parent, $parent);
    remove_submenu_page($parent, 'post-new.php?post_type=resource');
    remove_submenu_page($parent, 'edit-tags.php?taxonomy=resource_type&post_type=resource');
    global $submenu;
    if (isset($submenu[$parent]) && is_array($submenu[$parent])) {
        $submenu[$parent] = array_values(array_filter(
            $submenu[$parent],
            static function (array $item): bool {
                $url = isset($item[2]) ? (string) $item[2] : '';
                return strpos($url, 'taxonomy=resource_type') === false;
            }
        ));
    }

    add_submenu_page(
        $parent,
        'Resources',
        'Resources',
        'edit_posts',
        $parent
    );
    add_submenu_page(
        $parent,
        'Add New Resource',
        '<em>Add New Resource</em>',
        'edit_posts',
        'post-new.php?post_type=resource'
    );
    add_submenu_page(
        $parent,
        'Resource Type',
        '<em>Resource Type</em>',
        'manage_categories',
        'edit-tags.php?taxonomy=resource_type&post_type=resource'
    );

    $types = [
        'news-media'    => 'News & Media',
        'press-release' => 'Press Release',
        'events'        => 'Events',
        'insights'      => 'Blog',
        'videos'        => 'Videos',
        'webinars'      => 'Webinars',
        'iris-id-talk'  => 'Iris ID Talk',
        'data-sheets'   => 'Data Sheets',
        'case-studies'  => 'Case Studies',
        'tip-sheets'    => 'Tip Sheets',
    ];

    foreach ($types as $slug => $label) {
        add_submenu_page(
            $parent,
            $label,
            $label,
            'edit_posts',
            add_query_arg(
                [
                    'post_type'     => 'resource',
                    'resource_type' => $slug,
                ],
                'edit.php'
            )
        );
    }
}

add_action('admin_bar_menu', 'irisid_simplify_new_content_menu', 999);

function irisid_simplify_new_content_menu(WP_Admin_Bar $adminBar): void
{
    foreach (['new-post', 'new-page', 'new-product', 'new-solution', 'new-download', 'new-faq'] as $node) {
        $adminBar->remove_node($node);
    }
}

/** Send editors directly to the only website publishing list they use. */
add_action('load-index.php', 'irisid_redirect_dashboard_to_resources');

function irisid_redirect_dashboard_to_resources(): void
{
    wp_safe_redirect(admin_url('edit.php?post_type=resource'));
    exit;
}

add_action('admin_notices', 'irisid_resource_editor_notice');

function irisid_resource_editor_notice(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'resource') {
        return;
    }

    echo '<div class="notice notice-info"><p>';
    echo '<strong>Website publishing:</strong> Add and edit News, Press Releases, Insights, Events, Videos, Webinars, and other posts here. ';
    echo 'Website pages, products, and solutions are maintained in the frontend codebase.';
    echo '</p></div>';
}

/** Resource content uses ACF fields, so the duplicate native editor is unnecessary. */
add_filter('use_block_editor_for_post_type', 'irisid_disable_resource_block_editor', 10, 2);

function irisid_disable_resource_block_editor(bool $useBlockEditor, string $postType): bool
{
    return $postType === 'resource' ? false : $useBlockEditor;
}

add_action('init', 'irisid_simplify_resource_editor_supports', 20);

function irisid_simplify_resource_editor_supports(): void
{
    remove_post_type_support('resource', 'editor');
    remove_post_type_support('resource', 'thumbnail');
}

/** Excerpt is generated from Body and is not a manual editor field. */
add_filter('acf/prepare_field/key=field_irisid_resource_excerpt', '__return_false');
/**
 * Featured lives in the Publish box (see submitbox hooks below), not the
 * Resource Fields meta box – hide the ACF UI copy so editors only see one control.
 */
add_filter('acf/prepare_field/key=field_irisid_resource_is_featured', '__return_false');
add_action('acf/save_post', 'irisid_generate_resource_excerpt', 20);

/** Featured checkbox inside the Publish status box on resource edit screens. */
add_action('post_submitbox_misc_actions', 'irisid_resource_featured_submitbox');

function irisid_resource_featured_submitbox(): void
{
    global $post;
    if (!$post instanceof WP_Post || $post->post_type !== 'resource') {
        return;
    }

    $checked = (bool) get_field('is_featured', $post->ID);
    wp_nonce_field('irisid_resource_featured', 'irisid_resource_featured_nonce');
    echo '<div class="misc-pub-section irisid-resource-featured">';
    echo '<label for="irisid_is_featured" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">';
    printf(
        '<input type="checkbox" id="irisid_is_featured" name="irisid_is_featured" value="1"%s />',
        $checked ? ' checked="checked"' : ''
    );
    echo '<strong>Featured</strong>';
    echo '</label>';
    echo '<p class="description" style="margin:6px 0 0 22px;">Pin to the top Featured row on the Resources archive (up to 4 per type).</p>';
    echo '</div>';
}

add_action('save_post_resource', 'irisid_save_resource_featured_submitbox', 20, 2);

function irisid_save_resource_featured_submitbox(int $postId, WP_Post $post): void
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if ($post->post_type !== 'resource') {
        return;
    }
    if (!isset($_POST['irisid_resource_featured_nonce'])) {
        return;
    }
    $nonce = (string) wp_unslash($_POST['irisid_resource_featured_nonce']);
    if (!wp_verify_nonce($nonce, 'irisid_resource_featured')) {
        return;
    }
    if (!current_user_can('edit_post', $postId)) {
        return;
    }

    $isFeatured = isset($_POST['irisid_is_featured']) && (string) $_POST['irisid_is_featured'] === '1';
    update_field('field_irisid_resource_is_featured', $isFeatured ? 1 : 0, $postId);
}

function irisid_generate_resource_excerpt($postId): void
{
    if (!is_numeric($postId) || get_post_type((int) $postId) !== 'resource') {
        return;
    }

    $body = (string) get_field('body', (int) $postId);
    $plainText = trim(wp_strip_all_tags(strip_shortcodes($body)));
    $excerpt = $plainText === '' ? '' : wp_trim_words($plainText, 32, '…');

    update_field('field_irisid_resource_excerpt', $excerpt, (int) $postId);
}
