<?php
/**
 * Plugin Name: Axle Config Sync e2e fixture
 * Description: Test-only switches for the end-to-end tests: constants from an option, a classic menu location, a URL placeholder override and a probe URL the container can reach. Copied into mu-plugins by the tests.
 *
 * @package AryanX\Axle\ConfigSync
 */

// phpcs:ignoreFile

// Application passwords need HTTPS unless the site is local; the test site is plain HTTP.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

// Constants a real site sets in wp-config.php: secrets and the environment name.
foreach ( (array) get_option( 'acs_fixture_constants', array() ) as $acs_name => $acs_value ) {
	if ( is_string( $acs_name ) && preg_match( '/^(AXLE_SECRET_[A-Z0-9_]+|AXLE_CONFIG_SYNC_ENV)$/', $acs_name ) && ! defined( $acs_name ) ) {
		define( $acs_name, (string) $acs_value );
	}
}

// Block themes have no classic menu locations; the menu tests need one.
add_action(
	'after_setup_theme',
	static function () {
		register_nav_menus(
			array(
				'primary' => 'Primary',
				'footer'  => 'Footer',
			)
		);
	}
);

// Pretend the files are applied on another domain.
add_filter(
	'axle_config_sync_url_placeholders',
	static function ( $map ) {
		$home = get_option( 'acs_fixture_home' );
		if ( $home ) {
			$map['{{home_url}}'] = $home;
		}
		return $map;
	}
);

// Inside the container the site answers on port 80, not the published port.
add_filter(
	'axle_config_sync_probe_url',
	static function ( $url ) {
		return preg_replace( '#^https?://[^/]+#', 'http://localhost', $url );
	}
);
