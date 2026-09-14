<?php
/**
 * Migrate live irisid.com category posts → cms.irisid.com `resource` CPT
 * for the CMS-scoped resource boards (News, Press, Blog/Insights, Videos,
 * Iris ID Talk, Case Studies, Webinars, Events).
 *
 * Data Sheets / Tip Sheets use migrate-sheets-to-cms.php instead.
 *
 * Run on the CMS host (cPanel Terminal). Idempotent by slug.
 *
 *   # all boards (can take a long time – image sideloads)
 *   cd ~/cms.irisid.com
 *   wp eval-file ~/cms.irisid.com/migrate-resources-to-cms.php
 *
 *   # one board only
 *   IRISID_MIGRATE_ONLY=news-media wp eval-file ~/cms.irisid.com/migrate-resources-to-cms.php
 *
 * No declare(strict_types) – wp eval-file wraps the file.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via: wp eval-file migrate-resources-to-cms.php\n");
    exit(1);
}

@ini_set('memory_limit', '512M');
@set_time_limit(0);

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$live_base = 'https://irisid.com/wp-json/wp/v2';

/** live category slug → cms resource_type slug + label */
$boards = [
    'news-media'    => ['live' => 'news', 'label' => 'News & Media'],
    'press-release' => ['live' => 'press-release', 'label' => 'Press Release'],
    'insights'      => ['live' => 'blog', 'label' => 'Insights'],
    'videos'        => ['live' => 'videos', 'label' => 'Videos'],
    'iris-id-talk'  => ['live' => 'iris-id-radio', 'label' => 'Iris ID Talk'],
    'case-studies'  => ['live' => 'irisaccess-in-action', 'label' => 'Case Studies'],
    'webinars'      => ['live' => 'webinars', 'label' => 'Webinars'],
    'events'        => ['live' => 'events', 'label' => 'Events'],
];

$only = trim((string) (getenv('IRISID_MIGRATE_ONLY') ?: ''));
if ($only !== '') {
    if (!isset($boards[$only])) {
        fwrite(STDERR, "Unknown IRISID_MIGRATE_ONLY={$only}\n");
        exit(1);
    }
    $boards = [$only => $boards[$only]];
}

foreach ($boards as $type_slug => $meta) {
    if (!term_exists($type_slug, 'resource_type')) {
        wp_insert_term($meta['label'], 'resource_type', ['slug' => $type_slug]);
        echo "Created resource_type: {$type_slug}\n";
    }
}

$grand_ok = 0;
$grand_fail = 0;
$seen_live_ids = [];

foreach ($boards as $type_slug => $meta) {
    $live_slug = $meta['live'];
    echo "\n=== {$meta['label']} (live:{$live_slug} → {$type_slug}) ===\n";

    $cats = irisid_res_http_json("{$live_base}/categories?slug=" . rawurlencode($live_slug));
    if (!$cats || empty($cats[0]['id'])) {
        echo "  SKIP: live category not found\n";
        continue;
    }
    $cat_id = (int) $cats[0]['id'];
    echo "  category id={$cat_id}\n";

    $term = get_term_by('slug', $type_slug, 'resource_type');
    if (!$term instanceof WP_Term) {
        echo "  FAIL: cms term missing for {$type_slug}\n";
        continue;
    }
    $term_id = (int) $term->term_id;

    $posts = [];
    $page = 1;
    do {
        $batch = irisid_res_http_json(
            "{$live_base}/posts?categories={$cat_id}&page={$page}&per_page=50&_embed=1&status=publish"
        );
        if (!is_array($batch) || count($batch) === 0) {
            break;
        }
        $posts = array_merge($posts, $batch);
        $page++;
    } while (count($batch) === 50);

    echo '  fetched ' . count($posts) . " published posts\n";

    $ok = 0;
    $fail = 0;

    foreach ($posts as $post) {
        $live_id = (int) ($post['id'] ?? 0);
        $slug = (string) ($post['slug'] ?? '');
        $title = irisid_res_decode_title((string) ($post['title']['rendered'] ?? ''));
        if ($slug === '' || $title === '') {
            echo "  skip: missing slug/title (live #{$live_id})\n";
            $fail++;
            continue;
        }

        $already = isset($seen_live_ids[$live_id]);
        $post_id = irisid_res_upsert_resource($post, $type_slug, $term_id, !$already);

        if ($post_id <= 0) {
            $fail++;
            continue;
        }

        // Always ensure this board's term is attached (posts can sit in multiple cats).
        wp_set_object_terms($post_id, [$term_id], 'resource_type', true);

        if (!$already) {
            $seen_live_ids[$live_id] = $post_id;
            echo "  OK #{$post_id} {$slug}\n";
        } else {
            echo "  +term {$type_slug} on #{$post_id} {$slug}\n";
        }
        $ok++;
    }

    echo "  board done ok={$ok} fail={$fail}\n";
    $grand_ok += $ok;
    $grand_fail += $fail;
}

