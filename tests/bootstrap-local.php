<?php
/**
 * Stand-ins for WordPress functions the drift watch and the WP-CFM import call.
 * Loaded after the shared stubs, which the template owns.
 *
 * @package AryanX\Axle\ConfigSync
 */

// phpcs:ignoreFile

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( trim( (string) $email ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $email ) : '';
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		$host = (string) parse_url( (string) $url, PHP_URL_HOST );

		return '' !== $host && 'localhost' !== $host && ! preg_match( '/^(127\.|10\.|192\.168\.)/', $host );
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $text, $flags = ENT_QUOTES ) {
		return htmlspecialchars_decode( (string) $text, $flags );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ) {
		return preg_replace( '/[^A-Za-z0-9._\-\/]/', '-', (string) $name );
	}
}
