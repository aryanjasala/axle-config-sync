<?php
/**
 * Finding secrets in values, and resolving secret names on apply.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Secrets never go into files. A value counts as a secret when its key path
 * matches a pattern (key, secret, token, password, ...) or when it looks like
 * one: long, random and without spaces. In the file it becomes
 * `{"$secret":"NAME"}`, and apply resolves NAME from the constant
 * AXLE_SECRET_NAME, then the environment variable of the same name, then the
 * `axle_config_sync_secret` filter.
 */
final class Secrets {

	/**
	 * Tracking rules, for the "not a secret" marks.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Sets up secret handling.
	 *
	 * @param Rules $rules Tracking rules.
	 */
	public function __construct( Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Key path patterns that mark a value as a secret.
	 *
	 * @return string[] Regular expression fragments, matched as whole words.
	 */
	public static function key_patterns(): array {
		$defaults = array( 'key', 'secret', 'token', 'password', 'passwd', 'api', 'license', 'licence', 'private', 'auth', 'credential', 'webhook' );

		/**
		 * Filters the key path words that mark a value as a secret.
		 *
		 * Each is matched as a whole word in the item and key path, so `api`
		 * matches `acme_api_key` but not `rapid`. A trailing `s` is allowed.
		 *
		 * @param string[] $patterns Words or regular expression fragments.
		 */
		$patterns = (array) apply_filters( 'axle_config_sync_secret_key_patterns', $defaults );

		$patterns = array_values( array_filter( array_map( 'strval', $patterns ) ) );

		return $patterns ? $patterns : $defaults;
	}

	/**
	 * Whether a value is a secret.
	 *
	 * @param string $id      Item ID, such as options/acme_api_key.
	 * @param string $pointer Where in the item's value.
	 * @param mixed  $value   The leaf value.
	 * @return bool
	 */
	public function is_secret( string $id, string $pointer, $value ): bool {
		$secret = false;
		if ( is_string( $value ) && '' !== $value && ! $this->rules->is_not_secret( $id, $pointer ) ) {
			$secret = ( self::key_matches( $id, $pointer ) && self::looks_like_key_value( $value ) ) || self::looks_random( $value );
		}

		/**
		 * Filters whether a value is treated as a secret, so it never goes into a file.
		 *
		 * @param bool   $secret  Whether it is.
		 * @param string $id      Item ID.
		 * @param string $pointer JSON pointer inside the item's value.
		 * @param mixed  $value   The value.
		 */
		return (bool) apply_filters( 'axle_config_sync_is_secret', $secret, $id, $pointer, $value );
	}

	/**
	 * Replaces every secret in a value with a marker.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	public function mask( string $id, $value ) {
		return Pointer::map_leaves(
			$value,
			function ( $leaf, string $pointer ) use ( $id ) {
				if ( $leaf instanceof Marker || ! $this->is_secret( $id, $pointer, $leaf ) ) {
					return $leaf;
				}
				return Marker::secret( self::name_for( $id, $pointer ), self::fingerprint( $leaf ) );
			}
		);
	}

	/**
	 * Pointers of secrets that are still in plain text in a value.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Value.
	 * @return string[]
	 */
	public function find( string $id, $value ): array {
		$found = array();
		Pointer::map_leaves(
			$value,
			function ( $leaf, string $pointer ) use ( $id, &$found ) {
				if ( ! $leaf instanceof Marker && $this->is_secret( $id, $pointer, $leaf ) ) {
					$found[] = '' === $pointer ? '/' : $pointer;
				}
				return $leaf;
			}
		);

		return $found;
	}

	/**
	 * Resolves the secret markers in a file value.
	 *
	 * A secret that resolves is put in. One that does not keeps the live value
	 * at the same place, with a warning; when there is no live value there,
	 * the item is blocked.
	 *
	 * @param string $id       Item ID.
	 * @param mixed  $value    File value with markers.
	 * @param mixed  $live     Live value, or null.
	 * @param array  $problems Collects problems: array{type, message, block, secret}.
	 * @param bool   $on_site  Whether the item exists on the site at all.
	 * @return mixed
	 */
	public function resolve_all( string $id, $value, $live, array &$problems, bool $on_site = true ) {
		return Pointer::map_leaves(
			$value,
			static function ( $leaf, string $pointer ) use ( $id, $live, &$problems, $on_site ) {
				if ( ! $leaf instanceof Marker || Marker::SECRET !== $leaf->kind ) {
					return $leaf;
				}

				$name     = $leaf->name();
				$resolved = self::resolve( $name );
				if ( null !== $resolved ) {
					return $resolved;
				}

				$kept  = Pointer::get( $live, $pointer, $found );
				$found = $on_site && $found;

				/**
				 * Fires when a secret in a file has no value on this site.
				 *
				 * @param string $id   Item ID.
				 * @param string $name Secret name.
				 */
				do_action( 'axle_config_sync_secret_unresolved', $id, $name );

				$problems[] = array(
					'type'    => 'secret',
					'secret'  => $name,
					'block'   => ! $found,
					'message' => $found
						? Messages::get( 'secret_kept', $name, 'AXLE_SECRET_' . $name )
						: Messages::get( 'secret_missing', $name, 'AXLE_SECRET_' . $name ),
				);

				return $found ? $kept : $leaf;
			}
		);
	}

	/**
	 * Fills in secret fingerprints in a file value, so comparing it with the
	 * live value notices a secret that differs.
	 *
	 * @param mixed $value   File value with markers.
	 * @param mixed $live    Live value.
	 * @param bool  $on_site Whether the item exists on the site at all.
	 * @return mixed
	 */
	public static function fingerprint_all( $value, $live, bool $on_site = true ) {
		return Pointer::map_leaves(
			$value,
			static function ( $leaf, string $pointer ) use ( $live, $on_site ) {
				if ( ! $leaf instanceof Marker || Marker::SECRET !== $leaf->kind ) {
					return $leaf;
				}

				$copy     = Marker::secret( $leaf->name() );
				$resolved = self::resolve( $leaf->name() );
				if ( null !== $resolved ) {
					$copy->fingerprint = self::fingerprint( $resolved );
				} else {
					$kept              = Pointer::get( $live, $pointer, $found );
					$copy->fingerprint = $on_site && $found ? self::fingerprint( $kept ) : 'missing';
				}

				return $copy;
			}
		);
	}

	/**
	 * Names of the secrets in a file value that nothing sets on this site.
	 *
	 * @param mixed $value File value with markers.
	 * @return string[]
	 */
	public static function unresolved( $value ): array {
		$names = array();
		Pointer::map_leaves(
			$value,
			static function ( $leaf ) use ( &$names ) {
				if ( $leaf instanceof Marker && Marker::SECRET === $leaf->kind && null === self::resolve( $leaf->name() ) ) {
					$names[] = $leaf->name();
				}
				return $leaf;
			}
		);

		return array_values( array_unique( $names ) );
	}

	/**
	 * The value of a secret on this site.
	 *
	 * @param string $name Secret name, such as ACME_API_KEY.
	 * @return string|null Null when nothing sets it.
	 */
	public static function resolve( string $name ): ?string {
		$name  = self::sanitize_name( $name );
		$value = null;

		if ( '' !== $name ) {
			$constant = 'AXLE_SECRET_' . $name;
			if ( defined( $constant ) && is_scalar( constant( $constant ) ) ) {
				$value = (string) constant( $constant );
			} else {
				$env = getenv( $constant );
				if ( false !== $env ) {
					$value = (string) $env;
				}
			}
		}

		/**
		 * Filters the value of a secret, for secrets kept somewhere other than
		 * constants and environment variables (a vault, a host's secret store).
		 *
		 * Return null when the secret is not set.
		 *
		 * @param string|null $value Value from AXLE_SECRET_<NAME>, or null.
		 * @param string      $name  Secret name.
		 */
		$value = apply_filters( 'axle_config_sync_secret', $value, $name );

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * The secret name for a place in an item: the item key and the path, upper case.
	 *
	 * @param string $id      Item ID, such as options/acme_api_key.
	 * @param string $pointer Pointer inside the value.
	 * @return string Such as ACME_API_KEY.
	 */
	public static function name_for( string $id, string $pointer ): string {
		$key   = false !== strpos( $id, '/' ) ? substr( $id, strpos( $id, '/' ) + 1 ) : $id;
		$parts = array_merge( array( $key ), Pointer::parts( $pointer ) );

		return self::sanitize_name( implode( '_', $parts ) );
	}

	/**
	 * Upper case letters, digits and underscores only.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function sanitize_name( string $name ): string {
		return trim( (string) preg_replace( '/_+/', '_', (string) preg_replace( '/[^A-Z0-9]+/', '_', strtoupper( $name ) ) ), '_' );
	}

	/**
	 * Whether the item and key path match a secret word.
	 *
	 * @param string $id      Item ID.
	 * @param string $pointer Pointer.
	 * @return bool
	 */
	public static function key_matches( string $id, string $pointer ): bool {
		$path  = $id . $pointer;
		$path  = strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $path ) );
		$words = implode( '|', array_map( 'strval', self::key_patterns() ) );

		return 1 === preg_match( '/(^|[^a-z0-9])(?:' . $words . ')s?($|[^a-z0-9])/', $path );
	}

