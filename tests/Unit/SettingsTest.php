<?php
/**
 * Tests for the filterable limits, the capability and the messages.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Messages;
use AryanX\Axle\ConfigSync\Settings;
use Brain\Monkey\Filters;

/**
 * Covers Settings and Messages.
 */
final class SettingsTest extends SyncTestCase {

	public function test_capability_filter_with_a_fallback(): void {
		$this->assertSame( 'manage_options', Settings::capability() );

		Filters\expectApplied( 'axle_config_sync_capability' )->once()->with( 'manage_options' )->andReturn( 'manage_network' );
		$this->assertSame( 'manage_network', Settings::capability() );

		Filters\expectApplied( 'axle_config_sync_capability' )->once()->andReturn( '' );
		$this->assertSame( 'manage_options', Settings::capability() );
	}

	public function test_retention_filters_are_clamped(): void {
		$this->assertSame( 30, Settings::retention_days() );
		$this->assertSame( 50, Settings::retention_count() );

		Filters\expectApplied( 'axle_config_sync_retention_days' )->once()->with( 30 )->andReturn( -5 );
		$this->assertSame( 1, Settings::retention_days() );

		Filters\expectApplied( 'axle_config_sync_retention_count' )->once()->with( 50 )->andReturn( 999999 );
		$this->assertSame( 10000, Settings::retention_count() );
	}

	public function test_batch_size_filter_is_clamped(): void {
		$this->assertSame( 200, Settings::batch_size() );

		Filters\expectApplied( 'axle_config_sync_apply_batch_size' )->once()->with( 200 )->andReturn( 0 );
		$this->assertSame( 1, Settings::batch_size() );
	}

	public function test_messages_filter_rewords_and_keeps_defaults_for_bad_values(): void {
		Filters\expectApplied( 'axle_config_sync_messages' )->once()->andReturnUsing(
			static function ( array $messages ) {
				$messages['frozen']   = 'Frozen, try later.';
				$messages['conflict'] = '';
				return $messages;
			}
		);

		$this->assertSame( 'Frozen, try later.', Messages::get( 'frozen' ) );
		$this->assertStringContainsString( 'options/a', Messages::get( 'conflict', 'options/a' ) );
		$this->assertSame( 'unknown_key', Messages::get( 'unknown_key' ) );
	}
}
