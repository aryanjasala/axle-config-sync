<?php
/**
 * Tracking rules, kept in config-sync.json so they are in git too.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Which providers are on, which plugin options are tracked (names or
 * `prefix_*` patterns, with an optional owner plugin) and which flagged values
 * are "not a secret". Saved to config-sync.json in the config directory, with
 * a copy in the settings option for when the directory cannot be written.
 */
final class Rules {

	public const FILE = 'config-sync.json';

	/**
	 * The config directory.
	 *
	 * @var Directory
	 */
	private Directory $directory;

	/**
	 * Cached rules.
	 *
	 * @var array|null
	 */
	private ?array $rules = null;

	/**
	 * Sets up the rules.
	 *
	 * @param Directory $directory The config directory.
	 */
	public function __construct( Directory $directory ) {
		$this->directory = $directory;
	}

	/**
	 * Rules with defaults.
	 *
	 * @return array{providers: array<string, bool>, options: string[], owners: array<string, string>, not_secret: string[]}
	 */
	public function all(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		$saved = $this->directory->read_json( self::FILE );
		if ( null === $saved ) {
			$settings = get_option( Settings::OPTION, array() );
			$saved    = is_array( $settings ) && isset( $settings['rules'] ) && is_array( $settings['rules'] ) ? $settings['rules'] : array();
		}

		$this->rules = self::clean( $saved );

		return $this->rules;
	}

	/**
	 * Cleans rules from a file or a form.
	 *
	 * @param array $raw Raw rules.
	 * @return array{providers: array<string, bool>, options: string[], owners: array<string, string>, not_secret: string[]}
	 */
	public static function clean( array $raw ): array {
		$providers = array();
		foreach ( Registry::DEFAULT_PROVIDERS as $name ) {
			$providers[ $name ] = ! isset( $raw['providers'][ $name ] ) || (bool) $raw['providers'][ $name ];
		}
		if ( isset( $raw['providers'] ) && is_array( $raw['providers'] ) ) {
			foreach ( $raw['providers'] as $name => $on ) {
				$name = sanitize_key( (string) $name );
				if ( '' !== $name ) {
					$providers[ $name ] = (bool) $on;
				}
			}
		}

		$options = array();
		foreach ( (array) ( $raw['options'] ?? array() ) as $pattern ) {
			$pattern = self::clean_pattern( (string) $pattern );
			if ( '' !== $pattern ) {
				$options[] = $pattern;
			}
		}

		$owners = array();
		foreach ( (array) ( $raw['owners'] ?? array() ) as $pattern => $owner ) {
			$pattern = self::clean_pattern( (string) $pattern );
			$owner   = sanitize_key( (string) $owner );
			if ( '' !== $pattern && '' !== $owner ) {
				$owners[ $pattern ] = $owner;
			}
		}

		$not_secret = array();
		foreach ( (array) ( $raw['not_secret'] ?? array() ) as $mark ) {
			$mark = trim( sanitize_text_field( (string) $mark ) );
			if ( '' !== $mark ) {
				$not_secret[] = $mark;
			}
		}

		$options    = array_values( array_unique( $options ) );
		$not_secret = array_values( array_unique( $not_secret ) );
		sort( $options );
		sort( $not_secret );
		ksort( $owners );

		return array(
			'providers'  => $providers,
			'options'    => $options,
			'owners'     => $owners,
			'not_secret' => $not_secret,
		);
	}

