<?php
/**
 * Import of WP-CFM bundles.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Import;

use AryanX\Axle\ConfigSync\Codec;
use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Messages;
use AryanX\Axle\ConfigSync\Providers\Options;
use AryanX\Axle\ConfigSync\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WP-CFM bundles and writes Config Sync files from them. Nothing is
 * applied to the site, and WP-CFM's own files and settings are left alone.
 *
 * A WP-CFM bundle is a JSON file in wp-content/config (`<bundle>.json`, or
 * `blog<ID>-<bundle>.json` on multisite) that maps option names to the raw
 * option values as the database holds them: plain strings, or PHP serialized
 * strings for arrays. A key `.label` names the bundle. The bundle list (name,
 * label and the option names in it) is also kept in the `wpcfm_settings`
 * option as JSON, which is where a bundle that was never pushed to a file
 * lives. Both are read here with or without WP-CFM active.
 *
 * File content is never unserialized with classes: serialized values are read
 * with allowed_classes false, and a value that holds an object is skipped with
 * a reason.
 */
final class WpCfm {

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Sets up the importer.
	 *
	 * @param Engine $engine The engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * The largest bundle file that is read.
	 *
	 * @return int Bytes, 1 KB to 50 MB.
	 */
	public static function max_file_size(): int {
		/**
		 * Filters the largest WP-CFM bundle file Config Sync reads.
		 *
		 * @param int $bytes Default 5 MB.
		 */
		return max( KB_IN_BYTES, min( 50 * MB_IN_BYTES, (int) apply_filters( 'axle_config_sync_wpcfm_max_file_size', 5 * MB_IN_BYTES ) ) );
	}

	/**
	 * The folder WP-CFM keeps its bundle files in.
	 *
	 * @return string
	 */
	public function directory(): string {
		$default = defined( 'WPCFM_CONFIG_DIR' ) && is_string( WPCFM_CONFIG_DIR ) ? WPCFM_CONFIG_DIR : WP_CONTENT_DIR . '/config';

		/**
		 * Filters the folder Config Sync reads WP-CFM bundle files from.
		 *
		 * @param string $path Absolute path. Default WP-CFM's own folder, or wp-content/config.
		 */
		$path = (string) apply_filters( 'axle_config_sync_wpcfm_directory', $default );

		return '' !== $path ? untrailingslashit( wp_normalize_path( $path ) ) : untrailingslashit( wp_normalize_path( $default ) );
	}

	/**
	 * Whether WP-CFM left bundles behind: files or settings.
	 *
	 * @return bool
	 */
	public function detected(): bool {
		return (bool) $this->bundles();
	}

	/**
	 * Every bundle found, from files and from the database.
	 *
	 * @return array<string, array{name: string, label: string, source: string, keys: string[], values: array<string, mixed>, error: string, note: string}>
	 */
	public function bundles(): array {
		$bundles = array();

		foreach ( $this->settings_bundles() as $name => $bundle ) {
			$bundles[ $name ] = array(
				'name'   => $name,
				'label'  => $bundle['label'],
				'source' => 'database',
				'keys'   => $bundle['config'],
				'values' => array(),
				'error'  => '',
				'note'   => '',
			);
		}

		foreach ( $this->file_bundles() as $name => $bundle ) {
			if ( isset( $bundles[ $name ] ) ) {
				$bundles[ $name ]['source'] = 'both';
				$bundles[ $name ]['label']  = '' !== $bundle['label'] ? $bundle['label'] : $bundles[ $name ]['label'];
				$bundles[ $name ]['keys']   = array_values( array_unique( array_merge( array_keys( $bundle['values'] ), $bundles[ $name ]['keys'] ) ) );
			} else {
				$bundles[ $name ] = array(
					'name'   => $name,
					'label'  => $bundle['label'],
					'source' => 'files',
					'keys'   => array_keys( $bundle['values'] ),
					'values' => array(),
					'error'  => '',
					'note'   => $bundle['note'],
				);
			}
			$bundles[ $name ]['values'] = $bundle['values'];
			$bundles[ $name ]['error']  = $bundle['error'];
		}

		ksort( $bundles );

		return $bundles;
	}

