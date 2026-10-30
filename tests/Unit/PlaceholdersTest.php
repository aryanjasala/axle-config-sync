<?php
/**
 * Tests for URL placeholders.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Placeholders;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Placeholders.
 */
final class PlaceholdersTest extends SyncTestCase {

	public function test_site_urls_become_placeholders_in_any_scheme_and_escaping(): void {
		$out = Placeholders::compact(
			array(
				'home'    => 'https://example.test',
				'page'    => 'http://example.test/about?x=1',
				'json'    => '{"url":"https:\/\/example.test\/shop"}',
				'html'    => '<a href="https://example.test/contact">Contact</a>',
				'other'   => 'https://example.testing.com/page',
				'number'  => 5,
				'nested'  => array( 'deep' => 'https://example.test/' ),
				'serial'  => 'a:1:{i:0;s:20:"https://example.test";}',
			)
		);

		$this->assertSame( '{{home_url}}', $out['home'] );
		$this->assertSame( '{{home_url}}/about?x=1', $out['page'] );
		$this->assertSame( '{"url":"{{home_url}}\/shop"}', $out['json'] );
		$this->assertSame( '<a href="{{home_url}}/contact">Contact</a>', $out['html'] );
		$this->assertSame( 'https://example.testing.com/page', $out['other'], 'Another host that starts the same is left alone.' );
		$this->assertSame( 5, $out['number'] );
		$this->assertSame( '{{home_url}}/', $out['nested']['deep'] );
		$this->assertSame( 'a:1:{i:0;s:20:"https://example.test";}', $out['serial'], 'Serialized strings are never rewritten.' );
	}

	public function test_a_wordpress_url_in_a_subfolder_wins(): void {
		Functions\when( 'site_url' )->justReturn( 'https://example.test/wp' );

		$this->assertSame( '{{site_url}}/wp-login.php', Placeholders::compact( 'https://example.test/wp/wp-login.php' ) );
		$this->assertSame( '{{home_url}}/blog', Placeholders::compact( 'https://example.test/blog' ) );
	}

	public function test_expand_uses_this_sites_urls_and_the_filter(): void {
		Filters\expectApplied( 'axle_config_sync_url_placeholders' )->once()->andReturnUsing(
			static function ( array $map ) {
				$map['{{home_url}}'] = 'https://live.example.com/';
				$map['bad key']      = 'ignored';
				return $map;
			}
		);

		$this->assertSame( array( 'https://live.example.com/about', 7 ), Placeholders::expand( array( '{{home_url}}/about', 7 ) ) );
	}
}
