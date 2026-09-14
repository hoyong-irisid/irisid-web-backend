<?php
/**
 * Seed Architect & Engineer downloads + migrate live WP pages/careers into cms.
 *
 *   wp eval-file ~/cms.irisid.com/migrate-downloads-pages-careers.php
 *
 * No declare(strict_types) – wp eval-file wraps the file.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via: wp eval-file migrate-downloads-pages-careers.php\n");
    exit(1);
}

@ini_set('memory_limit', '512M');
@set_time_limit(0);

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$live = 'https://irisid.com/wp-json/wp/v2';

echo "=== Download types ===\n";
$download_types = [
    'software'           => 'Software',
    'drivers'            => 'Drivers',
    'documentation'      => 'Documentation',
    'literature'         => 'Literature',
    'tip-sheet'          => 'Tip Sheet',
    'architect-engineer' => 'Architect & Engineer',
];
foreach ($download_types as $slug => $name) {
    if (!term_exists($slug, 'download_type')) {
        wp_insert_term($name, 'download_type', ['slug' => $slug]);
        echo "  created download_type {$slug}\n";
    }
}

echo "=== A&E downloads ===\n";
$ae_docs = [
    [
        'title' => 'CAD Drawing',
        'slug'  => 'ia1000-cad-drawing',
        'desc'  => 'Installation package for detailing and coordination',
        'url'   => 'https://irisid.com/wp-content/uploads/2026/07/IA1000-Installation.zip',
        'thumb' => 'https://irisid.com/wp-content/uploads/2025/11/product-ia1000-03-1.png',
    ],
    [
        'title' => 'Data Sheet',
        'slug'  => 'ia1000-data-sheet',
        'desc'  => 'Specifications, features, and performance summary',
        'url'   => 'https://irisid.com/wp-content/uploads/2025/11/datasheet-ia1000-v2.05.pdf',
        'thumb' => '',
    ],
    [
        'title' => 'Quick Start Guide',
        'slug'  => 'ia1000-quick-start-guide',
        'desc'  => 'Install and commission orientation for field teams',
        'url'   => 'https://irisid.com/wp-content/uploads/2026/07/iA1000QSG.pdf',
        'thumb' => '',
    ],
];

$ae_term = get_term_by('slug', 'architect-engineer', 'download_type');
$ae_ok = 0;
foreach ($ae_docs as $doc) {
    $id = irisid_dl_upsert_download($doc, $ae_term instanceof WP_Term ? (int) $ae_term->term_id : 0);
    if ($id) {
        echo "  OK #{$id} {$doc['slug']}\n";
        $ae_ok++;
    }
}
echo "  A&E done={$ae_ok}\n";

echo "=== Live pages → cms pages ===\n";
$page_slugs = [
    'about-iris-id',
    'our-customers',
    'privacy',
    'legal',
    'faq',
    'company',
    'support',
    'product-documentation-download',
];
$page_ok = 0;
foreach ($page_slugs as $slug) {
    $batch = irisid_dl_http("{$live}/pages?slug=" . rawurlencode($slug) . '&_embed=1');
    if (!$batch || empty($batch[0])) {
        echo "  skip {$slug} (not on live)\n";
        continue;
    }
    $id = irisid_dl_upsert_page($batch[0]);
    if ($id) {
        echo "  OK page #{$id} {$slug}\n";
        $page_ok++;
    }
}
echo "  pages done={$page_ok}\n";

echo "=== Careers posts → cms posts ===\n";
$cats = irisid_dl_http("{$live}/categories?slug=careers");
$career_ok = 0;
if ($cats && !empty($cats[0]['id'])) {
    $live_cat = (int) $cats[0]['id'];
    $cms_term = term_exists('careers', 'category');
    if (!$cms_term) {
        $cms_term = wp_insert_term('Careers', 'category', ['slug' => 'careers']);
    }
    $cms_cat_id = is_array($cms_term) ? (int) $cms_term['term_id'] : (int) $cms_term;

    $page = 1;
    do {
        $batch = irisid_dl_http("{$live}/posts?categories={$live_cat}&page={$page}&per_page=50&_embed=1&status=publish");
        if (!is_array($batch) || !$batch) {
            break;
        }
        foreach ($batch as $post) {
            $id = irisid_dl_upsert_career_post($post, $cms_cat_id);
            if ($id) {
                echo "  OK career #{$id} {$post['slug']}\n";
                $career_ok++;
            }
        }
        $page++;
    } while (count($batch) === 50);
}
echo "  careers done={$career_ok}\n";

echo "\nDone.\n";
echo "Downloads: https://cms.irisid.com/wp-admin/edit.php?post_type=download\n";
echo "Pages: https://cms.irisid.com/wp-admin/edit.php?post_type=page\n";
echo "Careers: https://cms.irisid.com/wp-admin/edit.php?category_name=careers\n";

function irisid_dl_http(string $url)
{
    $res = wp_remote_get($url, [
        'timeout' => 90,
        'headers' => ['User-Agent' => 'irisid-dl-migrate/1.0'],
    ]);
    if (is_wp_error($res)) {
        echo '  HTTP ' . $res->get_error_message() . "\n";
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code === 400) {
        return [];
    }
    if ($code < 200 || $code >= 300) {
        echo "  HTTP {$code}\n";
        return null;
    }
    $data = json_decode((string) wp_remote_retrieve_body($res), true);
    return is_array($data) ? $data : null;
}

function irisid_dl_sideload(string $url, int $parent, string $desc): int
{
    $url = esc_url_raw($url);
    if ($url === '') {
        return 0;
    }
    $filename = wp_basename((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if ($filename !== '') {
        $q = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_query' => [[
                'key' => '_wp_attached_file',
                'value' => $filename,
                'compare' => 'LIKE',
            ]],
        ]);
        if ($q->have_posts()) {
            return (int) $q->posts[0]->ID;
        }
    }
    $tmp = download_url($url);
    if (is_wp_error($tmp)) {
        echo '    sideload fail: ' . $tmp->get_error_message() . "\n";
        return 0;
    }
    $id = media_handle_sideload([
        'name' => $filename !== '' ? $filename : 'file.bin',
        'tmp_name' => $tmp,
    ], $parent, $desc);
    if (is_wp_error($id)) {
        @unlink($tmp);
        echo '    sideload fail: ' . $id->get_error_message() . "\n";
        return 0;
    }
    return (int) $id;
}

/**
 * @param array{title:string,slug:string,desc:string,url:string,thumb:string} $doc
 */
