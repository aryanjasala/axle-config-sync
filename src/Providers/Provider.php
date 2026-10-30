<?php
/**
 * What every provider does.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Providers;

use AryanX\Axle\ConfigSync\References;

defined( 'ABSPATH' ) || exit;

/**
 * A provider owns one kind of setting: it lists its items, reads and writes
 * live values, and turns the IDs in them into portable references.
 *
 * Item IDs are the file path without `.json`, such as `options/blogname` or
 * `menus/primary`. Third-party providers (for settings in custom tables) are
 * added through the `axle_config_sync_providers` filter.
 */
interface Provider {

	/**
	 * Short name, such as options. Also the folder its files go in.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Name for people.
	 *
	 * @return string
	 */
	public function title(): string;

	/**
	 * Item IDs that exist on this site.
	 *
	 * @return string[]
	 */
	public function items(): array;

	/**
	 * Whether an item ID belongs to this provider.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function owns( string $id ): bool;

	/**
	 * The item's file, relative to the config directory.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function file( string $id ): string;

	/**
	 * The item ID for a file, or null when the file is not this provider's.
	 *
	 * @param string $relative File path relative to the config directory.
	 * @return string|null
	 */
	public function id_for_file( string $relative ): ?string;

	/**
	 * Whether the item exists on this site.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function exists( string $id ): bool;

	/**
	 * The live value, with real IDs. Null when it does not exist.
	 *
	 * @param string $id Item ID.
	 * @return mixed
	 */
	public function read( string $id );

	/**
	 * Writes a value through the core API, so sanitize callbacks and hooks run.
	 * Null deletes the item. Throws on failure.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Value with real IDs.
	 * @throws \RuntimeException When the write fails.
	 */
	public function write( string $id, $value ): void;

	/**
	 * Replaces the IDs in a live value with portable references.
	 *
	 * @param string     $id    Item ID.
	 * @param mixed      $value Live value.
	 * @param References $refs  Reference helper.
	 * @return mixed
	 */
	public function references( string $id, $value, References $refs );

	/**
	 * Replaces the references in a file value with IDs on this site.
	 *
	 * @param string     $id       Item ID.
	 * @param mixed      $value    Value with references.
	 * @param References $refs     Reference helper.
	 * @param array      $problems Collects unresolved references.
	 * @return mixed
	 */
	public function resolve( string $id, $value, References $refs, array &$problems );

	/**
	 * Why the item cannot be applied with these options, or empty.
	 *
	 * @param string $id   Item ID.
	 * @param array  $args Apply options (inactive_theme, ...).
	 * @return string
	 */
	public function gate( string $id, array $args ): string;

	/**
	 * Label for people.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function label( string $id ): string;

	/**
	 * Why writing $after over $before is dangerous, or empty.
	 *
	 * @param string $id     Item ID.
	 * @param mixed  $before Live value.
	 * @param mixed  $after  Value to write.
	 * @return string
	 */
	public function danger( string $id, $before, $after ): string;

	/**
	 * The plugin that owns the item (core for WordPress itself), or empty.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function owner( string $id ): string;

	/**
	 * A one-line summary of a change, such as "Footer: 3 widgets, 1 changed".
	 *
	 * @param string $id     Item ID.
	 * @param mixed  $before Live value.
	 * @param mixed  $after  File value.
	 * @return string
	 */
	public function summary( string $id, $before, $after ): string;
}
