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
        'irisid-resource-types',
        'irisid_render_resource_types_page'
    );
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
    // Category is chosen in the Resource panel after Type – not a sidebar checklist.
    remove_meta_box('resource_typediv', 'resource', 'side');
    remove_meta_box('resource_typediv', 'resource', 'normal');
}

/** Old Type-only group is merged into Resource. Never render it even if WP still has a DB copy. */
add_filter('acf/load_field_group', 'irisid_disable_legacy_resource_layout_group');

/**
 * @param array<string, mixed> $group
 * @return array<string, mixed>
 */
function irisid_disable_legacy_resource_layout_group(array $group): array
{
    if (($group['key'] ?? '') === 'group_irisid_resource_layout') {
        $group['active'] = 0;
    }
    return $group;
}

/**
 * Resources list: Type + Status immediately left of Date.
 *
 * @param array<string, string> $columns
 * @return array<string, string>
 */
add_filter('manage_edit-resource_columns', 'irisid_resource_admin_columns');

function irisid_resource_admin_columns(array $columns): array
{
    // Keep only checkbox + title + Type + Status + date so Title can use the width.
    $cb = $columns['cb'] ?? '';
    $title = $columns['title'] ?? 'Title';
    $date = $columns['date'] ?? 'Date';
    return [
        'cb'              => $cb,
        'title'           => $title,
        'irisid_layout'   => 'Type',
        'irisid_status'   => 'Status',
        'date'            => $date,
    ];
}

add_action('manage_resource_posts_custom_column', 'irisid_resource_admin_column_content', 10, 2);
add_action('admin_head-edit.php', 'irisid_resource_list_column_styles');
/** Date column already prints status above the timestamp – move that into Status. */
add_filter('post_date_column_status', 'irisid_resource_omit_date_column_status', 10, 2);
/** Replace the months dropdown with a resource category (Type) filter. */
add_filter('months_dropdown_results', 'irisid_resource_hide_months_dropdown', 10, 2);
add_action('restrict_manage_posts', 'irisid_resource_type_filter_dropdown');

/**
 * @param array<int, object> $months
 * @return array<int, object>
 */
function irisid_resource_hide_months_dropdown(array $months, string $post_type): array
{
    return $post_type === 'resource' ? [] : $months;
}

function irisid_resource_type_filter_dropdown(string $post_type): void
{
    if ($post_type !== 'resource') {
        return;
    }

    $taxonomy = 'resource_type';
    $selected = isset($_GET[$taxonomy]) ? sanitize_text_field(wp_unslash((string) $_GET[$taxonomy])) : '';

    wp_dropdown_categories([
        'show_option_all' => 'All types',
        'taxonomy'        => $taxonomy,
        'name'            => $taxonomy,
        'orderby'         => 'name',
        'selected'        => $selected,
        'hierarchical'    => false,
        'depth'           => 1,
        'show_count'      => true,
        'hide_empty'      => false,
        'value_field'     => 'slug',
    ]);
}

function irisid_resource_list_column_styles(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'resource') {
        return;
    }
    echo '<style id="irisid-resource-list-cols">'
        . '.wp-list-table.fixed .column-irisid_layout{width:72px;}'
        . '.wp-list-table.fixed .column-irisid_status{width:96px;}'
        . '.wp-list-table.fixed .column-date{width:10em;white-space:nowrap;text-align:left;}'
        . '.wp-list-table.fixed th.column-date,.wp-list-table.fixed td.column-date{padding-right:16px;overflow:hidden;text-overflow:ellipsis;}'
        . '.wp-list-table.fixed .column-title{width:auto;}'
        . '</style>';
}

/**
 * @param string   $status Status HTML for the Date column.
 * @param \WP_Post $post   Current row post.
 */
function irisid_resource_omit_date_column_status(string $status, $post): string
{
    if ($post instanceof \WP_Post && $post->post_type === 'resource') {
        return '';
    }
    return $status;
}

