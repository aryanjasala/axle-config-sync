<?php
/**
 * Portable references: IDs become paths and slugs in files, and IDs again on apply.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Posts, terms, attachments and menus have different IDs on every
 * environment. In files they are references:
 *
 * - `{"$ref":"post","type":"page","path":"about/team"}` (or a GUID)
 * - `{"$ref":"term","taxonomy":"category","slug":"news"}`
 * - `{"$ref":"attachment","file":"2026/10/logo.png"}`
 * - `{"$ref":"menu","slug":"primary"}`
 *
 * Known ID fields are listed per item and JSON-pointer-like path, where `*`
 * matches any key. Plugins add theirs with `axle_config_sync_reference_fields`.
 */
final class References {

	/**
	 * Resolved references, per request.
	 *
	 * @var array<string, int|null>
	 */
	private array $cache = array();

	/**
	 * Known ID fields: item ID => path => reference type.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function fields(): array {
		$defaults = array(
			'options/page_on_front'              => array( '' => 'post' ),
			'options/page_for_posts'             => array( '' => 'post' ),
			'options/wp_page_for_privacy_policy' => array( '' => 'post' ),
			'options/site_icon'                  => array( '' => 'attachment' ),
			'options/default_category'           => array( '' => 'term:category' ),
			'options/default_email_category'     => array( '' => 'term:category' ),
			'options/default_link_category'      => array( '' => 'term:link_category' ),
			'theme-mods/*'                       => array(
				'custom_logo'                     => 'attachment',
				'header_image_data.attachment_id' => 'attachment',
			),
			'widgets/*'                          => array(
				'*.settings.nav_menu'      => 'menu',
				'*.settings.attachment_id' => 'attachment',
				'*.settings.ids.*'         => 'attachment',
			),
		);

		/**
		 * Filters the fields that hold IDs, which files store as portable references.
		 *
		 * Keys are item IDs (`*` allowed, such as `widgets/*`); values map a
		 * dotted path inside the item's value (`*` for any key, empty for the
		 * whole value) to a type: post, attachment, menu, or term:<taxonomy>.
		 *
		 * @param array<string, array<string, string>> $fields Fields.
		 */
		$fields = Messages::as_array( apply_filters( 'axle_config_sync_reference_fields', $defaults ) );

		$out = array();
		foreach ( $fields as $item => $paths ) {
			if ( is_array( $paths ) ) {
				foreach ( $paths as $path => $type ) {
					$out[ (string) $item ][ (string) $path ] = (string) $type;
				}
			}
		}

