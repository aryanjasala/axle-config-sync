<?php
/**
 * Tests for the config directory.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Directory;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Directory.
 */
final class DirectoryTest extends SyncTestCase {

	public function test_the_default_is_inside_wp_content_and_gets_deny_rules(): void {
		$directory = new Directory();

		$this->assertSame( WP_CONTENT_DIR . '/axle-config', $directory->path() );
		$this->assertTrue( $directory->inside_web_root() );
		$this->assertSame( "location ^~ /wp-content/axle-config/ {\n\tdeny all;\n\treturn 404;\n}", $directory->nginx_rule() );
	}

	public function test_the_directory_filter_and_deny_files(): void {
		$inside = WP_CONTENT_DIR . '/acs-' . uniqid();
		Filters\expectApplied( 'axle_config_sync_directory' )->atLeast()->once()->with( WP_CONTENT_DIR . '/axle-config' )->andReturn( $inside );
		$directory = new Directory();

		$this->assertTrue( $directory->ensure() );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $inside . '/.htaccess' ) );
		$this->assertFileExists( $inside . '/index.php' );

		$this->assertTrue( $directory->write( 'options/a.json', '{}' ) );
		$this->assertSame( array( 'options/a.json' ), $directory->files() );
		$this->assertTrue( $directory->delete( 'options/a.json' ) );
		$this->remove( $inside );
	}

	public function test_outside_the_web_root_needs_no_deny_files(): void {
		Filters\expectApplied( 'axle_config_sync_directory' )->atLeast()->once()->andReturn( $this->dir );
		$directory = new Directory();

		$this->assertFalse( $directory->inside_web_root() );
		$this->assertSame( 'outside', $directory->reachability() );
		$directory->ensure();
		$this->assertFileDoesNotExist( $this->dir . '/.htaccess' );
	}

	public function test_multisite_sites_get_their_own_folder_and_the_filter_names_it(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );

		$this->assertSame( WP_CONTENT_DIR . '/axle-config/sites/3', ( new Directory() )->path() );

		Filters\expectApplied( 'axle_config_sync_site_directory' )->once()->with( 'sites/3', 3 )->andReturn( 'shop' );
		$this->assertSame( WP_CONTENT_DIR . '/axle-config/shop', ( new Directory() )->path() );
	}

	public function test_reachability_requests_the_probe_url_from_the_filter(): void {
		$inside = WP_CONTENT_DIR . '/acs-' . uniqid();
		mkdir( $inside, 0777, true );
		Filters\expectApplied( 'axle_config_sync_directory' )->atLeast()->once()->andReturn( $inside );
		Functions\when( 'wp_generate_password' )->justReturn( 'token123' );
		Functions\when( 'delete_transient' )->justReturn( true );
		Filters\expectApplied( 'axle_config_sync_probe_url' )->once()->with( 'https://example.test/wp-content/' . basename( $inside ) . '/reachability-check.json' )->andReturn( 'http://localhost/probe' );
		Functions\expect( 'wp_remote_get' )->once()->with( 'http://localhost/probe', \Mockery::type( 'array' ) )->andReturn( array( 'body' => 'token123' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'token123' );

		$this->assertSame( 'reachable', ( new Directory() )->reachability( true ) );
		$this->assertFileDoesNotExist( $inside . '/reachability-check.json', 'The probe file is removed again.' );
		$this->remove( $inside );
	}

	public function test_the_nginx_rule_on_multisite_covers_the_base_folder_not_one_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );

		$rule = ( new Directory() )->nginx_rule();

		$this->assertStringContainsString( 'location ^~ /wp-content/axle-config/ {', $rule );
		$this->assertStringNotContainsString( 'sites/3', $rule );
	}

	public function test_nginx_is_detected_from_the_server_software_and_ignores_htaccess(): void {
		unset( $_SERVER['SERVER_SOFTWARE'] );
		Functions\when( 'wp_unslash' )->returnArg();
		$this->assertSame( 'unknown', Directory::server() );
		$this->assertFalse( Directory::ignores_htaccess() );

		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27.2';
		$this->assertSame( 'nginx', Directory::server() );
		$this->assertTrue( Directory::ignores_htaccess() );

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.62 (Debian)';
		$this->assertSame( 'apache', Directory::server() );
		$this->assertFalse( Directory::ignores_htaccess() );

		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$this->assertSame( 'litespeed', Directory::server() );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	public function test_the_web_server_can_be_named_with_a_filter_for_hosts_behind_a_proxy(): void {
		unset( $_SERVER['SERVER_SOFTWARE'] );
		Functions\when( 'wp_unslash' )->returnArg();
		Filters\expectApplied( 'axle_config_sync_web_server' )->once()->with( 'unknown' )->andReturn( 'nginx' );
		$this->assertTrue( Directory::ignores_htaccess() );

		Filters\expectApplied( 'axle_config_sync_web_server' )->once()->andReturn( 'caddy' );
		$this->assertSame( 'unknown', Directory::server() );

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
		Filters\expectApplied( 'axle_config_sync_web_server' )->once()->with( 'apache' )->andReturn( 'nginx' );
		$this->assertSame( 'nginx', Directory::server(), 'The filter can correct a wrong guess.' );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}
}
