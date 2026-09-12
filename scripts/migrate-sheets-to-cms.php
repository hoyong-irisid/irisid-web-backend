<?php
/**
 * Migrate live Data Sheets + Tip Sheets → cms `resource` CPT.
 *
 *   scp scripts/migrate-sheets-to-cms.php irisid5@HOST:~/cms.irisid.com/
 *   cd ~/cms.irisid.com && wp eval-file ~/cms.irisid.com/migrate-sheets-to-cms.php
 *
 * Requires: ACF field group "Data / Tip Sheet Fields" synced (group_irisid_resource_sheet.json).
 * No declare(strict_types) – wp eval-file wraps the file.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via: wp eval-file migrate-sheets-to-cms.php\n");
    exit(1);
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

irisid_sheet_ensure_terms();

echo "=== Data Sheets ===\n";
$data_ok = irisid_sheet_migrate_data_sheets();
echo "=== Tip Sheets ===\n";
$tip_ok = irisid_sheet_migrate_tip_sheets();

echo "\nDone. data-sheets={$data_ok} tip-sheets={$tip_ok}\n";
echo "Admin: https://cms.irisid.com/wp-admin/edit.php?post_type=resource\n";

function irisid_sheet_ensure_terms(): void
{
    foreach (['data-sheets' => 'Data Sheets', 'tip-sheets' => 'Tip Sheets'] as $slug => $name) {
        if (!term_exists($slug, 'resource_type')) {
            wp_insert_term($name, 'resource_type', ['slug' => $slug]);
            echo "Created resource_type: {$slug}\n";
        }
    }
}

function irisid_sheet_migrate_data_sheets(): int
{
    $html = irisid_sheet_fetch('https://irisid.com/resources/literature/data-sheets/');
    if ($html === '') {
        return 0;
    }

    $sections = [];
    if (preg_match_all('/<h3[^>]*>([\s\S]*?)<\/h3>/i', $html, $hm, PREG_OFFSET_CAPTURE)) {
        foreach ($hm[1] as $i => $titleMatch) {
            $title = irisid_sheet_strip($titleMatch[0]);
            if ($title === 'Hardware Products' || $title === 'Software Products') {
                $sections[] = ['pos' => (int) $hm[0][$i][1], 'title' => $title];
            }
        }
    }

    $pattern = '/<img[^>]+src="(https:\/\/irisid\.com\/wp-content\/uploads\/[^"]*literature-[^"]+)"[^>]*>[\s\S]{0,800}?<h5[^>]*>([\s\S]*?)<\/h5>[\s\S]{0,600}?<p[^>]*kubio\/text[^>]*>([\s\S]*?)<\/p>/i';
    if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        echo "  No data-sheet cards found on live.\n";
        return 0;
    }

    $ok = 0;
    foreach ($matches as $match) {
        $image_url = $match[1][0];
        $title = irisid_sheet_strip($match[2][0]);
        $body = $match[3][0];
        $pos = (int) $match[0][1];
        $version = irisid_sheet_strip(preg_split('/<br\s*\/?>/i', $body)[0] ?? '');

        $downloads = [];
        if (preg_match_all('/<a[^>]+href="([^"]+\.pdf)"[^>]*>([\s\S]*?)<\/a>/i', $body, $lm, PREG_SET_ORDER)) {
            foreach ($lm as $link) {
                $href = html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (strpos($href, '/') === 0) {
                    $href = 'https://irisid.com' . $href;
                }
                $downloads[] = [
                    'label' => irisid_sheet_strip($link[2]) ?: 'English',
                    'href'  => $href,
                ];
            }
        }
        if ($title === '' || !$downloads) {
            continue;
        }

        $section = 'Hardware Products';
        foreach ($sections as $marker) {
            if ($marker['pos'] < $pos) {
                $section = $marker['title'];
            }
        }

        $post_id = irisid_sheet_upsert_resource($title, 'data-sheets', [
            'version' => $version,
            'section' => $section,
            'image'   => $image_url,
            'files'   => $downloads,
        ]);
        if ($post_id) {
            echo "  OK #{$post_id} {$title}\n";
            $ok++;
        }
    }

    return $ok;
}

function irisid_sheet_migrate_tip_sheets(): int
{
    $html = irisid_sheet_fetch('https://irisid.com/resources/literature/tip-sheets/');
    if ($html === '') {
        return 0;
    }

    $intro = stripos($html, 'Download our tip sheets');
    $chunk = $intro === false ? $html : substr($html, $intro);

    $pattern = '/<img[^>]+src="(https:\/\/irisid\.com\/wp-content\/uploads\/[^"]+)"[^>]*>[\s\S]{0,1200}?<h5[^>]*>([\s\S]*?)<\/h5>[\s\S]{0,800}?<a[^>]+href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i';
    if (!preg_match_all($pattern, $chunk, $matches, PREG_SET_ORDER)) {
        echo "  No tip-sheet cards found on live.\n";
        return 0;
    }

    $skip = [
        'Solutions' => true,
        'Hardware Products' => true,
        'Software Products' => true,
        'Resources' => true,
        'Support' => true,
        'Company' => true,
    ];

    $ok = 0;
    $seen = [];
    foreach ($matches as $match) {
        $image_url = $match[1];
        if (preg_match('/logo|symbol/i', $image_url)) {
            continue;
        }
        $title = irisid_sheet_strip($match[2]);
        if ($title === '' || isset($skip[$title]) || strlen($title) < 10) {
            continue;
        }
        $href = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($href, '/') === 0) {
            $href = 'https://irisid.com' . $href;
        }
        $label = irisid_sheet_strip($match[4]) ?: 'DOWNLOAD';
        if (isset($seen[$href])) {
            continue;
        }
        $seen[$href] = true;

        $files = [['label' => $label, 'href' => $href]];
        $post_id = irisid_sheet_upsert_resource($title, 'tip-sheets', [
            'version' => '',
            'section' => '',
            'image'   => $image_url,
            'files'   => $files,
        ]);
        if ($post_id) {
            echo "  OK #{$post_id} {$title}\n";
            $ok++;
        }
    }

    return $ok;
}

/**
 * @param array{version:string,section:string,image:string,files:array<int,array{label:string,href:string}>} $data
 */
