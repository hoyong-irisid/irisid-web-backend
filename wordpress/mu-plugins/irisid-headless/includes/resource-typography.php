<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const IRISID_RESOURCE_TYPOGRAPHY_OPTION = 'irisid_resource_typography';

/**
 * Default typography for resource body HTML (List / Video Contents).
 * Sizes are rem so they scale with the site root.
 *
 * @return array<string, array<string, string>>
 */
function irisid_resource_typography_defaults(): array
{
    return [
        'p'  => [
            'fontSize'   => '1.125',
            'fontWeight' => '300',
            'lineHeight' => '1.7',
            'marginTop'  => '0',
            'marginBottom' => '1.25',
            'color'      => '',
        ],
        'h1' => [
            'fontSize'   => '2.25',
            'fontWeight' => '300',
            'lineHeight' => '1.2',
            'marginTop'  => '2',
            'marginBottom' => '1',
            'color'      => '',
        ],
        'h2' => [
            'fontSize'   => '1.75',
            'fontWeight' => '400',
            'lineHeight' => '1.25',
            'marginTop'  => '2.25',
            'marginBottom' => '0.85',
            'color'      => '',
        ],
        'h3' => [
            'fontSize'   => '1.375',
            'fontWeight' => '500',
            'lineHeight' => '1.3',
            'marginTop'  => '1.75',
            'marginBottom' => '0.65',
            'color'      => '',
        ],
        'h4' => [
            'fontSize'   => '1.125',
            'fontWeight' => '500',
            'lineHeight' => '1.35',
            'marginTop'  => '1.5',
            'marginBottom' => '0.5',
            'color'      => '',
        ],
    ];
}

/** @return list<string> */
function irisid_resource_typography_tags(): array
{
    return ['p', 'h1', 'h2', 'h3', 'h4'];
}

/**
 * @param mixed $raw
 * @return array<string, array<string, string>>
 */
function irisid_normalize_resource_typography($raw): array
{
    $defaults = irisid_resource_typography_defaults();
    if (!is_array($raw)) {
        return $defaults;
    }

    $out = [];
    foreach (irisid_resource_typography_tags() as $tag) {
        $row = isset($raw[$tag]) && is_array($raw[$tag]) ? $raw[$tag] : [];
        $base = $defaults[$tag];
        $fontSize = isset($row['fontSize']) ? (string) $row['fontSize'] : $base['fontSize'];
        $fontWeight = isset($row['fontWeight']) ? (string) $row['fontWeight'] : $base['fontWeight'];
        $lineHeight = isset($row['lineHeight']) ? (string) $row['lineHeight'] : $base['lineHeight'];
        $marginTop = isset($row['marginTop']) ? (string) $row['marginTop'] : $base['marginTop'];
        $marginBottom = isset($row['marginBottom']) ? (string) $row['marginBottom'] : $base['marginBottom'];
        $color = isset($row['color']) ? trim((string) $row['color']) : $base['color'];

        if (!preg_match('/^\d+(\.\d+)?$/', $fontSize)) {
            $fontSize = $base['fontSize'];
        }
        if (!in_array($fontWeight, ['300', '400', '500', '600', '700'], true)) {
            $fontWeight = $base['fontWeight'];
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $lineHeight)) {
            $lineHeight = $base['lineHeight'];
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $marginTop)) {
            $marginTop = $base['marginTop'];
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $marginBottom)) {
            $marginBottom = $base['marginBottom'];
        }
        if ($color !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            $color = '';
        }

        $out[$tag] = [
            'fontSize'     => $fontSize,
            'fontWeight'   => $fontWeight,
            'lineHeight'   => $lineHeight,
            'marginTop'    => $marginTop,
            'marginBottom' => $marginBottom,
            'color'        => $color,
        ];
    }

    return $out;
}

/** @return array<string, array<string, string>> */
function irisid_get_resource_typography(): array
{
    return irisid_normalize_resource_typography(get_option(IRISID_RESOURCE_TYPOGRAPHY_OPTION, null));
}

