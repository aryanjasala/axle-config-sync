<?php
/**
 * Plugin Name: Axle Config Sync e2e fixture
 * Description: Test-only switches for the end-to-end tests: constants from an option, a classic menu location, a URL placeholder override and a probe URL the container can reach, captured mail and webhook calls, and a forced web server name. Copied into mu-plugins by the tests.
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

// The drift email and webhook are captured instead of sent.
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		update_option( 'acs_fixture_mail', $atts, false );
		return true;
	},
	10,
	2
);
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, '8.8.8.8/acs-hook' ) ) {
			return $pre;
		}
		update_option(
			'acs_fixture_hook',
			array(
				'url'  => $url,
				'body' => $args['body'],
			),
			false
		);
		return array(
			'headers'  => array(),
			'body'     => 'ok',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Say which web server this is, to see the nginx advice on an Apache test site.
add_filter(
	'axle_config_sync_web_server',
	static function ( $server ) {
		$forced = get_option( 'acs_fixture_server' );
		return $forced ? $forced : $server;
	}
);