	/**
	 * What an import would do, item by item. Nothing is written.
	 *
	 * @param string[]|null $names Bundle names, or null for every bundle.
	 * @return array{bundles: string[], items: array<int, array{bundle: string, key: string, id: string, action: string, reason: string, value: string}>, values: array<string, mixed>, errors: array<string, string>}
	 */
	public function plan( ?array $names = null ): array {
		$all = $this->bundles();
		if ( null !== $names ) {
			$all = array_intersect_key( $all, array_flip( $names ) );
		}

		/**
		 * Filters how many items one import reads.
		 *
		 * @param int $max Default 2000.
		 */
		$max    = max( 1, min( 20000, (int) apply_filters( 'axle_config_sync_wpcfm_max_items', 2000 ) ) );
		$rows   = array();
		$values = array();
		$errors = array();
		$mapped = array();
		$count  = 0;

		foreach ( $all as $name => $bundle ) {
			if ( '' !== $bundle['error'] ) {
				$errors[ $name ] = $bundle['error'];
				continue;
			}

			foreach ( $bundle['keys'] as $key ) {
				if ( $count >= $max ) {
					$errors[ $name ] = __( 'Too many items: the rest were not read.', 'axle-config-sync' );
					break;
				}
				++$count;

				$row = array(
					'bundle' => $name,
					'key'    => $key,
					'id'     => '',
					'action' => 'skip',
					'reason' => '',
					'value'  => '',
				);

				$map = $this->map( $key );
				if ( '' === $map['id'] ) {
					$row['reason'] = $map['reason'];
					$rows[]        = $row;
					continue;
				}
				$row['id'] = $map['id'];

				if ( isset( $mapped[ $map['id'] ] ) ) {
					/* translators: %s: bundle name. */
					$row['reason'] = sprintf( __( 'Already taken from bundle "%s".', 'axle-config-sync' ), $mapped[ $map['id'] ] );
					$rows[]        = $row;
					continue;
				}

				$decoded = $this->value( $bundle, $key, $map['id'] );
				if ( '' !== $decoded['reason'] ) {
					$row['reason'] = $decoded['reason'];
					$rows[]        = $row;
					continue;
				}

				$compose = $this->engine->compose_file( $map['id'], $decoded['value'] );
				if ( '' !== $compose['error'] ) {
					$row['reason'] = $compose['error'];
					$rows[]        = $row;
					continue;
				}

				$provider = $this->engine->registry()->for_item( $map['id'] );
				$existing = $provider ? $this->engine->directory()->read( $provider->file( $map['id'] ) ) : null;
				$parsed   = Codec::from_json( $compose['json'] );

				$row['action'] = null === $existing ? 'new' : ( $existing === $compose['json'] ? 'same' : 'replace' );
				$row['value']  = Drift::summarize( is_array( $parsed ) ? Codec::decode( $parsed['value'] ?? null ) : null );
				$rows[]        = $row;

				$mapped[ $map['id'] ] = $name;
				$values[ $map['id'] ] = $decoded['value'];
			}
		}

		return array(
			'bundles' => array_keys( $all ),
			'items'   => $rows,
			'values'  => $values,
			'errors'  => $errors,
		);
	}

