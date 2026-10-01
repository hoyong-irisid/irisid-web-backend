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
    // List screen only – keep edit/new screens clear so Resource Type
    // aligns with the Publish box at the top of the form.
    if (!$screen || $screen->post_type !== 'resource' || $screen->base !== 'edit') {
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
    remove_post_type_support('resource', 'revisions');
}

/** Drop Slug + Revisions meta boxes – List editors do not need them. */
add_action('add_meta_boxes', 'irisid_remove_resource_clutter_metaboxes', 99);

function irisid_remove_resource_clutter_metaboxes(): void
{
    remove_meta_box('revisionsdiv', 'resource', 'normal');
    remove_meta_box('slugdiv', 'resource', 'normal');
    remove_meta_box('slugdiv', 'resource', 'side');
}

/**
 * Resources list: Type (List/Gallery/File/Event) column immediately left of Date.
 *
 * @param array<string, string> $columns
 * @return array<string, string>
 */
add_filter('manage_edit-resource_columns', 'irisid_resource_admin_columns');

function irisid_resource_admin_columns(array $columns): array
{
    // Keep only checkbox + title + Type + date so Title can use the width.
    $cb = $columns['cb'] ?? '';
    $title = $columns['title'] ?? 'Title';
    $date = $columns['date'] ?? 'Date';
    return [
        'cb'             => $cb,
        'title'          => $title,
        'irisid_layout'  => 'Type',
        'date'           => $date,
    ];
}

add_action('manage_resource_posts_custom_column', 'irisid_resource_admin_column_content', 10, 2);
add_action('admin_head-edit.php', 'irisid_resource_list_column_styles');

function irisid_resource_list_column_styles(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'resource') {
        return;
    }
    echo '<style id="irisid-resource-list-cols">'
        . '.wp-list-table.fixed .column-irisid_layout{width:88px;}'
        . '.wp-list-table.fixed .column-date{width:140px;}'
        . '.wp-list-table.fixed .column-title{width:auto;}'
        . '</style>';
}

function irisid_resource_admin_column_content(string $column, int $post_id): void
{
    if ($column !== 'irisid_layout') {
        return;
    }
    $layout = (string) get_field('resource_layout', $post_id);
    $labels = [
        'list'    => 'List',
        'gallery' => 'Gallery',
        'file'    => 'File',
        'event'   => 'Event',
    ];
    // Default everything to List until an editor explicitly picks another type.
    if ($layout === '' || !isset($labels[$layout])) {
        $layout = 'list';
    }
    echo esc_html($labels[$layout]);
}

/** Description (excerpt) is edited for Gallery/Event cards; List keeps auto excerpt from Body. */
/** Keep Related / External out of the day-to-day layout editors (still in GraphQL). */
add_filter('acf/prepare_field/key=field_irisid_resource_external', '__return_false');
add_filter('acf/prepare_field/key=field_irisid_resource_products', '__return_false');
add_filter('acf/prepare_field/key=field_irisid_resource_solutions', '__return_false');

