<?php
/**
 * Tests for "Managed in git, locked on production".
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Lock;
use AryanX\Axle\ConfigSync\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Lock.
 */
final class LockTest extends SyncTestCase {

	/**
	 * Actions this test saw.
	 *
	 * @var array<string, array[]>
	 */
	private array $seen = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_installing' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'maybe_serialize' )->alias(
			static function ( $data ) {
				return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test stand-in for core's maybe_serialize().
			}
		);
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_date' )->justReturn( '14:30' );

		$this->seen = array();
		foreach ( array( 'axle_config_sync_lock_refused', 'axle_config_sync_lock_override_granted' ) as $action ) {
			Actions\expectDone( $action )->zeroOrMoreTimes()->whenHappen(
				function ( ...$args ) use ( $action ) {
					$this->seen[ $action ][] = $args;
				}
			);
		}
	}

	/**
	 * A lock over a config directory with a file for blogname.
	 *
	 * @param bool $with_file Whether options/blogname.json exists.
	 * @return Lock
	 */
	private function lock( bool $with_file = true ): Lock {
		if ( $with_file ) {
			$this->put( 'options/blogname.json', array( 'schema' => 1, 'value' => 'From git' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		}

		$provider               = new MemoryProvider();
		$provider->providerName = 'options';

		return new Lock( $this->engine( $provider ) );
	}

	public function test_it_refuses_a_save_of_a_managed_setting_on_production(): void {
		$lock = $this->lock();

		$this->assertSame( 'Old', $lock->guard( 'New', 'blogname', 'Old' ) );
		$this->assertSame( 'blogname', $this->seen['axle_config_sync_lock_refused'][0][0] );
	}

	public function test_a_setting_without_a_file_is_not_managed(): void {
		$lock = $this->lock( false );

		$this->assertSame( 'New', $lock->guard( 'New', 'blogname', 'Old' ) );
		$this->assertArrayNotHasKey( 'axle_config_sync_lock_refused', $this->seen );
	}

	public function test_this_plugins_own_options_and_private_options_are_never_locked(): void {
		$lock = $this->lock();
		$this->put( 'options/axle_config_sync_state.json', array( 'value' => 1 ) );

		$this->assertSame( 'New', $lock->guard( 'New', 'axle_config_sync_state', 'Old' ) );
		$this->assertSame( 'New', $lock->guard( 'New', '_transient_x', 'Old' ) );
	}

	public function test_a_save_that_changes_nothing_is_not_refused(): void {
		$this->assertSame( 'Same', $this->lock()->guard( 'Same', 'blogname', 'Same' ) );
		$this->assertArrayNotHasKey( 'axle_config_sync_lock_refused', $this->seen );
	}

	public function test_staging_is_not_locked(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );

		$this->assertSame( 'New', $this->lock()->guard( 'New', 'blogname', 'Old' ) );
	}

	public function test_the_shared_environment_filter_decides_production(): void {
		Filters\expectApplied( 'axle_environment_type' )->zeroOrMoreTimes()->andReturn( 'staging' );

		$this->assertSame( 'New', $this->lock()->guard( 'New', 'blogname', 'Old' ) );
	}

	public function test_the_plugins_own_apply_is_never_blocked(): void {
		$lock   = $this->lock();
		$result = null;

		Lock::bypass(
			static function () use ( $lock, &$result ): void {
				$result = $lock->guard( 'New', 'blogname', 'Old' );
			}
		);

		$this->assertSame( 'New', $result );
		$this->assertSame( 'Old', $lock->guard( 'New', 'blogname', 'Old' ), 'The bypass ends with the callback.' );
	}

	public function test_it_never_blocks_cron_or_signed_out_requests(): void {
		$lock = $this->lock();

		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->assertSame( 'New', $lock->guard( 'New', 'blogname', 'Old' ) );

		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertSame( 'New', $lock->guard( 'New', 'blogname', 'Old' ) );
	}

	public function test_it_fails_open_when_the_tracking_rules_cannot_be_read(): void {
		$lock = $this->lock();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( Settings::OPTION === $name ) {
					throw new \RuntimeException( 'rules unreadable' );
				}

				return $default;
			}
		);

		$this->assertSame( 'New', $lock->guard( 'New', 'blogname', 'Old' ) );
	}

	public function test_it_fails_open_when_the_config_directory_is_missing(): void {
		$lock = $this->lock();
		$this->remove( $this->dir );

		$this->assertSame( 'New', $lock->guard( 'New', 'blogname', 'Old' ) );
	}

	public function test_the_switch_and_the_filter_turn_it_off(): void {
		$this->options[ Lock::SETTINGS ] = array(
			'enabled' => false,
			'minutes' => 30,
		);

		$this->assertSame( 'New', $this->lock()->guard( 'New', 'blogname', 'Old' ) );
	}

	public function test_change_it_here_anyway_lets_the_same_person_through_and_is_remembered(): void {
		$lock  = $this->lock();
		$until = $lock->grant( 'blogname' );

		$this->assertIsInt( $until );
		$this->assertGreaterThan( time() + 25 * 60, $until );
		$this->assertSame( 'New', $lock->guard( 'New', 'blogname', 'Old' ) );
		$this->assertSame( 'blogname', $this->seen['axle_config_sync_lock_override_granted'][0][0] );
		$this->assertContains( 'options/blogname', $lock->expected( array() ), 'Expected drift until the next deploy.' );
	}

	public function test_an_override_belongs_to_the_person_who_chose_it(): void {
		$lock = $this->lock();
		$lock->grant( 'blogname' );

		Functions\when( 'get_current_user_id' )->justReturn( 2 );

		$this->assertSame( 'Old', $lock->guard( 'New', 'blogname', 'Old' ) );
	}

	public function test_an_override_ends_on_time(): void {
		$lock                              = $this->lock();
		$this->options[ Lock::OVERRIDES ] = array(
			'blogname' => array(
				'until' => time() - 5,
				'user'  => 1,
				'at'    => time() - 1800,
			),
		);

		$this->assertSame( 'Old', $lock->guard( 'New', 'blogname', 'Old' ) );
	}

	public function test_change_it_here_anyway_needs_the_capability_that_owns_the_setting(): void {
		$lock = $this->lock();
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $lock->grant( 'blogname' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_config_sync_lock_forbidden', $result->get_error_code() );
		$this->assertArrayNotHasKey( Lock::OVERRIDES, $this->options );
	}

	public function test_the_capability_has_a_filter_per_option(): void {
		Filters\expectApplied( 'axle_config_sync_lock_option_capability' )->once()->with( 'manage_options', 'blogname' )->andReturn( 'edit_theme_options' );

		$this->assertSame( 'edit_theme_options', $this->lock()->capability_for( 'blogname' ) );
	}

	public function test_change_it_here_anyway_is_refused_for_a_setting_git_does_not_own(): void {
		$result = $this->lock( false )->grant( 'blogname' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_config_sync_not_managed', $result->get_error_code() );
	}

	public function test_a_deploy_that_puts_the_file_value_back_ends_the_expected_drift(): void {
		$lock = $this->lock();
		$lock->grant( 'blogname' );

		$lock->forget_released( 'options/blogname' );

		$this->assertNotContains( 'options/blogname', $lock->expected( array() ) );
	}

	public function test_the_window_length_is_bounded_and_filterable(): void {
		Filters\expectApplied( 'axle_config_sync_lock_override_minutes' )->once()->andReturn( 100000 );

		$this->assertSame( 1440, $this->lock()->minutes() );
	}

	public function test_it_lists_only_settings_with_files(): void {
		$lock = $this->lock();

		$this->assertSame( array( 'blogname' ), $lock->managed_names() );
		$this->assertTrue( $lock->is_managed( 'blogname' ) );
		$this->assertFalse( $lock->is_managed( 'admin_email' ) );
	}

	public function test_the_override_is_written_to_activity_log_when_it_is_active(): void {
		require_once dirname( __DIR__ ) . '/Stubs/activity-log.php';
		\AryanX\Axle\ActivityLogWithUndo\Logger::$logged = array();

		$this->lock()->grant( 'blogname' );

		$logged = \AryanX\Axle\ActivityLogWithUndo\Logger::$logged;
		$this->assertCount( 1, $logged );
		$this->assertSame( 'config_lock_override', $logged[0]['event'] );
		$this->assertSame( 'blogname', $logged[0]['object_id'] );
		$this->assertFalse( $logged[0]['undoable'] );
	}

	public function test_the_status_lists_what_is_managed_and_what_is_open(): void {
		$lock = $this->lock();
		$lock->grant( 'blogname' );

		$status = $lock->status();

		$this->assertSame( 'production', $status['environment'] );
		$this->assertTrue( $status['locking'] );
		$this->assertSame( array( 'blogname' ), $status['managed'] );
		$this->assertSame( 'blogname', $status['overrides'][0]['option'] );
		$this->assertSame( array( 'options/blogname' ), $status['released'] );
		$this->assertSame( 1, $lock->end_overrides() );
		$this->assertSame( array(), $lock->status()['overrides'] );
	}

	public function test_the_eraser_clears_the_person_but_keeps_the_record(): void {
		$lock = $this->lock();
		$lock->grant( 'blogname' );

		$this->assertSame( array( 'blogname' ), array_keys( $lock->records_for_user( 1 ) ) );
		$this->assertSame( 2, $lock->forget_user( 1 ) );
		$this->assertSame( array(), $lock->records_for_user( 1 ) );
		$this->assertArrayHasKey( 'blogname', $lock->overrides() );
	}
}