		return $out;
	}

	/**
	 * Field paths for one item.
	 *
	 * @param string $id Item ID.
	 * @return array<string, string> Path => type.
	 */
	public static function fields_for( string $id ): array {
		$out = array();
		foreach ( self::fields() as $item => $paths ) {
			if ( Rules::matches( $id, $item ) ) {
				$out = array_merge( $out, $paths );
			}
		}

		return $out;
	}

	/**
	 * Replaces IDs in the known fields with references.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Live value.
	 * @return mixed
	 */
	public function to_portable( string $id, $value ) {
		foreach ( self::fields_for( $id ) as $path => $type ) {
			$value = $this->walk_path(
				$value,
				'' === $path ? array() : explode( '.', $path ),
				function ( $leaf ) use ( $type ) {
					return $this->to_ref( $type, $leaf );
				}
			);
		}

		return $value;
	}

	/**
	 * Replaces references anywhere in a value with IDs.
	 *
	 * @param mixed $value    Value with markers.
	 * @param array $problems Collects unresolved references.
	 * @return mixed
	 */
	public function resolve_all( $value, array &$problems ) {
		return Pointer::map_leaves(
			$value,
			function ( $leaf ) use ( &$problems ) {
				if ( ! $leaf instanceof Marker || Marker::REF !== $leaf->kind ) {
					return $leaf;
				}

				$resolved = $this->resolve( $leaf );
				if ( null === $resolved ) {
					$problems[] = array(
						'type'    => 'reference',
						'block'   => true,
						'message' => $this->missing_message( $leaf ),
						// A menu applied in the same run will exist by the time this item is written.
						'pending' => 'menu' === $leaf->type() ? 'menus/' . (string) ( $leaf->data['slug'] ?? '' ) : '',
					);
					return 0;
				}

				return $resolved;
			}
		);
	}

	/**
	 * An ID as a reference. Zero, empty and unknown IDs stay as they are.
	 *
	 * @param string $type  post, attachment, menu or term:<taxonomy>.
	 * @param mixed  $value The ID.
	 * @return mixed A Marker, or the value unchanged.
	 */
	public function to_ref( string $type, $value ) {
		if ( $value instanceof Marker || ! is_numeric( $value ) || (int) $value <= 0 ) {
			return $value;
		}

		$object_id = (int) $value;
		$base      = explode( ':', $type, 2 );

		switch ( $base[0] ) {
			case 'post':
			case 'attachment':
				$post = get_post( $object_id );
				if ( ! $post ) {
					return $value;
				}
				return $this->post_ref( $post );

			case 'term':
			case 'menu':
				$taxonomy = 'menu' === $base[0] ? 'nav_menu' : ( $base[1] ?? '' );
				$term     = get_term( $object_id, '' === $taxonomy ? '' : $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					return $value;
				}
				return 'nav_menu' === $term->taxonomy
					? Marker::ref( 'menu', array( 'slug' => $term->slug ) )
					: Marker::ref(
						'term',
						array(
							'taxonomy' => $term->taxonomy,
							'slug'     => $term->slug,
						)
					);
		}

		return $value;
	}

	/**
	 * A reference to a post or attachment.
	 *
	 * @param \WP_Post $post Post.
	 * @return Marker
	 */
	public function post_ref( \WP_Post $post ): Marker {
		if ( 'attachment' === $post->post_type ) {
			$file = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
			if ( '' !== $file ) {
				return Marker::ref( 'attachment', array( 'file' => $file ) );
			}
		}

		$path = is_post_type_hierarchical( $post->post_type ) ? (string) get_page_uri( $post ) : (string) $post->post_name;
		if ( '' !== $path ) {
			return Marker::ref(
				'post',
				array(
					'type' => $post->post_type,
					'path' => $path,
				)
			);
		}

		return Marker::ref(
			'post',
			array(
				'type' => $post->post_type,
				'guid' => (string) $post->guid,
			)
		);
	}

	/**
	 * The ID a reference points at on this site.
	 *
	 * @param Marker $ref Reference.
	 * @return int|null Null when nothing matches.
	 */
	public function resolve( Marker $ref ): ?int {
		$key = $ref->describe();
		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}

		$data = $ref->data;
		$id   = null;
		switch ( $ref->type() ) {
			case 'post':
				$id = $this->find_post( $this->post_type_of( $data ), (string) ( $data['path'] ?? '' ), (string) ( $data['guid'] ?? '' ) );
				break;
			case 'attachment':
				$id = $this->find_attachment( (string) ( $data['file'] ?? '' ) );
				break;
			case 'term':
				$term = get_term_by( 'slug', (string) ( $data['slug'] ?? '' ), (string) ( $data['taxonomy'] ?? '' ) );
				$id   = $term instanceof \WP_Term ? (int) $term->term_id : null;
				break;
			case 'menu':
				$term = get_term_by( 'slug', (string) ( $data['slug'] ?? '' ), 'nav_menu' );
				$id   = $term instanceof \WP_Term ? (int) $term->term_id : null;
				break;
		}

		/**
		 * Filters the ID a portable reference resolves to on this site.
		 *
		 * @param int|null $id  Resolved ID, or null when nothing matched.
		 * @param array    $ref The reference: `$ref` (post, term, attachment, menu) plus its fields (type, path, slug, file, ...).
		 */
		$id = apply_filters( 'axle_config_sync_resolve_reference', $id, $ref->data );
		$id = is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;

		$this->cache[ $key ] = $id;

		return $id;
	}

	/**
	 * Forgets resolved references, after an apply created new objects.
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * The plain message for a reference that does not resolve.
	 *
	 * @param Marker $ref Reference.
	 * @return string
	 */
	public function missing_message( Marker $ref ): string {
		$data = $ref->data;
		switch ( $ref->type() ) {
			case 'post':
				$type   = $this->post_type_of( $data );
				$object = get_post_type_object( $type );
				$label  = $object ? (string) $object->labels->singular_name : ucfirst( $type );
				return Messages::get( 'missing_post', $label, (string) ( $data['path'] ?? $data['guid'] ?? '' ) );
			case 'attachment':
				return Messages::get( 'missing_attachment', (string) ( $data['file'] ?? '' ) );
			case 'term':
				return Messages::get( 'missing_term', (string) ( $data['taxonomy'] ?? '' ), (string) ( $data['slug'] ?? '' ) );
			case 'menu':
				return Messages::get( 'missing_menu', (string) ( $data['slug'] ?? '' ) );
		}

		return Messages::get( 'unknown_ref', $ref->type() );
	}

	/**
	 * The post type in a post reference.
	 *
	 * @param array $data Reference fields.
	 * @return string
	 */
	private function post_type_of( array $data ): string {
		$type = sanitize_key( (string) ( $data['type'] ?? 'page' ) );
		return '' !== $type ? $type : 'page';
	}

	/**
	 * Finds a post by type and path, or by GUID.
	 *
	 * @param string $type Post type.
	 * @param string $path Path (parent/child) or slug.
	 * @param string $guid GUID.
	 * @return int|null
	 */
	private function find_post( string $type, string $path, string $guid ): ?int {
		if ( '' !== $path ) {
			$parts = explode( '/', trim( $path, '/' ) );
			$query = new \WP_Query(
				array(
					'post_type'              => $type,
					'name'                   => sanitize_title( (string) end( $parts ) ),
					'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future', 'inherit' ),
					'posts_per_page'         => 20,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'ignore_sticky_posts'    => true,
				)
			);
			foreach ( $query->posts as $post ) {
				if ( $post instanceof \WP_Post ) {
					$found = is_post_type_hierarchical( $type ) ? (string) get_page_uri( $post ) : (string) $post->post_name;
					if ( trim( $path, '/' ) === $found ) {
						return (int) $post->ID;
					}
				}
			}
			return null;
		}

		if ( '' !== $guid ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A post without a slug has nothing else to find it by; runs once per apply.
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = %s LIMIT 1", $guid, $type ) );
			return $id ? (int) $id : null;
		}

		return null;
	}

	/**
	 * Finds an attachment by its file in uploads.
	 *
	 * @param string $file Such as 2026/10/logo.png.
	 * @return int|null
	 */
	private function find_attachment( string $file ): ?int {
		if ( '' === $file ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Core keeps the file only in this meta key; runs once per reference on apply.
				'meta_query'             => array(
					array(
						'key'   => '_wp_attached_file',
						'value' => $file,
					),
				),
			)
		);

		return $query->posts ? (int) $query->posts[0] : null;
	}

	/**
	 * Applies a callback to the values at a dotted path, `*` matching any key.
	 *
	 * @param mixed    $value    Value.
	 * @param string[] $parts    Remaining path parts.
	 * @param callable $callback fn( $leaf ): mixed.
	 * @return mixed
	 */
	private function walk_path( $value, array $parts, callable $callback ) {
		if ( ! $parts ) {
			return $callback( $value );
		}

		$part = array_shift( $parts );
		if ( $value instanceof \stdClass ) {
			$copy = clone $value;
			foreach ( get_object_vars( $copy ) as $key => $item ) {
				if ( '*' === $part || (string) $key === $part ) {
					$copy->$key = $this->walk_path( $item, $parts, $callback );
				}
			}
			return $copy;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			if ( '*' === $part || (string) $key === $part ) {
				$value[ $key ] = $this->walk_path( $item, $parts, $callback );
			}
		}

		return $value;
	}
}
