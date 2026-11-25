<?php
/**
 * Plugin Name:       WP REST API Cache
 * Plugin URI:        https://github.com/Azanumer/wp-rest-api-cache
 * Description:       Caches GET responses from the WordPress REST API in transients, so repeated API calls skip PHP and the database. Auto-invalidates on content changes.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://github.com/Azanumer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-rest-api-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WRAC_VERSION', '1.0.0' );
define( 'WRAC_OPTION', 'wrac_settings' );
define( 'WRAC_PREFIX', 'wrac_' );

/**
 * Default settings.
 *
 * @return array
 */
function wrac_default_settings() {
    return array(
        'enabled'        => true,
        'ttl'            => 300, // 5 minutes.
        'exclude_routes' => "/wp/v2/users\n/wp/v2/settings\n/wp/v2/search",
        'cache_logged_in' => false,
    );
}

/**
 * Get a setting value.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function wrac_get_setting( $key ) {
    $settings = get_option( WRAC_OPTION, array() );
    $defaults = wrac_default_settings();
    if ( isset( $settings[ $key ] ) ) {
        return $settings[ $key ];
    }
    return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
}

/**
 * Build the transient key for a request.
 *
 * @param WP_REST_Request $request Request object.
 * @return string
 */
function wrac_cache_key( $request ) {
    $route  = $request->get_route();
    $params = $request->get_query_params();
    ksort( $params );
    $raw = $route . '?' . http_build_query( $params );
    return WRAC_PREFIX . md5( $raw );
}

/**
 * Is this request cacheable?
 *
 * @param WP_REST_Request $request Request object.
 * @return bool
 */
function wrac_is_cacheable( $request ) {
    if ( ! wrac_get_setting( 'enabled' ) ) {
        return false;
    }
    if ( 'GET' !== $request->get_method() ) {
        return false;
    }
    if ( is_user_logged_in() && ! wrac_get_setting( 'cache_logged_in' ) ) {
        return false;
    }
    $route = $request->get_route();
    $excluded = array_filter( array_map( 'trim', explode( "\n", (string) wrac_get_setting( 'exclude_routes' ) ) ) );
    foreach ( $excluded as $pattern ) {
        // Prefix match: "/wp/v2/users" excludes "/wp/v2/users" and "/wp/v2/users/123".
        if ( $route === $pattern || 0 === strpos( $route, rtrim( $pattern, '/' ) . '/' ) ) {
            return false;
        }
    }
    return (bool) apply_filters( 'wrac_is_cacheable', true, $request );
}

/**
 * Serve a cached response, if one exists.
 *
 * @param mixed           $result  Short-circuit result (null by default).
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request object.
 * @return mixed
 */
function wrac_serve_cached( $result, $server, $request ) {
    if ( null !== $result || ! wrac_is_cacheable( $request ) ) {
        return $result;
    }
    $cached = get_transient( wrac_cache_key( $request ) );
    if ( false === $cached ) {
        return $result;
    }
    // Store the served copy so the post_dispatch hook can add the header.
    $GLOBALS['wrac_served_from_cache'] = true;
    return new WP_REST_Response( $cached['data'], $cached['status'], $cached['headers'] );
}
add_filter( 'rest_pre_dispatch', 'wrac_serve_cached', 10, 3 );

/**
 * Store a fresh response in the cache and tag cached responses.
 *
 * @param WP_REST_Response $result  Response object.
 * @param WP_REST_Server   $server  Server instance.
 * @param WP_REST_Request  $request Request object.
 * @return WP_REST_Response
 */
function wrac_store_response( $result, $server, $request ) {
    if ( ! empty( $GLOBALS['wrac_served_from_cache'] ) ) {
        $result->header( 'X-WRAC-Cache', 'HIT' );
        unset( $GLOBALS['wrac_served_from_cache'] );
        return $result;
    }
    if ( ! wrac_is_cacheable( $request ) ) {
        return $result;
    }
    $status = $result->get_status();
    if ( $status < 200 || $status >= 300 ) {
        return $result;
    }
    $data = $result->get_data();
    // Only cache JSON-serializable payloads.
    $payload = array(
        'data'    => $data,
        'status'  => $status,
        'headers' => $result->get_headers(),
    );
    $ttl = (int) wrac_get_setting( 'ttl' );
    if ( $ttl > 0 ) {
        set_transient( wrac_cache_key( $request ), $payload, $ttl );
        $result->header( 'X-WRAC-Cache', 'MISS' );
    }
    return $result;
}
add_filter( 'rest_post_dispatch', 'wrac_store_response', 10, 3 );

/**
 * Flush the whole REST cache.
 */
function wrac_flush_cache() {
    global $wpdb;
    // Transients are stored as options named _transient_<key> (+ timeout row).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- targeted cleanup, no API for prefix delete.
    $keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( '_transient_' . WRAC_PREFIX ) . '%'
        )
    );
    foreach ( $keys as $option_name ) {
        $key = substr( $option_name, strlen( '_transient_' ) );
        delete_transient( $key );
    }
    do_action( 'wrac_cache_flushed' );
}

/**
 * Flush the cache when content changes.
 */
function wrac_invalidate_on_save() {
    wrac_flush_cache();
}
add_action( 'save_post', 'wrac_invalidate_on_save' );
add_action( 'deleted_post', 'wrac_invalidate_on_save' );
add_action( 'created_term', 'wrac_invalidate_on_save' );
add_action( 'edited_term', 'wrac_invalidate_on_save' );
add_action( 'delete_term', 'wrac_invalidate_on_save' );
add_action( 'switch_theme', 'wrac_invalidate_on_save' );