/**
 * Resource Type sits in acf_after_title (top, aligned with Publish).
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
        . 'body.post-type-resource #titlediv,'
        . 'body.post-type-resource #titlewrap,'
        . 'body.post-type-resource #edit-slug-box{'
        . 'display:none!important;height:0!important;margin:0!important;padding:0!important;'
        . 'border:0!important;overflow:hidden!important;}'
        . 'body.post-type-resource #post-body-content{'
        . 'margin-top:0!important;padding-top:0!important;}'
        /* Keep after_title visible; kill sortable empty-height that made a huge gap. */
        . 'body.post-type-resource #acf_after_title-sortables{'
        . 'margin:0!important;padding:0!important;min-height:0!important;height:auto!important;}'
        . 'body.post-type-resource #acf_after_title-sortables .ui-sortable-placeholder{'
        . 'display:none!important;height:0!important;margin:0!important;padding:0!important;}'
        . 'body.post-type-resource #acf-group_irisid_resource_layout{'
        . 'margin:0 0 20px!important;}'
        . 'body.post-type-resource #normal-sortables{'
        . 'margin-top:0!important;padding-top:0!important;min-height:0!important;}'
        . 'body.post-type-resource #normal-sortables > .postbox,'
        . 'body.post-type-resource #side-sortables > .postbox{'
        . 'margin-bottom:20px!important;}'
        . 'body.post-type-resource #post-body-content > .meta-box-sortables{'
        . 'min-height:0!important;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_layout"]{padding-top:12px;padding-bottom:6px;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_layout"] .acf-radio-list{'
        . 'display:flex;flex-wrap:wrap;gap:14px 20px;margin:0;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_layout"] .acf-radio-list li{margin:0;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_layout"] .acf-radio-list label{'
        . 'font-size:13px;font-weight:600;color:#1d2327;}'
        . 'body.post-type-resource .acf-field[data-key^="field_irisid_resource_layout_help_"]{'
        . 'padding-top:2px;padding-bottom:12px;border-top:0!important;}'
        . 'body.post-type-resource .acf-field[data-key^="field_irisid_resource_layout_help_"] .acf-label{display:none!important;}'
        . 'body.post-type-resource .acf-field[data-key^="field_irisid_resource_layout_help_"] .acf-input,'
        . 'body.post-type-resource .acf-field[data-key^="field_irisid_resource_layout_help_"] .acf-input p{'
        . 'font-size:13px;line-height:1.5;color:#646970;margin:0;}'
        . 'body.post-type-resource .irisid-layout-help{display:none!important;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_title"] input[type="text"]{'
        . 'font-size:1.4em;padding:8px 10px;width:100%;}'
        /* Display date (half) + Show checkbox on the same row. */
        . 'body.post-type-resource .acf-field.irisid-display-date,'
        . 'body.post-type-resource .acf-field.irisid-show-display-date{'
        . 'clear:none;}'
        . 'body.post-type-resource .acf-field.irisid-show-display-date .acf-label{'
        . 'margin-bottom:8px;}'
        . 'body.post-type-resource .acf-field.irisid-show-display-date .acf-switch{'
        . 'margin-top:2px;}'
        . 'body.post-type-resource .acf-field.irisid-layout-hidden{'
        . 'display:none!important;}'
        /* CSS gate: hide layout-specific fields until body has the matching class.
           Default (no class yet) is treated as List so Add New never flashes File/Event fields. */
        . 'body.post-type-resource #slugdiv,'
        . 'body.post-type-resource #revisionsdiv{'
        . 'display:none!important;}'
        . 'body.post-type-resource:not(.irisid-layout-gallery)'
        . ' .acf-field[data-key="field_irisid_resource_video"]{'
        . 'display:none!important;}'
        . 'body.post-type-resource:not(.irisid-layout-file)'
        . ' .acf-field[data-key="field_irisid_resource_file_name"],'
        . 'body.post-type-resource:not(.irisid-layout-file)'
        . ' .acf-field[data-key="field_irisid_resource_attachment"]{'
        . 'display:none!important;}'
        . 'body.post-type-resource:not(.irisid-layout-event)'
        . ' .acf-field[data-key="field_irisid_resource_event_date"],'
        . 'body.post-type-resource:not(.irisid-layout-event)'
        . ' .acf-field[data-key="field_irisid_resource_event_ends"],'
        . 'body.post-type-resource:not(.irisid-layout-event)'
        . ' .acf-field[data-key="field_irisid_resource_event_visibility"]{'
        . 'display:none!important;}'
        /* Display date: List + Gallery cards */
        . 'body.post-type-resource:not(.irisid-layout-list):not(.irisid-layout-gallery)'
        . ' .acf-field[data-key="field_irisid_resource_display_date"],'
        . 'body.post-type-resource:not(.irisid-layout-list):not(.irisid-layout-gallery)'
        . ' .acf-field[data-key="field_irisid_resource_show_display_date"]{'
        . 'display:none!important;}'
        /* Description: Gallery + Event cards */
        . 'body.post-type-resource:not(.irisid-layout-gallery):not(.irisid-layout-event)'
        . ' .acf-field[data-key="field_irisid_resource_excerpt"]{'
        . 'display:none!important;}'
        /* Body: List + Event only. Force visible so ACF cross-group conditionals cannot drop it. */
        . 'body.post-type-resource.irisid-layout-gallery'
        . ' .acf-field[data-key="field_irisid_resource_body"],'
        . 'body.post-type-resource.irisid-layout-file'
        . ' .acf-field[data-key="field_irisid_resource_body"]{'
        . 'display:none!important;}'
        . 'body.post-type-resource.irisid-layout-list'
        . ' .acf-field[data-key="field_irisid_resource_body"],'
        . 'body.post-type-resource.irisid-layout-event'
        . ' .acf-field[data-key="field_irisid_resource_body"],'
        . 'body.post-type-resource:not(.irisid-layout-gallery):not(.irisid-layout-file)'
        . ' .acf-field[data-key="field_irisid_resource_body"]{'
        . 'display:block!important;}'
        . '</style>';
    echo '<script id="irisid-resource-align-top">(function(){'
        . 'function layoutFromDom(){'
        . 'var checked=document.querySelector('
        . '".acf-field[data-key=\\"field_irisid_resource_layout\\"] input[type=radio]:checked");'
        . 'return checked&&checked.value?checked.value:"list";'
        . '}'
        . 'function applyLayoutClass(){'
        . 'var layout=layoutFromDom();'
        . '["list","gallery","file","event"].forEach(function(l){'
        . 'document.body.classList.toggle("irisid-layout-"+l,l===layout);'
        . '});'
        . '}'
        . 'function align(){'
        . 'var content=document.getElementById("post-body-content");'
        . 'if(!content)return;'
        . 'var title=document.getElementById("titlediv");'
        . 'if(title)title.remove();'
        . 'var sortables=document.getElementById("acf_after_title-sortables");'
        . 'if(sortables&&content.firstElementChild!==sortables){'
        . 'content.insertBefore(sortables,content.firstElementChild);'
        . '}'
        . 'if(sortables){sortables.style.minHeight="0";sortables.style.height="auto";}'
        . 'applyLayoutClass();'
        . '}'
        . 'document.body.classList.add("irisid-layout-list");'
        . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",align);'
        . 'else align();'
        . 'window.addEventListener("load",align);'
        . 'document.addEventListener("change",function(e){'
        . 'var t=e.target;if(!t||!t.closest)return;'
        . 'if(t.closest(".acf-field[data-key=\\"field_irisid_resource_layout\\"]"))applyLayoutClass();'
        . '});'
        . '})();</script>';
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

    $postId = (int) $postId;
    $layout = (string) get_field('resource_layout', $postId);
    // Gallery/Event editors enter Description manually for the 4-up cards.
    if ($layout === 'gallery' || $layout === 'event') {
        return;
    }

    $body = (string) get_field('body', $postId);
    $plainText = trim(wp_strip_all_tags(strip_shortcodes($body)));
    $excerpt = $plainText === '' ? '' : wp_trim_words($plainText, 32, '…');

    update_field('field_irisid_resource_excerpt', $excerpt, $postId);
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

    // Must run after ACF – jquery-core alone fires before `acf` exists.
    wp_enqueue_script('acf-input');
    wp_add_inline_script(
        'acf-input',
        <<<JS
        (function () {
            var TYPE_TO_LAYOUT = {$typeJson};
            var TERM_ID_TO_SLUG = {$termJson};
            var LAYOUT_HELP = {
                list: {
                    title: 'List',
                    body: 'News & Media, Press Release, Blog, Iris ID Talk, Case Studies'
                },
                gallery: {
                    title: 'Gallery',
                    body: 'Videos, Webinars'
                },
                file: {
                    title: 'File',
                    body: 'Data Sheets, Tip Sheets, Use Cases & White Papers'
                },
                event: {
                    title: 'Event',
                    body: 'Events & Exhibition'
                }
            };

            function start() {
                if (typeof acf === 'undefined') {
                    window.setTimeout(start, 50);
                    return;
                }
                acf.addAction('ready', bindLayoutUi);
                // If ACF already fired ready before this script attached:
                if (acf.getField && acf.getField('field_irisid_resource_layout')) {
                    bindLayoutUi();
                }
            }

            var bound = false;
            function bindLayoutUi() {
                if (bound) return;
                var layoutField = acf.getField('field_irisid_resource_layout');
                if (!layoutField) return;
                bound = true;

                function currentLayout() {
                    return String(layoutField.val() || 'list');
                }

                function ensureHelpEl() {
                    var existing = document.getElementById('irisid-layout-help');
                    if (existing) return existing;

                    var help = document.createElement('div');
                    help.id = 'irisid-layout-help';
                    help.className = 'irisid-layout-help';
                    help.setAttribute('aria-live', 'polite');

                    // Prefer inside the field input; fall back to the Resource Type postbox.
                    var input = layoutField.$el && layoutField.$el.find
                        ? layoutField.$el.find('.acf-input').get(0)
                        : null;
                    if (input) {
                        input.appendChild(help);
                        return help;
                    }
                    var box =
                        document.querySelector('#acf-group_irisid_resource_layout .inside') ||
                        document.querySelector('.postbox[id*="group_irisid_resource_layout"] .inside');
                    if (box) {
                        box.appendChild(help);
                        return help;
                    }
                    return null;
                }

                function syncLayoutHelp() {
                    var help = ensureHelpEl();
                    if (!help) return;
                    var layout = currentLayout();
                    var copy = LAYOUT_HELP[layout] || LAYOUT_HELP.list;
                    help.innerHTML =
                        '<strong>' + copy.title + '</strong>' +
                        '<div>' + copy.body + '</div>';
                }

                function syncSheetPanel() {
                    var isFile = currentLayout() === 'file';
                    var sheet =
                        document.getElementById('acf-group_irisid_resource_sheet') ||
                        document.querySelector('.postbox[id*="group_irisid_resource_sheet"]');
                    if (!sheet) return;
                    sheet.style.display = isFile ? '' : 'none';
                }

                // Per-type Resource Fields (Title always shown).
                // List:   date, image, body
                // Gallery: video URL, thumbnail, date, description  (4-up cards)
                // File:    thumbnail, file name, attachment + Sheet panel (date, version)
                // Event:   thumbnail, dates, description, body
                var LAYOUT_FIELDS = {
                    list: [
                        'field_irisid_resource_display_date',
                        'field_irisid_resource_show_display_date',
                        'field_irisid_resource_featured',
                        'field_irisid_resource_body'
                    ],
                    gallery: [
                        'field_irisid_resource_video',
                        'field_irisid_resource_featured',
                        'field_irisid_resource_display_date',
                        'field_irisid_resource_show_display_date',
                        'field_irisid_resource_excerpt'
                    ],
                    file: [
                        'field_irisid_resource_featured',
                        'field_irisid_resource_file_name',
                        'field_irisid_resource_attachment'
                    ],
                    event: [
                        'field_irisid_resource_featured',
                        'field_irisid_resource_event_date',
                        'field_irisid_resource_event_ends',
                        'field_irisid_resource_event_visibility',
                        'field_irisid_resource_excerpt',
                        'field_irisid_resource_body'
                    ]
                };
                var ALL_LAYOUT_FIELDS = {};
                Object.keys(LAYOUT_FIELDS).forEach(function (k) {
                    LAYOUT_FIELDS[k].forEach(function (key) { ALL_LAYOUT_FIELDS[key] = true; });
                });

                function applyBodyLayoutClass(layout) {
                    ['list', 'gallery', 'file', 'event'].forEach(function (l) {
                        document.body.classList.toggle('irisid-layout-' + l, l === layout);
                    });
                }

                function syncResourceFields() {
                    var layout = currentLayout();
                    if (!LAYOUT_FIELDS[layout]) layout = 'list';
                    applyBodyLayoutClass(layout);
                    var show = {};
                    LAYOUT_FIELDS[layout].forEach(function (key) { show[key] = true; });

                    Object.keys(ALL_LAYOUT_FIELDS).forEach(function (key) {
                        var els = document.querySelectorAll('.acf-field[data-key="' + key + '"]');
                        els.forEach(function (el) {
                            if (show[key]) {
                                el.classList.remove('irisid-layout-hidden');
                                el.style.display = '';
                            } else {
                                el.classList.add('irisid-layout-hidden');
                                el.style.display = 'none';
                            }
                        });
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
                    syncLayoutHelp();
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
                layoutField.$el.on('click', 'input[type="radio"], label', function () {
                    manualOverride = true;
                    // Radio updates value after the click handler – refresh next tick.
                    window.setTimeout(function () {
                        syncResourceFields();
                    }, 0);
                });

                // Default Type is List. Do not auto-flip from Resource Types taxonomy.
                layoutField.on('change', function () {
                    syncResourceFields();
                });
                syncResourceFields();
            }

            start();
        })();
        JS,
        'after'
    );
}

/**
 * Default resource_layout to List when unset.
 * Editors can still switch to Gallery / File / Event manually.
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
    update_field('field_irisid_resource_layout', 'list', $postId);
}

/**
 * One-time: reset every resource Type to List (starting default).
 * Flag option avoids re-running after editors intentionally pick Gallery/File/Event.
 */
add_action('admin_init', 'irisid_reset_resource_layouts_to_list_once');

function irisid_reset_resource_layouts_to_list_once(): void
{
    if (get_option('irisid_resource_layouts_defaulted_to_list_v1')) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    // ACF stores the value under resource_layout and the field key under _resource_layout.
    $wpdb->query(
        "UPDATE {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         SET pm.meta_value = 'list'
         WHERE p.post_type = 'resource'
           AND pm.meta_key = 'resource_layout'"
    );
    $wpdb->query(
        "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
         SELECT p.ID, 'resource_layout', 'list'
         FROM {$wpdb->posts} p
         WHERE p.post_type = 'resource'
           AND NOT EXISTS (
             SELECT 1 FROM {$wpdb->postmeta} pm
             WHERE pm.post_id = p.ID AND pm.meta_key = 'resource_layout'
           )"
    );
    $wpdb->query(
        "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
         SELECT p.ID, '_resource_layout', 'field_irisid_resource_layout'
         FROM {$wpdb->posts} p
         WHERE p.post_type = 'resource'
           AND NOT EXISTS (
             SELECT 1 FROM {$wpdb->postmeta} pm
             WHERE pm.post_id = p.ID AND pm.meta_key = '_resource_layout'
           )"
    );

    update_option('irisid_resource_layouts_defaulted_to_list_v1', 1, false);
}
