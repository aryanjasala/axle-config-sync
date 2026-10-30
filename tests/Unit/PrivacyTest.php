<?php
/**
 * Tests for the privacy exporter, eraser and the daily purge.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Changesets;
use AryanX\Axle\ConfigSync\Privacy;
use AryanX\Axle\ConfigSync\Schema;
use AryanX\Axle\ConfigSync\State;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Privacy and Changesets retention.
 */
final class PrivacyTest extends SyncTestCase {

	public function test_export_lists_change_sets_and_erase_keeps_them_without_the_user(): void {
		$changesets = new Changesets();
		$state      = new State();
		$changesets->create( array( 'options/blogname' => array() ), 'admin' );
		$state->synced(
			array(
				'options/blogname' => array(
					'file' => 'a',
					'site' => 'b',
				),
			),
			'apply',
			1
		);
		$privacy = new Privacy( $changesets, $state );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 1 ) );

		$GLOBALS['wpdb']->rows[1]['created_gmt'] = '2026-10-30 10:00:00';
		$this->assertArrayHasKey( 'axle-config-sync', $privacy->add_exporter( array() ) );
		$this->assertArrayHasKey( 'axle-config-sync', $privacy->add_eraser( array() ) );

		$export = $privacy->export( 'admin@example.com' );
		$this->assertTrue( $export['done'] );
		$this->assertSame( 'axle-config-sync-changeset-1', $export['data'][0]['item_id'] );

		$erased = $privacy->erase( 'admin@example.com' );
		$this->assertTrue( $erased['items_retained'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->rows[1]['user_id'] );
		$this->assertSame( 0, $state->all()['items']['options/blogname']['by'] );
		$this->assertSame( 0, $state->all()['last_apply']['by'] );
	}

	public function test_policy_text_uses_the_retention_filter(): void {
		Filters\expectApplied( 'axle_config_sync_retention_days' )->once()->andReturn( 7 );
		Functions\expect( 'wp_add_privacy_policy_content' )->once()->with( 'Axle Config Sync', \Mockery::pattern( '/deleted after 7 days/' ) );

		( new Privacy( new Changesets(), new State() ) )->policy_text();
	}

	public function test_purge_runs_daily_with_both_retention_limits(): void {
		$changesets = new Changesets();
		$changesets->register();
		$this->assertSame( 10, has_action( Schema::CRON_HOOK, array( $changesets, 'run_purge' ) ) );

		Filters\expectApplied( 'axle_config_sync_retention_days' )->once()->andReturn( 30 );
		Filters\expectApplied( 'axle_config_sync_retention_count' )->once()->andReturn( 50 );
		$changesets->run_purge();
	}
}
