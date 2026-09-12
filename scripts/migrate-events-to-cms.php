<?php
/**
 * Migrate live irisid.com Events posts → cms.irisid.com `resource` CPT
 * (resource_type = events).
 *
 * Run on the CMS host:
 *
 *   scp scripts/migrate-events-to-cms.php irisid5@HOST:~/cms.irisid.com/
 *   cd ~/cms.irisid.com && wp eval-file ~/cms.irisid.com/migrate-events-to-cms.php
 *
 * Idempotent: re-runs update by slug. No declare(strict_types) – wp eval-file wraps the file.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via: wp eval-file migrate-events-to-cms.php\n");
    exit(1);
}

$live_base = 'https://irisid.com/wp-json/wp/v2';
$category_slug = 'events';
$resource_type_slug = 'events';

echo "Fetching live category `{$category_slug}`…\n";
$cats = irisid_migrate_http_json("{$live_base}/categories?slug=" . rawurlencode($category_slug));
if (!$cats || empty($cats[0]['id'])) {
    fwrite(STDERR, "Live Events category not found.\n");
    exit(1);
}
$cat_id = (int) $cats[0]['id'];
echo "  category id={$cat_id} (live count reported: " . (int) ($cats[0]['count'] ?? 0) . ")\n";

$posts = [];
$page = 1;
do {
    $batch = irisid_migrate_http_json(
        "{$live_base}/posts?categories={$cat_id}&page={$page}&per_page=50&_embed=1&status=publish"
    );
    if (!is_array($batch)) {
        break;
    }
    $posts = array_merge($posts, $batch);
    $page++;
} while (count($batch) === 50);

echo 'Found ' . count($posts) . " published Events on live.\n";

$term = get_term_by('slug', $resource_type_slug, 'resource_type');
if (!$term instanceof WP_Term) {
    $inserted = wp_insert_term('Events', 'resource_type', ['slug' => $resource_type_slug]);
    if (is_wp_error($inserted)) {
        fwrite(STDERR, 'Cannot create resource_type term: ' . $inserted->get_error_message() . "\n");
        exit(1);
    }
    $term = get_term((int) $inserted['term_id'], 'resource_type');
}
$term_id = (int) $term->term_id;

$ok = 0;
$fail = 0;

foreach ($posts as $post) {
    $slug = (string) ($post['slug'] ?? '');
    $title = irisid_migrate_decode_title((string) ($post['title']['rendered'] ?? ''));
    if ($slug === '' || $title === '') {
        echo "  skip: missing slug/title\n";
        $fail++;
        continue;
    }

    $content_html = (string) ($post['content']['rendered'] ?? '');
    $excerpt_html = (string) ($post['excerpt']['rendered'] ?? '');
    $excerpt = irisid_migrate_strip_html($excerpt_html);
    $body = irisid_migrate_clean_body($content_html);
    $event_date = irisid_migrate_parse_event_date($content_html);
    $external = irisid_migrate_parse_more_info_url($content_html);

    $existing = get_page_by_path($slug, OBJECT, 'resource');
    $post_id = $existing instanceof WP_Post ? (int) $existing->ID : 0;

    $postarr = [
        'post_type'    => 'resource',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_content' => $body,
        'post_date'    => isset($post['date']) ? str_replace('T', ' ', substr($post['date'], 0, 19)) : current_time('mysql'),
        'post_date_gmt'=> isset($post['date_gmt']) ? str_replace('T', ' ', substr($post['date_gmt'], 0, 19)) : current_time('mysql', true),
    ];

    if ($post_id > 0) {
        $postarr['ID'] = $post_id;
        $result = wp_update_post($postarr, true);
    } else {
        $result = wp_insert_post($postarr, true);
    }

    if (is_wp_error($result)) {
        echo "  FAIL {$slug}: " . $result->get_error_message() . "\n";
        $fail++;
        continue;
    }

    $post_id = (int) $result;
    wp_set_object_terms($post_id, [$term_id], 'resource_type', false);

    update_post_meta($post_id, '_irisid_migrated_from_live_id', (int) ($post['id'] ?? 0));
    update_post_meta($post_id, '_irisid_migrated_from_live_url', (string) ($post['link'] ?? ''));

    if (function_exists('update_field')) {
        update_field('excerpt', $excerpt, $post_id);
        update_field('body', $body, $post_id);
        if ($event_date) {
            update_field('event_date', $event_date, $post_id);
        }
        if ($external) {
            update_field('external_url', $external, $post_id);
        }

        $featured_url = irisid_migrate_featured_url($post);
        if ($featured_url) {
            $att_id = irisid_migrate_sideload_image($featured_url, $post_id, $title);
            if ($att_id) {
                update_field('featured_image', $att_id, $post_id);
                set_post_thumbnail($post_id, $att_id);
            }
        }
    }

    $date_note = $event_date ? " event_date={$event_date}" : ' event_date=(none)';
    echo "  OK #{$post_id} {$slug}{$date_note}\n";
    $ok++;
}

echo "\nDone. ok={$ok} fail={$fail}\n";
echo "Admin: https://cms.irisid.com/wp-admin/edit.php?post_type=resource\n";
echo "Filter taxonomy resource_type=events in the Resources list.\n";

function irisid_migrate_http_json(string $url)
{
    $res = wp_remote_get($url, [
        'timeout' => 60,
        'headers' => ['User-Agent' => 'irisid-events-migrate/1.0'],
    ]);
    if (is_wp_error($res)) {
        fwrite(STDERR, 'HTTP error: ' . $res->get_error_message() . "\n");
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($code < 200 || $code >= 300) {
        fwrite(STDERR, "HTTP {$code} for {$url}\n");
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function irisid_migrate_decode_title(string $html): string
{
    return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function irisid_migrate_strip_html(string $html): string
{
    $text = wp_strip_all_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function irisid_migrate_clean_body(string $html): string
{
    // Drop Kubio inline font stacks; keep structure.
    $html = preg_replace('/\s*style="[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace('/<\/?span>/i', '', $html) ?? $html;
    return trim($html);
}

/**
 * Parse first day from lines like "Date: December 2-4, 2026" or "Date: September 2-3, 2026".
 */
