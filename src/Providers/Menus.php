<?php
/**
 * Classic menus and menu locations.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Providers;

use AryanX\Axle\ConfigSync\References;

defined( 'ABSPATH' ) || exit;

/**
 * Each menus/<slug>.json holds a menu with its items in order. Items are numbered
 * by position, and a child names its parent's position, so nothing depends
 * on post IDs. Items that point at posts or terms hold portable references.
 * menu-locations.json maps each theme location to a menu reference.
 */
final class Menus extends Base {

	public const LOCATIONS = 'menu-locations';

	/**
	 * Short name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'menus';
	}

	/**
	 * Name for people.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Menus', 'axle-config-sync' );
	}

	/**
	 * Every classic menu, plus the locations.
	 *
	 * @return string[]
	 */
	public function items(): array {
		$ids = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$ids[] = 'menus/' . $menu->slug;
		}
		sort( $ids );
		$ids[] = self::LOCATIONS;

		return $ids;
	}

	/**
	 * Menus own their folder and menu-locations.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function owns( string $id ): bool {
		return self::LOCATIONS === $id || parent::owns( $id );
	}

	/**
	 * The item ID for a file.
	 *
	 * @param string $relative File path.
	 * @return string|null
	 */
	public function id_for_file( string $relative ): ?string {
		return self::LOCATIONS . '.json' === $relative ? self::LOCATIONS : parent::id_for_file( $relative );
	}

	/**
	 * Whether the menu exists. The locations always do.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function exists( string $id ): bool {
		return self::LOCATIONS === $id || (bool) $this->menu( $this->key( $id ) );
	}

	/**
	 * The menu and its items, or the locations.
	 *
	 * @param string $id Item ID.
	 * @return array|null
	 */
	public function read( string $id ) {
		if ( self::LOCATIONS === $id ) {
			$locations = array();
			foreach ( (array) get_theme_mod( 'nav_menu_locations', array() ) as $location => $menu_id ) {
				if ( (int) $menu_id > 0 ) {
					$locations[ (string) $location ] = (int) $menu_id;
				}
			}
			ksort( $locations );
			return $locations;
		}

		$menu = $this->menu( $this->key( $id ) );
		if ( ! $menu ) {
			return null;
		}

		$options = get_option( 'nav_menu_options', array() );
		$items   = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'publish' ) );
		$items   = is_array( $items ) ? $items : array();
		usort(
			$items,
			static function ( $a, $b ): int {
				return (int) $a->menu_order - (int) $b->menu_order;
			}
		);

		$positions = array();
		foreach ( $items as $index => $item ) {
			$positions[ (int) $item->ID ] = $index + 1;
		}

		$out = array();
		foreach ( $items as $item ) {
			$type  = (string) $item->type;
			$out[] = array(
				'title'       => (string) $item->post_title,
				'type'        => $type,
				'object'      => (string) $item->object,
				'object_id'   => in_array( $type, array( 'post_type', 'taxonomy' ), true ) ? (int) $item->object_id : 0,
				'url'         => 'custom' === $type ? (string) $item->url : '',
				'parent'      => $positions[ (int) $item->menu_item_parent ] ?? 0,
				'target'      => (string) $item->target,
				'attr_title'  => (string) $item->attr_title,
				'classes'     => trim( implode( ' ', array_filter( (array) $item->classes ) ) ),
				'xfn'         => (string) $item->xfn,
				'description' => (string) $item->post_content,
			);
		}

		return array(
			'name'        => (string) $menu->name,
			'description' => (string) $menu->description,
			'auto_add'    => is_array( $options ) && in_array( (int) $menu->term_id, array_map( 'intval', (array) ( $options['auto_add'] ?? array() ) ), true ),
			'items'       => $out,
		);
	}

	/**
	 * Writes the menu and its items, or the locations.
	 *
	 * Existing items are updated in place by position, extra ones deleted,
	 * missing ones created, so item IDs stay the same where they can.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Menu, or null to delete it.
	 * @throws \RuntimeException When WordPress refuses the menu or an item.
	 */
	public function write( string $id, $value ): void {
		if ( self::LOCATIONS === $id ) {
			if ( null === $value ) {
				remove_theme_mod( 'nav_menu_locations' );
				return;
			}
			$locations = array();
			foreach ( (array) $value as $location => $menu_id ) {
				$locations[ (string) $location ] = (int) $menu_id;
			}
			set_theme_mod( 'nav_menu_locations', $locations );
			return;
		}

		$slug = $this->key( $id );
		$menu = $this->menu( $slug );

		if ( null === $value ) {
			if ( $menu ) {
				wp_delete_nav_menu( $menu->term_id );
			}
			return;
		}

		$value = is_array( $value ) ? $value : array();
		$name  = '' !== (string) ( $value['name'] ?? '' ) ? (string) $value['name'] : $slug;

		if ( ! $menu ) {
			$menu_id = wp_create_nav_menu( $name );
			if ( is_wp_error( $menu_id ) ) {
				throw new \RuntimeException( esc_html( $menu_id->get_error_message() ) );
			}
			wp_update_term( (int) $menu_id, 'nav_menu', array( 'slug' => $slug ) );
		} else {
			$menu_id = (int) $menu->term_id;
		}

		$updated = wp_update_nav_menu_object(
			(int) $menu_id,
			array(
				'menu-name'   => $name,
				'description' => (string) ( $value['description'] ?? '' ),
			)
		);
		if ( is_wp_error( $updated ) ) {
			throw new \RuntimeException( esc_html( $updated->get_error_message() ) );
		}

		$this->write_items( (int) $menu_id, (array) ( $value['items'] ?? array() ) );
		$this->set_auto_add( (int) $menu_id, ! empty( $value['auto_add'] ) );
	}

	/**
	 * Items pointing at posts and terms become references; the locations
	 * point at menus.
	 *
	 * @param string     $id    Item ID.
	 * @param mixed      $value Live value.
	 * @param References $refs  Reference helper.
	 * @return mixed
	 */
	public function references( string $id, $value, References $refs ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::LOCATIONS === $id ) {
			foreach ( $value as $location => $menu_id ) {
				$value[ $location ] = $refs->to_ref( 'menu', $menu_id );
			}
			return $value;
		}

		foreach ( (array) ( $value['items'] ?? array() ) as $index => $item ) {
			if ( 'post_type' === ( $item['type'] ?? '' ) ) {
				$value['items'][ $index ]['object_id'] = $refs->to_ref( 'post', $item['object_id'] ?? 0 );
			} elseif ( 'taxonomy' === ( $item['type'] ?? '' ) ) {
				$value['items'][ $index ]['object_id'] = $refs->to_ref( 'term:' . ( $item['object'] ?? '' ), $item['object_id'] ?? 0 );
			}
		}

		return $refs->to_portable( $id, $value );
	}

	/**
	 * The menu name, or "Menu locations".
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function label( string $id ): string {
		if ( self::LOCATIONS === $id ) {
			return __( 'Menu locations', 'axle-config-sync' );
		}

		$menu = $this->menu( $this->key( $id ) );

		return $menu ? (string) $menu->name : $this->key( $id );
	}

	/**
	 * "12 items, 2 changed".
	 *
	 * @param string $id     Item ID.
	 * @param mixed  $before Live value.
	 * @param mixed  $after  File value.
	 * @return string
	 */
	public function summary( string $id, $before, $after ): string {
		if ( self::LOCATIONS === $id ) {
			return '';
		}

		list( $count, $changed ) = self::count_changes( is_array( $before ) ? ( $before['items'] ?? array() ) : array(), is_array( $after ) ? ( $after['items'] ?? array() ) : array() );

		/* translators: 1: number of menu items, 2: number changed. */
		return sprintf( _n( '%1$d item, %2$d changed', '%1$d items, %2$d changed', $count, 'axle-config-sync' ), $count, $changed );
	}

	/**
	 * A menu by slug.
	 *
	 * @param string $slug Slug.
	 * @return \WP_Term|null
	 */
	private function menu( string $slug ): ?\WP_Term {
		$menu = get_term_by( 'slug', $slug, 'nav_menu' );

		return $menu instanceof \WP_Term ? $menu : null;
	}

	/**
	 * Updates, creates and deletes items so the menu matches the list.
	 *
	 * @param int   $menu_id Menu term ID.
	 * @param array $items   Items in order.
	 * @throws \RuntimeException When an item cannot be saved.
	 */
	private function write_items( int $menu_id, array $items ): void {
		$existing = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'publish,draft,pending,private' ) );
		$existing = is_array( $existing ) ? $existing : array();
		usort(
			$existing,
			static function ( $a, $b ): int {
				return (int) $a->menu_order - (int) $b->menu_order;
			}
		);

		wp_defer_term_counting( true );

		$ids      = array();
		$deferred = array();
		foreach ( array_values( $items ) as $index => $item ) {
			$position = $index + 1;
			$parent   = (int) ( $item['parent'] ?? 0 );
			$db_id    = isset( $existing[ $index ] ) ? (int) $existing[ $index ]->ID : 0;
			$args     = self::item_args( (array) $item, $position, $parent > 0 && isset( $ids[ $parent ] ) ? $ids[ $parent ] : 0 );

			$saved = wp_update_nav_menu_item( $menu_id, $db_id, $args );
			if ( is_wp_error( $saved ) ) {
				wp_defer_term_counting( false );
				throw new \RuntimeException( esc_html( $saved->get_error_message() ) );
			}
			$ids[ $position ] = (int) $saved;
			if ( $parent > 0 && ! isset( $ids[ $parent ] ) ) {
				$deferred[ $position ] = array( (array) $item, $parent );
			}
		}

		// Children listed before their parent get the parent now that it exists.
		foreach ( $deferred as $position => $pair ) {
			if ( isset( $ids[ $pair[1] ] ) ) {
				wp_update_nav_menu_item( $menu_id, $ids[ $position ], self::item_args( $pair[0], $position, $ids[ $pair[1] ] ) );
			}
		}

		foreach ( array_slice( $existing, count( $items ) ) as $extra ) {
			wp_delete_post( (int) $extra->ID, true );
		}

		wp_defer_term_counting( false );
	}

	/**
	 * Arguments for wp_update_nav_menu_item().
	 *
	 * @param array $item      Item from the file.
	 * @param int   $position  Position, from 1.
	 * @param int   $parent_id Parent item ID, or 0.
	 * @return array
	 */
	private static function item_args( array $item, int $position, int $parent_id ): array {
		$type = (string) ( $item['type'] ?? 'custom' );

		return array(
			'menu-item-title'       => (string) ( $item['title'] ?? '' ),
			'menu-item-type'        => $type,
			'menu-item-object'      => (string) ( $item['object'] ?? ( 'custom' === $type ? 'custom' : '' ) ),
			'menu-item-object-id'   => (int) ( $item['object_id'] ?? 0 ),
			'menu-item-url'         => (string) ( $item['url'] ?? '' ),
			'menu-item-parent-id'   => $parent_id,
			'menu-item-position'    => $position,
			'menu-item-target'      => (string) ( $item['target'] ?? '' ),
			'menu-item-attr-title'  => (string) ( $item['attr_title'] ?? '' ),
			'menu-item-classes'     => (string) ( $item['classes'] ?? '' ),
			'menu-item-xfn'         => (string) ( $item['xfn'] ?? '' ),
			'menu-item-description' => (string) ( $item['description'] ?? '' ),
			'menu-item-status'      => 'publish',
		);
	}

	/**
	 * Sets whether new top-level pages are added to the menu.
	 *
	 * @param int  $menu_id Menu term ID.
	 * @param bool $on      Whether to add them.
	 */
	private function set_auto_add( int $menu_id, bool $on ): void {
		$options  = get_option( 'nav_menu_options', array() );
		$options  = is_array( $options ) ? $options : array();
		$auto_add = array_map( 'intval', (array) ( $options['auto_add'] ?? array() ) );
		$has      = in_array( $menu_id, $auto_add, true );

		if ( $on === $has ) {
			return;
		}

		$options['auto_add'] = $on ? array_merge( $auto_add, array( $menu_id ) ) : array_values( array_diff( $auto_add, array( $menu_id ) ) );
		update_option( 'nav_menu_options', $options );
	}
}
