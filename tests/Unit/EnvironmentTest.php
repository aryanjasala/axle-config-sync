<?php
/**
 * Tests for the environment and its values.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Environment;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Environment.
 */
final class EnvironmentTest extends SyncTestCase {

	public function test_it_follows_wp_get_environment_type(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );

		$env = Environment::detect();
		$this->assertSame( 'staging', $env['name'] );
		$this->assertSame( 'env/staging.json', Environment::file() );
	}

	public function test_the_environment_filter_wins(): void {
		Filters\expectApplied( 'axle_config_sync_environment' )->once()->with( 'production', 'wp_get_environment_type()' )->andReturn( 'Prod-EU' );

		$this->assertSame(
			array(
				'name'   => 'prod-eu',
				'source' => 'axle_config_sync_environment',
			),
			Environment::detect()
		);
	}

	public function test_environment_file_values_apply_and_are_kept_out_of_exports(): void {
		Filters\expectApplied( 'axle_config_sync_directory' )->zeroOrMoreTimes()->andReturn( $this->dir );
		$this->put(
			'env/production.json',
			array(
				'schema' => 1,
				'values' => array(
					'options/admin_email' => array( '' => 'ops@example.com' ),
					'options/acme'        => array( '/mode' => 'live' ),
				),
			)
		);
		$env = new Environment( new Directory() );

		$this->assertSame( 'ops@example.com', $env->apply( 'options/admin_email', 'dev@example.com' ) );
		$this->assertSame(
			array(
				'mode' => 'live',
				'x'    => 1,
			),
			$env->apply(
				'options/acme',
				array(
					'mode' => 'test',
					'x'    => 1,
				)
			)
		);
		$this->assertSame( 'shared', $env->apply( 'options/blogname', 'shared' ) );

		$this->assertSame( 'from file', $env->protect( 'options/admin_email', 'ops@example.com', 'from file', true ), 'Export keeps what the shared file had.' );
		$this->assertSame( 'ops@example.com', $env->protect( 'options/admin_email', 'ops@example.com', null, false ) );
	}
}