	/**
	 * Writes Config Sync files for the bundles. WP-CFM's files stay as they are.
	 *
	 * @param string[]|null $names   Bundle names, or null for every bundle.
	 * @param bool          $dry_run Only plan.
	 * @return array{plan: array, written: array<string, string>, refused: array<string, string>, tracked: string[], directory: string}|\WP_Error
	 */
	public function import( ?array $names = null, bool $dry_run = false ) {
		$plan   = $this->plan( $names );
		$result = array(
			'plan'      => $plan,
			'written'   => array(),
			'refused'   => array(),
			'tracked'   => array(),
			'directory' => $this->engine->directory()->path(),
		);

		if ( $dry_run ) {
			return $result;
		}

		$write = array();
		foreach ( $plan['items'] as $row ) {
			if ( in_array( $row['action'], array( 'new', 'replace' ), true ) ) {
				$write[ $row['id'] ] = $plan['values'][ $row['id'] ];
			}
		}

		if ( ! $write ) {
			return $result;
		}

		$written = $this->engine->write_imported( $write );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		$result['written'] = $written['written'];
		$result['refused'] = $written['refused'];

		// Plugin options need a tracking rule, or status would not list them.
		$rules   = $this->engine->rules()->all();
		$tracked = array();
		foreach ( array_keys( $written['written'] ) as $id ) {
			$name = 0 === strpos( $id, 'options/' ) ? substr( $id, 8 ) : '';
			if ( '' !== $name && ! in_array( $name, Options::core_names(), true ) && ! $this->engine->rules()->is_tracked( $name ) ) {
				$tracked[] = $name;
			}
		}
		if ( $tracked ) {
			$rules['options'] = array_merge( $rules['options'], $tracked );
			$this->engine->rules()->save( $rules );
		}
		$result['tracked'] = $tracked;

		/**
		 * Fires after a WP-CFM import wrote Config Sync files.
		 *
		 * @param array<string, string> $written Item ID => file written.
		 * @param string[]              $bundles Bundle names read.
		 * @param string[]              $tracked Option names added to the tracking rules.
		 */
		do_action( 'axle_config_sync_wpcfm_imported', $written['written'], $plan['bundles'], $tracked );

		return $result;
	}

	/**
	 * Which Config Sync item a WP-CFM key maps to.
	 *
	 * @param string $key WP-CFM key: an option name.
	 * @return array{id: string, reason: string} An empty ID comes with the reason.
	 */
	public function map( string $key ): array {
		$map = $this->map_default( $key );

		/**
		 * Filters which Config Sync item a WP-CFM key maps to.
		 *
		 * Return an empty `id` with a `reason` to leave the key out, or an item
		 * ID that a provider owns, such as one a third-party provider adds.
		 *
		 * @param array{id: string, reason: string} $map Item ID and reason.
		 * @param string                            $key WP-CFM key (an option name).
		 */
		$filtered = Messages::as_array( apply_filters( 'axle_config_sync_wpcfm_map', $map, $key ) );

		return isset( $filtered['id'], $filtered['reason'] ) && is_string( $filtered['id'] ) && is_string( $filtered['reason'] ) ? array(
			'id'     => $filtered['id'],
			'reason' => $filtered['reason'],
		) : $map;
	}

	/**
	 * The built-in mapping of a WP-CFM key.
	 *
	 * @param string $key WP-CFM key.
	 * @return array{id: string, reason: string}
	 */
	private function map_default( string $key ): array {
		$none = static function ( string $reason ): array {
			return array(
				'id'     => '',
				'reason' => $reason,
			);
		};

		if ( '' === $key || false !== strpos( $key, '*' ) || Rules::clean_pattern( $key ) !== $key ) {
			return $none( __( 'Not a plain option name.', 'axle-config-sync' ) );
		}
		if ( 0 === strpos( $key, 'theme_mods_' ) ) {
			$id = 'theme-mods/' . substr( $key, 11 );
			return $this->engine->registry()->for_item( $id ) ? array(
				'id'     => $id,
				'reason' => '',
			) : $none( __( 'Not a theme mods name.', 'axle-config-sync' ) );
		}
		if ( 'sidebars_widgets' === $key || 0 === strpos( $key, 'widget_' ) ) {
			return $none( __( 'Config Sync keeps widgets per sidebar. On the live site run: wp axle-config-sync export --all', 'axle-config-sync' ) );
		}
		if ( 'nav_menu_options' === $key ) {
			return $none( __( 'Config Sync keeps menus as their own items. On the live site run: wp axle-config-sync export --all', 'axle-config-sync' ) );
		}
		if ( 'user_roles' === substr( $key, -10 ) ) {
			return $none( __( 'Config Sync keeps roles as their own items. On the live site run: wp axle-config-sync export --all', 'axle-config-sync' ) );
		}
		if ( ! Options::allowed( $key ) ) {
			return $none( __( 'Config Sync never syncs this option: it holds site URLs, keys or site state.', 'axle-config-sync' ) );
		}

		return array(
			'id'     => 'options/' . $key,
			'reason' => '',
		);
	}

