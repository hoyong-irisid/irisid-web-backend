<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Adds Duration + Status columns to the Site Notices list table (wp-admin). */

add_filter('manage_edit-site_notice_columns', 'irisid_site_notice_admin_columns');
add_action('manage_site_notice_posts_custom_column', 'irisid_site_notice_admin_column_content', 10, 2);

/**
 * Core's Revisions + Slug boxes register in the same 'normal' column as the ACF
 * "Site Notice Fields" panel, ahead of it, pushing the actual editable content
 * (Linked event, Notice body, ...) below a long revision list. Demote them to
 * 'low' priority so the ACF panel – whatever priority it happens to register
 * at – renders first regardless.
 *
 * Core adds revisionsdiv/slugdiv directly in wp-admin/edit-form-advanced.php,
 * not through a hooked add_meta_boxes callback, so by the time the
 * `add_meta_boxes` action fires they don't exist yet to move. `do_meta_boxes`
 * fires once per context right before that context's boxes are printed –
 * definitely after both core and ACF have registered theirs.
 */
add_action('do_meta_boxes', 'irisid_reorder_site_notice_metaboxes', 1, 2);

/** @param string|WP_Screen $screen WP core has passed either across versions. */
function irisid_reorder_site_notice_metaboxes($screen, string $context): void
{
    $post_type = is_object($screen) && isset($screen->post_type) ? $screen->post_type : (string) $screen;
    if ($post_type !== 'site_notice' || $context !== 'normal') {
        return;
    }

    global $wp_meta_boxes;
    if (empty($wp_meta_boxes['site_notice']['normal'])) {
        return;
    }

    foreach (['high', 'core', 'default'] as $priority) {
        if (empty($wp_meta_boxes['site_notice']['normal'][$priority])) {
            continue;
        }
        foreach (['revisionsdiv', 'slugdiv'] as $id) {
            if (isset($wp_meta_boxes['site_notice']['normal'][$priority][$id])) {
                $wp_meta_boxes['site_notice']['normal']['low'][$id] =
                    $wp_meta_boxes['site_notice']['normal'][$priority][$id];
                unset($wp_meta_boxes['site_notice']['normal'][$priority][$id]);
            }
        }
    }
}

/**
 * @param array<string, string> $columns
 * @return array<string, string>
 */
function irisid_site_notice_admin_columns(array $columns): array
{
    $with_new = [];
    foreach ($columns as $key => $label) {
        $with_new[$key] = $label;
        if ($key === 'date') {
            $with_new['irisid_duration'] = 'Duration';
            $with_new['irisid_status'] = 'Status';
        }
    }
    // Safety net in case a future WP version ever drops the 'date' column.
    if (!isset($with_new['irisid_duration'])) {
        $with_new['irisid_duration'] = 'Duration';
        $with_new['irisid_status'] = 'Status';
    }
    return $with_new;
}

function irisid_site_notice_admin_column_content(string $column, int $post_id): void
{
    if ($column === 'irisid_duration') {
        echo esc_html(irisid_site_notice_duration_label($post_id));
        return;
    }
    if ($column === 'irisid_status') {
        [$label, $color] = irisid_site_notice_status_label($post_id);
        printf('<span style="color:%s;font-weight:600;">%s</span>', esc_attr($color), esc_html($label));
    }
}

function irisid_site_notice_et_datetime(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value, new DateTimeZone('America/New_York'));
    } catch (Exception $e) {
        return null;
    }
}

function irisid_site_notice_duration_label(int $post_id): string
{
    $starts = irisid_site_notice_et_datetime((string) get_post_meta($post_id, 'starts_at', true));
    $ends = irisid_site_notice_et_datetime((string) get_post_meta($post_id, 'ends_at', true));

    $format = static fn (DateTimeImmutable $d): string => $d->format('M j, Y g:ia') . ' ET';

    if ($starts && $ends) {
        return $format($starts) . ' – ' . $format($ends);
    }
    if ($starts) {
        return $format($starts) . ' – no end date';
    }
    if ($ends) {
        return 'from publish – ' . $format($ends);
    }
    return 'from publish, no end date';
}