echo "\nDone. ok={$grand_ok} fail={$grand_fail} unique_live=" . count($seen_live_ids) . "\n";
echo "Admin: https://cms.irisid.com/wp-admin/edit.php?post_type=resource\n";

/**
 * @param array<string,mixed> $post
 */
function irisid_res_upsert_resource(array $post, string $type_slug, int $term_id, bool $full_update): int
{
    $slug = (string) $post['slug'];
    $title = irisid_res_decode_title((string) ($post['title']['rendered'] ?? ''));

    $existing = get_page_by_path($slug, OBJECT, 'resource');
    $post_id = $existing instanceof WP_Post ? (int) $existing->ID : 0;

    if (!$full_update && $post_id > 0) {
        return $post_id;
    }

    $content_html = (string) ($post['content']['rendered'] ?? '');
    $excerpt_html = (string) ($post['excerpt']['rendered'] ?? '');
    $excerpt = irisid_res_strip_html($excerpt_html);
    $body = irisid_res_clean_body($content_html);

    $postarr = [
        'post_type'     => 'resource',
        'post_status'   => 'publish',
        'post_title'    => $title,
        'post_name'     => $slug,
        'post_content'  => $body,
        'post_date'     => isset($post['date']) ? str_replace('T', ' ', substr($post['date'], 0, 19)) : current_time('mysql'),
        'post_date_gmt' => isset($post['date_gmt']) ? str_replace('T', ' ', substr($post['date_gmt'], 0, 19)) : current_time('mysql', true),
    ];

    if ($post_id > 0) {
        $postarr['ID'] = $post_id;
        $result = wp_update_post($postarr, true);
    } else {
        $result = wp_insert_post($postarr, true);
    }

    if (is_wp_error($result)) {
        echo '  FAIL ' . $slug . ': ' . $result->get_error_message() . "\n";
        return 0;
    }

    $post_id = (int) $result;
    update_post_meta($post_id, '_irisid_migrated_from_live_id', (int) ($post['id'] ?? 0));
    update_post_meta($post_id, '_irisid_migrated_from_live_url', (string) ($post['link'] ?? ''));

    if (!function_exists('update_field')) {
        return $post_id;
    }

    update_field('excerpt', $excerpt, $post_id);
    update_field('body', $body, $post_id);

    if ($type_slug === 'events') {
        $event_date = irisid_res_parse_event_date($content_html);
        if ($event_date) {
            update_field('event_date', $event_date, $post_id);
        }
        $external = irisid_res_parse_more_info_url($content_html);
        if ($external) {
            update_field('external_url', $external, $post_id);
        }
    }

    if (in_array($type_slug, ['videos', 'webinars', 'iris-id-talk'], true)) {
        $video = irisid_res_extract_video_url($content_html);
        if ($video) {
            update_field('video_embed', $video, $post_id);
        }
    }

    $featured_url = irisid_res_featured_url($post);
    if ($featured_url && !irisid_res_is_default_thumb($featured_url)) {
        $att_id = irisid_res_sideload($featured_url, $post_id, $title);
        if ($att_id) {
            update_field('featured_image', $att_id, $post_id);
            set_post_thumbnail($post_id, $att_id);
        }
    }

    return $post_id;
}

