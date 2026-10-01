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
/** Keep Related / External out of the day-to-day layout editors (still in GraphQL). */
add_filter('acf/prepare_field/key=field_irisid_resource_external', '__return_false');
add_filter('acf/prepare_field/key=field_irisid_resource_products', '__return_false');
add_filter('acf/prepare_field/key=field_irisid_resource_solutions', '__return_false');

/**
 * Resource Type (layout) sits first via acf_after_title.
 * Hide the native WP title so editors use Title inside Resource Fields instead.
 */
add_action('admin_head', 'irisid_resource_hide_native_title');

function irisid_resource_hide_native_title(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'resource') {
        return;
    }
    echo '<style id="irisid-resource-title-order">'
        . 'body.post-type-resource #titlediv{display:none!important;}'
        . 'body.post-type-resource #acf-group_irisid_resource_layout{margin-top:0;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_title"] input[type="text"]{'
        . 'font-size:1.4em;padding:8px 10px;width:100%;}'
        . '</style>';
}

/** Prefill ACF Title from the real post_title when editing. */
add_filter('acf/load_value/key=field_irisid_resource_title', 'irisid_resource_title_load_value', 10, 2);

function irisid_resource_title_load_value($value, $postId)
{
    if (!is_numeric($postId)) {
        return $value;
    }
    if (is_string($value) && trim($value) !== '') {
        return $value;
    }
    $title = get_post_field('post_title', (int) $postId);
    return is_string($title) ? $title : $value;
}

add_action('acf/save_post', 'irisid_generate_resource_excerpt', 20);
add_action('acf/save_post', 'irisid_sync_resource_title_to_post', 25);

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

/** Copy Resource Fields → Title into the WordPress post_title. */
function irisid_sync_resource_title_to_post($postId): void
{
    if (!is_numeric($postId) || get_post_type((int) $postId) !== 'resource') {
        return;
    }
    $postId = (int) $postId;
    $title = trim((string) get_field('resource_title', $postId));
    if ($title === '') {
        return;
    }
    $current = get_post_field('post_title', $postId);
    if (is_string($current) && $current === $title) {
        return;
    }

    remove_action('acf/save_post', 'irisid_sync_resource_title_to_post', 25);
    wp_update_post([
        'ID'         => $postId,
        'post_title' => $title,
    ]);
    add_action('acf/save_post', 'irisid_sync_resource_title_to_post', 25);
}

/**
 * Map sidebar Resource Type taxonomy → List / Gallery / File / Event layout.
 * Editors can still override the layout manually.
 */
function irisid_resource_layout_for_type_slug(string $slug): ?string
{
    $map = [
        'news-media'    => 'list',
        'press-release' => 'list',
        'insights'      => 'list',
        'iris-id-talk'  => 'list',
        'case-studies'  => 'list',
        'videos'        => 'gallery',
        'webinars'      => 'gallery',
        'data-sheets'   => 'file',
        'tip-sheets'    => 'file',
        'literature'    => 'file',
        'events'        => 'event',
    ];
    return $map[$slug] ?? null;
}

/** Admin: sync layout from taxonomy checkboxes + show File/Sheet panel only for File. */
add_action('admin_enqueue_scripts', 'irisid_resource_layout_admin_script');

