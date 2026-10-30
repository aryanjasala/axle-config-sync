<?php
/**
 * The change-set table and the daily clean-up.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the change-set table with dbDelta on activation and whenever the
 * stored version differs, so sites added to a network later get it on their
 * first request. Schedules the daily purge of old change sets.
 */
final class Schema {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'axle_config_sync_db_version';
	public const CRON_HOOK         = 'axle_config_sync_purge';

	/**
	 * The change-set table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'axle_config_sync_changesets';
	}

	/**
	 * Activation.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		self::each_site( (bool) $network_wide, array( self::class, 'install' ) );
	}

	/**
	 * Deactivation: stops the schedule. Data stays until uninstall.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ): void {
		self::each_site(
			(bool) $network_wide,
			static function (): void {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
		);
	}

	/**
	 * Installs when the stored version differs, and keeps the schedule.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Creates the table and the schedule.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				user_id bigint(20) unsigned NOT NULL default 0,
				via varchar(20) NOT NULL default '',
				environment varchar(64) NOT NULL default '',
				items_count int(10) unsigned NOT NULL default 0,
				status varchar(20) NOT NULL default 'applied',
				undo_of bigint(20) unsigned NOT NULL default 0,
				payload longtext NULL,
				PRIMARY KEY  (id),
				KEY created_gmt (created_gmt),
				KEY user_id (user_id)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Runs a callback on the current site, or every site for network-wide.
	 *
	 * @param bool     $network_wide Every site.
	 * @param callable $callback     Callback.
	 */
	private static function each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$callback();
			return;
		}

		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);
			foreach ( $sites as $site ) {
				switch_to_blog( (int) $site );
				$callback();
				restore_current_blog();
			}
			$offset += 100;
		} while ( $sites );
	}
}
