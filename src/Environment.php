<?php
/**
 * Which environment this site is, and its per-environment values.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * The environment is AXLE_CONFIG_SYNC_ENV when it is defined, else
 * wp_get_environment_type(), the same source Staging Guard uses, so both
 * agree. env/<environment>.json in the config directory then sets values for
 * this environment only: item => JSON pointer => value.
 */
final class Environment {

	/**
	 * The config directory.
	 *
	 * @var Directory
	 */
	private Directory $directory;

	/**
	 * Overrides read from the environment file, cached per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $overrides = null;

	/**
	 * Sets up the environment.
	 *
	 * @param Directory $directory The config directory.
	 */
	public function __construct( Directory $directory ) {
		$this->directory = $directory;
	}

	/**
	 * The environment name and where it came from.
	 *
	 * @return array{name: string, source: string}
	 */
	public static function detect(): array {
		if ( defined( 'AXLE_CONFIG_SYNC_ENV' ) && is_string( AXLE_CONFIG_SYNC_ENV ) && '' !== AXLE_CONFIG_SYNC_ENV ) {
			$name   = AXLE_CONFIG_SYNC_ENV;
			$source = 'AXLE_CONFIG_SYNC_ENV';
		} else {
			$name   = wp_get_environment_type();
			$source = defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' ) ? 'WP_ENVIRONMENT_TYPE' : 'wp_get_environment_type()';
		}

		/**
		 * Filters the environment this site is in, which picks env/<environment>.json.
		 *
		 * @param string $name   Environment name.
		 * @param string $source Where it came from.
		 */
		$filtered = sanitize_key( (string) apply_filters( 'axle_config_sync_environment', $name, $source ) );
		if ( '' === $filtered ) {
			$filtered = sanitize_key( $name );
		}
		if ( sanitize_key( $name ) !== $filtered ) {
			$source = 'axle_config_sync_environment';
		}

		return array(
			'name'   => '' === $filtered ? 'production' : $filtered,
			'source' => $source,
		);
	}

	/**
	 * The environment name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return self::detect()['name'];
	}

	/**
	 * Relative path of this environment's file.
	 *
	 * @return string
	 */
	public static function file(): string {
		return 'env/' . self::name() . '.json';
	}

	/**
	 * The overrides for this environment: item => pointer => value.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function overrides(): array {
		if ( null !== $this->overrides ) {
			return $this->overrides;
		}

		$this->overrides = array();
		$data            = $this->directory->read_json( self::file() );
		if ( is_array( $data ) && isset( $data['values'] ) && is_array( $data['values'] ) ) {
			foreach ( $data['values'] as $id => $pointers ) {
				if ( ! is_array( $pointers ) ) {
					continue;
				}
				foreach ( $pointers as $pointer => $value ) {
					$pointer = (string) $pointer;
					if ( '' === $pointer || '/' === $pointer[0] ) {
						$this->overrides[ (string) $id ][ $pointer ] = Codec::decode( $value );
					}
				}
			}
		}

		return $this->overrides;
	}

	/**
	 * Applies this environment's overrides to an item value.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value File value.
	 * @return mixed
	 */
	public function apply( string $id, $value ) {
		foreach ( $this->overrides()[ $id ] ?? array() as $pointer => $override ) {
			$value = Pointer::set( $value, (string) $pointer, $override );
		}

		return $value;
	}

	/**
	 * Before export: keeps what the file already has at the pointers this
	 * environment overrides, so one environment's values never end up in the
	 * shared file.
	 *
	 * @param string $id       Item ID.
	 * @param mixed  $value    Value about to be written.
	 * @param mixed  $existing Value in the current file, or null.
	 * @param bool   $has_file Whether there is a current file.
	 * @return mixed
	 */
	public function protect( string $id, $value, $existing, bool $has_file ) {
		if ( ! $has_file ) {
			return $value;
		}

		foreach ( array_keys( $this->overrides()[ $id ] ?? array() ) as $pointer ) {
			$old = Pointer::get( $existing, (string) $pointer, $found );
			if ( $found ) {
				$value = Pointer::set( $value, (string) $pointer, $old );
			}
		}

		return $value;
	}

	/**
	 * Forgets cached overrides.
	 */
	public function flush(): void {
		$this->overrides = null;
	}
}
