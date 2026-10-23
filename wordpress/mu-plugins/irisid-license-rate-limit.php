<?php
/**
 * Plugin Name: IrisID - License lookup rate limit
 * Description: Limits license key / reference number lookups per visitor IP (20 per 10 minutes).
 */
if (!defined('ABSPATH')) exit;

function irisid_rl_server_ips() { return array('127.0.0.1', '::1', '173.231.221.180'); }

function irisid_rl_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    if (in_array($ip, irisid_rl_server_ips(), true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
    }
    return $ip;
}

/** Counts one lookup; returns true when this visitor is over the limit. */
function irisid_rl_over_limit($bucket) {
    $ip = irisid_rl_client_ip();
    $limit = in_array($ip, irisid_rl_server_ips(), true) ? 300 : 20;
    $key = 'irisid_rl_' . md5($bucket . '|' . $ip);
    $count = (int) get_transient($key);
    if ($count >= $limit) return true;
    set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
    return false;
}

/* Live site: admin-ajax lookups */
function irisid_rl_ajax_guard() {
    if (current_user_can('manage_options')) return;
    if (irisid_rl_over_limit('ajax')) {
        wp_send_json_error(array('message' => 'Too many lookups. Please wait 10 minutes and try again.'), 429);
    }
}
foreach (array('check_cd_key', 'check_ref_number') as $irisid_rl_action) {
    add_action('wp_ajax_nopriv_' . $irisid_rl_action, 'irisid_rl_ajax_guard', 0);
    add_action('wp_ajax_' . $irisid_rl_action, 'irisid_rl_ajax_guard', 0);
}

/* CMS: REST licensing routes */
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if ($result !== null) return $result;
    if (strpos($request->get_route(), '/irisid/v1/licensing/') !== 0) return $result;
    if (current_user_can('manage_options')) return $result;
    if (irisid_rl_over_limit('rest')) {
        return new WP_Error('too_many_requests', 'Too many lookups. Please wait 10 minutes and try again.', array('status' => 429));
    }
    return $result;
}, 10, 3);