/** @return array{0: string, 1: string} [label, CSS color] */
function irisid_site_notice_status_label(int $post_id): array
{
    $status = get_post_status($post_id);
    if ($status !== 'publish') {
        return [ucfirst((string) $status), '#71717a'];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $starts = irisid_site_notice_et_datetime((string) get_post_meta($post_id, 'starts_at', true));
    $ends = irisid_site_notice_et_datetime((string) get_post_meta($post_id, 'ends_at', true));

    if ($starts && $now < $starts) {
        return ['Scheduled', '#2563eb'];
    }
    if ($ends && $now > $ends) {
        return ['Ended', '#a1a1aa'];
    }
    return ['Live', '#16a34a'];
}

/**
 * Warn (don't block) when saving a notice whose Show from/until window overlaps
 * another published notice's – both would be eligible on at least the homepage
 * at the same time, and pickSiteNoticeForPath() on the frontend just picks the
 * first match, silently hiding the other. Editors can still choose to proceed.
 */
add_action('admin_enqueue_scripts', 'irisid_site_notice_overlap_check_script');

function irisid_site_notice_overlap_check_script(string $hook): void
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'site_notice') {
        return;
    }

    $current_post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

    wp_add_inline_script(
        'jquery-core',
        irisid_site_notice_overlap_check_js($current_post_id),
        'after'
    );
}