/**
 * Register the settings page.
 */
function wrac_admin_menu() {
    add_options_page(
        __( 'REST API Cache', 'wp-rest-api-cache' ),
        __( 'REST API Cache', 'wp-rest-api-cache' ),
        'manage_options',
        'wp-rest-api-cache',
        'wrac_settings_page'
    );
}
add_action( 'admin_menu', 'wrac_admin_menu' );

/**
 * Register settings.
 */
function wrac_admin_init() {
    register_setting(
        'wrac_settings_group',
        WRAC_OPTION,
        array( 'sanitize_callback' => 'wrac_sanitize_settings' )
    );
    add_settings_section( 'wrac_main', __( 'Cache Settings', 'wp-rest-api-cache' ), '__return_false', 'wp-rest-api-cache' );
    add_settings_field( 'wrac_enabled', __( 'Enable caching', 'wp-rest-api-cache' ), 'wrac_field_enabled', 'wp-rest-api-cache', 'wrac_main' );
    add_settings_field( 'wrac_ttl', __( 'Cache lifetime (seconds)', 'wp-rest-api-cache' ), 'wrac_field_ttl', 'wp-rest-api-cache', 'wrac_main' );
    add_settings_field( 'wrac_cache_logged_in', __( 'Cache for logged-in users', 'wp-rest-api-cache' ), 'wrac_field_logged_in', 'wp-rest-api-cache', 'wrac_main' );
    add_settings_field( 'wrac_exclude_routes', __( 'Excluded routes (one per line)', 'wp-rest-api-cache' ), 'wrac_field_exclude', 'wp-rest-api-cache', 'wrac_main' );
}
add_action( 'admin_init', 'wrac_admin_init' );

/**
 * Sanitize settings input.
 *
 * @param array $input Raw input.
 * @return array
 */
function wrac_sanitize_settings( $input ) {
    $out = wrac_default_settings();
    $out['enabled']         = ! empty( $input['enabled'] );
    $out['cache_logged_in'] = ! empty( $input['cache_logged_in'] );
    $out['ttl']             = max( 60, (int) ( isset( $input['ttl'] ) ? $input['ttl'] : 300 ) );
    $lines                  = array();
    if ( isset( $input['exclude_routes'] ) ) {
        foreach ( explode( "\n", (string) $input['exclude_routes'] ) as $line ) {
            $line = trim( $line );
            if ( '' !== $line && 0 === strpos( $line, '/' ) ) {
                $lines[] = sanitize_text_field( $line );
            }
        }
    }
    $out['exclude_routes'] = implode( "\n", $lines );
    return $out;
}

function wrac_field_enabled() {
    printf(
        '<input type="checkbox" name="%s[enabled]" value="1" %s />',
        esc_attr( WRAC_OPTION ),
        checked( wrac_get_setting( 'enabled' ), true, false )
    );
}

function wrac_field_ttl() {
    printf(
        '<input type="number" min="60" step="60" name="%s[ttl]" value="%d" class="small-text" />',
        esc_attr( WRAC_OPTION ),
        (int) wrac_get_setting( 'ttl' )
    );
    echo '<p class="description">' . esc_html__( 'Minimum 60 seconds. Longer TTL = fewer database hits, staler data.', 'wp-rest-api-cache' ) . '</p>';
}

function wrac_field_logged_in() {
    printf(
        '<input type="checkbox" name="%s[cache_logged_in]" value="1" %s />',
        esc_attr( WRAC_OPTION ),
        checked( wrac_get_setting( 'cache_logged_in' ), true, false )
    );
    echo '<p class="description">' . esc_html__( 'Off by default: logged-in users usually see personalized data that must not be shared.', 'wp-rest-api-cache' ) . '</p>';
}

function wrac_field_exclude() {
    printf(
        '<textarea name="%s[exclude_routes]" rows="5" cols="50" class="large-text code">%s</textarea>',
        esc_attr( WRAC_OPTION ),
        esc_textarea( (string) wrac_get_setting( 'exclude_routes' ) )
    );
    echo '<p class="description">' . esc_html__( 'Routes are matched by prefix, so /wp/v2/users also excludes /wp/v2/users/123.', 'wp-rest-api-cache' ) . '</p>';
}

/**
 * Render the settings page, including the manual flush button.
 */
function wrac_settings_page() {
    if ( isset( $_POST['wrac_flush'] ) && check_admin_referer( 'wrac_flush_cache' ) && current_user_can( 'manage_options' ) ) {
        wrac_flush_cache();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'REST API cache flushed.', 'wp-rest-api-cache' ) . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'REST API Cache', 'wp-rest-api-cache' ); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'wrac_settings_group' );
            do_settings_sections( 'wp-rest-api-cache' );
            submit_button();
            ?>
        </form>
        <hr />
        <h2><?php esc_html_e( 'Flush Cache', 'wp-rest-api-cache' ); ?></h2>
        <p><?php esc_html_e( 'Delete every cached REST response immediately.', 'wp-rest-api-cache' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'wrac_flush_cache' ); ?>
            <input type="submit" name="wrac_flush" class="button button-secondary" value="<?php esc_attr_e( 'Flush Cache Now', 'wp-rest-api-cache' ); ?>" />
        </form>
        <p class="description"><?php esc_html_e( 'Cached responses carry an X-WRAC-Cache: HIT header; fresh ones carry MISS.', 'wp-rest-api-cache' ); ?></p>
    </div>
    <?php
}
