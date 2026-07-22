<?php

declare(strict_types=1);

/**
 * Minimal GraphQL multipart Upload support for Gravity Forms file fields.
 * Spec: https://github.com/jaydenseric/graphql-multipart-request-spec
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('graphql_is_valid_http_content_type', 'irisid_allow_graphql_multipart', 10, 2);

/**
 * @param bool   $is_valid
 * @param string $content_type
 */
function irisid_allow_graphql_multipart($is_valid, $content_type)
{
    if (is_string($content_type) && 0 === stripos($content_type, 'multipart/form-data')) {
        return true;
    }

    return $is_valid;
}

add_action('graphql_register_types', 'irisid_register_upload_scalar');

function irisid_register_upload_scalar(): void
{
    register_graphql_scalar('Upload', [
        'description' => 'A file upload conforming to the GraphQL multipart request spec.',
        'serialize' => static function ($value) {
            throw new \GraphQL\Error\InvariantViolation('`Upload` cannot be serialized');
        },
        'parseValue' => static function ($value) {
            if (!is_array($value)) {
                throw new \GraphQL\Error\UserError('Could not get uploaded file.');
            }

            foreach (['name', 'type', 'size', 'tmp_name'] as $key) {
                if (!array_key_exists($key, $value)) {
                    throw new \GraphQL\Error\UserError(
                        'Could not get uploaded file, be sure to conform to GraphQL multipart request specification.'
                    );
                }
            }

            if (empty($value['tmp_name'])) {
                $tmp_dir = get_temp_dir();
                $value['tmp_name'] = $tmp_dir . wp_unique_filename($tmp_dir, (string) $value['name']);
            }

            return $value;
        },
        'parseLiteral' => static function ($valueNode) {
            throw new \GraphQL\Error\Error('`Upload` cannot be hardcoded in query.');
        },
    ]);
}

add_filter('graphql_request_data', 'irisid_parse_graphql_multipart', 10, 2);

/**
 * @param array<string, mixed> $body_params
 * @param mixed                $request_context
 * @return array<string, mixed>
 */
function irisid_parse_graphql_multipart(array $body_params, $request_context = null): array
{
    $content_type = isset($_SERVER['CONTENT_TYPE'])
        ? (string) $_SERVER['CONTENT_TYPE']
        : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? (string) $_SERVER['HTTP_CONTENT_TYPE'] : '');

    if (0 !== stripos($content_type, 'multipart/form-data')) {
        return $body_params;
    }

    if (empty($_POST['operations']) || empty($_POST['map'])) {
        return $body_params;
    }

    $operations = json_decode(wp_unslash((string) $_POST['operations']), true);
    $map = json_decode(wp_unslash((string) $_POST['map']), true);

    if (!is_array($operations) || !is_array($map)) {
        return $body_params;
    }

    foreach ($map as $file_key => $locations) {
        if (!is_array($locations) || !isset($_FILES[$file_key])) {
            continue;
        }

        $file = $_FILES[$file_key];

        foreach ($locations as $location) {
            if (!is_string($location) || $location === '') {
                continue;
            }

            $segments = explode('.', $location);
            $last = array_pop($segments);
            if ($last === null || $last === '') {
                continue;
            }

            $target = &$operations;
            foreach ($segments as $segment) {
                if (!is_array($target)) {
                    continue 2;
                }
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = [];
                }
                $target = &$target[$segment];
            }

            if (!is_array($target)) {
                unset($target);
                continue;
            }

            $target[$last] = $file;
            unset($target);
        }
    }

    return $operations;
}