function irisid_resource_admin_column_content(string $column, int $post_id): void
{
    if ($column === 'irisid_status') {
        $status = get_post_status($post_id);
        $obj = $status ? get_post_status_object($status) : null;
        echo esc_html($obj && !empty($obj->label) ? (string) $obj->label : (string) $status);
        return;
    }
    if ($column !== 'irisid_layout') {
        return;
    }
    $layout = (string) get_field('resource_layout', $post_id);
    $labels = irisid_resource_layout_choices();
    // Default everything to List until an editor explicitly picks another type.
    if ($layout === '' || !isset($labels[$layout])) {
        $layout = 'list';
    }
    echo esc_html($labels[$layout]);
}

/** Description (excerpt) is edited for Event cards; List keeps auto excerpt from Body. */
/** Keep Related / External out of the day-to-day layout editors (still in GraphQL). */
add_filter('acf/load_value/key=field_irisid_resource_layout', 'irisid_resource_layout_drop_gallery', 10, 1);

function irisid_resource_layout_drop_gallery($value)
{
    // Legacy Gallery layout → Video.
    return $value === 'gallery' ? 'video' : $value;
}

add_filter('acf/prepare_field/key=field_irisid_resource_external', '__return_false');
add_filter('acf/prepare_field/key=field_irisid_resource_products', '__return_false');
add_filter('acf/prepare_field/key=field_irisid_resource_solutions', '__return_false');

/**
 * One Resource box: Type, then Category, then layout fields.
 * Hide native WP title / slug / taxonomy sidebar.
 */
add_action('admin_head', 'irisid_resource_hide_native_title');

function irisid_resource_hide_native_title(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'resource' || !in_array($screen->base, ['post', 'post-new'], true)) {
        return;
    }
    echo '<style id="irisid-resource-title-order">'
        . 'body.post-type-resource #titlediv,'
        . 'body.post-type-resource #titlewrap,'
        . 'body.post-type-resource #edit-slug-box,'
        . 'body.post-type-resource #slugdiv,'
        . 'body.post-type-resource #revisionsdiv,'
        . 'body.post-type-resource #resource_typediv,'
        . 'body.post-type-resource #acf-group_irisid_resource_layout{'
        . 'display:none!important;}'
        . 'body.post-type-resource #post-body-content,'
        . 'body.post-type-resource #acf_after_title-sortables,'
        . 'body.post-type-resource #normal-sortables{'
        . 'margin-top:0!important;padding-top:0!important;min-height:0!important;height:auto!important;}'
        . 'body.post-type-resource #acf_after_title-sortables .ui-sortable-placeholder{display:none!important;}'
        . 'body.post-type-resource #acf-group_irisid_resource{margin:0 0 20px!important;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_layout"]{padding-top:12px;padding-bottom:8px;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_layout"] .acf-radio-list,'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_kind"] .acf-radio-list{'
        . 'display:flex;flex-wrap:wrap;gap:8px 18px;margin:0;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_layout"] .acf-radio-list li,'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_kind"] .acf-radio-list li{margin:0;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_layout"] .acf-radio-list label{'
        . 'font-size:13px;font-weight:600;color:#1d2327;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_title"] input[type="text"]{'
        . 'font-size:1.4em;padding:8px 10px;width:100%;}'
        . 'body.post-type-resource .acf-field.irisid-display-date,'
        . 'body.post-type-resource .acf-field.irisid-show-display-date{clear:none;}'
        . 'body.post-type-resource .acf-field.irisid-show-display-date .acf-switch{margin-top:2px;}'
        . 'body.post-type-resource .acf-field.irisid-layout-hidden{display:none!important;}'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_kind"] .acf-checkbox-list,'
        . 'body.post-type-resource .acf-field[data-key="field_irisid_resource_kind"] .acf-radio-list{'
        . 'display:flex;flex-wrap:wrap;gap:8px 18px;margin:0;}'
        . '</style>';
    echo '<script id="irisid-resource-align-top">(function(){'
        . 'function align(){'
        . 'var content=document.getElementById("post-body-content");'
        . 'if(!content)return;'
        . 'var title=document.getElementById("titlediv");'
        . 'if(title)title.remove();'
        . 'var sortables=document.getElementById("acf_after_title-sortables");'
        . 'if(sortables&&content.firstElementChild!==sortables){'
        . 'content.insertBefore(sortables,content.firstElementChild);'
        . '}'
        . '}'
        . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",align);'
        . 'else align();'
        . 'window.addEventListener("load",align);'
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
    // Event editors enter Description manually for archive cards.
    if ($layout === 'event') {
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
 * Map sidebar Resource Type taxonomy → List / Video / File / Event layout.
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
        'videos'        => 'video',
        'webinars'      => 'video',
        'data-sheets'   => 'file',
        'tip-sheets'    => 'file',
        'literature'    => 'file',
        'events'        => 'event',
    ];
    return $map[$slug] ?? null;
}

