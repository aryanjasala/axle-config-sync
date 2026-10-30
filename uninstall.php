<?php
/**
 * Removes Axle Config Sync's settings, state, change sets and schedule, on
 * every site. The config directory is left alone: it holds your settings
 * files and belongs to your git repository.
 *
 * @package AryanX\Axle\ConfigSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Axle Config Sync data from the current site.
 */
function axle_config_sync_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'axle_config_sync_purge' );
	delete_option( 'axle_config_sync_settings' );
	delete_option( 'axle_config_sync_state' );
	delete_option( 'axle_config_sync_db_version' );
	delete_transient( 'axle_config_sync_reachable' );

	// Per-user notices that may still be waiting.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_axle_config_sync_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_config_sync_' ) . '%' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'axle_config_sync_changesets' ) );
}

if ( is_multisite() ) {
	$axle_config_sync_offset = 0;
	do {
		$axle_config_sync_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_config_sync_offset,
			)
		);

		foreach ( $axle_config_sync_sites as $axle_config_sync_site ) {
			switch_to_blog( $axle_config_sync_site );
			axle_config_sync_uninstall_site();
			restore_current_blog();
		}

		$axle_config_sync_offset += 100;
	} while ( $axle_config_sync_sites );
} else {
	axle_config_sync_uninstall_site();
}