function irisid_sheet_upsert_resource(string $title, string $type_slug, array $data): int
{
    $slug = sanitize_title($title);
    if ($slug === '') {
        return 0;
    }

    $existing = get_page_by_path($slug, OBJECT, 'resource');
    $post_id = $existing instanceof WP_Post ? (int) $existing->ID : 0;

    $postarr = [
        'post_type'   => 'resource',
        'post_status' => 'publish',
        'post_title'  => $title,
        'post_name'   => $slug,
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

    $term = get_term_by('slug', $type_slug, 'resource_type');
    if ($term instanceof WP_Term) {
        wp_set_object_terms($post_id, [(int) $term->term_id], 'resource_type', false);
    }

    if (!function_exists('update_field')) {
        return $post_id;
    }

    update_field('version', $data['version'], $post_id);
    if ($data['section'] !== '') {
        update_field('section', $data['section'], $post_id);
    }

    $thumb_id = irisid_sheet_sideload($data['image'], $post_id, $title . ' thumbnail');
    if ($thumb_id) {
        update_field('featured_image', $thumb_id, $post_id);
        set_post_thumbnail($post_id, $thumb_id);
    }

    $rows = [];
    foreach ($data['files'] as $file) {
        $label = $file['label'];
        $href = $file['href'];
        $row = ['language' => irisid_sheet_normalize_language($label)];

        if (preg_match('/\.pdf($|\?)/i', $href)) {
            $file_id = irisid_sheet_sideload($href, $post_id, $title . ' ' . $label);
            if ($file_id) {
                $row['file'] = $file_id;
            } else {
                $row['url'] = $href;
            }
        } else {
            $row['url'] = $href;
        }
        $rows[] = $row;
    }
    update_field('language_files', $rows, $post_id);

    return $post_id;
}

function irisid_sheet_normalize_language(string $label): string
{
    $known = [
        'english' => 'English',
        'spanish' => 'Spanish',
        'french' => 'French',
        'turkish' => 'Turkish',
        'german' => 'German',
        'korean' => 'Korean',
        'japanese' => 'Japanese',
        'chinese' => 'Chinese',
        'portuguese' => 'Portuguese',
        'italian' => 'Italian',
        'arabic' => 'Arabic',
        'download' => 'DOWNLOAD',
    ];
    $key = strtolower(trim($label));
    return $known[$key] ?? (trim($label) !== '' ? trim($label) : 'English');
}

function irisid_sheet_fetch(string $url): string
{
    $res = wp_remote_get($url, [
        'timeout' => 90,
        'headers' => ['User-Agent' => 'irisid-sheets-migrate/1.0'],
    ]);
    if (is_wp_error($res)) {
        echo '  fetch error: ' . $res->get_error_message() . "\n";
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
        echo "  fetch HTTP {$code} for {$url}\n";
        return '';
    }
    return (string) wp_remote_retrieve_body($res);
}

function irisid_sheet_strip(string $html): string
{
    $text = wp_strip_all_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function irisid_sheet_sideload(string $url, int $parent_id, string $desc): int
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
        'name'     => $filename !== '' ? $filename : 'sheet-file.bin',
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