function irisid_resource_layout_admin_script(string $hook): void
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'resource') {
        return;
    }

    $typeToLayout = [
        'news-media'    => 'list',
        'press-release' => 'list',
        'insights'      => 'list',
        'iris-id-talk'  => 'list',
        'case-studies'  => 'list',
        'videos'        => 'gallery',
        'webinars'      => 'gallery',
        'data-sheets'   => 'file',
        'tip-sheets'    => 'file',
        'literature'    => 'file',
        'events'        => 'event',
    ];

    $termIdToSlug = [];
    $terms = get_terms([
        'taxonomy'   => 'resource_type',
        'hide_empty' => false,
    ]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $termIdToSlug[(string) $term->term_id] = $term->slug;
        }
    }

    $typeJson = wp_json_encode($typeToLayout);
    $termJson = wp_json_encode($termIdToSlug);

    wp_add_inline_script(
        'jquery-core',
        <<<JS
        (function () {
            var TYPE_TO_LAYOUT = {$typeJson};
            var TERM_ID_TO_SLUG = {$termJson};

            function boot() {
                if (typeof acf === 'undefined') return;
                acf.addAction('ready', function () {
                    var layoutField = acf.getField('field_irisid_resource_layout');
                    if (!layoutField) return;

                    function currentLayout() {
                        return String(layoutField.val() || 'list');
                    }

                    function syncSheetPanel() {
                        var isFile = currentLayout() === 'file';
                        var sheet =
                            document.getElementById('acf-group_irisid_resource_sheet') ||
                            document.querySelector('.postbox[id*="group_irisid_resource_sheet"]');
                        if (!sheet) return;
                        sheet.style.display = isFile ? '' : 'none';
                    }

                    /**
                     * Cross-group ACF conditionals are unreliable here – drive
                     * Resource Fields visibility from the layout selector explicitly.
                     * Keys match group_irisid_resource.json.
                     */
                    var LAYOUT_FIELDS = {
                        list: [
                            'field_irisid_resource_display_date',
                            'field_irisid_resource_featured',
                            'field_irisid_resource_body',
                            'field_irisid_resource_attachment'
                        ],
                        gallery: [
                            'field_irisid_resource_featured',
                            'field_irisid_resource_video'
                        ],
                        file: [
                            'field_irisid_resource_featured',
                            'field_irisid_resource_file_name',
                            'field_irisid_resource_attachment'
                        ],
                        event: [
                            'field_irisid_resource_featured',
                            'field_irisid_resource_body',
                            'field_irisid_resource_event_date',
                            'field_irisid_resource_event_ends',
                            'field_irisid_resource_event_visibility'
                        ]
                    };
                    var ALL_LAYOUT_FIELDS = {};
                    Object.keys(LAYOUT_FIELDS).forEach(function (k) {
                        LAYOUT_FIELDS[k].forEach(function (key) { ALL_LAYOUT_FIELDS[key] = true; });
                    });

                    function syncResourceFields() {
                        var layout = currentLayout();
                        if (!LAYOUT_FIELDS[layout]) layout = 'list';
                        var show = {};
                        LAYOUT_FIELDS[layout].forEach(function (key) { show[key] = true; });

                        Object.keys(ALL_LAYOUT_FIELDS).forEach(function (key) {
                            var field = acf.getField(key);
                            if (!field || !field.$el || !field.$el.length) return;
                            if (show[key]) {
                                field.show();
                                field.$el.removeClass('irisid-layout-hidden');
                            } else {
                                field.hide();
                                field.$el.addClass('irisid-layout-hidden');
                            }
                        });
                        syncSheetPanel();
                    }

                    function layoutFromCheckedTypes() {
                        var layouts = [];
                        var inputs = document.querySelectorAll(
                            '#resource_typediv input[type="checkbox"], #taxonomy-resource_type input[type="checkbox"]'
                        );
                        inputs.forEach(function (input) {
                            if (!input.checked) return;
                            var slug = TERM_ID_TO_SLUG[String(input.value)] || '';
                            if (slug && TYPE_TO_LAYOUT[slug]) {
                                layouts.push(TYPE_TO_LAYOUT[slug]);
                            }
                        });
                        if (!layouts.length) return null;
                        if (layouts.indexOf('event') !== -1) return 'event';
                        if (layouts.indexOf('file') !== -1) return 'file';
                        if (layouts.indexOf('gallery') !== -1) return 'gallery';
                        return 'list';
                    }

                    var manualOverride = false;
                    layoutField.$el.on('click', 'input, button, .acf-button-group label', function () {
                        manualOverride = true;
                    });

                    function maybeApplyFromTaxonomy() {
                        if (!manualOverride) {
                            var next = layoutFromCheckedTypes();
                            if (next && next !== currentLayout()) {
                                layoutField.val(next);
                            }
                        }
                        syncResourceFields();
                    }

                    layoutField.on('change', syncResourceFields);
                    document.addEventListener('change', function (e) {
                        var t = e.target;
                        if (!t || !t.closest) return;
                        if (t.closest('#resource_typediv') || t.closest('#taxonomy-resource_type')) {
                            maybeApplyFromTaxonomy();
                        }
                    });

                    maybeApplyFromTaxonomy();
                    syncResourceFields();
                });
            }

            if (window.acf) boot();
            else document.addEventListener('DOMContentLoaded', boot);
        })();
        JS,
        'after'
    );
}

/**
 * Backfill resource_layout from taxonomy for posts that do not have it yet.
 * Runs once per edit-screen load (cheap) and on save.
 */
add_action('acf/save_post', 'irisid_ensure_resource_layout_on_save', 5);

function irisid_ensure_resource_layout_on_save($postId): void
{
    if (!is_numeric($postId) || get_post_type((int) $postId) !== 'resource') {
        return;
    }
    $postId = (int) $postId;
    $existing = get_field('resource_layout', $postId);
    if (is_string($existing) && $existing !== '') {
        return;
    }
    $terms = wp_get_post_terms($postId, 'resource_type', ['fields' => 'slugs']);
    if (is_wp_error($terms) || !$terms) {
        update_field('field_irisid_resource_layout', 'list', $postId);
        return;
    }
    $layout = 'list';
    foreach ($terms as $slug) {
        $mapped = irisid_resource_layout_for_type_slug((string) $slug);
        if ($mapped === 'event') {
            $layout = 'event';
            break;
        }
        if ($mapped === 'file') {
            $layout = 'file';
        } elseif ($mapped === 'gallery' && $layout !== 'file') {
            $layout = 'gallery';
        } elseif ($mapped === 'list' && $layout === 'list') {
            $layout = 'list';
        }
    }
    update_field('field_irisid_resource_layout', $layout, $postId);
}