	/**
	 * Decodes a WP-CFM raw value into what the provider reads from a site.
	 *
	 * @param mixed $raw Value from a bundle file.
	 * @return array{value: mixed, reason: string}
	 */
	public static function decode( $raw ): array {
		$fail = static function ( string $reason ): array {
			return array(
				'value'  => null,
				'reason' => $reason,
			);
		};

		if ( is_bool( $raw ) ) {
			return array(
				'value'  => $raw ? '1' : '',
				'reason' => '',
			);
		}
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return array(
				'value'  => (string) $raw,
				'reason' => '',
			);
		}
		if ( is_array( $raw ) ) {
			return array(
				'value'  => $raw,
				'reason' => '',
			);
		}
		if ( ! is_string( $raw ) ) {
			return $fail( __( 'The value is empty or not a type Config Sync can store.', 'axle-config-sync' ) );
		}

		if ( ! is_serialized( $raw ) ) {
			return array(
				'value'  => $raw,
				'reason' => '',
			);
		}

		// A serialized object names a class. Never even try those.
		if ( preg_match( '/(^|[;{}:])[OC]:\d+:"/', $raw ) ) {
			return $fail( __( 'The value holds a PHP object, which is never read from a file.', 'axle-config-sync' ) );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Keeps a malformed value from raising a warning.
		set_error_handler( '__return_true' );
		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- No classes are allowed; a value that holds an object is refused below.
			$value = unserialize( $raw, array( 'allowed_classes' => false ) );
		} finally {
			restore_error_handler();
		}

		if ( false === $value && 'b:0;' !== $raw ) {
			return $fail( __( 'The serialized value is damaged.', 'axle-config-sync' ) );
		}
		if ( self::has_object( $value ) ) {
			return $fail( __( 'The value holds a PHP object, which is never read from a file.', 'axle-config-sync' ) );
		}

