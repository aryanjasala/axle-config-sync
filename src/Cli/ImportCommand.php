<?php
/**
 * `wp axle-config-sync import` commands.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Cli;

use AryanX\Axle\ConfigSync\Import\WpCfm;

defined( 'ABSPATH' ) || exit;

/**
 * Brings settings from another tool into Config Sync files.
 */
final class ImportCommand {

	/**
	 * The importer.
	 *
	 * @var WpCfm
	 */
	private WpCfm $wpcfm;

	/**
	 * Sets up the command.
	 *
	 * @param WpCfm $wpcfm The WP-CFM importer.
	 */
	public function __construct( WpCfm $wpcfm ) {
		$this->wpcfm = $wpcfm;
	}

	/**
	 * Imports WP-CFM bundles as Config Sync files.
	 *
	 * Reads the bundle files in wp-content/config and the bundle list WP-CFM
	 * keeps in the database, with or without WP-CFM active. Writes Config Sync
	 * files only: the site is not changed and the WP-CFM files stay as they are.
	 * Values that hold a PHP object are skipped with a reason.
	 *
	 * ## OPTIONS
	 *
	 * [--bundle=<bundle>]
	 * : One bundle name, or several separated by commas. Default: every bundle.
	 *
	 * [--dry-run]
	 * : Show how each item maps and stop.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync import wp-cfm --dry-run
	 *     wp axle-config-sync import wp-cfm --bundle=main
	 *
	 * @subcommand wp-cfm
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function wp_cfm( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI passes both.
		$bundle  = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'bundle', '' );
		$names   = '' !== $bundle ? array_values( array_filter( array_map( 'trim', explode( ',', $bundle ) ) ) ) : null;
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( ! $this->wpcfm->detected() ) {
			\WP_CLI::error( sprintf( 'No WP-CFM bundles found in %s or in the wpcfm_settings option.', $this->wpcfm->directory() ) );
			return;
		}

		$result = $this->wpcfm->import( $names, $dry_run );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		$plan = $result['plan'];
		if ( ! $plan['bundles'] ) {
			\WP_CLI::error( 'No bundle with that name.' );
			return;
		}

		$rows = array();
		foreach ( $plan['items'] as $row ) {
			$rows[] = array(
				'bundle' => $row['bundle'],
				'wpcfm'  => $row['key'],
				'item'   => $row['id'],
				'result' => $row['action'],
				'reason' => $row['reason'],
			);
		}
		\WP_CLI\Utils\format_items( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array( 'bundle', 'wpcfm', 'item', 'result', 'reason' ) );

		foreach ( $plan['errors'] as $bundle_name => $error ) {
			\WP_CLI::warning( $bundle_name . ': ' . $error );
		}

		if ( $dry_run ) {
			$write = count(
				array_filter(
					$plan['items'],
					static function ( array $row ): bool {
						return in_array( $row['action'], array( 'new', 'replace' ), true );
					}
				)
			);
			\WP_CLI::success( sprintf( 'Dry run: %d file(s) would be written. Nothing was changed.', $write ) );
			return;
		}

		foreach ( $result['refused'] as $id => $reason ) {
			\WP_CLI::warning( $id . ': ' . $reason );
		}
		if ( $result['tracked'] ) {
			\WP_CLI::log( 'Now tracking: ' . implode( ', ', $result['tracked'] ) );
		}
		\WP_CLI::success( sprintf( 'Wrote %d Config Sync file(s) to %s. The site and the WP-CFM files were not changed. Review with: wp axle-config-sync status', count( $result['written'] ), $result['directory'] ) );
	}
}
