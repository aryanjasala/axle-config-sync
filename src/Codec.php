<?php
/**
 * Turns PHP values into file JSON and back, exactly.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Value encoding for config files.
 *
 * JSON already tells integers from numeric strings, and booleans and null
 * from everything else. On top of that:
 *
 * - `stdClass` becomes `{"$object":"stdClass","value":{...}}`. Any other
 *   object is refused with UnsupportedValue, so a file can never carry one.
 * - A string that is not valid UTF-8 becomes `{"$binary":"<base64>"}`.
 * - An array that happens to use one of the tag keys is wrapped in
 *   `{"$array":{...}}`, so it is never read as a tag.
 * - References and secrets are Marker objects in PHP, `{"$ref":...}` and
 *   `{"$secret":...}` in JSON.
 *
 * Map keys are sorted so files and their git diffs stay stable. Nothing read
 * from a file is ever passed to unserialize() or maybe_unserialize(): a
 * serialized string in a file stays a string.
 */
final class Codec {

	public const SCHEMA = 1;

	private const TAGS = array( '$ref', '$secret', '$object', '$array', '$binary' );

	/**
	 * PHP value to a JSON-ready structure.
	 *
	 * @param mixed  $value     Value.
	 * @param bool   $canonical Add secret fingerprints, for hashing.
	 * @param string $path      Where we are, for the error message.
	 * @return mixed
	 * @throws UnsupportedValue For objects other than stdClass, resources and NAN or INF.
	 */
	public static function encode( $value, bool $canonical = false, string $path = '' ) {
		if ( $value instanceof Marker ) {
			return $value->to_array( $canonical );
		}

		if ( $value instanceof \stdClass ) {
			return array(
				'$object' => 'stdClass',
				'value'   => self::encode_map( get_object_vars( $value ), $canonical, $path ),
			);
		}

		if ( is_object( $value ) || is_resource( $value ) ) {
			/* translators: 1: PHP class or type, 2: where in the value. */
			throw new UnsupportedValue( esc_html( sprintf( __( 'Unsupported value: %1$s at %2$s. Only plain values, arrays and stdClass objects can go into a file.', 'axle-config-sync' ), is_object( $value ) ? get_class( $value ) : 'resource', '' === $path ? '/' : $path ) ) );
		}

		if ( is_float( $value ) && ( is_nan( $value ) || is_infinite( $value ) ) ) {
			/* translators: %s: where in the value. */
			throw new UnsupportedValue( esc_html( sprintf( __( 'Unsupported value: a number JSON cannot hold at %s.', 'axle-config-sync' ), '' === $path ? '/' : $path ) ) );
		}

		if ( is_string( $value ) && ! self::is_utf8( $value ) ) {
			return array( '$binary' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Keeps bytes that are not UTF-8 exact in JSON.
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::is_list( $value ) ) {
			$out = array();
			foreach ( $value as $index => $item ) {
				$out[] = self::encode( $item, $canonical, $path . '/' . $index );
			}
			return $out;
		}

		$map = self::encode_map( $value, $canonical, $path );
		foreach ( self::TAGS as $tag ) {
			if ( array_key_exists( $tag, $value ) ) {
				return array( '$array' => $map );
			}
		}

		return $map;
	}

	/**
	 * JSON structure back to a PHP value.
	 *
	 * @param mixed $data Decoded JSON (associative arrays).
	 * @return mixed
	 */
	public static function decode( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		if ( self::is_list( $data ) ) {
			return array_map( array( self::class, 'decode' ), $data );
		}

		if ( isset( $data['$object'] ) ) {
			$object = new \stdClass();
			if ( 'stdClass' === $data['$object'] && isset( $data['value'] ) && is_array( $data['value'] ) ) {
				foreach ( $data['value'] as $key => $item ) {
					$object->{ (string) $key} = self::decode( $item );
				}
			}
			return $object;
		}

		if ( isset( $data['$ref'] ) || isset( $data['$secret'] ) ) {
			$marker = Marker::from_array( $data );
			if ( $marker ) {
				return $marker;
			}
		}

		if ( isset( $data['$binary'] ) && is_string( $data['$binary'] ) && 1 === count( $data ) ) {
			$bytes = base64_decode( $data['$binary'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The counterpart of encode().
			return false === $bytes ? '' : $bytes;
		}

		if ( isset( $data['$array'] ) && is_array( $data['$array'] ) && 1 === count( $data ) ) {
			$data = $data['$array'];
		}

		$out = array();
		foreach ( $data as $key => $item ) {
			$out[ $key ] = self::decode( $item );
		}

		return $out;
	}

	/**
	 * JSON text for a file: pretty, sorted, slashes and Unicode kept readable.
	 *
	 * @param mixed $data JSON-ready structure.
	 * @return string With a trailing newline.
	 */
	public static function to_json( $data ): string {
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

		/**
		 * Filters the json_encode() flags for config files.
		 *
		 * JSON_PRESERVE_ZERO_FRACTION is always added back, or 1.0 would come back as 1.
		 *
		 * @param int $flags Default JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION.
		 */
		$flags = (int) apply_filters( 'axle_config_sync_file_json_flags', $flags ) | JSON_PRESERVE_ZERO_FRACTION;

		$json = wp_json_encode( $data, $flags );

		return ( false === $json ? 'null' : $json ) . "\n";
	}

	/**
	 * Reads file JSON text. Returns null for text that is not JSON.
	 *
	 * @param string $json File contents.
	 * @return array|null
	 */
	public static function from_json( string $json ): ?array {
		$data = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Stable hash of a value, for three-way status.
	 *
	 * @param mixed $value PHP value (markers allowed).
	 * @return string
	 * @throws UnsupportedValue When the value cannot be encoded.
	 */
	public static function hash( $value ): string {
		return md5( (string) wp_json_encode( self::encode( $value, true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	/**
	 * Whether two values encode the same, ignoring map key order.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 * @return bool
	 */
	public static function same( $a, $b ): bool {
		try {
			return self::hash( $a ) === self::hash( $b );
		} catch ( UnsupportedValue $e ) {
			return false;
		}
	}

	/**
	 * Pretty JSON of a value for the screen and diffs. Never fails.
	 *
	 * @param mixed $value PHP value.
	 * @return string
	 */
	public static function pretty( $value ): string {
		try {
			return rtrim( self::to_json( self::encode( $value ) ) );
		} catch ( UnsupportedValue $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * Whether an array has keys 0..n-1 in order.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	public static function is_list( array $value ): bool {
		$i = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}

	/**
	 * Encodes a map with sorted keys.
	 *
	 * @param array  $value     Map.
	 * @param bool   $canonical Add secret fingerprints.
	 * @param string $path      Where we are.
	 * @return array
	 */
	private static function encode_map( array $value, bool $canonical, string $path ): array {
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ (string) $key ] = self::encode( $item, $canonical, $path . '/' . $key );
		}
		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Whether a string is valid UTF-8.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function is_utf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}
}
