<?php
/**
 * Settings: the small option, and the retention limits.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * `axle_config_sync_settings` holds a copy of the tracking rules (for when
 * the config directory cannot be written) and nothing else that changes
 * often. Retention and batch sizes come from filters.
 */
final class Settings {

	public const OPTION = 'axle_config_sync_settings';

	/**
	 * The capability that may use the screen, the REST routes and the abilities.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability that may see, export, apply and undo config.
		 *
		 * @param string $capability Default manage_options.
		 */
		$cap = (string) apply_filters( 'axle_config_sync_capability', 'manage_options' );

		return '' !== trim( $cap ) ? $cap : 'manage_options';
	}

	/**
	 * Days a change set is kept.
	 *
	 * @return int 1 to 3650.
	 */
	public static function retention_days(): int {
		/**
		 * Filters how many days change sets are kept for undo.
		 *
		 * @param int $days Default 30.
		 */
		return max( 1, min( 3650, (int) apply_filters( 'axle_config_sync_retention_days', 30 ) ) );
	}

	/**
	 * How many change sets are kept at most.
	 *
	 * @return int 1 to 10000.
	 */
	public static function retention_count(): int {
		/**
		 * Filters how many change sets are kept at most.
		 *
		 * @param int $count Default 50.
		 */
		return max( 1, min( 10000, (int) apply_filters( 'axle_config_sync_retention_count', 50 ) ) );
	}

	/**
	 * Items applied in one request; more go through chunked REST calls.
	 *
	 * @return int 1 to 1000.
	 */
	public static function batch_size(): int {
		/**
		 * Filters how many items one request applies. The screen sends more in chunks.
		 *
		 * @param int $size Default 200.
		 */
		return max( 1, min( 1000, (int) apply_filters( 'axle_config_sync_apply_batch_size', 200 ) ) );
	}
}
