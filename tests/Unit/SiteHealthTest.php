<?php
/**
 * Tests for the Site Health tests.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Lock;
use AryanX\Axle\ConfigSync\Health\SiteHealth;
use Brain\Monkey\Functions;

/**
 * Covers SiteHealth, the drift test.
 */
final class SiteHealthTest extends SyncTestCase {

	protected function setUp(): void {
		parent::setUp();
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
	}

	/**
	 * The tests, over the current temporary directory.
	 *
	 * @return SiteHealth
	 */
	private function health(): SiteHealth {
		$engine = $this->engine( new MemoryProvider() );

		return new SiteHealth( $engine, new Drift( $engine ) );
	}

	/**
	 * Health tests with a lock over a directory that has a file for blogname.
	 *
	 * @param bool $with_file Whether git owns blogname.
	 * @return SiteHealth
	 */
	private function health_with_lock( bool $with_file = true ): SiteHealth {
		if ( $with_file ) {
			$this->put( 'options/blogname.json', array( 'value' => 'x' ) );
		}
		$provider               = new MemoryProvider();
		$provider->providerName = 'options';
		$engine                 = $this->engine( $provider );

		return new SiteHealth( $engine, new Drift( $engine ), new Lock( $engine ) );
	}

	public function test_the_lock_test_is_good_when_locked_and_recommended_when_the_lock_is_off(): void {
		$this->assertArrayHasKey( 'axle_config_sync_lock', $this->health_with_lock()->add_tests( array() )['direct'] );
		$this->assertSame( 'good', $this->health_with_lock()->test_lock()['status'] );

		$this->options[ Lock::SETTINGS ] = array(
			'enabled' => false,
			'minutes' => 30,
		);
		$this->assertSame( 'recommended', $this->health_with_lock()->test_lock()['status'] );
	}

	public function test_the_lock_test_is_good_when_nothing_is_managed_or_off_production(): void {
		$this->assertSame( 'good', $this->health_with_lock( false )->test_lock()['status'] );

		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );
		$this->assertSame( 'good', $this->health_with_lock()->test_lock()['status'] );
	}

	public function test_the_drift_test_is_registered(): void {
		$tests = $this->health()->add_tests( array() );

		$this->assertArrayHasKey( 'axle_config_sync_drift', $tests['direct'] );
	}

	public function test_no_check_yet_and_no_drift_are_good(): void {
		$result = $this->health()->test_drift();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'has not run yet', $result['description'] );

		$this->options[ Drift::OPTION ] = array(
			'items'      => array(),
			'checked_at' => 1700000000,
		);
		$this->assertStringContainsString( 'as of the last check', $this->health()->test_drift()['description'] );
	}

	public function test_drift_is_recommended_and_lists_the_items(): void {
		$this->options[ Drift::OPTION ] = array(
			'items'      => array(
				'options/blogname' => array(
					'hash'  => 'a',
					'first' => 1,
				),
			),
			'checked_at' => 1700000000,
		);

		$result = $this->health()->test_drift();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'options/blogname', $result['description'] );
		$this->assertStringContainsString( 'tab=drift', $result['actions'] );
	}

	public function test_a_probe_that_did_not_get_through_is_recommended_on_nginx_and_fine_on_apache(): void {
		$this->remove( $this->dir );
		$inside    = WP_CONTENT_DIR . '/acs-health-' . uniqid();
		$this->dir = $inside;
		mkdir( $inside, 0777, true );
		Functions\when( 'wp_generate_password' )->justReturn( 'token123' );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http', 'No route.' ) );
		Functions\when( 'wp_unslash' )->returnArg();
		$health = $this->health();

		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27';
		$nginx                      = $health->test_served();
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
		$apache                     = $health->test_served();
		unset( $_SERVER['SERVER_SOFTWARE'] );

		$this->assertSame( 'recommended', $nginx['status'] );
		$this->assertStringContainsString( 'location ^~ /wp-content/acs-health-', $nginx['description'] );
		$this->assertStringContainsString( 'ignores the .htaccess', $nginx['description'] );
		$this->assertSame( 'good', $apache['status'] );
	}
}
