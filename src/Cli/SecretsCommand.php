<?php
/**
 * `wp axle-config-sync secrets` commands.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Cli;

use AryanX\Axle\ConfigSync\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Finds secrets committed to the config files by hand.
 */
final class SecretsCommand {

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Sets up the command.
	 *
	 * @param Engine $engine The engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Scans the config files for values that look like keys or passwords.
	 * Exits with 1 when it finds one, for CI.
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<dir>]
	 * : Scan this folder instead of the config directory, such as a git checkout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync secrets check --dir=./config
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function check( $args, $assoc_args ): void {
		$found = $this->engine->scan_secrets( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dir', '' ) );
		if ( ! $found ) {
			\WP_CLI::success( 'No secrets in the config files.' );
			return;
		}

		foreach ( $found as $hit ) {
			\WP_CLI::line( $hit['file'] . ' ' . $hit['pointer'] );
		}
		\WP_CLI::error( sprintf( '%d value(s) look like secrets. Replace each with {"$secret":"NAME"} and set AXLE_SECRET_NAME on the site.', count( $found ) ), false );
		\WP_CLI::halt( 1 );
	}

	/**
	 * Marks a value as not a secret, so it is written to files as it is.
	 *
	 * The same as "Not secrets" on the Track tab. The mark is saved to config-sync.json.
	 *
	 * ## OPTIONS
	 *
	 * <mark>
	 * : An item ID, or item#/json/pointer for one value inside it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync secrets mark options/acme_settings#/public_token
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function mark( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI passes both.
		$mark = trim( (string) $args[0] );
		if ( '' === $mark || 0 === strpos( $mark, '#' ) ) {
			\WP_CLI::error( 'Give an item ID, or item#/json/pointer.' );
			return;
		}

		if ( ! $this->engine->rules()->mark_not_secret( $mark ) ) {
			\WP_CLI::warning( 'Saved on this site only: the config directory cannot be written.' );
		}
		\WP_CLI::success( sprintf( '%s is marked as not a secret.', $mark ) );
	}
}
