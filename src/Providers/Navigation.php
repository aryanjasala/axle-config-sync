<?php
/**
 * Block navigation menus (wp_navigation posts).
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Providers;

use AryanX\Axle\ConfigSync\Marker;
use AryanX\Axle\ConfigSync\References;

defined( 'ABSPATH' ) || exit;

/**
 * Each navigation/<slug>.json holds the title and block markup of a navigation
 * menu. The `id` of each navigation link and submenu becomes a portable
 * reference inside the markup, and back again on apply.
 */
final class Navigation extends Base {

	private const BLOCKS = array( 'core/navigation-link', 'core/navigation-submenu' );

	/**
	 * Short name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'navigation';
	}

	/**
	 * Name for people.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Block navigation', 'axle-config-sync' );
	}

	/**
	 * Every published navigation menu.
	 *
	 * @return string[]
	 */
	public function items(): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'wp_navigation',
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'orderby'                => 'name',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && '' !== $post->post_name ) {
				$ids[] = 'navigation/' . $post->post_name;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether the navigation menu exists.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function exists( string $id ): bool {
		return (bool) $this->post( $this->key( $id ) );
	}

	/**
	 * Title, status and markup.
	 *
	 * @param string $id Item ID.
	 * @return array|null
	 */
	public function read( string $id ) {
		$post = $this->post( $this->key( $id ) );
		if ( ! $post ) {
			return null;
		}

		return array(
			'title'   => (string) $post->post_title,
			'status'  => (string) $post->post_status,
			'content' => (string) $post->post_content,
		);
	}

	/**
	 * Saves the navigation post, or deletes it.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Navigation, or null to delete it.
	 * @throws \RuntimeException When WordPress refuses the post.
	 */
	public function write( string $id, $value ): void {
		$slug = $this->key( $id );
		$post = $this->post( $slug );

		if ( null === $value ) {
			if ( $post ) {
				wp_delete_post( $post->ID, true );
			}
			return;
		}

		$value = is_array( $value ) ? $value : array();
		$data  = array(
			'post_type'    => 'wp_navigation',
			'post_name'    => $slug,
			'post_title'   => (string) ( $value['title'] ?? $slug ),
			'post_status'  => in_array( $value['status'] ?? '', array( 'publish', 'draft', 'private' ), true ) ? (string) $value['status'] : 'publish',
			'post_content' => (string) ( $value['content'] ?? '' ),
		);
		if ( $post ) {
			$data['ID'] = $post->ID;
		}

		$saved = $post ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $saved ) ) {
			throw new \RuntimeException( esc_html( $saved->get_error_message() ) );
		}
	}

	/**
	 * Link IDs in the markup become references.
	 *
	 * @param string     $id    Item ID.
	 * @param mixed      $value Live value.
	 * @param References $refs  Reference helper.
	 * @return mixed
	 */
	public function references( string $id, $value, References $refs ) {
		if ( ! is_array( $value ) || ! isset( $value['content'] ) || ! has_blocks( (string) $value['content'] ) ) {
			return $value;
		}

		$changed = false;
		$blocks  = $this->walk(
			parse_blocks( (string) $value['content'] ),
			static function ( array $attrs ) use ( $refs, &$changed ): array {
				if ( ! isset( $attrs['id'] ) || ! is_numeric( $attrs['id'] ) ) {
					return $attrs;
				}
				$kind = (string) ( $attrs['kind'] ?? 'post-type' );
				$type = (string) ( $attrs['type'] ?? '' );
				$ref  = 'taxonomy' === $kind
					? $refs->to_ref( 'term:' . ( 'tag' === $type ? 'post_tag' : $type ), $attrs['id'] )
					: $refs->to_ref( 'post', $attrs['id'] );
				if ( $ref instanceof Marker ) {
					$attrs['id'] = $ref->to_array();
					$changed     = true;
				}
				return $attrs;
			}
		);

		if ( $changed ) {
			$value['content'] = serialize_blocks( $blocks );
		}

		return $value;
	}

	/**
	 * References in the markup become IDs.
	 *
	 * @param string     $id       Item ID.
	 * @param mixed      $value    Value with references.
	 * @param References $refs     Reference helper.
	 * @param array      $problems Collects problems.
	 * @return mixed
	 */
	public function resolve( string $id, $value, References $refs, array &$problems ) {
		$value = parent::resolve( $id, $value, $refs, $problems );
		if ( ! is_array( $value ) || ! isset( $value['content'] ) || false === strpos( (string) $value['content'], '"$ref"' ) ) {
			return $value;
		}

		$blocks = $this->walk(
			parse_blocks( (string) $value['content'] ),
			static function ( array $attrs ) use ( $refs, &$problems ): array {
				if ( ! isset( $attrs['id'] ) || ! is_array( $attrs['id'] ) ) {
					return $attrs;
				}
				$ref = Marker::from_array( $attrs['id'] );
				if ( ! $ref || Marker::REF !== $ref->kind ) {
					return $attrs;
				}
				$resolved = $refs->resolve( $ref );
				if ( null === $resolved ) {
					$problems[] = array(
						'type'    => 'reference',
						'block'   => true,
						'message' => $refs->missing_message( $ref ),
					);
					unset( $attrs['id'] );
					return $attrs;
				}
				$attrs['id'] = $resolved;
				return $attrs;
			}
		);

		$value['content'] = serialize_blocks( $blocks );

		return $value;
	}

	/**
	 * The navigation title.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function label( string $id ): string {
		$post = $this->post( $this->key( $id ) );

		return $post && '' !== $post->post_title ? (string) $post->post_title : $this->key( $id );
	}

	/**
	 * Calls $callback on the attributes of every navigation link and submenu.
	 *
	 * @param array    $blocks   Parsed blocks.
	 * @param callable $callback fn( array $attrs ): array.
	 * @return array
	 */
	private function walk( array $blocks, callable $callback ): array {
		foreach ( $blocks as $index => $block ) {
			if ( in_array( $block['blockName'] ?? '', self::BLOCKS, true ) ) {
				$blocks[ $index ]['attrs'] = $callback( (array) ( $block['attrs'] ?? array() ) );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = $this->walk( (array) $block['innerBlocks'], $callback );
			}
		}

		return $blocks;
	}

	/**
	 * A navigation post by slug.
	 *
	 * @param string $slug Slug.
	 * @return \WP_Post|null
	 */
	private function post( string $slug ): ?\WP_Post {
		if ( '' === $slug ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'wp_navigation',
				'name'                   => $slug,
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return isset( $query->posts[0] ) && $query->posts[0] instanceof \WP_Post ? $query->posts[0] : null;
	}
}