		return array(
			'value'  => $value,
			'reason' => '',
		);
	}

	/**
	 * The value of a key: from the bundle file, or, for a bundle that only
	 * lives in the database, the live value on this site.
	 *
	 * @param array  $bundle Bundle.
	 * @param string $key    Key.
	 * @param string $id     Item ID.
	 * @return array{value: mixed, reason: string}
	 */
	private function value( array $bundle, string $key, string $id ): array {
		if ( array_key_exists( $key, $bundle['values'] ) ) {
			$decoded = self::decode( $bundle['values'][ $key ] );
			if ( '' === $decoded['reason'] && 0 === strpos( $id, 'theme-mods/' ) ) {
				$decoded['value'] = is_array( $decoded['value'] ) ? $this->clean_mods( $decoded['value'] ) : null;
				if ( null === $decoded['value'] ) {
					$decoded['reason'] = __( 'The theme mods are not a list of settings.', 'axle-config-sync' );
				}
			}
			return $decoded;
		}

		$provider = $this->engine->registry()->for_item( $id );
		if ( $provider && $provider->exists( $id ) ) {
			return array(
				'value'  => $provider->read( $id ),
				'reason' => '',
			);
		}

		return array(
			'value'  => null,
			'reason' => 'database' === $bundle['source'] ? __( 'In the bundle list, but there is no file and no value on this site.', 'axle-config-sync' ) : __( 'Not in the bundle file.', 'axle-config-sync' ),
		);
	}

	/**
	 * Theme mods as the provider reads them: without menu locations, sidebars and Additional CSS.
	 *
	 * @param array $mods Raw theme mods.
	 * @return array
	 */
	private function clean_mods( array $mods ): array {
		foreach ( \AryanX\Axle\ConfigSync\Providers\ThemeMods::EXCLUDED as $key ) {
			unset( $mods[ $key ] );
		}
		ksort( $mods );

		return $mods;
	}

	/**
	 * Whether a value holds an object anywhere.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function has_object( $value ): bool {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_object( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Bundles listed in WP-CFM's `wpcfm_settings` option.
	 *
	 * @return array<string, array{label: string, config: string[]}>
	 */
	private function settings_bundles(): array {
		$raw = get_option( 'wpcfm_settings', '' );
		$raw = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$out = array();

		foreach ( is_array( $raw ) && isset( $raw['bundles'] ) && is_array( $raw['bundles'] ) ? $raw['bundles'] : array() as $bundle ) {
			if ( ! is_array( $bundle ) || ! isset( $bundle['name'] ) || ! is_string( $bundle['name'] ) || '' === $bundle['name'] ) {
				continue;
			}
			$name         = sanitize_file_name( $bundle['name'] );
			$out[ $name ] = array(
				'label'  => isset( $bundle['label'] ) && is_string( $bundle['label'] ) ? sanitize_text_field( $bundle['label'] ) : $name,
				'config' => isset( $bundle['config'] ) && is_array( $bundle['config'] ) ? array_values( array_filter( array_map( 'strval', array_filter( $bundle['config'], 'is_scalar' ) ) ) ) : array(),
			);
		}

		return $out;
	}

	/**
	 * Bundles in WP-CFM's folder.
	 *
	 * @return array<string, array{label: string, values: array<string, mixed>, error: string, note: string}>
	 */
	private function file_bundles(): array {
		$folder = new Directory();
		$folder->use_path( $this->directory() );
		$out = array();

		foreach ( $folder->files() as $relative ) {
			$name = substr( $relative, 0, -5 );
			$base = basename( $name );
			$note = '';

			if ( '.' === $base[0] ) {
				continue;
			}

			if ( is_multisite() ) {
				if ( 0 === strpos( $base, 'network-' ) ) {
					continue;
				}
				$prefix = 'blog' . get_current_blog_id() . '-';
				if ( 0 !== strpos( $base, $prefix ) ) {
					continue;
				}
				$name = ( dirname( $name ) !== '.' ? dirname( $name ) . '/' : '' ) . substr( $base, strlen( $prefix ) );
			}
			if ( false !== strpos( $name, '/' ) ) {
				$note = __( 'From a subfolder: WP-CFM keeps one folder per environment.', 'axle-config-sync' );
			}

			$text   = $folder->read( $relative );
			$bundle = array(
				'label'  => $name,
				'values' => array(),
				'error'  => '',
				'note'   => $note,
			);
			if ( null === $text || strlen( $text ) > self::max_file_size() ) {
				$bundle['error'] = __( 'The file cannot be read or is larger than the size limit.', 'axle-config-sync' );
			} else {
				$data = json_decode( $text, true );
				if ( ! is_array( $data ) ) {
					$bundle['error'] = __( 'The file is not valid JSON.', 'axle-config-sync' );
				} else {
					if ( isset( $data['.label'] ) && is_string( $data['.label'] ) ) {
						$bundle['label'] = sanitize_text_field( $data['.label'] );
					}
					foreach ( $data as $key => $value ) {
						if ( '.' !== substr( (string) $key, 0, 1 ) ) {
							$bundle['values'][ (string) $key ] = $value;
						}
					}
				}
			}

			$out[ $name ] = $bundle;
		}

		return $out;
	}
}