	/**
	 * An option name or pattern: letters, digits, _ - . and *.
	 *
	 * @param string $pattern Raw.
	 * @return string
	 */
	public static function clean_pattern( string $pattern ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-.*]/', '', trim( $pattern ) );
	}

	/**
	 * Saves the rules to config-sync.json and the settings option.
	 *
	 * @param array $rules Rules.
	 * @return bool Whether the file was written.
	 */
	public function save( array $rules ): bool {
		$rules       = self::clean( $rules );
		$this->rules = $rules;

		$settings          = get_option( Settings::OPTION, array() );
		$settings          = is_array( $settings ) ? $settings : array();
		$settings['rules'] = $rules;
		update_option( Settings::OPTION, $settings );

		return $this->write_file();
	}

	/**
	 * Writes config-sync.json from the current rules.
	 *
	 * @return bool
	 */
	public function write_file(): bool {
		$rules = $this->all();
		if ( ! $this->directory->ensure() ) {
			return false;
		}

		$data = array_merge( array( 'schema' => Codec::SCHEMA ), $rules );
		if ( ! $data['owners'] ) {
			$data['owners'] = new \stdClass();
		}

		return $this->directory->write( self::FILE, Codec::to_json( $data ) );
	}

	/**
	 * Whether a provider is on.
	 *
	 * @param string $name Provider name.
	 * @return bool
	 */
	public function provider_on( string $name ): bool {
		$providers = $this->all()['providers'];

		return $providers[ $name ] ?? true;
	}

	/**
	 * Tracked option names and patterns, beyond the core settings.
	 *
	 * @return string[]
	 */
	public function tracked(): array {
		/**
		 * Filters the tracked option names and `prefix_*` patterns.
		 *
		 * The core settings screens are always tracked. Add plugin options here
		 * or on the Track tab.
		 *
		 * @param string[] $options Names and patterns from config-sync.json.
		 */
		$tracked = (array) apply_filters( 'axle_config_sync_tracked_options', $this->all()['options'] );

		return array_values( array_filter( array_map( array( self::class, 'clean_pattern' ), array_map( 'strval', $tracked ) ) ) );
	}

	/**
	 * Adds a name or pattern to the tracked list.
	 *
	 * @param string $pattern Name or pattern.
	 * @param string $owner   Owner plugin slug, optional.
	 * @return bool Whether the file was written.
	 */
	public function track( string $pattern, string $owner = '' ): bool {
		$rules              = $this->all();
		$rules['options'][] = $pattern;
		if ( '' !== $owner ) {
			$rules['owners'][ $pattern ] = $owner;
		}

		return $this->save( $rules );
	}

	/**
	 * Removes a name or pattern from the tracked list.
	 *
	 * @param string $pattern Name or pattern.
	 * @return bool Whether it was tracked.
	 */
	public function untrack( string $pattern ): bool {
		$rules = $this->all();
		$found = in_array( $pattern, $rules['options'], true );

		$rules['options'] = array_values( array_diff( $rules['options'], array( $pattern ) ) );
		unset( $rules['owners'][ $pattern ] );
		$this->save( $rules );

		return $found;
	}

	/**
	 * Whether an option name matches a tracked rule.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public function is_tracked( string $name ): bool {
		return self::matches_any( $name, $this->tracked() );
	}

	/**
	 * The owner set for an option by a rule, or empty.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	public function owner_of( string $name ): string {
		foreach ( $this->all()['owners'] as $pattern => $owner ) {
			if ( self::matches( $name, (string) $pattern ) ) {
				return (string) $owner;
			}
		}

		return '';
	}

	/**
	 * Whether a value was marked "not a secret".
	 *
	 * Marks are `item` (the whole item) or `item#/pointer`.
	 *
	 * @param string $id      Item ID.
	 * @param string $pointer Pointer.
	 * @return bool
	 */
	public function is_not_secret( string $id, string $pointer ): bool {
		$marks = array_merge( $this->all()['not_secret'], array( 'options/moderation_keys', 'options/disallowed_keys', 'options/blacklist_keys' ) );
		foreach ( $marks as $mark ) {
			$parts = explode( '#', (string) $mark, 2 );
			if ( $parts[0] === $id && ( ! isset( $parts[1] ) || $parts[1] === $pointer || ( '/' === $parts[1] && '' === $pointer ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Marks a value as not a secret.
	 *
	 * @param string $mark item or item#/pointer.
	 * @return bool Whether the file was written.
	 */
	public function mark_not_secret( string $mark ): bool {
		$rules                 = $this->all();
		$rules['not_secret'][] = $mark;

		return $this->save( $rules );
	}

	/**
	 * Whether a name matches a pattern: exact, or `*` for any characters.
	 *
	 * @param string $name    Name.
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	public static function matches( string $name, string $pattern ): bool {
		if ( false === strpos( $pattern, '*' ) ) {
			return $name === $pattern;
		}

		return 1 === preg_match( '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/', $name );
	}

	/**
	 * Whether a name matches any pattern.
	 *
	 * @param string   $name     Name.
	 * @param string[] $patterns Patterns.
	 * @return bool
	 */
	public static function matches_any( string $name, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( self::matches( $name, (string) $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Forgets the cached rules.
	 */
	public function flush(): void {
		$this->rules = null;
	}
}
