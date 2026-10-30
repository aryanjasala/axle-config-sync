<?php
/**
 * User roles and their capabilities.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Providers;

use AryanX\Axle\ConfigSync\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Each role goes to roles/<role>.json with the display name and the capabilities, sorted.
 * A role gaining a capability that hands over the site is a dangerous change.
 */
final class Roles extends Base {

	/**
	 * Short name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'roles';
	}

	/**
	 * Name for people.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Roles', 'axle-config-sync' );
	}

	/**
	 * One item per role.
	 *
	 * @return string[]
	 */
	public function items(): array {
		$ids = array();
		foreach ( array_keys( wp_roles()->roles ) as $role ) {
			$ids[] = 'roles/' . $role;
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * Whether the role exists.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function exists( string $id ): bool {
		return isset( wp_roles()->roles[ $this->key( $id ) ] );
	}

	/**
	 * Name and capabilities.
	 *
	 * @param string $id Item ID.
	 * @return array|null
	 */
	public function read( string $id ) {
		$role = wp_roles()->roles[ $this->key( $id ) ] ?? null;
		if ( ! is_array( $role ) ) {
			return null;
		}

		$caps = is_array( $role['capabilities'] ?? null ) ? $role['capabilities'] : array();
		ksort( $caps );

		return array(
			'name'         => (string) ( $role['name'] ?? $this->key( $id ) ),
			'capabilities' => $caps,
		);
	}

	/**
	 * Saves the role through WP_Roles, or removes it.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Role, or null to remove it.
	 */
	public function write( string $id, $value ): void {
		$role  = $this->key( $id );
		$roles = wp_roles();

		if ( null === $value ) {
			remove_role( $role );
			return;
		}

		$value = is_array( $value ) ? $value : array();
		$name  = (string) ( $value['name'] ?? $role );
		$caps  = array();
		foreach ( (array) ( $value['capabilities'] ?? array() ) as $cap => $grant ) {
			$caps[ (string) $cap ] = (bool) $grant;
		}

		if ( ! isset( $roles->roles[ $role ] ) ) {
			add_role( $role, $name, $caps ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Runs only when an admin applies a role from the config files.
			return;
		}

		$roles->roles[ $role ] = array(
			'name'         => $name,
			'capabilities' => $caps,
		);
		update_option( $roles->role_key, $roles->roles );
		$roles->for_site();
	}

	/**
	 * The role's display name.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function label( string $id ): string {
		$role = wp_roles()->roles[ $this->key( $id ) ] ?? null;

		return is_array( $role ) && isset( $role['name'] ) ? translate_user_role( (string) $role['name'] ) : $this->key( $id );
	}

	/**
	 * Capabilities that hand over the site.
	 *
	 * @return string[]
	 */
	public static function dangerous_capabilities(): array {
		/**
		 * Filters the capabilities that make an apply dangerous when a role gains them.
		 *
		 * @param string[] $caps Default manage_options, promote_users, edit_users, unfiltered_html, activate_plugins, install_plugins.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'axle_config_sync_dangerous_capabilities', array( 'manage_options', 'promote_users', 'edit_users', 'unfiltered_html', 'activate_plugins', 'install_plugins' ) ) ) );
	}

	/**
	 * A role gaining a dangerous capability, or a role being removed.
	 *
	 * @param string $id     Item ID.
	 * @param mixed  $before Live role.
	 * @param mixed  $after  New role.
	 * @return string
	 */
	public function danger( string $id, $before, $after ): string {
		if ( null === $after ) {
			return null === $before ? '' : Messages::get( 'dangerous_remove' );
		}

		$old    = is_array( $before ) ? (array) ( $before['capabilities'] ?? array() ) : array();
		$new    = is_array( $after ) ? (array) ( $after['capabilities'] ?? array() ) : array();
		$gained = array();
		foreach ( self::dangerous_capabilities() as $cap ) {
			if ( ! empty( $new[ $cap ] ) && empty( $old[ $cap ] ) ) {
				$gained[] = $cap;
			}
		}

		return $gained ? Messages::get( 'dangerous_role', implode( ', ', $gained ) ) : '';
	}
}