/** Build CSS for .resource-content (frontend + TinyMCE preview). */
function irisid_resource_typography_css(string $selector = '.resource-content'): string
{
    $styles = irisid_get_resource_typography();
    $chunks = [];
    foreach ($styles as $tag => $row) {
        $color = $row['color'] !== '' ? "color: {$row['color']};" : '';
        $chunks[] = sprintf(
            '%1$s %2$s { font-size: %3$srem; font-weight: %4$s; line-height: %5$s; margin-top: %6$srem; margin-bottom: %7$srem; %8$s }',
            $selector,
            $tag,
            $row['fontSize'],
            $row['fontWeight'],
            $row['lineHeight'],
            $row['marginTop'],
            $row['marginBottom'],
            $color
        );
    }
    $chunks[] = "{$selector} a { text-decoration: underline; text-underline-offset: 2px; }";
    $chunks[] = "{$selector} ul, {$selector} ol { margin: 1.25rem 0; padding-left: 1.25rem; }";
    $chunks[] = "{$selector} ul { list-style: disc; }";
    $chunks[] = "{$selector} ol { list-style: decimal; }";
    $chunks[] = "{$selector} li { margin: 0.25rem 0; }";
    $chunks[] = "{$selector} strong { font-weight: 500; }";
    $chunks[] = "{$selector} em { font-style: italic; }";
    $chunks[] = "{$selector} blockquote { margin: 1.25rem 0; padding-left: 1rem; border-left: 2px solid #d4d4d8; }";

    return implode("\n", $chunks);
}

add_action('admin_menu', 'irisid_register_resource_typography_menu', 1000);

function irisid_register_resource_typography_menu(): void
{
    add_submenu_page(
        'edit.php?post_type=resource',
        'Resource Style',
        'Style',
        'manage_options',
        'irisid-resource-style',
        'irisid_render_resource_typography_page'
    );
}

add_action('admin_init', 'irisid_handle_resource_typography_save');

