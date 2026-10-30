<?php
/**
 * The paged option list on the Track tab.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Admin;

use AryanX\Axle\ConfigSync\Providers\Options;
use AryanX\Axle\ConfigSync\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Lists options with their size, autoload and guessed owner, a page at a time.
 * Noise (transients, caches, counters, timestamps) and names that are never
 * synced are hidden by default. Pages are cached for five minutes. A search reads up to 5,000 options.
 */
final class OptionList {

	/**
	 * Options read per search, before noise is filtered out.
	 */
	private const LIMIT = 5000;

	/**
	 * One page of options.
	 *
	 * @param string $search      Name contains.
	 * @param bool   $show_hidden Include noise.
	 * @param int    $page        Page from 1.
	 * @param int    $per_page    Rows per page.
	 * @return array{rows: array<int, array{name: string, size: int, autoload: string, owner: string}>, total: int}
	 */
	public static function page( string $search, bool $show_hidden, int $page, int $per_page ): array {
		global $wpdb;

		$key    = 'options_' . md5( wp_json_encode( array( $search, $show_hidden, $page, $per_page, wp_cache_get_last_changed( 'options' ) ) ) );
		$cached = wp_cache_get( $key, 'axle_config_sync' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$hide = array_merge( Options::never_sync(), array( 'theme_mods_*', 'widget_*', 'sidebars_widgets', '*user_roles', 'nav_menu_options' ) );
		if ( ! $show_hidden ) {
			$hide = array_merge( $hide, Options::ignored() );
		}

		// Transients are most of the noise, so they are left out in SQL; the rest is filtered here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Listing options has no API. Cached above and below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size, autoload FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT %d",
				'%' . $wpdb->esc_like( $search ) . '%',
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%',
				self::LIMIT
			),
			ARRAY_A
		);

		$all = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = (string) $row['option_name'];
			if ( Rules::matches_any( $name, $hide ) ) {
				continue;
			}
			$all[] = array(
				'name'     => $name,
				'size'     => (int) $row['size'],
				'autoload' => (string) $row['autoload'],
				'owner'    => '',
			);
		}

		$out = array_slice( $all, ( max( 1, $page ) - 1 ) * $per_page, $per_page );
		foreach ( $out as $index => $option ) {
			$out[ $index ]['owner'] = in_array( $option['name'], Options::core_names(), true ) ? 'core' : Options::guess_owner( $option['name'] );
		}
		$total = count( $all );

		$result = array(
			'rows'  => $out,
			'total' => $total,
		);
		wp_cache_set( $key, $result, 'axle_config_sync', 5 * MINUTE_IN_SECONDS );

		return $result;
	}
}
