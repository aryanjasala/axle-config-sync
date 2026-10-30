<?php
/**
 * The manifest file: where and with what the files were exported.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * The source environment, WordPress version, active theme, and the version of
 * each plugin that owns a tracked option. Apply warns when a plugin's version
 * differs on the target, since settings change shape between versions.
 */
final class Manifest {

	public const FILE = 'manifest.json';

	/**
	 * The config directory.
	 *
	 * @var Directory
	 */
	private Directory $directory;

	/**
	 * Sets up the manifest.
	 *
	 * @param Directory $directory The config directory.
	 */
	public function __construct( Directory $directory ) {
		$this->directory = $directory;
	}

	/**
	 * Builds the manifest for the owners of the exported items.
	 *
	 * @param string[] $owners Owner slugs (plugin folder names, core, theme:x).
	 * @return array
	 */
	public static function build( array $owners ): array {
		$plugins = array();
		foreach ( array_unique( $owners ) as $owner ) {
			$version = self::plugin_version( (string) $owner );
			if ( '' !== $version ) {
				$plugins[ (string) $owner ] = $version;
			}
		}
		ksort( $plugins );

		return array(
			'schema'      => Codec::SCHEMA,
			'environment' => Environment::name(),
			'wordpress'   => get_bloginfo( 'version' ),
			'theme'       => get_stylesheet(),
			'plugins'     => $plugins ? $plugins : new \stdClass(),
		);
	}

	/**
	 * The manifest in the directory.
	 *
	 * @return array
	 */
	public function read(): array {
		$data = $this->directory->read_json( self::FILE );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * A warning when the item's owner plugin differs from the export, or empty.
	 *
	 * @param string $owner Owner slug from the item file.
	 * @return string
	 */
	public function warning( string $owner ): string {
		$plugins = (array) ( $this->read()['plugins'] ?? array() );
		if ( '' === $owner || ! isset( $plugins[ $owner ] ) ) {
			return '';
		}

		$here = self::plugin_version( $owner );
		if ( '' === $here ) {
			return Messages::get( 'plugin_missing', $owner );
		}

		return (string) $plugins[ $owner ] !== $here ? Messages::get( 'plugin_version', $owner, (string) $plugins[ $owner ], $here ) : '';
	}

	/**
	 * The version of an active plugin by folder name, or empty.
	 *
	 * @param string $slug Plugin folder name.
	 * @return string
	 */
	public static function plugin_version( string $slug ): string {
		if ( '' === $slug || 'core' === $slug || 0 === strpos( $slug, 'theme:' ) ) {
			return '';
		}

		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( dirname( (string) $file ) === $slug ) {
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
				return (string) $data['Version'];
			}
		}

		return '';
	}
}
