<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Replace Gravatar-only profile pictures with a Media Library upload.
 *
 * User meta key: irisid_local_avatar (attachment ID).
 */

const IRISID_LOCAL_AVATAR_META = 'irisid_local_avatar';

/**
 * @param array<string, mixed> $args
 * @param mixed                $idOrEmail
 * @return array<string, mixed>
 */
function irisid_pre_get_avatar_data(array $args, $idOrEmail): array
{
    $userId = irisid_avatar_resolve_user_id($idOrEmail);
    if ($userId <= 0) {
        return $args;
    }

    $attachmentId = (int) get_user_meta($userId, IRISID_LOCAL_AVATAR_META, true);
    if ($attachmentId <= 0) {
        return $args;
    }

    $size = (int) ($args['size'] ?? 96);
    $url = wp_get_attachment_image_url($attachmentId, [$size, $size]);
    if (!$url) {
        $url = wp_get_attachment_image_url($attachmentId, 'thumbnail');
    }
    if (!$url) {
        return $args;
    }

    $args['url'] = $url;
    $args['found_avatar'] = true;
    return $args;
}
add_filter('pre_get_avatar_data', 'irisid_pre_get_avatar_data', 10, 2);

/**
 * Replace the Gravatar help link with local upload controls under Profile Picture.
 *
 * @param string  $description
 * @param WP_User $profileUser
 */
function irisid_profile_picture_description(string $description, $profileUser): string
{
    unset($description);

    if (!($profileUser instanceof WP_User)) {
        return '';
    }

    $attachmentId = (int) get_user_meta($profileUser->ID, IRISID_LOCAL_AVATAR_META, true);

    ob_start();
    wp_nonce_field('irisid_local_avatar_save', 'irisid_local_avatar_nonce');
    ?>
    <input type="hidden" name="irisid_local_avatar" id="irisid_local_avatar" value="<?php echo esc_attr((string) $attachmentId); ?>" />
    <p style="margin:8px 0;">
        <button type="button" class="button" id="irisid-local-avatar-upload">
            <?php esc_html_e('Upload / choose image', 'irisid'); ?>
        </button>
        <button
            type="button"
            class="button"
            id="irisid-local-avatar-remove"
            <?php disabled($attachmentId <= 0); ?>
        >
            <?php esc_html_e('Remove', 'irisid'); ?>
        </button>
    </p>
    <p class="description" style="margin:0;">
        <?php esc_html_e('Choose an image from the Media Library. Click Update Profile to save. Gravatar is not used when a local image is set.', 'irisid'); ?>
    </p>
    <?php
    return (string) ob_get_clean();
}
add_filter('user_profile_picture_description', 'irisid_profile_picture_description', 10, 2);

/** @param int $userId */
function irisid_save_local_avatar(int $userId): void
{
    if (!current_user_can('edit_user', $userId)) {
        return;
    }
    if (
        !isset($_POST['irisid_local_avatar_nonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['irisid_local_avatar_nonce'])), 'irisid_local_avatar_save')
    ) {
        return;
    }

    $raw = isset($_POST['irisid_local_avatar']) ? (string) wp_unslash($_POST['irisid_local_avatar']) : '';
    $attachmentId = (int) $raw;

    if ($attachmentId <= 0) {
        delete_user_meta($userId, IRISID_LOCAL_AVATAR_META);
        return;
    }

    if (get_post_type($attachmentId) !== 'attachment') {
        return;
    }
    if (!wp_attachment_is_image($attachmentId)) {
        return;
    }

    update_user_meta($userId, IRISID_LOCAL_AVATAR_META, $attachmentId);
}
add_action('personal_options_update', 'irisid_save_local_avatar');
add_action('edit_user_profile_update', 'irisid_save_local_avatar');

function irisid_enqueue_local_avatar_admin(string $hook): void
{
    if ($hook !== 'profile.php' && $hook !== 'user-edit.php') {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script(
        'irisid-local-avatar',
        content_url('mu-plugins/irisid-headless/js/local-avatar.js'),
        ['jquery'],
        IRISID_HEADLESS_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'irisid_enqueue_local_avatar_admin');

/**
 * @param mixed $idOrEmail
 */
function irisid_avatar_resolve_user_id($idOrEmail): int
{
    if (is_numeric($idOrEmail)) {
        return (int) $idOrEmail;
    }
    if ($idOrEmail instanceof WP_User) {
        return (int) $idOrEmail->ID;
    }
    if ($idOrEmail instanceof WP_Post) {
        return (int) $idOrEmail->post_author;
    }
    if (is_object($idOrEmail) && isset($idOrEmail->user_id)) {
        return (int) $idOrEmail->user_id;
    }
    if (is_string($idOrEmail) && is_email($idOrEmail)) {
        $user = get_user_by('email', $idOrEmail);
        return $user instanceof WP_User ? (int) $user->ID : 0;
    }
    return 0;
}
