<?php
/**
 * Plugin Name: Headless Preview Bridge
 * Description: Redirects WordPress previews to a headless frontend.
 * Version: 0.1.0
 * Author: George Neagu
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HPB_DEFAULT_FRONTEND_URL', 'https://frontend-site.com');
define('HPB_FRONTEND_URL_OPTION', 'hpb_frontend_url');
define('HPB_REST_NAMESPACE', 'headless-preview-bridge/v1');

add_filter('preview_post_link', 'hpb_preview_post_link', 10, 2);
add_action('rest_api_init', 'hpb_register_rest_routes');
add_action('admin_menu', 'hpb_add_settings_page');
add_action('admin_init', 'hpb_register_settings');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'hpb_add_settings_link');

function hpb_preview_post_link($preview_link, $post) {
    if (!$post instanceof WP_Post) {
        return $preview_link;
    }

    $token = hpb_create_preview_token($post);

    $url = add_query_arg([
        'id'       => $post->ID,
        'type'     => $post->post_type,
        'status'   => $post->post_status,
        'token'    => $token,
        'rest_url' => hpb_get_preview_rest_url($post->ID, $token),
    ], hpb_get_frontend_url() . '/api/preview');

    return $url;
}

function hpb_register_rest_routes() {
    register_rest_route(
        HPB_REST_NAMESPACE,
        '/preview',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'hpb_get_preview_post',
            'permission_callback' => '__return_true',
            'args'                => [
                'id'    => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'hpb_validate_post_id_arg',
                ],
                'token' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]
    );
}

function hpb_validate_post_id_arg($value) {
    return absint($value) > 0;
}

function hpb_get_preview_post(WP_REST_Request $request) {
    $post = get_post((int) $request->get_param('id'));

    if (!$post instanceof WP_Post) {
        return new WP_Error(
            'hpb_preview_not_found',
            __('Preview post not found.', 'headless-preview-bridge'),
            ['status' => 404]
        );
    }

    if (!hpb_verify_preview_token($post, (string) $request->get_param('token'))) {
        return new WP_Error(
            'hpb_preview_forbidden',
            __('Invalid preview token.', 'headless-preview-bridge'),
            ['status' => 403]
        );
    }

    return rest_ensure_response(hpb_prepare_preview_post($post));
}

function hpb_create_preview_token(WP_Post $post) {
    return hash_hmac('sha256', hpb_get_preview_token_message($post), wp_salt('auth'));
}

function hpb_verify_preview_token(WP_Post $post, $token) {
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals(hpb_create_preview_token($post), $token);
}

function hpb_get_preview_token_message(WP_Post $post) {
    return implode('|', [
        $post->ID,
        $post->post_type,
    ]);
}

function hpb_get_preview_rest_url($post_id, $token) {
    return add_query_arg(
        [
            'id'    => absint($post_id),
            'token' => $token,
        ],
        rest_url(HPB_REST_NAMESPACE . '/preview')
    );
}

function hpb_prepare_preview_post(WP_Post $post) {
    setup_postdata($post);

    $data = [
        'id'             => $post->ID,
        'date'           => mysql_to_rfc3339($post->post_date),
        'date_gmt'       => mysql_to_rfc3339($post->post_date_gmt),
        'modified'       => mysql_to_rfc3339($post->post_modified),
        'modified_gmt'   => mysql_to_rfc3339($post->post_modified_gmt),
        'slug'           => $post->post_name,
        'status'         => $post->post_status,
        'type'           => $post->post_type,
        'link'           => get_permalink($post),
        'title'          => [
            'raw'      => $post->post_title,
            'rendered' => get_the_title($post),
        ],
        'content'        => [
            'raw'       => $post->post_content,
            'rendered'  => apply_filters('the_content', $post->post_content),
            'protected' => post_password_required($post),
        ],
        'excerpt'        => [
            'raw'       => $post->post_excerpt,
            'rendered'  => apply_filters('the_excerpt', get_the_excerpt($post)),
            'protected' => post_password_required($post),
        ],
        'author'         => (int) $post->post_author,
        'featured_media' => (int) get_post_thumbnail_id($post),
    ];

    wp_reset_postdata();

    return $data;
}

function hpb_get_frontend_url() {
    $frontend_url = get_option(HPB_FRONTEND_URL_OPTION, HPB_DEFAULT_FRONTEND_URL);
    $frontend_url = is_string($frontend_url) ? trim($frontend_url) : '';

    if ($frontend_url === '') {
        return HPB_DEFAULT_FRONTEND_URL;
    }

    return untrailingslashit($frontend_url);
}

function hpb_add_settings_page() {
    add_options_page(
        __('Headless Preview Bridge', 'headless-preview-bridge'),
        __('Headless Preview Bridge', 'headless-preview-bridge'),
        'manage_options',
        'headless-preview-bridge',
        'hpb_render_settings_page'
    );
}

function hpb_register_settings() {
    register_setting(
        'hpb_settings',
        HPB_FRONTEND_URL_OPTION,
        [
            'type'              => 'string',
            'sanitize_callback' => 'hpb_sanitize_frontend_url',
            'default'           => HPB_DEFAULT_FRONTEND_URL,
        ]
    );

    add_settings_section(
        'hpb_preview_section',
        __('Preview Redirect', 'headless-preview-bridge'),
        'hpb_render_preview_section',
        'headless-preview-bridge'
    );

    add_settings_field(
        HPB_FRONTEND_URL_OPTION,
        __('Frontend URL', 'headless-preview-bridge'),
        'hpb_render_frontend_url_field',
        'headless-preview-bridge',
        'hpb_preview_section'
    );
}

function hpb_sanitize_frontend_url($value) {
    $value = is_string($value) ? trim($value) : '';

    if ($value === '') {
        add_settings_error(
            HPB_FRONTEND_URL_OPTION,
            'hpb_frontend_url_empty',
            __('Frontend URL cannot be empty. The default URL was restored.', 'headless-preview-bridge')
        );

        return HPB_DEFAULT_FRONTEND_URL;
    }

    $url    = esc_url_raw($value);
    $scheme = wp_parse_url($url, PHP_URL_SCHEME);
    $host   = wp_parse_url($url, PHP_URL_HOST);

    if ($url === '' || !$host || !in_array($scheme, ['http', 'https'], true)) {
        add_settings_error(
            HPB_FRONTEND_URL_OPTION,
            'hpb_frontend_url_invalid',
            __('Enter a valid frontend URL beginning with http:// or https://.', 'headless-preview-bridge')
        );

        return hpb_get_frontend_url();
    }

    return untrailingslashit($url);
}

function hpb_render_preview_section() {
    echo '<p>' . esc_html__('Choose where WordPress preview links should redirect.', 'headless-preview-bridge') . '</p>';
    echo '<p>' . esc_html__('Enter the base URL of the frontend app that handles /api/preview requests.', 'headless-preview-bridge') . '</p>';
}

function hpb_render_frontend_url_field() {
    printf(
        '<input type="url" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" required>',
        esc_attr(HPB_FRONTEND_URL_OPTION),
        esc_attr(hpb_get_frontend_url()),
        esc_attr(HPB_DEFAULT_FRONTEND_URL)
    );

    echo '<p class="description">' . esc_html__('Preview links are sent to /api/preview on this frontend URL.', 'headless-preview-bridge') . '</p>';
}

function hpb_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('hpb_settings');
            do_settings_sections('headless-preview-bridge');
            submit_button();
            ?>
        </form>
        <?php hpb_render_preview_instructions(); ?>
    </div>
    <?php
}

function hpb_render_preview_instructions() {
    ?>
    <hr>
    <h2><?php esc_html_e('Preview Flow', 'headless-preview-bridge'); ?></h2>
    <p><?php esc_html_e('The preview flow lets WordPress open unpublished posts in the frontend.', 'headless-preview-bridge'); ?></p>

    <h3><?php esc_html_e('How it works', 'headless-preview-bridge'); ?></h3>
    <ol>
        <li>
            <?php esc_html_e('WordPress creates a preview URL that points to the frontend:', 'headless-preview-bridge'); ?>
            <p><code><?php echo esc_html('/api/preview?id=POST_ID&token=PREVIEW_TOKEN&status=draft'); ?></code></p>
        </li>
        <li><?php esc_html_e('The app detects /api/preview and reads the id, token, and optional status query params.', 'headless-preview-bridge'); ?></li>
        <li>
            <?php esc_html_e('The frontend calls this local API route:', 'headless-preview-bridge'); ?>
            <p><code><?php echo esc_html('/api/wp-preview?id=POST_ID&token=PREVIEW_TOKEN'); ?></code></p>
        </li>
        <li>
            <?php esc_html_e('In development, Vite proxies that request to WordPress:', 'headless-preview-bridge'); ?>
            <p><code><?php echo esc_html('/wp-json/headless-preview-bridge/v1/preview'); ?></code></p>
        </li>
        <li><?php esc_html_e('The plugin validates the token and returns the preview post content.', 'headless-preview-bridge'); ?></li>
    </ol>

    <h3><?php esc_html_e('Required URL parameters', 'headless-preview-bridge'); ?></h3>
    <ul>
        <li><code>id</code>: <?php esc_html_e('the WordPress post ID.', 'headless-preview-bridge'); ?></li>
        <li><code>token</code>: <?php esc_html_e('the preview token generated by the plugin.', 'headless-preview-bridge'); ?></li>
        <li><code>status</code>: <?php esc_html_e('optional, used only for display in the frontend.', 'headless-preview-bridge'); ?></li>
    </ul>

    <p>
        <?php esc_html_e('Example:', 'headless-preview-bridge'); ?>
        <br>
        <code><?php echo esc_html('http://localhost:5174/api/preview?id=123&token=abc123&status=draft'); ?></code>
    </p>

    <h3><?php esc_html_e('Frontend expectation', 'headless-preview-bridge'); ?></h3>
    <p><?php esc_html_e('The frontend expects the preview endpoint to return a post object shaped like the WordPress REST API post response, including:', 'headless-preview-bridge'); ?></p>
    <pre><code><?php echo esc_html('{
  "id": 123,
  "date": "2026-05-16T10:00:00",
  "title": {
    "rendered": "Post title"
  },
  "content": {
    "rendered": "<p>Preview content</p>"
  }
}'); ?></code></pre>

    <h3><?php esc_html_e('Notes', 'headless-preview-bridge'); ?></h3>
    <p><?php esc_html_e('The token should be treated as temporary access to preview content. Do not expose permanent credentials or require the user to be logged into WordPress from the frontend.', 'headless-preview-bridge'); ?></p>
    <?php
}

function hpb_add_settings_link($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('options-general.php?page=headless-preview-bridge')),
        esc_html__('Settings', 'headless-preview-bridge')
    );

    array_unshift($links, $settings_link);

    return $links;
}
