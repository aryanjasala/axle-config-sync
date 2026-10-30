<?php
/**
 * Tests for dangerous changes and the providers' own rules.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Danger;
use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Providers\Options;
use AryanX\Axle\ConfigSync\Providers\Roles;
use AryanX\Axle\ConfigSync\Registry;
use AryanX\Axle\ConfigSync\Rules;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Danger, Roles::danger and the option lists.
 */
final class DangerTest extends SyncTestCase {

	/**
	 * Options provider.
	 *
	 * @return Options
	 */
	private function options(): Options {
		Filters\expectApplied( 'axle_config_sync_directory' )->zeroOrMoreTimes()->andReturn( $this->dir );
		return new Options( new Rules( new Directory() ) );
	}

	public function test_listed_options_are_dangerous_when_they_change(): void {
		$options = $this->options();

		$this->assertNotSame( '', Danger::reason( $options, 'options/default_role', 'subscriber', 'administrator' ) );
		$this->assertNotSame( '', Danger::reason( $options, 'options/users_can_register', '0', '1' ) );
		$this->assertSame( '', Danger::reason( $options, 'options/default_role', 'subscriber', 'subscriber' ) );
		$this->assertSame( '', Danger::reason( $options, 'options/blogname', 'A', 'B' ) );
	}

	public function test_hiding_production_from_search_engines_is_dangerous_only_on_production(): void {
		$options = $this->options();

		$this->assertNotSame( '', Danger::reason( $options, 'options/blog_public', '1', '0' ) );
		$this->assertSame( '', Danger::reason( $options, 'options/blog_public', '0', '1' ) );

		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );
		$this->assertSame( '', Danger::reason( $options, 'options/blog_public', '1', '0' ) );
	}

	public function test_roles_gaining_powerful_capabilities(): void {
		$roles = new Roles();
		$old   = array( 'capabilities' => array( 'read' => true ) );

		$this->assertSame( 'Role gains manage_options.', Danger::reason( $roles, 'roles/editor', $old, array( 'capabilities' => array( 'read' => true, 'manage_options' => true ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( '', Danger::reason( $roles, 'roles/editor', $old, array( 'capabilities' => array( 'read' => true, 'edit_posts' => true ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNotSame( '', Danger::reason( $roles, 'roles/editor', $old, null ), 'Removing a role is dangerous.' );

		Filters\expectApplied( 'axle_config_sync_dangerous_capabilities' )->once()->andReturn( array( 'edit_posts' ) );
		$this->assertSame( 'Role gains edit_posts.', $roles->danger( 'roles/editor', $old, array( 'capabilities' => array( 'edit_posts' => true ) ) ) );
	}

	public function test_never_sync_keeps_salts_blocked_after_the_filter(): void {
		Filters\expectApplied( 'axle_config_sync_never_sync' )->atLeast()->once()->andReturn( array() );

		$this->assertFalse( Options::allowed( 'auth_salt' ) );
		$this->assertFalse( Options::allowed( 'home' ) );
		$this->assertFalse( Options::allowed( 'axle_config_sync_state' ) );
		$this->assertTrue( Options::allowed( 'cron' ), 'The filter emptied the rest of the list.' );
		$this->assertFalse( Options::allowed( 'theme_mods_twentytwentyfive' ), 'Other providers own it.' );
	}

	public function test_ignored_options_filter(): void {
		$this->assertContains( '*_version', Options::ignored() );

		Filters\expectApplied( 'axle_config_sync_ignored_options' )->once()->andReturn( array( 'only_this' ) );
		$this->assertSame( array( 'only_this' ), Options::ignored() );
	}

	public function test_the_providers_filter_adds_and_drops_providers(): void {
		Filters\expectApplied( 'axle_config_sync_directory' )->zeroOrMoreTimes()->andReturn( $this->dir );
		$memory = new MemoryProvider();
		Filters\expectApplied( 'axle_config_sync_providers' )->once()->andReturnUsing(
			static function ( array $providers ) use ( $memory ) {
				unset( $providers['roles'] );
				$providers['memory'] = $memory;
				$providers['junk']   = 'not a provider';
				return $providers;
			}
		);

		$registry = new Registry( new Rules( new Directory() ) );

		$this->assertSame( array( 'options', 'theme-mods', 'widgets', 'menus', 'navigation', 'memory' ), array_keys( $registry->all() ) );
		$this->assertSame( $memory, $registry->for_item( 'memory/x' ) );
		$this->assertSame( 'menu-locations', $registry->id_for_file( 'menu-locations.json' ) );
		$this->assertSame( 'options/blogname', $registry->id_for_file( 'options/blogname.json' ) );
		$this->assertNull( $registry->id_for_file( 'manifest.json' ) );
	}

	public function test_apply_order_puts_references_after_their_targets(): void {
		Filters\expectApplied( 'axle_config_sync_directory' )->zeroOrMoreTimes()->andReturn( $this->dir );
		$registry = new Registry( new Rules( new Directory() ) );

		$this->assertSame( array( 'roles/x', 'options/a', 'menus/m', 'menu-locations', 'navigation/n', 'widgets/w' ), $registry->sort( array( 'widgets/w', 'menu-locations', 'navigation/n', 'menus/m', 'options/a', 'roles/x' ) ) );
	}
}
