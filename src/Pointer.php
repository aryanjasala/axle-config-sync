<?php
/**
 * JSON pointers into PHP values.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sets values by JSON pointer (RFC 6901): "" is the whole value,
 * "/a/0/b" a nested one. Works on arrays and stdClass.
 */
final class Pointer {

	/**
	 * Splits a pointer into keys.
	 *
	 * @param string $pointer Pointer.
	 * @return string[]
	 */
	public static function parts( string $pointer ): array {
		if ( '' === $pointer ) {
			return array();
		}

		return array_map(
			static function ( string $part ): string {
				return str_replace( array( '~1', '~0' ), array( '/', '~' ), $part );
			},
			explode( '/', substr( $pointer, 1 ) )
		);
	}

	/**
	 * Adds a key to a pointer.
	 *
	 * @param string     $pointer Pointer.
	 * @param string|int $key     Key.
	 * @return string
	 */
	public static function append( string $pointer, $key ): string {
		return $pointer . '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), (string) $key );
	}

	/**
	 * The value at a pointer.
	 *
	 * @param mixed  $value   Value.
	 * @param string $pointer Pointer.
	 * @param bool   $found   Set to whether it exists.
	 * @param-out bool $found
	 * @return mixed
	 */
	public static function get( $value, string $pointer, ?bool &$found = null ) {
		$found = true;
		foreach ( self::parts( $pointer ) as $key ) {
			if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
				$value = $value[ $key ];
			} elseif ( $value instanceof \stdClass && property_exists( $value, $key ) ) {
				$value = $value->$key;
			} else {
				$found = false;
				return null;
			}
		}

		return $value;
	}

	/**
	 * A copy of the value with the pointer set, creating arrays on the way.
	 *
	 * @param mixed  $value   Value.
	 * @param string $pointer Pointer.
	 * @param mixed  $replacement New value.
	 * @return mixed
	 */
	public static function set( $value, string $pointer, $replacement ) {
		$parts = self::parts( $pointer );
		if ( ! $parts ) {
			return $replacement;
		}

		$key  = array_shift( $parts );
		$rest = $parts ? '/' . implode( '/', array_map( array( self::class, 'escape' ), $parts ) ) : '';

		if ( $value instanceof \stdClass ) {
			$value       = clone $value;
			$value->$key = self::set( property_exists( $value, $key ) ? $value->$key : null, $rest, $replacement );
			return $value;
		}

		$value         = is_array( $value ) ? $value : array();
		$value[ $key ] = self::set( $value[ $key ] ?? null, $rest, $replacement );

		return $value;
	}

	/**
	 * Escapes one key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function escape( string $key ): string {
		return str_replace( array( '~', '/' ), array( '~0', '~1' ), $key );
	}

	/**
	 * Calls $callback on every leaf (non-array, non-stdClass) and puts back what it returns.
	 *
	 * @param mixed    $value    Value.
	 * @param callable $callback fn( $leaf, string $pointer ): mixed.
	 * @param string   $pointer  Pointer of $value.
	 * @return mixed
	 */
	public static function map_leaves( $value, callable $callback, string $pointer = '' ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::map_leaves( $item, $callback, self::append( $pointer, $key ) );
			}
			return $value;
		}

		if ( $value instanceof \stdClass ) {
			$copy = new \stdClass();
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$copy->$key = self::map_leaves( $item, $callback, self::append( $pointer, $key ) );
			}
			return $copy;
		}

		return $callback( $value, $pointer );
	}

	/**
	 * Flattens a value to pointer => leaf, for the key/value table.
	 *
	 * @param mixed  $value   Value.
	 * @param string $pointer Pointer of $value.
	 * @return array<string, mixed>
	 */
	public static function flatten( $value, string $pointer = '' ): array {
		$out = array();
		self::map_leaves(
			$value,
			static function ( $leaf, string $at ) use ( &$out ) {
				$out[ '' === $at ? '/' : $at ] = $leaf;
				return $leaf;
			},
			$pointer
		);

		if ( ( is_array( $value ) && ! $value ) ) {
			$out[ '' === $pointer ? '/' : $pointer ] = array();
		}

		return $out;
	}
}
