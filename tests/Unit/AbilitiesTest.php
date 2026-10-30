<?php
/**
 * Tests for the abilities.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Abilities;
use Brain\Monkey\Functions;

/**
 * Covers Abilities.
 */
final class AbilitiesTest extends SyncTestCase {

	/**
	 * Registered abilities by name.
	 *
	 * @var array<string, array>
	 */
	private array $registered = array();

	/**
	 * The provider.
	 *
	 * @var MemoryProvider
	 */
	private MemoryProvider $memory;

	/**
	 * The abilities.
	 *
	 * @var Abilities
	 */
	private Abilities $abilities;

	protected function setUp(): void {
		parent::setUp();
		$this->memory        = new MemoryProvider();
		$this->memory->store = array( 'acme_api_key' => 'sk_live_abcdefgh' );
		$this->abilities     = new Abilities( $this->engine( $this->memory ) );

		Functions\when( 'wp_register_ability_category' )->justReturn( true );
		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->registered[ $name ] = $args;
			}
		);
		$this->abilities->register();
		$this->abilities->register_category();
		$this->abilities->register_abilities();
	}

	public function test_seven_public_abilities_with_the_right_annotations(): void {
		$this->assertSame( 10, has_action( 'wp_abilities_api_init', array( $this->abilities, 'register_abilities' ) ) );
		$this->assertSame(
			array( 'get-status', 'get-item-diff', 'list-changesets', 'export-config', 'preview-apply', 'apply-config', 'undo-apply' ),
			array_map(
				static function ( string $name ): string {
					return substr( $name, strlen( 'axle-config-sync/' ) );
				},
				array_keys( $this->registered )
			)
		);

		foreach ( $this->registered as $args ) {
			$this->assertTrue( $args['meta']['public'] );
			$this->assertTrue( $args['meta']['mcp']['public'] );
			$this->assertTrue( $args['meta']['show_in_rest'] );
		}

		foreach ( array( 'get-status', 'get-item-diff', 'list-changesets', 'preview-apply' ) as $readonly ) {
			$this->assertTrue( $this->registered[ 'axle-config-sync/' . $readonly ]['meta']['annotations']['readonly'] );
		}
		$this->assertFalse( $this->registered['axle-config-sync/export-config']['meta']['annotations']['readonly'] );
		$this->assertTrue( $this->registered['axle-config-sync/export-config']['meta']['annotations']['idempotent'] );
		$this->assertTrue( $this->registered['axle-config-sync/apply-config']['meta']['annotations']['destructive'] );
		$this->assertTrue( $this->registered['axle-config-sync/undo-apply']['meta']['annotations']['destructive'] );
	}

	public function test_permission_uses_the_screen_capability(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		$this->assertFalse( $this->registered['axle-config-sync/apply-config']['permission_callback']() );
	}

	public function test_outputs_never_carry_a_secret_value(): void {
		$export = $this->registered['axle-config-sync/export-config']['execute_callback']( array( 'all' => true ) );
		$this->assertArrayHasKey( 'memory/acme_api_key', (array) $export['written'] );

		$diff = $this->registered['axle-config-sync/get-item-diff']['execute_callback']( array( 'item' => 'memory/acme_api_key' ) );
		$this->assertSame( array( '$secret' => 'ACME_API_KEY' ), $diff['site_value'] );
		$this->assertSame( array( '$secret' => 'ACME_API_KEY' ), $diff['file_value'] );

		$status = $this->registered['axle-config-sync/get-status']['execute_callback']( array() );
		$this->assertStringNotContainsString( 'sk_live', (string) wp_json_encode( $status ) );
		$this->assertSame( 1, $status['counts']['in_sync'] );
	}

	public function test_apply_and_undo_through_abilities(): void {
		$this->memory->store['title'] = 'Old';
		$this->put(
			'memory/title.json',
			array(
				'id'    => 'memory/title',
				'value' => 'New',
			)
		);

		$preview = $this->registered['axle-config-sync/preview-apply']['execute_callback']( array( 'items' => array( 'memory/title' ) ) );
		$this->assertSame( 'apply', $preview[0]['action'] );

		$applied = $this->registered['axle-config-sync/apply-config']['execute_callback']( array( 'all' => true ) );
		$this->assertSame( array( 'memory/title' ), $applied['applied'] );
		$this->assertSame( 'New', $this->memory->store['title'] );
		$this->assertSame( 'ability', $this->registered['axle-config-sync/list-changesets']['execute_callback']( array() )[0]['via'] );

		$undone = $this->registered['axle-config-sync/undo-apply']['execute_callback']( array( 'changeset' => $applied['changeset'] ) );
		$this->assertSame( $applied['changeset'], $undone['undone'] );
		$this->assertSame( 'Old', $this->memory->store['title'] );

		$missing = $this->registered['axle-config-sync/get-item-diff']['execute_callback']( array( 'item' => 'memory/none' ) );
		$this->assertInstanceOf( \WP_Error::class, $missing );
	}
}
