<?php
/**
 * Widgets, one item per sidebar.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Each widgets/<sidebar>.json holds the sidebar's widgets in order, each with its
 * type (id_base) and settings. Widget numbers differ between sites, so they
 * are not in the file; apply gives each widget a free number. Block widgets
 * are widget_block like any other.
 */
final class Widgets extends Base {

	/**
	 * Short name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'widgets';
	}

	/**
	 * Name for people.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Widgets', 'axle-config-sync' );
	}

	/**
	 * One item per sidebar.
	 *
	 * @return string[]
	 */
	public function items(): array {
		$ids = array();
		foreach ( array_keys( $this->sidebars() ) as $sidebar ) {
			if ( 'array_version' !== $sidebar ) {
				$ids[] = 'widgets/' . $sidebar;
			}
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * Whether the sidebar is in sidebars_widgets.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function exists( string $id ): bool {
		$sidebars = $this->sidebars();

		return isset( $sidebars[ $this->key( $id ) ] ) && is_array( $sidebars[ $this->key( $id ) ] );
	}

	/**
	 * The sidebar's widgets in order.
	 *
	 * @param string $id Item ID.
	 * @return array|null
	 */
	public function read( string $id ) {
		$sidebars = $this->sidebars();
		$sidebar  = $this->key( $id );
		if ( ! isset( $sidebars[ $sidebar ] ) || ! is_array( $sidebars[ $sidebar ] ) ) {
			return null;
		}

		$out = array();
		foreach ( $sidebars[ $sidebar ] as $widget_id ) {
			$parsed = self::parse( (string) $widget_id );
			if ( ! $parsed ) {
				continue;
			}
			$instances = get_option( 'widget_' . $parsed[0] );
			$out[]     = array(
				'id_base'  => $parsed[0],
				'settings' => is_array( $instances ) && isset( $instances[ $parsed[1] ] ) ? $instances[ $parsed[1] ] : array(),
			);
		}

		return $out;
	}

	/**
	 * Replaces the sidebar's widgets: the old instances are removed from
	 * their widget_* options and the new ones added with free numbers.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Widgets, or null to remove the sidebar.
	 */
	public function write( string $id, $value ): void {
		$sidebar  = $this->key( $id );
		$sidebars = $this->sidebars();
		$options  = array();
		$freed    = array();

		foreach ( (array) ( $sidebars[ $sidebar ] ?? array() ) as $widget_id ) {
			$parsed = self::parse( (string) $widget_id );
			if ( ! $parsed ) {
				continue;
			}
			$options[ $parsed[0] ] = $options[ $parsed[0] ] ?? self::instances( $parsed[0] );
			unset( $options[ $parsed[0] ][ $parsed[1] ] );
			$freed[ $parsed[0] ][] = $parsed[1];
		}

		$ids = array();
		foreach ( is_array( $value ) ? $value : array() as $widget ) {
			$base = sanitize_key( (string) ( $widget['id_base'] ?? '' ) );
			if ( '' === $base ) {
				continue;
			}
			$options[ $base ] = $options[ $base ] ?? self::instances( $base );
			$number           = ! empty( $freed[ $base ] ) ? (int) array_shift( $freed[ $base ] ) : 0;
			if ( $number < 2 || isset( $options[ $base ][ $number ] ) ) {
				$numbers = array_filter( array_keys( $options[ $base ] ), 'is_int' );
				$number  = max( array_merge( array( 1 ), $numbers ) ) + 1;
			}
			$options[ $base ][ $number ] = $widget['settings'] ?? array();
			$ids[]                       = $base . '-' . $number;
		}

		foreach ( $options as $base => $instances ) {
			$instances['_multiwidget'] = 1;
			update_option( 'widget_' . $base, $instances );
		}

		if ( null === $value ) {
			unset( $sidebars[ $sidebar ] );
		} else {
			$sidebars[ $sidebar ] = $ids;
		}
		wp_set_sidebars_widgets( $sidebars );
	}

	/**
	 * The sidebar name.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function label( string $id ): string {
		global $wp_registered_sidebars;

		$sidebar = $this->key( $id );
		if ( 'wp_inactive_widgets' === $sidebar ) {
			return __( 'Inactive widgets', 'axle-config-sync' );
		}

		return isset( $wp_registered_sidebars[ $sidebar ]['name'] ) ? (string) $wp_registered_sidebars[ $sidebar ]['name'] : $sidebar;
	}

	/**
	 * "Footer: 3 widgets, 1 changed".
	 *
	 * @param string $id     Item ID.
	 * @param mixed  $before Live value.
	 * @param mixed  $after  File value.
	 * @return string
	 */
	public function summary( string $id, $before, $after ): string {
		list( $count, $changed ) = self::count_changes( $before, $after );

		/* translators: 1: sidebar name, 2: number of widgets, 3: number changed. */
		return sprintf( _n( '%1$s: %2$d widget, %3$d changed', '%1$s: %2$d widgets, %3$d changed', $count, 'axle-config-sync' ), $this->label( $id ), $count, $changed );
	}

	/**
	 * The sidebars_widgets option.
	 *
	 * @return array
	 */
	private function sidebars(): array {
		$sidebars = get_option( 'sidebars_widgets', array() );

		return is_array( $sidebars ) ? $sidebars : array();
	}

	/**
	 * Instances of one widget type.
	 *
	 * @param string $base id_base.
	 * @return array
	 */
	private static function instances( string $base ): array {
		$instances = get_option( 'widget_' . $base, array() );

		return is_array( $instances ) ? $instances : array();
	}

	/**
	 * Splits a widget ID into its id_base and number.
	 *
	 * @param string $widget_id Such as block-3.
	 * @return array{0: string, 1: int}|null
	 */
	public static function parse( string $widget_id ): ?array {
		if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $match ) ) {
			return null;
		}

		return array( $match[1], (int) $match[2] );
	}
}