	/**
	 * Whether a string under a secret-looking key is a real value, not a flag
	 * such as "valid" or "yes": 8 characters or more, no spaces.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_like_key_value( string $value ): bool {
		return strlen( $value ) >= 8 && ! preg_match( '/\s/', $value ) && false === strpos( $value, '{{' );
	}

	/**
	 * Whether a string looks like a random key wherever it is: 32 characters
	 * or more, letters and digits, no spaces, not a URL, and high entropy.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_random( string $value ): bool {
		$length = strlen( $value );
		if ( $length < 32 || $length > 512 || preg_match( '/\s/', $value ) || false !== strpos( $value, '://' ) || false !== strpos( $value, '{{' ) ) {
			return false;
		}

		if ( ! preg_match( '/[0-9]/', $value ) || ! preg_match( '/[a-zA-Z]/', $value ) ) {
			return false;
		}

		return self::entropy( $value ) >= 3.5;
	}

	/**
	 * Shannon entropy in bits per character.
	 *
	 * @param string $value Value.
	 * @return float
	 */
	public static function entropy( string $value ): float {
		$length = strlen( $value );
		if ( 0 === $length ) {
			return 0.0;
		}

		$entropy = 0.0;
		foreach ( count_chars( $value, 1 ) as $count ) {
			$p        = $count / $length;
			$entropy -= $p * log( $p, 2 );
		}

		return $entropy;
	}

	/**
	 * Hash of a secret value for comparisons. Salted with the site's salt, so
	 * the stored hashes cannot be looked up.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function fingerprint( $value ): string {
		return substr( hash_hmac( 'sha256', is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ), wp_salt( 'auth' ) ), 0, 16 );
	}
}
