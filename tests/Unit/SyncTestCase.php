<?php
/**
 * Base class for Config Sync unit tests: the common WordPress functions, a
 * temporary config directory, an in-memory $wpdb and a fake provider.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Changesets;
use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Messages;
use AryanX\Axle\ConfigSync\Registry;
use AryanX\Axle\ConfigSync\Rules;
use AryanX\Axle\ConfigSync\State;
use AryanX\Axle\ConfigSync\Tests\FakeWpdb;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/fakes.php';
require_once __DIR__ . '/MemoryProvider.php';

defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/axle-config-sync-unit-content' );
defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'AXLE_SECRET_FROM_CONSTANT' ) || define( 'AXLE_SECRET_FROM_CONSTANT', 'constant-value' );

/**
 * Shared setup.
 */
abstract class SyncTestCase extends TestCase {

	/**
	 * A fresh config directory per test.
	 *
	 * @var string
	 */
	protected string $dir = '';

	/**
	 * Fires per action name, for assertions.
	 *
	 * @var array<string, array[]>
	 */
	protected array $fired = array();

	protected function setUp(): void {
		parent::setUp();
		Messages::reset();

		$this->dir = sys_get_temp_dir() . '/axle-config-sync-unit-' . uniqid();
		mkdir( $this->dir, 0777, true );

		$GLOBALS['wpdb'] = new FakeWpdb();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $flags = 0 ) {
				return json_encode( $data, $flags );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'site_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
			}
		);
		Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-content/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( $value, '/\\' );
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( $value, '/\\' ) . '/';
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', $path );
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( $path ) {
				return is_dir( $path ) || mkdir( $path, 0777, true );
			}
		);
		Functions\when( 'is_serialized' )->alias(
			static function ( $data ) {
				return is_string( $data ) && 1 === preg_match( '/^(a|O|s|i|b|d):\d*[:;]/', $data );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->alias(
			static function () {
				return gmdate( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1' );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_get_last_changed' )->justReturn( '1' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_list_pluck' )->alias(
			static function ( $list, $field ) {
				return array_map(
					static function ( $item ) use ( $field ) {
						return is_array( $item ) ? $item[ $field ] : $item->$field;
					},
					$list
				);
			}
		);

		$this->fired = array();
		foreach ( array( 'axle_config_sync_exported', 'axle_config_sync_apply_started', 'axle_config_sync_item_applied', 'axle_config_sync_item_skipped', 'axle_config_sync_apply_finished', 'axle_config_sync_apply_failed', 'axle_config_sync_rolled_back', 'axle_config_sync_undone', 'axle_config_sync_conflict_detected', 'axle_config_sync_secret_unresolved' ) as $action ) {
			\Brain\Monkey\Actions\expectDone( $action )->zeroOrMoreTimes()->whenHappen(
				function ( ...$args ) use ( $action ) {
					$this->fired[ $action ][] = $args;
				}
			);
		}
	}

	protected function tearDown(): void {
		$this->remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * An engine over the temporary directory with only the memory provider.
	 *
	 * @param MemoryProvider $provider Provider.
	 * @return Engine
	 */
	protected function engine( MemoryProvider $provider ): Engine {
		Filters\expectApplied( 'axle_config_sync_providers' )->zeroOrMoreTimes()->andReturn( array( 'memory' => $provider ) );
		Filters\expectApplied( 'axle_config_sync_directory' )->zeroOrMoreTimes()->andReturn( $this->dir );

		$directory = new Directory();
		$rules     = new Rules( $directory );

		return new Engine( $directory, $rules, new State(), new Changesets(), new Registry( $rules ) );
	}

	/**
	 * Writes a file in the temporary config directory.
	 *
	 * @param string $relative Path.
	 * @param mixed  $data     JSON data.
	 */
	protected function put( string $relative, $data ): void {
		$file = $this->dir . '/' . $relative;
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}
		file_put_contents( $file, is_string( $data ) ? $data : json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Reads a JSON file from the temporary config directory.
	 *
	 * @param string $relative Path.
	 * @return mixed
	 */
	protected function read( string $relative ) {
		return json_decode( (string) file_get_contents( $this->dir . '/' . $relative ), true );
	}

	/**
	 * Removes a folder.
	 *
	 * @param string $path Path.
	 */
	protected function remove( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = $path . '/' . $name;
			is_dir( $child ) ? $this->remove( $child ) : unlink( $child );
		}
		rmdir( $path );
	}
}
