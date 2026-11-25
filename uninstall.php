<?php
/**
 * Uninstall WP REST API Cache.
 *
 * Deletes the plugin option and every cached transient.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wrac_settings' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- targeted cleanup, no API for prefix delete.
$keys = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_wrac_' ) . '%'
    )
);
foreach ( $keys as $option_name ) {
    delete_transient( substr( $option_name, strlen( '_transient_' ) ) );
}
