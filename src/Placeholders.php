<?php
/**
 * Site URLs in values become {{home_url}} and {{site_url}} in files.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Every string in a value that holds this site's home or WordPress URL (over
 * http or https, with plain or JSON-escaped slashes) is written with a
 * placeholder, and expanded to the target site's URL on apply.
 *
 * It walks the unserialized value and only changes plain strings. A string
 * that is itself serialized PHP is left alone, since changing its length
 * would break it.
 */
final class Placeholders {

	/**
	 * Placeholder => URL on this site.
	 *
	 * @return array<string, string>
	 */
	public static function map(): array {
		$defaults = array(
			'{{home_url}}' => untrailingslashit( home_url() ),
			'{{site_url}}' => untrailingslashit( site_url() ),
		);

		/**
		 * Filters the URL placeholders: placeholder => this site's URL.
		 *
		 * Export writes the placeholder where the URL was; apply writes the
		 * URL where the placeholder is. Add more for CDNs or other domains.
		 *
		 * @param array<string, string> $placeholders Placeholders.
		 */
		$map = Messages::as_array( apply_filters( 'axle_config_sync_url_placeholders', $defaults ) );

		$out = array();
		foreach ( $map as $placeholder => $url ) {
			if ( is_string( $placeholder ) && preg_match( '/^\{\{[a-z0-9_]+\}\}$/', $placeholder ) && is_string( $url ) && '' !== $url ) {
				$out[ $placeholder ] = untrailingslashit( $url );
			}
		}

		return $out;
	}

	/**
	 * Replaces this site's URLs with placeholders.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function compact( $value ) {
		$map = self::map();
		// Longest URL first, so a WordPress URL in a subfolder wins over the home URL.
		uasort(
			$map,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);

		$patterns = array();
		foreach ( $map as $placeholder => $url ) {
			$bare = (string) preg_replace( '#^https?://#i', '', $url );
			if ( isset( $patterns[ $bare ] ) || '' === $bare ) {
				continue;
			}
			$quoted            = preg_quote( $bare, '#' );
			$escaped           = preg_quote( str_replace( '/', '\\/', $bare ), '#' );
			$patterns[ $bare ] = array(
				'#https?://' . $quoted . '(?=$|[/?\#"\'\s<>\\\\)])#i'         => $placeholder,
				'#https?:\\\\/\\\\/' . $escaped . '(?=$|[/?\#"\'\s<>\\\\)])#i' => $placeholder,
			);
		}

		return Pointer::map_leaves(
			$value,
			static function ( $leaf ) use ( $patterns ) {
				if ( ! is_string( $leaf ) || '' === $leaf || is_serialized( $leaf ) || false === stripos( $leaf, 'http' ) ) {
					return $leaf;
				}
				foreach ( $patterns as $pair ) {
					foreach ( $pair as $pattern => $placeholder ) {
						$leaf = (string) preg_replace( $pattern, $placeholder, $leaf );
					}
				}
				return $leaf;
			}
		);
	}

	/**
	 * Replaces placeholders with this site's URLs.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function expand( $value ) {
		$map = self::map();

		return Pointer::map_leaves(
			$value,
			static function ( $leaf ) use ( $map ) {
				if ( ! is_string( $leaf ) || false === strpos( $leaf, '{{' ) || is_serialized( $leaf ) ) {
					return $leaf;
				}
				return strtr( $leaf, $map );
			}
		);
	}
}