/** @return array<string, string> */
function irisid_resource_layout_choices(): array
{
    return [
        'list'  => 'List',
        'video' => 'Video',
        'file'  => 'File',
        'event' => 'Event',
    ];
}

function irisid_term_resource_layout(int $termId, string $slug = ''): string
{
    $layout = (string) get_term_meta($termId, 'irisid_layout', true);
    if (isset(irisid_resource_layout_choices()[$layout])) {
        return $layout;
    }
    $mapped = $slug !== '' ? irisid_resource_layout_for_type_slug($slug) : null;
    return $mapped ?? 'list';
}

add_action('resource_type_add_form_fields', 'irisid_resource_type_add_layout_field');
add_action('resource_type_edit_form_fields', 'irisid_resource_type_edit_layout_field', 10, 1);
add_action('created_resource_type', 'irisid_save_resource_type_layout');
add_action('edited_resource_type', 'irisid_save_resource_type_layout');
add_filter('manage_edit-resource_type_columns', 'irisid_resource_type_columns');
add_filter('manage_resource_type_custom_column', 'irisid_resource_type_column_content', 10, 3);
add_action('admin_init', 'irisid_seed_resource_type_layouts');
add_action('admin_head-edit-tags.php', 'irisid_resource_type_admin_styles');
add_action('admin_head-term.php', 'irisid_resource_type_admin_styles');

function irisid_resource_type_admin_styles(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->taxonomy !== 'resource_type') {
        return;
    }
    echo '<style id="irisid-resource-type-admin">'
        . '.taxonomy-resource_type .term-parent-wrap{display:none!important;}'
        . '.taxonomy-resource_type .irisid-term-layout-radios{display:flex;flex-wrap:wrap;gap:8px 18px;margin:6px 0 0;}'
        . '.taxonomy-resource_type .irisid-term-layout-radios label{font-weight:600;}'
        . '.taxonomy-resource_type .column-irisid_layout{width:88px;}'
        . '</style>';
}

function irisid_resource_type_add_layout_field(): void
{
    echo '<div class="form-field term-layout-wrap">'
        . '<label>Type</label>';
    irisid_resource_type_layout_radios('list');
    echo '<p>Public page layout. Resources with this Type will list this category.</p>'
        . '</div>';
}

function irisid_resource_type_edit_layout_field($term): void
{
    $termId = (int) ($term->term_id ?? 0);
    $slug = (string) ($term->slug ?? '');
    $layout = $termId ? irisid_term_resource_layout($termId, $slug) : 'list';
    echo '<tr class="form-field term-layout-wrap">'
        . '<th scope="row"><label for="irisid_layout">Type</label></th>'
        . '<td>';
    irisid_resource_type_layout_radios($layout);
    echo '<p class="description">Public page layout. Resources with this Type will list this category.</p>'
        . '</td></tr>';
}

function irisid_resource_type_layout_radios(string $current): void
{
    echo '<div class="irisid-term-layout-radios">';
    foreach (irisid_resource_layout_choices() as $value => $label) {
        printf(
            '<label><input type="radio" name="irisid_layout" value="%s"%s> %s</label>',
            esc_attr($value),
            checked($current, $value, false),
            esc_html($label)
        );
    }
    echo '</div>';
}

