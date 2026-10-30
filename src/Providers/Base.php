<?php
/**
 * Shared provider behaviour.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Providers;

use AryanX\Axle\ConfigSync\References;

defined( 'ABSPATH' ) || exit;

/**
 * Items live in a folder named after the provider, one file each.
 */
abstract class Base implements Provider {

	/**
	 * Whether an item ID belongs to this provider.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function owns( string $id ): bool {
		return 0 === strpos( $id, $this->name() . '/' ) && '' !== $this->key( $id );
	}

	/**
	 * The item's file.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function file( string $id ): string {
		return $id . '.json';
	}

	/**
	 * The item ID for a file.
	 *
	 * @param string $relative File path.
	 * @return string|null
	 */
	public function id_for_file( string $relative ): ?string {
		$prefix = $this->name() . '/';
		if ( 0 !== strpos( $relative, $prefix ) || '.json' !== substr( $relative, -5 ) ) {
			return null;
		}

		$key = substr( $relative, strlen( $prefix ), -5 );

		return '' !== $key && false === strpos( $key, '/' ) ? $prefix . $key : null;
	}

	/**
	 * The part of the ID after the provider name.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function key( string $id ): string {
		$prefix = $this->name() . '/';

		return 0 === strpos( $id, $prefix ) ? substr( $id, strlen( $prefix ) ) : '';
	}

	/**
	 * Known ID fields become references.
	 *
	 * @param string     $id    Item ID.
	 * @param mixed      $value Live value.
	 * @param References $refs  Reference helper.
	 * @return mixed
	 */
	public function references( string $id, $value, References $refs ) {
		return $refs->to_portable( $id, $value );
	}

	/**
	 * References become IDs.
	 *
	 * @param string     $id       Item ID.
	 * @param mixed      $value    Value with references.
	 * @param References $refs     Reference helper.
	 * @param array      $problems Collects problems.
	 * @return mixed
	 */
	public function resolve( string $id, $value, References $refs, array &$problems ) {
		return $refs->resolve_all( $value, $problems );
	}

	/**
	 * Nothing stops an apply by default.
	 *
	 * @param string $id   Item ID.
	 * @param array  $args Apply options.
	 * @return string
	 */
	public function gate( string $id, array $args ): string {
		return '';
	}

	/**
	 * The key is the label by default.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function label( string $id ): string {
		return $this->key( $id );
	}

	/**
	 * Nothing dangerous by default; the shared list in Danger still applies.
	 *
	 * @param string $id     Item ID.
	 * @param mixed  $before Live value.
	 * @param mixed  $after  New value.
	 * @return string
	 */
	public function danger( string $id, $before, $after ): string {
		return '';
	}

	/**
	 * WordPress owns it by default.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function owner( string $id ): string {
		return 'core';
	}

	/**
	 * No summary by default.
	 *
	 * @param string $id     Item ID.
	 * @param mixed  $before Live value.
	 * @param mixed  $after  File value.
	 * @return string
	 */
	public function summary( string $id, $before, $after ): string {
		return '';
	}

	/**
	 * Counts list entries and how many differ, for summaries.
	 *
	 * @param mixed $before Live list.
	 * @param mixed $after  File list.
	 * @return array{0: int, 1: int} Count in the file, changed.
	 */
	protected static function count_changes( $before, $after ): array {
		$before  = is_array( $before ) ? array_values( $before ) : array();
		$after   = is_array( $after ) ? array_values( $after ) : array();
		$changed = 0;
		$max     = max( count( $before ), count( $after ) );
		for ( $i = 0; $i < $max; $i++ ) {
			if ( ! isset( $before[ $i ], $after[ $i ] ) || ! \AryanX\Axle\ConfigSync\Codec::same( $before[ $i ], $after[ $i ] ) ) {
				++$changed;
			}
		}

		return array( count( $after ), $changed );
	}
}