function irisid_handle_resource_typography_save(): void
{
    if (!is_admin()) {
        return;
    }
    if (!isset($_POST['irisid_resource_typography_nonce'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!wp_verify_nonce(
        sanitize_text_field(wp_unslash((string) $_POST['irisid_resource_typography_nonce'])),
        'irisid_resource_typography_save'
    )) {
        return;
    }

    $raw = isset($_POST['typography']) && is_array($_POST['typography'])
        ? wp_unslash($_POST['typography'])
        : [];
    $normalized = irisid_normalize_resource_typography($raw);
    update_option(IRISID_RESOURCE_TYPOGRAPHY_OPTION, $normalized, false);

    // Drop Next.js ISR cache so public resource pages pick up new CSS.
    $url = defined('IRISID_REVALIDATE_URL') ? IRISID_REVALIDATE_URL : getenv('IRISID_REVALIDATE_URL');
    $secret = defined('IRISID_REVALIDATE_SECRET') ? IRISID_REVALIDATE_SECRET : getenv('IRISID_REVALIDATE_SECRET');
    if ($url && $secret) {
        wp_remote_post((string) $url, [
            'timeout' => 5,
            'headers' => [
                'Content-Type'        => 'application/json',
                'x-revalidate-secret' => (string) $secret,
            ],
            'body'    => wp_json_encode(['tag' => 'resource-typography']),
        ]);
    }

    wp_safe_redirect(add_query_arg(
        ['page' => 'irisid-resource-style', 'updated' => '1'],
        admin_url('edit.php?post_type=resource')
    ));
    exit;
}

function irisid_render_resource_typography_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to edit resource styles.', 'irisid'));
    }

    $styles = irisid_get_resource_typography();
    $labels = [
        'p'  => 'Paragraph',
        'h1' => 'Heading 1',
        'h2' => 'Heading 2',
        'h3' => 'Heading 3',
        'h4' => 'Heading 4',
    ];
    $weights = ['300' => 'Light (300)', '400' => 'Regular (400)', '500' => 'Medium (500)', '600' => 'Semibold (600)', '700' => 'Bold (700)'];
    ?>
    <div class="wrap">
      <h1>Resource content style</h1>
      <p>Controls how Contents HTML renders on every resource detail page (all Resource Types). Changes apply on the public site after the next page refresh / cache purge.</p>
      <?php if (isset($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Styles saved.</p></div>
      <?php endif; ?>
      <form method="post" action="">
        <?php wp_nonce_field('irisid_resource_typography_save', 'irisid_resource_typography_nonce'); ?>
        <table class="widefat striped" style="max-width:960px;margin-top:16px;">
          <thead>
            <tr>
              <th>Element</th>
              <th>Font size (rem)</th>
              <th>Weight</th>
              <th>Line height</th>
              <th>Margin top (rem)</th>
              <th>Margin bottom (rem)</th>
              <th>Color (optional)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($labels as $tag => $label) :
                $row = $styles[$tag];
                ?>
              <tr>
                <td><strong><?php echo esc_html($label); ?></strong> <code>&lt;<?php echo esc_html($tag); ?>&gt;</code></td>
                <td><input name="typography[<?php echo esc_attr($tag); ?>][fontSize]" type="text" value="<?php echo esc_attr($row['fontSize']); ?>" class="small-text" /></td>
                <td>
                  <select name="typography[<?php echo esc_attr($tag); ?>][fontWeight]">
                    <?php foreach ($weights as $value => $weightLabel) : ?>
                      <option value="<?php echo esc_attr($value); ?>" <?php selected($row['fontWeight'], $value); ?>><?php echo esc_html($weightLabel); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input name="typography[<?php echo esc_attr($tag); ?>][lineHeight]" type="text" value="<?php echo esc_attr($row['lineHeight']); ?>" class="small-text" /></td>
                <td><input name="typography[<?php echo esc_attr($tag); ?>][marginTop]" type="text" value="<?php echo esc_attr($row['marginTop']); ?>" class="small-text" /></td>
                <td><input name="typography[<?php echo esc_attr($tag); ?>][marginBottom]" type="text" value="<?php echo esc_attr($row['marginBottom']); ?>" class="small-text" /></td>
                <td><input name="typography[<?php echo esc_attr($tag); ?>][color]" type="text" value="<?php echo esc_attr($row['color']); ?>" placeholder="#0a0a0a" class="regular-text" style="max-width:7rem;" /></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="description" style="margin-top:12px;">Leave color empty to inherit the site theme foreground. Font size / margins use rem (1rem ≈ 16px).</p>
        <p class="submit">
          <button type="submit" class="button button-primary">Save styles</button>
        </p>
      </form>
      <h2 style="margin-top:2rem;">Preview (editor)</h2>
      <div class="irisid-resource-style-preview" style="max-width:720px;padding:24px;border:1px solid #d4d4d8;background:#fff;">
        <style><?php echo irisid_resource_typography_css('.irisid-resource-style-preview'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
        <h1>Heading 1 sample</h1>
        <h2>Heading 2 sample</h2>
        <h3>Heading 3 sample</h3>
        <h4>Heading 4 sample</h4>
        <p>Paragraph sample – iris recognition is contactless and accurate for enterprise identity programs.</p>
      </div>
    </div>
    <?php
}

add_action('rest_api_init', 'irisid_register_resource_typography_rest');

function irisid_register_resource_typography_rest(): void
{
    register_rest_route('irisid/v1', '/resource-typography', [
        'methods'             => 'GET',
        'callback'            => static function (): WP_REST_Response {
            return new WP_REST_Response([
                'styles' => irisid_get_resource_typography(),
                'css'    => irisid_resource_typography_css('.resource-content'),
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);
}

/** Make Heading 1–4 visibly distinct inside the ACF Contents WYSIWYG. */
add_filter('tiny_mce_before_init', 'irisid_resource_typography_tinymce', 20);

/**
 * @param array<string, mixed> $init
 * @return array<string, mixed>
 */
function irisid_resource_typography_tinymce(array $init): array
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'resource') {
        return $init;
    }

    $css = irisid_resource_typography_css('body');
    $css = str_replace(["\n", "\r"], ' ', $css);
    $existing = isset($init['content_style']) ? (string) $init['content_style'] : '';
    $init['content_style'] = trim($existing . ' ' . $css);

    return $init;
}
