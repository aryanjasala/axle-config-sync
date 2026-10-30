<?php
/**
 * Tests for tracking rules.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Rules;
use AryanX\Axle\ConfigSync\Settings;
use Brain\Monkey\Filters;

/**
 * Covers Rules.
 */
final class RulesTest extends SyncTestCase {

	/**
	 * Rules over the temp directory.
	 *
	 * @return Rules
	 */
	private function rules(): Rules {
		Filters\expectApplied( 'axle_config_sync_directory' )->zeroOrMoreTimes()->andReturn( $this->dir );

		return new Rules( new Directory() );
	}

	public function test_defaults_turn_every_provider_on(): void {
		$rules = $this->rules()->all();

		$this->assertTrue( $rules['providers']['options'] );
		$this->assertTrue( $rules['providers']['roles'] );
		$this->assertSame( array(), $rules['options'] );
	}

	public function test_save_writes_the_file_and_a_copy_in_the_option(): void {
		$rules = $this->rules();

		$this->assertTrue(
			$rules->save(
				array(
					'providers'  => array( 'roles' => false ),
					'options'    => array( 'woocommerce_*', 'acme key!' ),
					'owners'     => array( 'woocommerce_*' => 'woocommerce' ),
					'not_secret' => array( 'options/acme#/id' ),
				)
			)
		);

		$file = $this->read( 'config-sync.json' );
		$this->assertSame( 1, $file['schema'] );
		$this->assertFalse( $file['providers']['roles'] );
		$this->assertSame( array( 'acmekey', 'woocommerce_*' ), $file['options'] );
		$this->assertSame( array( 'woocommerce_*' => 'woocommerce' ), $file['owners'] );
		$this->assertSame( $file['options'], $this->options[ Settings::OPTION ]['rules']['options'] );
		$this->assertFalse( $rules->provider_on( 'roles' ) );
	}

	public function test_patterns_and_owners(): void {
		$rules = $this->rules();
		$rules->track( 'acme_*', 'acme' );

		$this->assertTrue( $rules->is_tracked( 'acme_api_key' ) );
		$this->assertFalse( $rules->is_tracked( 'other' ) );
		$this->assertSame( 'acme', $rules->owner_of( 'acme_mode' ) );
		$this->assertTrue( $rules->untrack( 'acme_*' ) );
		$this->assertFalse( $rules->untrack( 'acme_*' ) );
		$this->assertFalse( $rules->is_tracked( 'acme_api_key' ) );
		$this->assertTrue( Rules::matches( 'a.b', 'a.b' ) );
		$this->assertFalse( Rules::matches( 'axb', 'a.b' ), 'A dot is a dot, not any character.' );
	}

	public function test_the_tracked_options_filter_adds_names(): void {
		Filters\expectApplied( 'axle_config_sync_tracked_options' )->once()->with( array() )->andReturn( array( 'my_plugin_*', '' ) );

		$this->assertSame( array( 'my_plugin_*' ), $this->rules()->tracked() );
	}

	public function test_not_a_secret_marks_for_items_and_pointers(): void {
		$rules = $this->rules();
		$rules->mark_not_secret( 'options/x#/id' );

		$this->assertTrue( $rules->is_not_secret( 'options/x', '/id' ) );
		$this->assertFalse( $rules->is_not_secret( 'options/x', '/other' ) );
		$this->assertTrue( $rules->is_not_secret( 'options/disallowed_keys', '' ) );
	}
}