function irisid_migrate_parse_event_date(string $html): string
{
    $text = irisid_migrate_strip_html($html);
    if (!preg_match('/Date:\s*([A-Za-z]+)\s+(\d{1,2})(?:\s*[-–]\s*\d{1,2})?,?\s*(\d{4})/u', $text, $m)) {
        return '';
    }
    $month = $m[1];
    $day = (int) $m[2];
    $year = (int) $m[3];
    $ts = strtotime(sprintf('%s %d, %d', $month, $day, $year));
    if ($ts === false) {
        return '';
    }
    return gmdate('Y-m-d', $ts);
}

function irisid_migrate_parse_more_info_url(string $html): string
{
    if (preg_match('/More Info:\s*<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m)) {
        return esc_url_raw($m[1]);
    }
    if (preg_match('/More Info:\s*(https?:\/\/\S+)/i', irisid_migrate_strip_html($html), $m)) {
        return esc_url_raw(rtrim($m[1], '.,);'));
    }
    return '';
}

function irisid_migrate_featured_url(array $post): string
{
    $media = $post['_embedded']['wp:featuredmedia'][0] ?? null;
    if (is_array($media) && !empty($media['source_url'])) {
        return (string) $media['source_url'];
    }
    return '';
}

function irisid_migrate_sideload_image(string $url, int $parent_id, string $title): int
{
    $url = esc_url_raw($url);
    if ($url === '') {
        return 0;
    }

    $existing = attachment_url_to_postid($url);
    if ($existing) {
        return (int) $existing;
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

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url);
    if (is_wp_error($tmp)) {
        echo "    warn: image download failed ({$filename}): " . $tmp->get_error_message() . "\n";
        return 0;
    }

    $file_array = [
        'name'     => $filename !== '' ? $filename : 'event.jpg',
        'tmp_name' => $tmp,
    ];
    $id = media_handle_sideload($file_array, $parent_id, $title);
    if (is_wp_error($id)) {
        @unlink($tmp);
        echo '    warn: image sideload failed: ' . $id->get_error_message() . "\n";
        return 0;
    }

    return (int) $id;
}
