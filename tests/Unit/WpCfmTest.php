<?php
/**
 * Tests for the WP-CFM import.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Changesets;
use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Import\WpCfm;
use AryanX\Axle\ConfigSync\Registry;
use AryanX\Axle\ConfigSync\Rules;
use AryanX\Axle\ConfigSync\State;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * Covers WpCfm. The bundle format comes from WP-CFM 1.7: a JSON file that
 * maps option names to raw option values, plus a `.label` key.
 */
final class WpCfmTest extends SyncTestCase {

	/**
	 * The options provider.
	 *
	 * @var MemoryProvider
	 */
	private MemoryProvider $site;

	/**
	 * The theme mods provider.
	 *
	 * @var MemoryProvider
	 */
	private MemoryProvider $mods;

	/**
	 * Where WP-CFM keeps its files.
	 *
	 * @var string
	 */
	private string $cfm = '';

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * The importer.
	 *
	 * @var WpCfm
	 */
	private WpCfm $wpcfm;

	protected function setUp(): void {
		parent::setUp();
		$this->site                  = new MemoryProvider();
		$this->site->providerName = 'options';
		$this->site->store        = array( 'blogname' => 'Live name' );
		$this->mods                  = new MemoryProvider();
		$this->mods->providerName    = 'theme-mods';

		Filters\expectApplied( 'axle_config_sync_providers' )->zeroOrMoreTimes()->andReturn(
			array(
				'options'    => $this->site,
				'theme-mods' => $this->mods,
			)
		);
		Filters\expectApplied( 'axle_config_sync_directory' )->zeroOrMoreTimes()->andReturn( $this->dir );

		$this->cfm = sys_get_temp_dir() . '/axle-config-sync-wpcfm-' . uniqid();
		mkdir( $this->cfm, 0777, true );
		Filters\expectApplied( 'axle_config_sync_wpcfm_directory' )->zeroOrMoreTimes()->andReturn( $this->cfm );

		$directory    = new Directory();
		$rules        = new Rules( $directory );
		$this->engine = new Engine( $directory, $rules, new State(), new Changesets(), new Registry( $rules ) );
		$this->wpcfm  = new WpCfm( $this->engine );
	}

	protected function tearDown(): void {
		$this->remove( $this->cfm );
		parent::tearDown();
	}

	/**
	 * Writes a WP-CFM bundle file.
	 *
	 * @param string $name   Bundle name.
	 * @param array  $values Option name => raw value.
	 * @param string $label  Bundle label.
	 */
	private function bundle( string $name, array $values, string $label = '' ): void {
		file_put_contents( $this->cfm . '/' . $name . '.json', json_encode( array_merge( array( '.label' => '' !== $label ? $label : $name ), $values ), JSON_PRETTY_PRINT ) );
	}

	public function test_decode_reads_what_the_database_holds_and_refuses_objects(): void {
		$this->assertSame( 'plain', WpCfm::decode( 'plain' )['value'] );
		$this->assertSame( array( 'a' => 1 ), WpCfm::decode( serialize( array( 'a' => 1 ) ) )['value'] );
		$this->assertSame( '1', WpCfm::decode( true )['value'] );
		$this->assertSame( '12', WpCfm::decode( 12 )['value'] );
		$this->assertSame( array( 'x' ), WpCfm::decode( array( 'x' ) )['value'] );
		$this->assertSame( '', WpCfm::decode( 'b:0;' )['reason'] );

		$object = WpCfm::decode( serialize( new \ArrayObject( array( 1 ) ) ) );
		$this->assertStringContainsString( 'PHP object', $object['reason'] );

		$nested = WpCfm::decode( 'a:1:{s:1:"x";O:8:"stdClass":0:{}}' );
		$this->assertStringContainsString( 'PHP object', $nested['reason'] );

		$this->assertStringContainsString( 'damaged', WpCfm::decode( 'a:2:{s:1:"x";' )['reason'] );
		$this->assertNotSame( '', WpCfm::decode( null )['reason'] );
	}