function irisid_save_resource_type_layout($termId): void
{
    $termId = (int) $termId;
    if ($termId <= 0 || !current_user_can('manage_categories')) {
        return;
    }
    $layout = isset($_POST['irisid_layout']) ? sanitize_key((string) $_POST['irisid_layout']) : 'list';
    if (!isset(irisid_resource_layout_choices()[$layout])) {
        $layout = 'list';
    }
    update_term_meta($termId, 'irisid_layout', $layout);
}

/**
 * @param array<string, string> $columns
 * @return array<string, string>
 */
function irisid_resource_type_columns(array $columns): array
{
    unset($columns['description']);
    $with_type = [];
    foreach ($columns as $key => $label) {
        $with_type[$key] = $label;
        if ($key === 'name') {
            $with_type['irisid_layout'] = 'Type';
        }
    }
    if (!isset($with_type['irisid_layout'])) {
        $with_type['irisid_layout'] = 'Type';
    }
    return $with_type;
}

function irisid_resource_type_column_content(string $content, string $column, int $termId): string
{
    if ($column !== 'irisid_layout') {
        return $content;
    }
    $term = get_term($termId, 'resource_type');
    $slug = ($term && !is_wp_error($term)) ? (string) $term->slug : '';
    $layout = irisid_term_resource_layout($termId, $slug);
    return esc_html(irisid_resource_layout_choices()[$layout] ?? 'List');
}

function irisid_seed_resource_type_layouts(): void
{
    $terms = get_terms([
        'taxonomy'   => 'resource_type',
        'hide_empty' => false,
    ]);
    if (is_wp_error($terms)) {
        return;
    }

    $migrateVideo = !get_option('irisid_video_layout_migrated');
    foreach ($terms as $term) {
        $termId = (int) $term->term_id;
        $slug = (string) $term->slug;
        $existing = (string) get_term_meta($termId, 'irisid_layout', true);
        $mapped = irisid_resource_layout_for_type_slug($slug);

        if ($migrateVideo && $mapped === 'video' && ($existing === '' || $existing === 'list' || $existing === 'gallery')) {
            update_term_meta($termId, 'irisid_layout', 'video');
            continue;
        }

        if (isset(irisid_resource_layout_choices()[$existing])) {
            continue;
        }
        update_term_meta($termId, 'irisid_layout', $mapped ?? 'list');
    }
    if ($migrateVideo) {
        update_option('irisid_video_layout_migrated', 1, false);
    }
}

/**
 * Resource Type editor: rename / delete / set layout, then Save.
 * Native edit-tags re-seeded deleted terms on every load; this screen owns the list.
 */
