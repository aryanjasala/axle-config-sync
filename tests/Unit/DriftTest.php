<?php
/**
 * Tests for the drift watch.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Drift.
 */
final class DriftTest extends SyncTestCase {

	/**
	 * The provider.
	 *
	 * @var MemoryProvider
	 */
	private MemoryProvider $memory;

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * The drift watch.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Actions seen, by name.
	 *
	 * @var array<string, array[]>
	 */
	private array $actions = array();

	protected function setUp(): void {
		parent::setUp();
		$this->memory        = new MemoryProvider();
		$this->memory->store = array(
			'title' => 'My site',
			'count' => 10,
		);
		$this->engine        = $this->engine( $this->memory );
		$this->drift         = new Drift( $this->engine );

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				return 7 === (int) $id ? (object) array( 'display_name' => 'Maria' ) : false;
			}
		);
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		foreach ( array( 'axle_config_sync_drift_check_started', 'axle_config_sync_drift_checked', 'axle_config_sync_drift_detected', 'axle_config_sync_drift_notified', 'axle_config_sync_drift_kept', 'axle_config_sync_drift_expected_changed' ) as $action ) {
			Actions\expectDone( $action )->zeroOrMoreTimes()->whenHappen(
				function ( ...$args ) use ( $action ) {
					$this->actions[ $action ][] = $args;
				}
			);
		}
		$this->actions = array();
	}

	/**
	 * Exports, then changes the site, so the items drifted.
	 */
	private function drifted(): void {
		$this->engine->export();
		$this->memory->store['title'] = 'Edited live';
		$this->engine->flush();
	}

	public function test_only_site_side_changes_after_a_sync_count_as_drift(): void {
		$this->drifted();
		$this->put(
			'memory/count.json',
			array(
				'id'    => 'memory/count',
				'value' => 99,
			)
		);
		$this->engine->flush();

		$items = $this->drift->items();

		$this->assertSame( array( 'memory/title' ), array_keys( $items ), 'A file changed in git is not drift.' );
		$this->assertSame( 'changed_on_site', $items['memory/title']['state'] );
		$this->assertSame( 'My site', $items['memory/title']['before'] );
		$this->assertSame( 'Edited live', $items['memory/title']['after'] );
	}

	public function test_expected_items_and_patterns_are_never_reported(): void {
		$this->drifted();

		$this->drift->expect( array( 'memory/*' ) );

		$this->assertSame( array(), $this->drift->items() );
		$this->assertSame( array( 'memory/*' ), $this->drift->settings()['expected'] );
		$this->assertSame( array( array( array( 'memory/*' ) ) ), $this->actions['axle_config_sync_drift_expected_changed'] );

		$this->drift->unexpect( array( 'memory/*' ) );
		$this->assertArrayHasKey( 'memory/title', $this->drift->items() );
	}

	public function test_expected_list_is_filterable(): void {
		$this->drifted();
		Filters\expectApplied( 'axle_config_sync_drift_expected' )->once()->with( array() )->andReturn( array( 'memory/title', '<b>bad</b>' ) );

		$this->assertSame( array(), $this->drift->items() );
	}

	public function test_states_that_count_as_drift_are_filterable(): void {
		$this->drifted();
		Filters\expectApplied( 'axle_config_sync_drift_states' )->once()->andReturn( array( 'in_sync' ) );
		$this->assertSame( array( 'memory/count' ), array_keys( $this->drift->items() ) );

		Filters\expectApplied( 'axle_config_sync_drift_states' )->once()->andReturn( array( 'not-a-state' ) );
		$this->assertSame( array( 'memory/title' ), array_keys( $this->drift->items() ), 'Nothing valid falls back to the default states.' );
	}

	public function test_a_check_reports_new_drift_once_and_again_when_the_value_changes(): void {
		$this->drifted();

		$first = $this->drift->check( 'cron' );
		$this->assertSame( array( 'memory/title' ), array_keys( $first['new'] ) );
		$this->assertCount( 1, $this->actions['axle_config_sync_drift_detected'] );
		$this->assertSame( 1, $this->drift->count() );
		$this->assertNotSame( '', $this->drift->fingerprint() );

		$second = $this->drift->check( 'cron' );
		$this->assertSame( array(), $second['new'], 'Already seen in the last check.' );
		$this->assertCount( 1, $this->actions['axle_config_sync_drift_detected'] );
		$this->assertSame( 'cron', $this->drift->state()['via'] );

		$this->memory->store['title'] = 'Edited again';
		$this->engine->flush();
		$third = $this->drift->check( 'cron' );
		$this->assertSame( array( 'memory/title' ), array_keys( $third['new'] ) );
		$this->assertCount( 2, $this->actions['axle_config_sync_drift_detected'] );
	}

	public function test_nothing_is_sent_unless_email_or_webhook_is_on(): void {
		$this->drifted();
		Functions\expect( 'wp_mail' )->never();
		Functions\expect( 'wp_safe_remote_post' )->never();

		$this->drift->check( 'cron' );

		$this->assertArrayNotHasKey( 'axle_config_sync_drift_notified', $this->actions );
	}

	public function test_email_and_webhook_carry_the_change_and_who_made_it(): void {
		$this->drifted();
		$this->drift->save_settings(
			array(
				'email'    => true,
				'email_to' => 'ops@example.com',
				'webhook'  => 'https://hooks.example.com/abc',
			)
		);
		$this->options['admin_email'] = 'admin@example.com';
		$this->options['blogname']    = 'Bean There';
		$this->actions                = array();

		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $message ) use ( &$sent ) {
				$sent['mail'] = array( $to, $subject, $message );
				return true;
			}
		);
		Functions\when( 'wp_safe_remote_post' )->alias(
			static function ( $url, $args ) use ( &$sent ) {
				$sent['hook'] = array( $url, json_decode( $args['body'], true ) );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		// A change by user 7 recorded after the sync.
		$this->options[ Drift::ACTORS ] = array(
			'memory/title' => array(
				'user' => 7,
				'at'   => time() + 5,
				'via'  => 'admin',
			),
		);

		$this->drift->check( 'cron' );

		$this->assertSame( 'ops@example.com', $sent['mail'][0] );
		$this->assertStringContainsString( 'Files: My site', $sent['mail'][2] );
		$this->assertStringContainsString( 'Site: Edited live', $sent['mail'][2] );
		$this->assertStringContainsString( 'Changed by: Maria', $sent['mail'][2] );
		$this->assertSame( 'https://hooks.example.com/abc', $sent['hook'][0] );
		$this->assertSame( 'drift_detected', $sent['hook'][1]['event'] );
		$this->assertSame( 'memory/title', $sent['hook'][1]['items'][0]['id'] );
		$this->assertSame( 'Maria', $sent['hook'][1]['items'][0]['by'] );
		$this->assertCount( 2, $this->actions['axle_config_sync_drift_notified'] );
		$this->assertSame( array(), $this->drift->state()['errors'] );
	}

	public function test_a_failed_webhook_is_remembered_for_the_screen(): void {
		$this->drifted();
		$this->drift->save_settings( array( 'webhook' => 'https://hooks.example.com/abc' ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( new \WP_Error( 'http', 'Could not resolve host.' ) );

		$this->drift->check( 'cron' );

		$this->assertSame( array( 'webhook' => 'Could not resolve host.' ), $this->drift->state()['errors'] );
	}

	public function test_settings_keep_only_a_public_webhook_and_a_real_address(): void {
		$this->drift->save_settings(
			array(
				'webhook'  => 'http://localhost/hook',
				'email_to' => 'not an address',
			)
		);
		$this->assertSame( '', $this->drift->settings()['webhook'] );
		$this->assertSame( '', $this->drift->settings()['email_to'] );
		$this->assertFalse( $this->drift->settings()['email'], 'Off by default.' );

		$this->drift->save_settings( array( 'webhook' => 'https://hooks.example.com/abc' ) );
		$this->assertSame( 'https://hooks.example.com/abc', $this->drift->settings()['webhook'] );
	}

	public function test_tracked_option_changes_record_who_and_skip_everything_else(): void {
		$this->drift->record( 'blogname' );
		$this->drift->record( '_transient_feed_abc' );
		$this->drift->record( 'some_unknown_option' );
		$this->drift->record( 'siteurl' );
		$this->drift->record( 'axle_config_sync_state' );

		$this->assertSame( array( 'options/blogname' ), array_keys( $this->options[ Drift::ACTORS ] ) );
		$this->assertSame( 1, $this->options[ Drift::ACTORS ]['options/blogname']['user'] );
		$this->assertSame( 'admin', $this->options[ Drift::ACTORS ]['options/blogname']['via'] );
	}

	public function test_plugin_options_match_the_tracking_rules_and_providers_use_wildcards(): void {
		$this->options[ \AryanX\Axle\ConfigSync\Settings::OPTION ] = array(
			'rules' => array( 'options' => array( 'acme_*' ) ),
		);
		$this->engine->rules()->flush();

		$this->drift->record( 'acme_settings' );
		$this->drift->record( 'theme_mods_twentytwentyfive' );
		$this->drift->record( 'widget_text' );

		$this->assertEqualsCanonicalizing( array( 'options/acme_settings', 'theme-mods/twentytwentyfive', 'widgets/*' ), array_keys( $this->options[ Drift::ACTORS ] ) );
	}

	public function test_the_plugins_own_writes_are_not_someone_elses_change(): void {
		Drift::silently(
			function (): void {
				$this->drift->record( 'blogname' );
				Drift::silently(
					function (): void {
						$this->drift->record( 'blogdescription' );
					}
				);
				$this->drift->record( 'posts_per_page' );
			}
		);
		$this->assertArrayNotHasKey( Drift::ACTORS, $this->options );

		$this->drift->record( 'blogname' );
		$this->assertArrayHasKey( 'options/blogname', $this->options[ Drift::ACTORS ] );
	}

	public function test_who_ignores_changes_from_before_the_last_sync_and_the_record_limit_holds(): void {
		$this->options[ Drift::ACTORS ] = array(
			'options/old' => array(
				'user' => 7,
				'at'   => 1000,
				'via'  => 'admin',
			),
		);

		$this->assertNull( $this->drift->who( 'options/old', 2000 ) );
		$this->assertSame( 'Maria', $this->drift->who( 'options/old', 500 )['name'] );
		$this->assertSame( 'Maria', Drift::who_label( $this->drift->who( 'options/old' ) ) );
		$this->assertSame( 'WP-CLI', Drift::who_label(
			array(
				'name' => '',
				'via'  => 'cli',
			)
		) );
		$this->assertSame( '', Drift::who_label( null ) );

	}

	public function test_the_record_limit_keeps_the_newest_and_has_a_floor(): void {
		$actors = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$actors[ 'options/item_' . $i ] = array(
				'user' => 7,
				'at'   => 1000 + $i,
				'via'  => 'admin',
			);
		}
		$this->options[ Drift::ACTORS ] = $actors;
		Filters\expectApplied( 'axle_config_sync_drift_actor_limit' )->once()->andReturn( 1 );

		$this->drift->record( 'blogname' );

		$kept = $this->options[ Drift::ACTORS ];
		$this->assertCount( 10, $kept, 'The floor is 10.' );
		$this->assertArrayHasKey( 'options/blogname', $kept );
		$this->assertArrayNotHasKey( 'options/item_1', $kept );
		$this->assertArrayHasKey( 'options/item_12', $kept );
	}

	public function test_the_actor_can_be_replaced_with_a_filter(): void {
		Filters\expectApplied( 'axle_config_sync_drift_actor' )->once()->andReturn(
			array(
				'user_id' => 0,
				'name'    => 'Deploy bot',
				'at'      => 5,
				'via'     => 'cli',
				'source'  => 'custom',
			)
		);

		$this->assertSame( 'Deploy bot', Drift::who_label( $this->drift->who( 'options/blogname' ) ) );
	}

	public function test_keep_writes_the_live_value_to_the_file_and_clears_the_drift(): void {
		$this->drifted();

		$result = $this->drift->keep( array( 'memory/title' ) );

		$this->assertSame( array( 'memory/title' => 'memory/title.json' ), $result['written'] );
		$this->assertSame( 'Edited live', $this->read( 'memory/title.json' )['value'] );
		$this->assertSame( array(), $this->drift->items() );
		$this->assertSame( array( array( array( 'memory/title' ) ) ), $this->actions['axle_config_sync_drift_kept'] );
	}

	public function test_the_engine_does_not_record_its_own_apply_as_a_change(): void {
		$this->put(
			'memory/title.json',
			array(
				'id'    => 'memory/title',
				'value' => 'From git',
			)
		);
		$this->engine->flush();
		$recorded = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$recorded ) {
				$this->options[ $name ] = $value;
				$recorded[]             = $name;
				return true;
			}
		);
		$this->memory->onWrite = function () {
			$this->drift->record( 'blogname' );
		};

		$this->engine->apply( array( 'memory/title' ), array( 'via' => 'cli' ) );

		$this->assertArrayNotHasKey( Drift::ACTORS, $this->options );
	}

	public function test_summaries_are_short_and_the_length_is_filterable(): void {
		$this->assertSame( '', Drift::summarize( null ) );
		$this->assertSame( 'abc', Drift::summarize( 'abc' ) );
		$this->assertSame( '{"a":1}', Drift::summarize( array( 'a' => 1 ) ) );
		$this->assertSame( 203, strlen( Drift::summarize( str_repeat( 'x', 500 ) ) ) );

		Filters\expectApplied( 'axle_config_sync_drift_value_length' )->once()->andReturn( 1 );
		$this->assertSame( 23, strlen( Drift::summarize( str_repeat( 'x', 50 ) ) ), 'Never shorter than 20 characters.' );
	}

	public function test_privacy_helpers_find_and_clear_a_users_records(): void {
		$this->options[ Drift::ACTORS ] = array(
			'options/a' => array(
				'user' => 7,
				'at'   => 100,
				'via'  => 'admin',
			),
			'options/b' => array(
				'user' => 8,
				'at'   => 100,
				'via'  => 'admin',
			),
		);

		$this->assertSame( array( 'options/a' ), array_keys( $this->drift->records_for_user( 7 ) ) );
		$this->assertSame( 1, $this->drift->forget_user( 7 ) );
		$this->assertSame( 0, $this->options[ Drift::ACTORS ]['options/a']['user'] );
		$this->assertSame( 8, $this->options[ Drift::ACTORS ]['options/b']['user'] );
		$this->assertSame( 0, $this->drift->forget_user( 7 ) );
	}

	public function test_old_records_of_settled_items_are_dropped_by_a_check(): void {
		$this->options[ Drift::ACTORS ] = array(
			'options/gone'  => array(
				'user' => 7,
				'at'   => 1000,
				'via'  => 'admin',
			),
			'options/fresh' => array(
				'user' => 7,
				'at'   => time(),
				'via'  => 'admin',
			),
		);

		$this->drift->check( 'cron' );

		$this->assertSame( array( 'options/fresh' ), array_keys( $this->options[ Drift::ACTORS ] ) );
		$this->assertSame( 30, Settings::retention_days() );
	}
}