function irisid_dl_upsert_download(array $doc, int $term_id): int
{
    $existing = get_page_by_path($doc['slug'], OBJECT, 'download');
    $post_id = $existing instanceof WP_Post ? (int) $existing->ID : 0;
    $postarr = [
        'post_type' => 'download',
        'post_status' => 'publish',
        'post_title' => $doc['title'],
        'post_name' => $doc['slug'],
        'post_content' => $doc['desc'],
        'post_excerpt' => $doc['desc'],
    ];
    if ($post_id) {
        $postarr['ID'] = $post_id;
        $result = wp_update_post($postarr, true);
    } else {
        $result = wp_insert_post($postarr, true);
    }
    if (is_wp_error($result)) {
        echo '  FAIL ' . $doc['slug'] . ': ' . $result->get_error_message() . "\n";
        return 0;
    }
    $post_id = (int) $result;
    if ($term_id) {
        wp_set_object_terms($post_id, [$term_id], 'download_type', false);
    }
    if (function_exists('update_field')) {
        $file_id = irisid_dl_sideload($doc['url'], $post_id, $doc['title']);
        if ($file_id) {
            update_field('file', $file_id, $post_id);
        }
        if ($doc['thumb'] !== '') {
            $thumb = irisid_dl_sideload($doc['thumb'], $post_id, $doc['title'] . ' thumb');
            if ($thumb) {
                update_field('thumbnail', $thumb, $post_id);
                set_post_thumbnail($post_id, $thumb);
            }
        }
    }
    return $post_id;
}

function irisid_dl_upsert_page(array $page): int
{
    $slug = (string) ($page['slug'] ?? '');
    $title = trim(html_entity_decode(wp_strip_all_tags((string) ($page['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($slug === '' || $title === '') {
        return 0;
    }
    $existing = get_page_by_path($slug, OBJECT, 'page');
    $post_id = $existing instanceof WP_Post ? (int) $existing->ID : 0;
    $content = (string) ($page['content']['rendered'] ?? '');
    $postarr = [
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => $content,
    ];
    if ($post_id) {
        $postarr['ID'] = $post_id;
        $result = wp_update_post($postarr, true);
    } else {
        $result = wp_insert_post($postarr, true);
    }
    if (is_wp_error($result)) {
        echo '  FAIL page ' . $slug . ': ' . $result->get_error_message() . "\n";
        return 0;
    }
    return (int) $result;
}

function irisid_dl_upsert_career_post(array $post, int $cat_id): int
{
    $slug = (string) ($post['slug'] ?? '');
    $title = trim(html_entity_decode(wp_strip_all_tags((string) ($post['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($slug === '' || $title === '') {
        return 0;
    }
    $existing = get_page_by_path($slug, OBJECT, 'post');
    $post_id = $existing instanceof WP_Post ? (int) $existing->ID : 0;
    $postarr = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => (string) ($post['content']['rendered'] ?? ''),
        'post_excerpt' => wp_strip_all_tags((string) ($post['excerpt']['rendered'] ?? '')),
        'post_date' => isset($post['date']) ? str_replace('T', ' ', substr($post['date'], 0, 19)) : current_time('mysql'),
    ];
    if ($post_id) {
        $postarr['ID'] = $post_id;
        $result = wp_update_post($postarr, true);
    } else {
        $result = wp_insert_post($postarr, true);
    }
    if (is_wp_error($result)) {
        echo '  FAIL career ' . $slug . ': ' . $result->get_error_message() . "\n";
        return 0;
    }
    $post_id = (int) $result;
    wp_set_post_categories($post_id, [$cat_id], false);
    return $post_id;
}