function irisid_res_http_json(string $url)
{
    $res = wp_remote_get($url, [
        'timeout' => 90,
        'headers' => ['User-Agent' => 'irisid-resources-migrate/1.0'],
    ]);
    if (is_wp_error($res)) {
        fwrite(STDERR, 'HTTP error: ' . $res->get_error_message() . "\n");
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($code < 200 || $code >= 300) {
        // WP REST returns 400 when page is past the end – treat as empty.
        if ($code === 400) {
            return [];
        }
        fwrite(STDERR, "HTTP {$code} for {$url}\n");
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function irisid_res_decode_title(string $html): string
{
    return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function irisid_res_strip_html(string $html): string
{
    $text = wp_strip_all_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function irisid_res_clean_body(string $html): string
{
    $html = preg_replace('/\s*style="[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace('/<\/?span>/i', '', $html) ?? $html;
    return trim($html);
}

function irisid_res_parse_event_date(string $html): string
{
    $text = irisid_res_strip_html($html);
    if (!preg_match('/Date:\s*([A-Za-z]+)\s+(\d{1,2})(?:\s*[-–]\s*\d{1,2})?,?\s*(\d{4})/u', $text, $m)) {
        return '';
    }
    $ts = strtotime(sprintf('%s %d, %d', $m[1], (int) $m[2], (int) $m[3]));
    return $ts === false ? '' : gmdate('Y-m-d', $ts);
}

function irisid_res_parse_more_info_url(string $html): string
{
    if (preg_match('/More Info:\s*<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m)) {
        return esc_url_raw($m[1]);
    }
    if (preg_match('/More Info:\s*(https?:\/\/\S+)/i', irisid_res_strip_html($html), $m)) {
        return esc_url_raw(rtrim($m[1], '.,);'));
    }
    return '';
}

function irisid_res_extract_video_url(string $html): string
{
    if (preg_match('#https?://(?:www\.)?(?:youtube\.com/watch\?v=[\w-]+|youtu\.be/[\w-]+|vimeo\.com/\d+)#i', $html, $m)) {
        return esc_url_raw($m[0]);
    }
    if (preg_match('#src=["\'](https?://(?:www\.)?youtube\.com/embed/[^"\']+)["\']#i', $html, $m)) {
        return esc_url_raw($m[1]);
    }
    return '';
}

function irisid_res_featured_url(array $post): string
{
    $media = $post['_embedded']['wp:featuredmedia'][0] ?? null;
    if (is_array($media) && !empty($media['source_url'])) {
        return (string) $media['source_url'];
    }
    return '';
}

function irisid_res_is_default_thumb(string $url): bool
{
    return (bool) preg_match('/\/default-thumb\.(jpe?g|png|webp|gif)(\?|$)/i', $url);
}

function irisid_res_sideload(string $url, int $parent_id, string $desc): int
{
    $url = esc_url_raw($url);
    if ($url === '') {
        return 0;
    }

    $filename = wp_basename((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if ($filename !== '') {
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_wp_attached_file',
                    'value'   => $filename,
                    'compare' => 'LIKE',
                ],
            ],
        ]);
        if ($q->have_posts()) {
            return (int) $q->posts[0]->ID;
        }
    }

    $tmp = download_url($url);
    if (is_wp_error($tmp)) {
        echo "    warn sideload {$filename}: " . $tmp->get_error_message() . "\n";
        return 0;
    }

    $file_array = [
        'name'     => $filename !== '' ? $filename : 'resource.jpg',
        'tmp_name' => $tmp,
    ];
    $id = media_handle_sideload($file_array, $parent_id, $desc);
    if (is_wp_error($id)) {
        @unlink($tmp);
        echo '    warn sideload: ' . $id->get_error_message() . "\n";
        return 0;
    }
    return (int) $id;
}
