<?php
/**
 * Theme mods (Customizer settings) of the active theme.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Providers;

use AryanX\Axle\ConfigSync\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Saved to theme-mods/<stylesheet>.json. Menu locations and sidebars are left out:
 * the menus and widgets providers own them. Additional CSS is a post, so it
 * is left out too. Applying to a theme that is not active needs the
 * "Apply to an inactive theme" choice.
 */
final class ThemeMods extends Base {

	/**
	 * Theme mods other providers own, or that point at content.
	 */
	public const EXCLUDED = array( 'nav_menu_locations', 'sidebars_widgets', 'custom_css_post_id' );

	/**
	 * Short name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'theme-mods';
	}

	/**
	 * Name for people.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Theme mods', 'axle-config-sync' );
	}

	/**
	 * The active theme's item.
	 *
	 * @return string[]
	 */
	public function items(): array {
		$id = 'theme-mods/' . get_stylesheet();

		return $this->exists( $id ) ? array( $id ) : array();
	}

	/**
	 * Whether the theme has saved mods.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function exists( string $id ): bool {
		return is_array( get_option( 'theme_mods_' . $this->key( $id ) ) );
	}

	/**
	 * The theme's mods, without the excluded ones.
	 *
	 * @param string $id Item ID.
	 * @return array|null
	 */
	public function read( string $id ) {
		$mods = get_option( 'theme_mods_' . $this->key( $id ) );
		if ( ! is_array( $mods ) ) {
			return null;
		}

		foreach ( self::EXCLUDED as $key ) {
			unset( $mods[ $key ] );
		}
		ksort( $mods );

		return $mods;
	}

	/**
	 * Writes the mods: set_theme_mod() for the active theme, the option for
	 * another. Excluded mods on the site are kept.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Mods, or null to remove them.
	 */
	public function write( string $id, $value ): void {
		$stylesheet = $this->key( $id );
		$current    = get_option( 'theme_mods_' . $stylesheet );
		$current    = is_array( $current ) ? $current : array();
		$value      = is_array( $value ) ? $value : array();

		foreach ( self::EXCLUDED as $key ) {
			unset( $value[ $key ] );
		}

		if ( get_stylesheet() === $stylesheet ) {
			foreach ( array_keys( $current ) as $key ) {
				if ( ! array_key_exists( $key, $value ) && ! in_array( $key, self::EXCLUDED, true ) ) {
					remove_theme_mod( (string) $key );
				}
			}
			foreach ( $value as $key => $mod ) {
				set_theme_mod( (string) $key, $mod );
			}
			return;
		}

		$keep = array_intersect_key( $current, array_flip( self::EXCLUDED ) );
		update_option( 'theme_mods_' . $stylesheet, array_merge( $value, $keep ) );
	}

	/**
	 * Refuses an inactive theme unless asked.
	 *
	 * @param string $id   Item ID.
	 * @param array  $args Apply options.
	 * @return string
	 */
	public function gate( string $id, array $args ): string {
		$stylesheet = $this->key( $id );
		if ( get_stylesheet() === $stylesheet || ! empty( $args['inactive_theme'] ) ) {
			return '';
		}

		return Messages::get( 'inactive_theme', $stylesheet );
	}

	/**
	 * The theme name.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function label( string $id ): string {
		$theme = wp_get_theme( $this->key( $id ) );

		return $theme->exists() ? (string) $theme->get( 'Name' ) : $this->key( $id );
	}

	/**
	 * The theme owns its mods.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function owner( string $id ): string {
		return 'theme:' . $this->key( $id );
	}
}