function irisid_render_resource_types_page(): void
{
    if (!current_user_can('manage_categories')) {
        wp_die(esc_html__('Sorry, you are not allowed to manage resource types.', 'irisid'));
    }

    $notice = '';
    if (
        isset($_POST['irisid_save_resource_types'])
        && check_admin_referer('irisid_save_resource_types', 'irisid_resource_types_nonce')
    ) {
        $notice = irisid_save_resource_types_form($_POST);
    }

    $terms = get_terms([
        'taxonomy'   => 'resource_type',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms)) {
        $terms = [];
    }

    $layouts = irisid_resource_layout_choices();
    $action = admin_url('edit.php?post_type=resource&page=irisid-resource-types');
    ?>
    <div class="wrap">
        <h1>Resource Type</h1>
        <p>Name, slug, and layout for each site category (News &amp; Media, Press Release, …). Click <strong>Save changes</strong> to apply renames, deletes, and Type (List / Video / File / Event).</p>
        <?php if ($notice !== '') : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url($action); ?>">
            <?php wp_nonce_field('irisid_save_resource_types', 'irisid_resource_types_nonce'); ?>
            <table class="widefat striped" style="max-width:960px">
                <thead>
                    <tr>
                        <th scope="col" style="width:28%">Name</th>
                        <th scope="col" style="width:22%">Slug</th>
                        <th scope="col" style="width:18%">Type</th>
                        <th scope="col" style="width:10%">Count</th>
                        <th scope="col" style="width:12%">Delete</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($terms as $term) :
                    $termId = (int) $term->term_id;
                    $layout = irisid_term_resource_layout($termId, (string) $term->slug);
                    ?>
                    <tr>
                        <td>
                            <input type="text" class="regular-text" style="width:100%"
                                name="types[<?php echo $termId; ?>][name]"
                                value="<?php echo esc_attr((string) $term->name); ?>" required />
                        </td>
                        <td>
                            <input type="text" class="regular-text code" style="width:100%"
                                name="types[<?php echo $termId; ?>][slug]"
                                value="<?php echo esc_attr((string) $term->slug); ?>" required />
                        </td>
                        <td>
                            <select name="types[<?php echo $termId; ?>][layout]">
                                <?php foreach ($layouts as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($layout, $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><?php echo esc_html((string) (int) $term->count); ?></td>
                        <td>
                            <label>
                                <input type="checkbox" name="types[<?php echo $termId; ?>][delete]" value="1" />
                                Delete
                            </label>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr>
                        <td colspan="5" style="background:#f6f7f7;font-weight:600">Add new type</td>
                    </tr>
                    <tr>
                        <td>
                            <input type="text" class="regular-text" style="width:100%"
                                name="new_type[name]" placeholder="Name" />
                        </td>
                        <td>
                            <input type="text" class="regular-text code" style="width:100%"
                                name="new_type[slug]" placeholder="slug (optional)" />
                        </td>
                        <td>
                            <select name="new_type[layout]">
                                <?php foreach ($layouts as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>—</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            <p class="submit" style="max-width:960px">
                <button type="submit" name="irisid_save_resource_types" class="button button-primary" value="1">
                    Save changes
                </button>
            </p>
            <p class="description" style="max-width:960px">
                Slug is used in public URLs (<code>/resources/{slug}/</code>). Changing a slug can break existing links until the frontend is updated.
            </p>
        </form>
    </div>
    <?php
}

/**
 * @param array<string, mixed> $post
 */
function irisid_save_resource_types_form(array $post): string
{
    $updated = 0;
    $deleted = 0;
    $created = 0;
    $layouts = irisid_resource_layout_choices();

    $rows = isset($post['types']) && is_array($post['types']) ? $post['types'] : [];
    foreach ($rows as $termIdRaw => $row) {
        if (!is_array($row)) {
            continue;
        }
        $termId = (int) $termIdRaw;
        if ($termId <= 0) {
            continue;
        }
        $term = get_term($termId, 'resource_type');
        if (!$term || is_wp_error($term)) {
            continue;
        }

        if (!empty($row['delete'])) {
            $result = wp_delete_term($termId, 'resource_type');
            if (!is_wp_error($result) && $result) {
                $deleted++;
            }
            continue;
        }

        $name = sanitize_text_field((string) ($row['name'] ?? ''));
        $slug = sanitize_title((string) ($row['slug'] ?? ''));
        $layout = sanitize_key((string) ($row['layout'] ?? 'list'));
        if ($name === '') {
            continue;
        }
        if ($slug === '') {
            $slug = sanitize_title($name);
        }
        if (!isset($layouts[$layout])) {
            $layout = 'list';
        }

        $args = [];
        if ($name !== (string) $term->name) {
            $args['name'] = $name;
        }
        if ($slug !== (string) $term->slug) {
            $args['slug'] = $slug;
        }
        if ($args !== []) {
            $result = wp_update_term($termId, 'resource_type', $args);
            if (!is_wp_error($result)) {
                $updated++;
            }
        }

        $existingLayout = (string) get_term_meta($termId, 'irisid_layout', true);
        if ($existingLayout !== $layout) {
            update_term_meta($termId, 'irisid_layout', $layout);
            $updated++;
        }
    }

    $new = isset($post['new_type']) && is_array($post['new_type']) ? $post['new_type'] : [];
    $newName = sanitize_text_field((string) ($new['name'] ?? ''));
    if ($newName !== '') {
        $newSlug = sanitize_title((string) ($new['slug'] ?? ''));
        if ($newSlug === '') {
            $newSlug = sanitize_title($newName);
        }
        $newLayout = sanitize_key((string) ($new['layout'] ?? 'list'));
        if (!isset($layouts[$newLayout])) {
            $newLayout = 'list';
        }
        $inserted = wp_insert_term($newName, 'resource_type', ['slug' => $newSlug]);
        if (!is_wp_error($inserted) && isset($inserted['term_id'])) {
            update_term_meta((int) $inserted['term_id'], 'irisid_layout', $newLayout);
            $created++;
        }
    }

    // Editors now own this list – never re-seed deleted terms.
    update_option('irisid_resource_types_seeded', 1, false);

    $parts = [];
    if ($updated > 0) {
        $parts[] = sprintf('%d updated', $updated);
    }
    if ($deleted > 0) {
        $parts[] = sprintf('%d deleted', $deleted);
    }
    if ($created > 0) {
        $parts[] = sprintf('%d created', $created);
    }
    return $parts !== [] ? 'Saved: ' . implode(', ', $parts) . '.' : 'No changes to save.';
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

    $termIdToLayout = [];
    $terms = get_terms([
        'taxonomy'   => 'resource_type',
        'hide_empty' => false,
    ]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $termIdToLayout[(string) $term->term_id] = irisid_term_resource_layout(
                (int) $term->term_id,
                (string) $term->slug
            );
        }
    }

    $termLayoutJson = wp_json_encode($termIdToLayout);

    // Must run after ACF – jquery-core alone fires before `acf` exists.
    wp_enqueue_script('acf-input');
    wp_add_inline_script(
        'acf-input',
        <<<JS
        (function () {
            var TERM_ID_TO_LAYOUT = {$termLayoutJson};
            var ALWAYS = [
                'field_irisid_resource_layout',
                'field_irisid_resource_kind',
                'field_irisid_resource_title',
                'field_irisid_resource_featured'
            ];
            var BY_LAYOUT = {
                list: [
                    'field_irisid_resource_display_date',
                    'field_irisid_resource_show_display_date',
                    'field_irisid_resource_attachment',
                    'field_irisid_resource_body'
                ],
                video: [
                    'field_irisid_resource_video',
                    'field_irisid_resource_body'
                ],
                file: [
                    'field_irisid_resource_file_name',
                    'field_irisid_resource_attachment'
                ],
                event: [
                    'field_irisid_resource_event_date',
                    'field_irisid_resource_event_ends',
                    'field_irisid_resource_event_visibility',
                    'field_irisid_resource_excerpt',
                    'field_irisid_resource_body'
                ]
            };
            var ORDER = {
                list: ALWAYS.concat(BY_LAYOUT.list),
                video: ALWAYS.concat(BY_LAYOUT.video),
                file: ALWAYS.concat(BY_LAYOUT.file),
                event: [
                    'field_irisid_resource_layout',
                    'field_irisid_resource_kind',
                    'field_irisid_resource_title',
                    'field_irisid_resource_featured',
                    'field_irisid_resource_event_date',
                    'field_irisid_resource_event_ends',
                    'field_irisid_resource_event_visibility',
                    'field_irisid_resource_excerpt',
                    'field_irisid_resource_body'
                ]
            };
            var ALL_TOGGLE = {};
            Object.keys(BY_LAYOUT).forEach(function (k) {
                BY_LAYOUT[k].forEach(function (key) { ALL_TOGGLE[key] = true; });
            });

            function start() {
                if (typeof acf === 'undefined') {
                    window.setTimeout(start, 50);
                    return;
                }
                acf.addAction('ready', bindLayoutUi);
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
                    var value = String(layoutField.val() || 'list');
                    return value === 'gallery' ? 'video' : value;
                }

                function fieldEl(key) {
                    return document.querySelector('.acf-field[data-key="' + key + '"]');
                }

                function syncSheetPanel() {
                    var isFile = currentLayout() === 'file';
                    var sheet =
                        document.getElementById('acf-group_irisid_resource_sheet') ||
                        document.querySelector('.postbox[id*="group_irisid_resource_sheet"]');
                    if (!sheet) return;
                    sheet.style.display = isFile ? '' : 'none';
                }

                function applyBodyLayoutClass(layout) {
                    ['list', 'video', 'file', 'event'].forEach(function (l) {
                        document.body.classList.toggle('irisid-layout-' + l, l === layout);
                    });
                }

                function showAllCategories() {
                    var wrap = fieldEl('field_irisid_resource_kind') ||
                        document.querySelector('.acf-field[data-name="resource_kind"]');
                    if (!wrap) return;
                    wrap.querySelectorAll('li').forEach(function (li) {
                        li.style.display = '';
                        li.hidden = false;
                    });
                }

                function reorderFields(layout) {
                    var order = ORDER[layout] || ORDER.list;
                    var first = fieldEl(order[0]);
                    if (!first || !first.parentNode) return;
                    var parent = first.parentNode;
                    order.forEach(function (key) {
                        var el = fieldEl(key);
                        if (el && el.parentNode === parent) parent.appendChild(el);
                    });
                }

                function syncResourceFields() {
                    var layout = currentLayout();
                    if (!BY_LAYOUT[layout]) layout = 'list';
                    var show = {};
                    BY_LAYOUT[layout].forEach(function (key) { show[key] = true; });

                    Object.keys(ALL_TOGGLE).forEach(function (key) {
                        var el = fieldEl(key);
                        var field = acf.getField(key);
                        if (show[key]) {
                            if (el) {
                                el.classList.remove('irisid-layout-hidden');
                                el.style.display = '';
                            }
                            if (field && field.show) field.show();
                        } else {
                            if (el) {
                                el.classList.add('irisid-layout-hidden');
                                el.style.display = 'none';
                            }
                            if (field && field.hide) field.hide();
                        }
                    });
                    applyBodyLayoutClass(layout);
                    reorderFields(layout);
                    showAllCategories();
                    syncSheetPanel();
                }

                document.addEventListener('change', function (e) {
                    var t = e.target;
                    if (!t || !t.closest) return;
                    if (t.closest('.acf-field[data-key="field_irisid_resource_layout"]')) {
                        window.setTimeout(syncResourceFields, 0);
                    }
                });
                layoutField.on('change', function () {
                    window.setTimeout(syncResourceFields, 0);
                });
                syncResourceFields();
                window.setTimeout(syncResourceFields, 200);
                window.setTimeout(syncResourceFields, 800);
            }

            start();
        })();
        JS,
        'after'
    );
}

/**
 * Default resource_layout to List when unset.
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
    $layout = 'list';
    if (!is_wp_error($terms)) {
        foreach ($terms as $slug) {
            $mapped = irisid_resource_layout_for_type_slug((string) $slug);
            if ($mapped) {
                $layout = $mapped;
                break;
            }
        }
    }
    update_field('field_irisid_resource_layout', $layout, $postId);
}

/**
 * Map existing resources' Type from their Category (News=List, Videos=Gallery, …).
 */
add_action('admin_init', 'irisid_backfill_resource_layouts_from_taxonomy_v2');

function irisid_backfill_resource_layouts_from_taxonomy_v2(): void
{
    if (get_option('irisid_resource_layouts_from_taxonomy_v2')) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    $ids = get_posts([
        'post_type'      => 'resource',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    foreach ($ids as $id) {
        $id = (int) $id;
        $terms = wp_get_post_terms($id, 'resource_type', ['fields' => 'slugs']);
        $layout = 'list';
        if (!is_wp_error($terms)) {
            foreach ($terms as $slug) {
                $mapped = irisid_resource_layout_for_type_slug((string) $slug);
                if ($mapped === 'event') {
                    $layout = 'event';
                    break;
                }
                if ($mapped === 'file') {
                    $layout = 'file';
                }
            }
        }
        update_field('field_irisid_resource_layout', $layout, $id);
    }
    update_option('irisid_resource_layouts_from_taxonomy_v2', 1, false);
}
