<?php
/**
 * Tests for portable references.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Marker;
use AryanX\Axle\ConfigSync\References;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers References.
 */
final class ReferencesTest extends SyncTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_post_type_hierarchical' )->alias(
			static function ( $type ) {
				return 'page' === $type;
			}
		);
		Functions\when( 'get_page_uri' )->alias(
			static function ( $post ) {
				return 3 === $post->ID ? 'about/team' : $post->post_name;
			}
		);
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$posts = array(
					3 => new \WP_Post( (object) array( 'ID' => 3, 'post_type' => 'page', 'post_name' => 'team' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					9 => new \WP_Post( (object) array( 'ID' => 9, 'post_type' => 'attachment', 'post_name' => 'logo' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				);
				return $posts[ $id ] ?? null;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '2026/10/logo.png' );
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				return 4 === $id ? new \WP_Term( array( 'term_id' => 4, 'taxonomy' => 'nav_menu', 'slug' => 'primary' ) ) : ( 5 === $id ? new \WP_Term( array( 'term_id' => 5, 'taxonomy' => 'category', 'slug' => 'news' ) ) : null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		);
	}

	public function test_known_fields_become_references(): void {
		$refs = new References();

		$front = $refs->to_portable( 'options/page_on_front', '3' );
		$this->assertInstanceOf( Marker::class, $front );
		$this->assertSame(
			array(
				'$ref' => 'post',
				'path' => 'about/team',
				'type' => 'page',
			),
			$front->to_array()
		);

		$icon = $refs->to_portable( 'options/site_icon', 9 );
		$this->assertSame(
			array(
				'$ref' => 'attachment',
				'file' => '2026/10/logo.png',
			),
			$icon->to_array()
		);

		$widgets = $refs->to_portable(
			'widgets/footer',
			array(
				array(
					'id_base'  => 'nav_menu',
					'settings' => array( 'nav_menu' => 4 ),
				),
			)
		);
		$this->assertSame( 'menu', $widgets[0]['settings']['nav_menu']->type() );

		$this->assertSame( 0, $refs->to_portable( 'options/page_on_front', 0 ), 'Zero stays zero.' );
		$this->assertSame( 77, $refs->to_portable( 'options/page_on_front', 77 ), 'An ID that points nowhere stays as it is.' );
		$this->assertSame( 'news', $refs->to_ref( 'term:category', 5 )->data['slug'] );
	}

	public function test_the_reference_fields_filter_adds_fields(): void {
		Filters\expectApplied( 'axle_config_sync_reference_fields' )->once()->andReturnUsing(
			static function ( array $fields ) {
				$fields['options/acme_settings'] = array( 'landing_page' => 'post' );
				return $fields;
			}
		);

		$out = ( new References() )->to_portable( 'options/acme_settings', array( 'landing_page' => 3 ) );

		$this->assertSame( 'post', $out['landing_page']->type() );
	}

	public function test_unresolved_references_block_with_a_plain_message(): void {
		Functions\when( 'get_post_type_object' )->justReturn( (object) array( 'labels' => (object) array( 'singular_name' => 'Page' ) ) );
		Functions\when( 'sanitize_title' )->returnArg();
		\WP_Query::$queue = array( array( 'posts' => array() ) );

		$problems = array();
		$value    = ( new References() )->resolve_all(
			array(
				'front' => Marker::ref(
					'post',
					array(
						'type' => 'page',
						'path' => 'about/team',
					)
				),
			),
			$problems
		);

		$this->assertSame( 0, $value['front'] );
		$this->assertTrue( $problems[0]['block'] );
		$this->assertSame( 'Page "about/team" does not exist on this site.', $problems[0]['message'] );
	}

	public function test_references_resolve_and_the_filter_can_answer(): void {
		Functions\when( 'get_term_by' )->justReturn( new \WP_Term( array( 'term_id' => 12, 'taxonomy' => 'nav_menu', 'slug' => 'primary' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$refs = new References();

		$this->assertSame( 12, $refs->resolve( Marker::ref( 'menu', array( 'slug' => 'primary' ) ) ) );

		Filters\expectApplied( 'axle_config_sync_resolve_reference' )->once()->with( null, \Mockery::type( 'array' ) )->andReturn( 41 );
		$this->assertSame( 41, $refs->resolve( Marker::ref( 'custom', array( 'key' => 'x' ) ) ) );
	}

	public function test_a_missing_menu_says_which_item_to_apply_first(): void {
		Functions\when( 'get_term_by' )->justReturn( false );
		$problems = array();

		( new References() )->resolve_all( array( Marker::ref( 'menu', array( 'slug' => 'footer' ) ) ), $problems );

		$this->assertSame( 'menus/footer', $problems[0]['pending'] );
		$this->assertSame( 'Menu "footer" does not exist on this site. Apply menus/footer first.', $problems[0]['message'] );
	}
}