	public function test_decode_never_loads_a_class_from_a_value(): void {
		$GLOBALS['axle_cfm_destructed'] = false;
		if ( ! class_exists( 'AxleCfmTrap' ) ) {
			eval( 'class AxleCfmTrap { public function __destruct() { $GLOBALS["axle_cfm_destructed"] = true; } public function __wakeup() { $GLOBALS["axle_cfm_destructed"] = true; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- A trap class that proves no object is ever built.
		}

		$result = WpCfm::decode( 'O:11:"AxleCfmTrap":0:{}' );

		$this->assertNotSame( '', $result['reason'] );
		$this->assertFalse( $GLOBALS['axle_cfm_destructed'] );
	}

	public function test_items_map_to_providers_and_the_rest_come_with_a_reason(): void {
		$this->assertSame( 'options/blogname', $this->wpcfm->map( 'blogname' )['id'] );
		$this->assertSame( 'options/acme_settings', $this->wpcfm->map( 'acme_settings' )['id'] );
		$this->assertSame( 'theme-mods/astra', $this->wpcfm->map( 'theme_mods_astra' )['id'] );

		foreach ( array( 'siteurl', 'home', 'auth_key', 'cron', 'active_plugins', '_transient_x' ) as $key ) {
			$this->assertSame( '', $this->wpcfm->map( $key )['id'], $key );
			$this->assertStringContainsString( 'never syncs', $this->wpcfm->map( $key )['reason'], $key );
		}
		foreach ( array( 'sidebars_widgets', 'widget_text', 'nav_menu_options', 'wp_user_roles' ) as $key ) {
			$this->assertSame( '', $this->wpcfm->map( $key )['id'], $key );
			$this->assertStringContainsString( 'wp axle-config-sync export', $this->wpcfm->map( $key )['reason'], $key );
		}
		$this->assertSame( '', $this->wpcfm->map( 'bad name!' )['id'] );
		$this->assertSame( '', $this->wpcfm->map( 'acme_*' )['id'] );
	}

	public function test_bundles_come_from_files_and_from_the_database_setting(): void {
		$this->bundle( 'main', array( 'blogname' => 'Bean There', '.blogname_format' => 'x' ), 'Main settings' );
		file_put_contents( $this->cfm . '/broken.json', '{not json' );
		file_put_contents( $this->cfm . '/.hidden.json', '{}' );
		$this->site->store['posts_per_page'] = '10';
		$this->options_set(
			'wpcfm_settings',
			json_encode(
				array(
					'bundles' => array(
						array(
							'label'  => 'Main settings',
							'name'   => 'main',
							'config' => array( 'blogname', 'blogdescription' ),
						),
						array(
							'label'  => 'Never pushed',
							'name'   => 'later',
							'config' => array( 'posts_per_page' ),
						),
					),
				)
			)
		);

		$bundles = $this->wpcfm->bundles();

		$this->assertSame( array( 'broken', 'later', 'main' ), array_keys( $bundles ) );
		$this->assertSame( 'both', $bundles['main']['source'] );
		$this->assertSame( 'Main settings', $bundles['main']['label'] );
		$this->assertSame( array( 'blogname', 'blogdescription' ), $bundles['main']['keys'] );
		$this->assertSame( 'database', $bundles['later']['source'] );
		$this->assertSame( 'files', $bundles['broken']['source'] );
		$this->assertStringContainsString( 'not valid JSON', $bundles['broken']['error'] );
		$this->assertTrue( $this->wpcfm->detected() );
	}

	public function test_nothing_is_detected_without_bundles(): void {
		$this->assertFalse( $this->wpcfm->detected() );
		$this->assertSame( array(), $this->wpcfm->plan()['items'] );
	}

	public function test_multisite_reads_only_this_sites_bundles(): void {
		\Brain\Monkey\Functions\when( 'is_multisite' )->justReturn( true );
		$this->bundle( 'blog1-main', array( 'blogname' => 'One' ) );
		$this->bundle( 'blog2-main', array( 'blogname' => 'Two' ) );
		$this->bundle( 'network-main', array( 'blogname' => 'Network' ) );

		$bundles = $this->wpcfm->bundles();

		$this->assertSame( array( 'main' ), array_keys( $bundles ) );
		$this->assertSame( 'One', $bundles['main']['values']['blogname'] );
	}

	public function test_plan_maps_every_item_and_says_why_one_is_left_out(): void {
		$this->bundle(
			'main',
			array(
				'blogname'    => 'Bean There',
				'siteurl'     => 'https://example.test',
				'acme_list'   => serialize( array( 'a', 'b' ) ),
				'acme_object' => serialize( new \ArrayObject( array() ) ),
				'widget_text' => 'a:0:{}',
			)
		);

		$plan = $this->wpcfm->plan();
		$rows = array();
		foreach ( $plan['items'] as $row ) {
			$rows[ $row['key'] ] = $row;
		}

		$this->assertSame( array( 'main' ), $plan['bundles'] );
		$this->assertSame( 'new', $rows['blogname']['action'] );
		$this->assertSame( 'options/blogname', $rows['blogname']['id'] );
		$this->assertSame( 'new', $rows['acme_list']['action'] );
		$this->assertSame( array( 'a', 'b' ), $plan['values']['options/acme_list'] );
		$this->assertSame( 'skip', $rows['siteurl']['action'] );
		$this->assertSame( 'skip', $rows['acme_object']['action'] );
		$this->assertStringContainsString( 'PHP object', $rows['acme_object']['reason'] );
		$this->assertSame( 'skip', $rows['widget_text']['action'] );
	}

	public function test_a_value_already_in_its_file_is_the_same_and_a_different_one_replaces_it(): void {
		$this->site->store = array(
			'blogname'        => 'Bean There',
			'blogdescription' => 'x',
		);
		$this->engine->export();
		$this->bundle(
			'main',
			array(
				'blogname'        => 'Bean There',
				'blogdescription' => 'A different tagline',
			)
		);

		$actions = array();
		foreach ( $this->wpcfm->plan()['items'] as $row ) {
			$actions[ $row['key'] ] = $row['action'];
		}

		$this->assertSame(
			array(
				'blogname'        => 'same',
				'blogdescription' => 'replace',
			),
			$actions
		);
	}

	public function test_the_same_option_in_two_bundles_is_taken_once(): void {
		$this->bundle( 'a', array( 'blogname' => 'From a' ) );
		$this->bundle( 'b', array( 'blogname' => 'From b' ) );

		$plan = $this->wpcfm->plan();

		$this->assertSame( 'new', $plan['items'][0]['action'] );
		$this->assertSame( 'skip', $plan['items'][1]['action'] );
		$this->assertStringContainsString( '"a"', $plan['items'][1]['reason'] );
		$this->assertSame( 'From a', $plan['values']['options/blogname'] );
	}

	public function test_a_bundle_only_in_the_database_takes_live_values(): void {
		$this->options_set(
			'wpcfm_settings',
			json_encode(
				array(
					'bundles' => array(
						array(
							'label'  => 'Later',
							'name'   => 'later',
							'config' => array( 'blogname', 'not_on_this_site' ),
						),
					),
				)
			)
		);

		$plan = $this->wpcfm->plan();

		$this->assertSame( 'new', $plan['items'][0]['action'] );
		$this->assertSame( 'Live name', $plan['values']['options/blogname'] );
		$this->assertSame( 'skip', $plan['items'][1]['action'] );
		$this->assertStringContainsString( 'no file and no value', $plan['items'][1]['reason'] );
	}

	public function test_a_secret_is_masked_in_the_file_and_never_in_the_plan(): void {
		$this->bundle( 'main', array( 'acme_api_key' => 'sk_live_abcdefghijkl' ) );

		$plan = $this->wpcfm->plan();

		$this->assertSame( 'new', $plan['items'][0]['action'] );
		$this->assertStringNotContainsString( 'sk_live', $plan['items'][0]['value'] );

		$this->wpcfm->import();
		$this->assertStringNotContainsString( 'sk_live', (string) file_get_contents( $this->dir . '/options/acme_api_key.json' ) );
	}

	public function test_a_dry_run_writes_nothing(): void {
		$this->bundle( 'main', array( 'blogname' => 'Bean There' ) );

		$result = $this->wpcfm->import( null, true );

		$this->assertSame( array(), $result['written'] );
		$this->assertFileDoesNotExist( $this->dir . '/options/blogname.json' );
		$this->assertSame( 'new', $result['plan']['items'][0]['action'] );
	}

	public function test_import_writes_config_sync_files_tracks_plugin_options_and_leaves_wpcfm_alone(): void {
		$this->bundle(
			'main',
			array(
				'blogname'     => 'Bean There',
				'acme_options' => serialize( array( 'mode' => 'live' ) ),
				'theme_mods_astra' => serialize(
					array(
						'header_layout'      => 'wide',
						'nav_menu_locations' => array( 'primary' => 3 ),
					)
				),
			)
		);
		$before = (string) file_get_contents( $this->cfm . '/main.json' );
		Actions\expectDone( 'axle_config_sync_wpcfm_imported' )->once();

		$result = $this->wpcfm->import( array( 'main' ) );

		$this->assertEqualsCanonicalizing( array( 'options/blogname', 'options/acme_options', 'theme-mods/astra' ), array_keys( $result['written'] ) );
		$this->assertSame( 'Bean There', $this->read( 'options/blogname.json' )['value'] );
		$this->assertSame( array( 'mode' => 'live' ), $this->read( 'options/acme_options.json' )['value'] );
		$this->assertSame( array( 'header_layout' => 'wide' ), $this->read( 'theme-mods/astra.json' )['value'], 'Menu locations are not part of theme mods.' );
		$this->assertSame( array( 'acme_options' ), $result['tracked'] );
		$this->assertContains( 'acme_options', $this->engine->rules()->all()['options'] );
		$this->assertSame( $before, (string) file_get_contents( $this->cfm . '/main.json' ), 'WP-CFM files are never touched.' );
		$this->assertSame( array( 'blogname' => 'Live name' ), $this->site->store, 'The site is never changed.' );
		$this->assertSame( array(), $this->site->writes );
	}

	public function test_import_limits_are_filterable(): void {
		$this->bundle( 'main', array( 'a' => '1', 'b' => '2', 'c' => '3' ) );
		Filters\expectApplied( 'axle_config_sync_wpcfm_max_items' )->once()->andReturn( 2 );

		$plan = $this->wpcfm->plan();

		$this->assertCount( 2, $plan['items'] );
		$this->assertArrayHasKey( 'main', $plan['errors'] );
	}

	/**
	 * Sets an option for get_option().
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 */
	private function options_set( string $name, $value ): void {
		$this->options[ $name ] = $value;
	}

	public function test_the_mapping_and_the_file_size_limit_are_filterable(): void {
		Filters\expectApplied( 'axle_config_sync_wpcfm_map' )->once()->andReturnUsing(
			static function ( array $map, string $key ) {
				return 'widget_text' === $key ? array(
					'id'     => 'options/widget_text',
					'reason' => '',
				) : $map;
			}
		);
		$this->assertSame( 'options/widget_text', $this->wpcfm->map( 'widget_text' )['id'] );

		Filters\expectApplied( 'axle_config_sync_wpcfm_map' )->once()->andReturn( 'nonsense' );
		$this->assertSame( 'options/blogname', $this->wpcfm->map( 'blogname' )['id'], 'A bad filter result falls back to the built-in mapping.' );

		$this->assertSame( 5 * MB_IN_BYTES, WpCfm::max_file_size() );
		Filters\expectApplied( 'axle_config_sync_wpcfm_max_file_size' )->once()->andReturn( 10 );
		$this->assertSame( KB_IN_BYTES, WpCfm::max_file_size() );
	}

	public function test_a_file_over_the_size_limit_is_listed_with_an_error(): void {
		$this->bundle( 'big', array( 'blogname' => str_repeat( 'x', 3000 ) ) );
		Filters\expectApplied( 'axle_config_sync_wpcfm_max_file_size' )->twice()->andReturn( KB_IN_BYTES );

		$bundle = $this->wpcfm->bundles()['big'];

		$this->assertStringContainsString( 'size limit', $bundle['error'] );
		$this->assertSame( array(), $this->wpcfm->plan( array( 'big' ) )['items'] );
	}
}