function irisid_site_notice_overlap_check_js(int $currentPostId): string
{
    $rest_url = esc_url_raw(rest_url('wp/v2/site_notice') . '?status=publish&per_page=100&_fields=id,title,acf');

    return <<<JS
    (function () {
        function boot() {
            if (typeof acf === 'undefined') return;
            acf.addAction('ready', function () {
                var startsField = acf.getField('field_irisid_notice_starts_at');
                var endsField = acf.getField('field_irisid_notice_ends_at');
                if (!startsField || !endsField) return;

                var currentPostId = {$currentPostId};
                var others = null;

                fetch('{$rest_url}', { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (list) {
                        others = (Array.isArray(list) ? list : []).filter(function (n) {
                            return n.id !== currentPostId;
                        });
                    })
                    .catch(function () { others = []; });

                function parseDate(v) {
                    if (!v) return null;
                    var d = new Date(String(v).replace(' ', 'T'));
                    return isNaN(d.getTime()) ? null : d;
                }

                // Open-ended bounds (null) mean "always" on that side.
                function overlaps(aStart, aEnd, bStart, bEnd) {
                    if (aEnd && bStart && aEnd < bStart) return false;
                    if (bEnd && aStart && bEnd < aStart) return false;
                    return true;
                }

                function injectModalStyles() {
                    if (document.getElementById('irisid-overlap-modal-styles')) return;
                    var style = document.createElement('style');
                    style.id = 'irisid-overlap-modal-styles';
                    style.textContent = `
                        .irisid-overlap-backdrop {
                            position: fixed; inset: 0; background: rgba(30, 34, 40, .55);
                            z-index: 100000; display: flex; align-items: center; justify-content: center;
                            padding: 20px; opacity: 0; transition: opacity .15s ease;
                        }
                        .irisid-overlap-backdrop.is-visible { opacity: 1; }
                        .irisid-overlap-modal {
                            background: #fff; width: 100%; max-width: 460px; border-radius: 4px;
                            box-shadow: 0 8px 30px rgba(0,0,0,.25); overflow: hidden;
                            transform: translateY(-8px) scale(.98); transition: transform .15s ease;
                            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                        }
                        .irisid-overlap-backdrop.is-visible .irisid-overlap-modal { transform: translateY(0) scale(1); }
                        .irisid-overlap-modal__header {
                            display: flex; align-items: center; gap: 10px; padding: 16px 20px;
                            border-bottom: 1px solid #dcdcde;
                        }
                        .irisid-overlap-modal__icon {
                            flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%;
                            background: #fcf0e3; color: #b5680a; display: flex; align-items: center;
                            justify-content: center; font-size: 14px; font-weight: 700;
                        }
                        .irisid-overlap-modal__title { margin: 0; font-size: 15px; font-weight: 600; color: #1d2327; }
                        .irisid-overlap-modal__body { padding: 16px 20px; font-size: 13px; line-height: 1.6; color: #3c434a; }
                        .irisid-overlap-modal__body p { margin: 0 0 8px; }
                        .irisid-overlap-modal__body p:last-child { margin-bottom: 0; }
                        .irisid-overlap-modal__body strong { color: #1d2327; }
                        .irisid-overlap-modal__footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px 18px; }
                        .irisid-overlap-btn {
                            border-radius: 3px; padding: 6px 14px; font-size: 13px; cursor: pointer;
                            border: 1px solid transparent; line-height: 1.4;
                        }
                        .irisid-overlap-btn--cancel { background: #f6f7f7; border-color: #dcdcde; color: #2c3338; }
                        .irisid-overlap-btn--cancel:hover { background: #f0f0f1; }
                        .irisid-overlap-btn--proceed { background: #b32d2e; border-color: #b32d2e; color: #fff; }
                        .irisid-overlap-btn--proceed:hover { background: #8a2323; }
                    `;
                    document.head.appendChild(style);
                }

                function showOverlapModal(namesText) {
                    return new Promise(function (resolve) {
                        injectModalStyles();

                        var backdrop = document.createElement('div');
                        backdrop.className = 'irisid-overlap-backdrop';

                        var modal = document.createElement('div');
                        modal.className = 'irisid-overlap-modal';
                        modal.setAttribute('role', 'alertdialog');
                        modal.setAttribute('aria-modal', 'true');
                        modal.setAttribute('aria-labelledby', 'irisid-overlap-title');

                        var header = document.createElement('div');
                        header.className = 'irisid-overlap-modal__header';
                        var icon = document.createElement('span');
                        icon.className = 'irisid-overlap-modal__icon';
                        icon.setAttribute('aria-hidden', 'true');
                        icon.textContent = '!';
                        var title = document.createElement('h2');
                        title.className = 'irisid-overlap-modal__title';
                        title.id = 'irisid-overlap-title';
                        title.textContent = 'Schedule overlap warning';
                        header.appendChild(icon);
                        header.appendChild(title);

                        var body = document.createElement('div');
                        body.className = 'irisid-overlap-modal__body';
                        var p1 = document.createElement('p');
                        p1.appendChild(document.createTextNode('This notice’s Show from/until window overlaps with '));
                        var strong = document.createElement('strong');
                        strong.textContent = namesText;
                        p1.appendChild(strong);
                        p1.appendChild(document.createTextNode('.'));
                        var p2 = document.createElement('p');
                        p2.textContent = 'Only one notice shows at a time on a given page, so one of these may be hidden.';
                        body.appendChild(p1);
                        body.appendChild(p2);

                        var footer = document.createElement('div');
                        footer.className = 'irisid-overlap-modal__footer';
                        var cancelBtn = document.createElement('button');
                        cancelBtn.type = 'button';
                        cancelBtn.className = 'irisid-overlap-btn irisid-overlap-btn--cancel';
                        cancelBtn.textContent = 'Cancel';
                        var proceedBtn = document.createElement('button');
                        proceedBtn.type = 'button';
                        proceedBtn.className = 'irisid-overlap-btn irisid-overlap-btn--proceed';
                        proceedBtn.textContent = 'Save anyway';
                        footer.appendChild(cancelBtn);
                        footer.appendChild(proceedBtn);

                        modal.appendChild(header);
                        modal.appendChild(body);
                        modal.appendChild(footer);
                        backdrop.appendChild(modal);
                        document.body.appendChild(backdrop);

                        requestAnimationFrame(function () { backdrop.classList.add('is-visible'); });

                        function close(result) {
                            backdrop.classList.remove('is-visible');
                            document.removeEventListener('keydown', onKeydown);
                            window.setTimeout(function () { backdrop.remove(); }, 150);
                            resolve(result);
                        }
                        function onKeydown(e) {
                            if (e.key === 'Escape') close(false);
                        }
                        document.addEventListener('keydown', onKeydown);
                        backdrop.addEventListener('click', function (e) {
                            if (e.target === backdrop) close(false);
                        });
                        cancelBtn.addEventListener('click', function () { close(false); });
                        proceedBtn.addEventListener('click', function () { close(true); });
                        proceedBtn.focus();
                    });
                }

                var form = document.getElementById('post');
                if (!form) return;

                var bypassCheck = false;

                form.addEventListener('submit', function (e) {
                    if (bypassCheck) {
                        bypassCheck = false;
                        return;
                    }
                    if (!others || !others.length) return; // fetch not ready / nothing to compare

                    var starts = parseDate(startsField.val());
                    var ends = parseDate(endsField.val());

                    var conflicts = others.filter(function (n) {
                        var acfData = n.acf || {};
                        return overlaps(starts, ends, parseDate(acfData.starts_at), parseDate(acfData.ends_at));
                    });

                    if (conflicts.length === 0) return;

                    e.preventDefault();
                    e.stopImmediatePropagation();

                    var names = conflicts
                        .map(function (n) { return (n.title && n.title.rendered) || ('#' + n.id); })
                        .join(', ');
                    var submitter = e.submitter;

                    showOverlapModal(names).then(function (proceed) {
                        if (!proceed) return;
                        bypassCheck = true;
                        if (submitter && typeof form.requestSubmit === 'function') {
                            form.requestSubmit(submitter);
                        } else {
                            form.submit();
                        }
                    });
                }, true);
            });
        }

        if (window.acf) {
            boot();
        } else {
            document.addEventListener('DOMContentLoaded', boot);
        }
    })();
    JS;
}
