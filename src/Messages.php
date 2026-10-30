<?php
/**
 * User-facing messages, in one filterable map.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Every message the plugin shows about an item or a refusal comes from here,
 * so a site can reword them with one filter.
 */
final class Messages {

	/**
	 * The map, built once per request after translations load.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $map = null;

	/**
	 * A message, with sprintf() arguments filled in.
	 *
	 * @param string $key     Message key.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public static function get( string $key, ...$args ): string {
		$map     = self::all();
		$message = $map[ $key ] ?? $key;

		return $args ? vsprintf( $message, array_map( 'strval', $args ) ) : $message;
	}

	/**
	 * All messages.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$defaults = array(
			/* translators: 1: post type label, 2: path. */
			'missing_post'       => __( '%1$s "%2$s" does not exist on this site.', 'axle-config-sync' ),
			/* translators: 1: taxonomy, 2: term slug. */
			'missing_term'       => __( 'Term "%2$s" in %1$s does not exist on this site.', 'axle-config-sync' ),
			/* translators: %s: file path in uploads. */
			'missing_attachment' => __( 'Media file "%s" does not exist on this site.', 'axle-config-sync' ),
			/* translators: %s: menu slug. */
			'missing_menu'       => __( 'Menu "%1$s" does not exist on this site. Apply menus/%1$s first.', 'axle-config-sync' ),
			/* translators: %s: reference type. */
			'unknown_ref'        => __( 'Unknown reference type "%s".', 'axle-config-sync' ),
			/* translators: 1: secret name, 2: constant name. */
			'secret_kept'        => __( 'Secret %1$s is not set on this site, so the live value is kept. Define %2$s in wp-config.php or the environment to set it.', 'axle-config-sync' ),
			/* translators: 1: secret name, 2: constant name. */
			'secret_missing'     => __( 'Secret %1$s is not set on this site and there is no live value to keep. Define %2$s in wp-config.php or the environment.', 'axle-config-sync' ),
			/* translators: %s: theme folder name. */
			'inactive_theme'     => __( 'Theme "%s" is not the active theme. Pick "Apply to an inactive theme" to apply it anyway.', 'axle-config-sync' ),
			/* translators: 1: plugin, 2: version in the files, 3: version here. */
			'plugin_version'     => __( 'Exported with %1$s %2$s, this site runs %3$s. Its settings may have changed shape.', 'axle-config-sync' ),
			/* translators: %s: plugin. */
			'plugin_missing'     => __( 'Exported with %s active, which is not active on this site.', 'axle-config-sync' ),
			'email_value'        => __( 'Holds an email address. Consider an environment file (env/<environment>.json) for it, so each environment keeps its own.', 'axle-config-sync' ),
			'frozen'             => __( 'Axle Read-Only Mode has frozen this site, so nothing can be applied or undone until it is unfrozen.', 'axle-config-sync' ),
			/* translators: %s: item IDs. */
			'conflict'           => __( 'These items changed both in the files and on this site since the last sync: %s. Apply them anyway with force.', 'axle-config-sync' ),
			/* translators: %s: item IDs. */
			'dangerous'          => __( 'These items are dangerous changes: %s. Confirm you read them (or pass --allow-dangerous / allow_dangerous) to apply them.', 'axle-config-sync' ),
			'nothing'            => __( 'Nothing to apply: the chosen items are in sync or blocked.', 'axle-config-sync' ),
			/* translators: %s: item IDs. */
			'changed_since'      => __( 'These items changed after this apply, so undoing would overwrite newer changes: %s. Use "Undo anyway" to undo them.', 'axle-config-sync' ),
			/* translators: %s: directory path. */
			'not_writable'       => __( 'The config directory %s cannot be written. Make it writable, set AXLE_CONFIG_SYNC_DIR to a writable folder, or download the files as a zip.', 'axle-config-sync' ),
			/* translators: %s: JSON pointers. */
			'secret_in_file'     => __( 'Not written: a secret is still in plain text at %s. Mark it "not a secret" on the Track tab, or keep it out of the value.', 'axle-config-sync' ),
			'never_sync'         => __( 'This item is never synced.', 'axle-config-sync' ),
			'bad_file'           => __( 'The file is not valid JSON for this plugin.', 'axle-config-sync' ),
			/* translators: %s: capabilities. */
			'dangerous_role'     => __( 'Role gains %s.', 'axle-config-sync' ),
			'dangerous_change'   => __( 'Changes who can sign up, sign in or manage the site.', 'axle-config-sync' ),
			'dangerous_public'   => __( 'Hides the production site from search engines.', 'axle-config-sync' ),
			'dangerous_remove'   => __( 'Removes it from this site.', 'axle-config-sync' ),
		);

		/**
		 * Filters the user-facing messages, keyed by name.
		 *
		 * Messages use sprintf() placeholders; keep the same ones.
		 *
		 * @param array<string, string> $messages Messages.
		 */
		$map = self::as_array( apply_filters( 'axle_config_sync_messages', $defaults ) );

		self::$map = array();
		foreach ( $defaults as $key => $default ) {
			self::$map[ $key ] = isset( $map[ $key ] ) && is_string( $map[ $key ] ) && '' !== trim( $map[ $key ] ) ? $map[ $key ] : $default;
		}

		return self::$map;
	}

	/**
	 * A filter result as an array of unknown values.
	 *
	 * @param mixed $value Filter result.
	 * @return array<mixed>
	 */
	public static function as_array( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Forgets the cached map, for tests.
	 */
	public static function reset(): void {
		self::$map = null;
	}
}
