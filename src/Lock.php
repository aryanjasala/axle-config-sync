<?php
/**
 * Managed in git, locked on production.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

use AryanX\Axle\ConfigSync\Admin\AxleUi;

defined( 'ABSPATH' ) || exit;

/**
 * On production, a setting that has a file in the config directory is
 * managed in git: a save from a settings screen, the Customizer or
 * /wp/v2/settings is refused, with a notice, unless the person chooses
 * "Change it here anyway". That choice needs the capability that owns the
 * setting, lasts a short while, is logged, and keeps the setting out of drift
 * reports until the next deploy puts the file value back.
 *
 * The lock never blocks WP-CLI, cron, an install, this plugin's own apply or
 * undo, or a deploy that sets AXLE_CONFIG_SYNC_DEPLOYING. It only looks at
 * requests from a signed-in person, and it fails open: if the tracking rules
 * or the config directory cannot be read, nothing is locked.
 */
final class Lock implements Service {

	public const OVERRIDES = 'axle_config_sync_lock_overrides';
	public const RELEASED  = 'axle_config_sync_lock_released';
	public const SETTINGS  = 'axle_config_sync_lock_settings';
	public const FLASH     = 'axle_config_sync_lock_flash_';
	public const ACTION    = 'axle_config_sync_lock_override';
	public const ROUTE     = '/lock/override';

	/**
	 * Greater than zero while the plugin itself writes settings.
	 *
	 * @var int
	 */
	private static int $bypass = 0;

	/**
	 * Per-request answers to "is this option managed", by name.
	 *
	 * @var array<string, bool>
	 */
	private array $managed = array();

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Option names of the core settings, as a lookup.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $core = null;

	/**
	 * Sets up the lock.
	 *
	 * @param Engine $engine The engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Hooks the lock.
	 */
	public function register(): void {
		add_filter( 'pre_update_option', array( $this, 'guard' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest' ), 10, 3 );
		add_filter( 'axle_config_sync_drift_expected', array( $this, 'expected' ) );
		add_action( 'axle_config_sync_item_applied', array( $this, 'forget_released' ), 10, 1 );
		add_action( 'axle_config_sync_drift_kept', array( $this, 'forget_released_many' ), 10, 1 );
		add_action( 'axle_config_sync_exported', array( $this, 'forget_exported' ), 10, 1 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_override' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_customizer' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_wall' ) );
	}

	/**
	 * Runs a callback while the lock is off, for the plugin's own writes.
	 *
	 * @param callable $callback Callback.
	 */
	public static function bypass( callable $callback ): void {
		++self::$bypass;
		try {
			$callback();
		} finally {
			--self::$bypass;
		}
	}

	/**
	 * The saved switch and window.
	 *
	 * @return array{enabled: bool, minutes: int}
	 */
	public function settings(): array {
		$saved = get_option( self::SETTINGS, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'enabled' => ! isset( $saved['enabled'] ) || ! empty( $saved['enabled'] ),
			'minutes' => isset( $saved['minutes'] ) ? max( 5, min( 1440, (int) $saved['minutes'] ) ) : 30,
		);
	}

	/**
	 * Saves the switch and the window.
	 *
	 * @param bool $enabled Lock managed settings on production.
	 * @param int  $minutes Minutes "Change it here anyway" lasts.
	 */
	public function save_settings( bool $enabled, int $minutes ): void {
		update_option(
			self::SETTINGS,
			array(
				'enabled' => $enabled,
				'minutes' => max( 5, min( 1440, $minutes ) ),
			),
			false
		);
	}

	/**
	 * Whether the lock is switched on at all (the switch, the constant and the filter).
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		$on = $this->settings()['enabled'] && ! ( defined( 'AXLE_CONFIG_SYNC_LOCK' ) && false === AXLE_CONFIG_SYNC_LOCK );

		/**
		 * Filters whether managed settings are locked on production.
		 *
		 * @param bool $enabled Default true, unless switched off on the Track tab or with AXLE_CONFIG_SYNC_LOCK set to false.
		 */
		return (bool) apply_filters( 'axle_config_sync_lock_enabled', $on );
	}

	/**
	 * Whether the current request is held to the lock: production, a signed-in
	 * person, and not a deploy, WP-CLI, cron, an install or the plugin's own apply.
	 *
	 * @return bool
	 */
	public function enforcing(): bool {
		if ( self::$bypass > 0 || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_installing() ) {
			return false;
		}
		if ( defined( 'AXLE_CONFIG_SYNC_DEPLOYING' ) && AXLE_CONFIG_SYNC_DEPLOYING ) {
			return false;
		}
		if ( ! function_exists( 'wp_doing_cron' ) || wp_doing_cron() || ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}
		if ( ! $this->enabled() ) {
			return false;
		}

		return $this->production();
	}

	/**
	 * Whether this site is a lock environment, by Axle's shared answer.
	 *
	 * @return bool
	 */
	public function production(): bool {
		/**
		 * Filters the environments where managed settings are locked.
		 *
		 * @param string[] $environments Default production.
		 */
		$environments = array_map( 'strval', (array) apply_filters( 'axle_config_sync_lock_environments', array( 'production' ) ) );

		return in_array( AxleUi::environment_type(), $environments, true );
	}

	/**
	 * Whether Read-Only Mode has frozen the site, asked through its public functions.
	 *
	 * @return bool
	 */
	public function frozen(): bool {
		try {
			return $this->engine->frozen();
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Whether an option is managed in git: a tracked setting with a file.
	 * Fails open: anything that cannot be read means "not managed".
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public function is_managed( string $name ): bool {
		if ( isset( $this->managed[ $name ] ) ) {
			return $this->managed[ $name ];
		}
		$this->managed[ $name ] = false;

		if ( '' === $name || '_' === $name[0] || 0 === strpos( $name, 'axle_config_sync_' ) ) {
			return false;
		}

		try {
			if ( ! Providers\Options::allowed( $name ) || ! $this->engine->rules()->provider_on( 'options' ) ) {
				return false;
			}
			if ( null === $this->core ) {
				$this->core = array_fill_keys( Providers\Options::core_names(), true );
			}
			if ( ! isset( $this->core[ $name ] ) && ! $this->engine->rules()->is_tracked( $name ) ) {
				return false;
			}

			$path = $this->engine->directory()->file( 'options/' . $name . '.json' );
			$yes  = is_file( $path ) && is_readable( $path );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}

		/**
		 * Filters whether an option is managed in git, and so locked on production.
		 *
		 * @param bool   $managed True when the option is tracked and has a file.
		 * @param string $name    Option name.
		 */
		$this->managed[ $name ] = (bool) apply_filters( 'axle_config_sync_lock_managed', $yes, $name );

		return $this->managed[ $name ];
	}

	/**
	 * Every managed option name, for the screens. Fails open to an empty list.
	 *
	 * @return string[]
	 */
	public function managed_names(): array {
		$names = array();
		try {
			foreach ( array_keys( $this->engine->file_ids() ) as $id ) {
				if ( 0 === strpos( $id, 'options/' ) && $this->is_managed( substr( $id, 8 ) ) ) {
					$names[] = substr( $id, 8 );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			return array();
		}

		sort( $names );

		return $names;
	}

	/**
	 * The capability that owns an option: the one that may save it on its own screen.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	public function capability_for( string $name ): string {
		/**
		 * Filters the capability needed to choose "Change it here anyway" for an option.
		 * Core settings screens need manage_options; give plugin options their own.
		 *
		 * @param string $capability Default manage_options.
		 * @param string $name       Option name.
		 */
		$cap = (string) apply_filters( 'axle_config_sync_lock_option_capability', 'manage_options', $name );

		return '' !== trim( $cap ) ? $cap : 'manage_options';
	}

	/**
	 * Refuses a save of a managed option while the lock holds.
	 *
	 * @param mixed $value Value about to be saved.
	 * @param mixed $name  Option name.
	 * @param mixed $old   Value now.
	 * @return mixed
	 */
	public function guard( $value, $name = '', $old = null ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return $value;
		}

		try {
			if ( maybe_serialize( $value ) === maybe_serialize( $old ) || ! $this->enforcing() || ! $this->is_managed( $name ) || $this->overridden( $name ) ) {
				return $value;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			return $value;
		}

		$this->flash( 'refused', $name );

		/**
		 * Fires when a save of a managed setting was refused.
		 *
		 * @param string $name    Option name.
		 * @param int    $user_id The person who tried.
		 */
		do_action( 'axle_config_sync_lock_refused', $name, get_current_user_id() );

		return $old;
	}

	/**
	 * Refuses a /wp/v2/settings save of a managed option, with an error the
	 * editor shows as a notice with the "Change it here anyway" button.
	 *
	 * @param mixed $result  Pre-dispatch result.
	 * @param mixed $server  Server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public function guard_rest( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || '/wp/v2/settings' !== $request->get_route() || 'GET' === $request->get_method() ) {
			return $result;
		}

		try {
			if ( ! $this->enforcing() || ! function_exists( 'get_registered_settings' ) ) {
				return $result;
			}

			foreach ( get_registered_settings() as $name => $args ) {
				if ( empty( $args['show_in_rest'] ) ) {
					continue;
				}
				$rest_name = is_array( $args['show_in_rest'] ) && ! empty( $args['show_in_rest']['name'] ) ? (string) $args['show_in_rest']['name'] : (string) $name;
				$params    = $request->get_json_params();
				$params    = $params ? $params : $request->get_body_params();
				if ( ! array_key_exists( $rest_name, $params ) ) {
					continue;
				}
				if ( ! $this->is_managed( (string) $name ) || $this->overridden( (string) $name ) ) {
					continue;
				}
				if ( self::plain( $params[ $rest_name ] ) === self::plain( get_option( (string) $name ) ) ) {
					continue;
				}

				$cap = $this->capability_for( (string) $name );
				do_action( 'axle_config_sync_lock_refused', (string) $name, get_current_user_id() );

				return new \WP_Error(
					'axle_config_sync_managed',
					Messages::get( 'locked', (string) $name ),
					array(
						'status'    => 403,
						'axle_wall' => AxleUi::wall(
							array(
								'plugin'        => 'axle-config-sync',
								'capability'    => $cap,
								'message'       => Messages::get( 'locked', (string) $name ),
								'action_label'  => __( 'Change it here anyway', 'axle-config-sync' ),
								'request_route' => 'axle-config-sync/v1' . self::ROUTE,
								'request_data'  => array( 'option' => (string) $name ),
							)
						),
					)
				);
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $result;
	}

	/**
	 * A value as a plain string, so a saved "1" and a posted true compare equal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function plain( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Whether the current person has an open "Change it here anyway" for an option.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public function overridden( string $name ): bool {
		$entry = $this->overrides()[ $name ] ?? null;

		return is_array( $entry ) && time() < (int) $entry['until'] && get_current_user_id() === (int) $entry['user'];
	}

	/**
	 * Open and recent overrides: option name => until, user, at.
	 *
	 * @return array<string, array{until: int, user: int, at: int}>
	 */
	public function overrides(): array {
		$saved = get_option( self::OVERRIDES, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $name => $entry ) {
			if ( is_array( $entry ) && isset( $entry['until'] ) ) {
				$out[ (string) $name ] = array(
					'until' => (int) $entry['until'],
					'user'  => (int) ( $entry['user'] ?? 0 ),
					'at'    => (int) ( $entry['at'] ?? 0 ),
				);
			}
		}

		return $out;
	}

	/**
	 * Settings that were changed here anyway and are expected to differ
	 * until a deploy puts the file value back: item ID => user, at.
	 *
	 * @return array<string, array{user: int, at: int}>
	 */
	public function released(): array {
		$saved = get_option( self::RELEASED, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $id => $entry ) {
			if ( is_array( $entry ) ) {
				$out[ (string) $id ] = array(
					'user' => (int) ( $entry['user'] ?? 0 ),
					'at'   => (int) ( $entry['at'] ?? 0 ),
				);
			}
		}

		return $out;
	}

	/**
	 * Minutes "Change it here anyway" lasts.
	 *
	 * @return int 5 to 1440.
	 */
	public function minutes(): int {
		/**
		 * Filters how many minutes "Change it here anyway" lasts.
		 *
		 * @param int $minutes Default 30, or what the Track tab saved.
		 */
		return max( 5, min( 1440, (int) apply_filters( 'axle_config_sync_lock_override_minutes', $this->settings()['minutes'] ) ) );
	}

	/**
	 * Opens "Change it here anyway" for the signed-in person.
	 *
	 * @param string $name Option name.
	 * @return int|\WP_Error When it ends (Unix time), or why not.
	 */
	public function grant( string $name ) {
		if ( ! $this->is_managed( $name ) ) {
			return new \WP_Error( 'axle_config_sync_not_managed', Messages::get( 'lock_not_managed', $name ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( $this->capability_for( $name ) ) ) {
			return new \WP_Error( 'axle_config_sync_lock_forbidden', Messages::get( 'lock_forbidden' ), array( 'status' => 403 ) );
		}
		if ( $this->frozen() ) {
			return new \WP_Error( 'axle_config_sync_frozen', Messages::get( 'lock_frozen' ), array( 'status' => 423 ) );
		}

		$now   = time();
		$until = $now + $this->minutes() * MINUTE_IN_SECONDS;
		$user  = get_current_user_id();

		$overrides = array_filter(
			$this->overrides(),
			static function ( array $entry ) use ( $now ): bool {
				return $entry['until'] > $now - DAY_IN_SECONDS;
			}
		);

		$overrides[ $name ] = array(
			'until' => $until,
			'user'  => $user,
			'at'    => $now,
		);
		update_option( self::OVERRIDES, $overrides, false );

		$released                       = $this->released();
		$released[ 'options/' . $name ] = array(
			'user' => $user,
			'at'   => $now,
		);
		update_option( self::RELEASED, $released, false );

		$this->flash( 'granted', $name, $until );
		$this->log( $name, $until );

		/**
		 * Fires when someone chose "Change it here anyway" for a managed setting.
		 *
		 * @param string $name    Option name.
		 * @param int    $until   When the window ends (Unix time).
		 * @param int    $user_id The person.
		 */
		do_action( 'axle_config_sync_lock_override_granted', $name, $until, $user );

		return $until;
	}

	/**
	 * Closes every open override now.
	 *
	 * @return int How many were open.
	 */
	public function end_overrides(): int {
		$open = 0;
		$now  = time();
		$all  = $this->overrides();
		foreach ( $all as $name => $entry ) {
			if ( $entry['until'] > $now ) {
				++$open;
				$all[ $name ]['until'] = $now;
			}
		}
		update_option( self::OVERRIDES, $all, false );

		return $open;
	}

	/**
	 * Adds the settings released here to the expected drift, until a deploy.
	 *
	 * @param mixed $items Expected items.
	 * @return array
	 */
	public function expected( $items ): array {
		return array_merge( is_array( $items ) ? $items : array(), array_keys( $this->released() ) );
	}

	/**
	 * A deploy put the file value back: the setting is managed again.
	 *
	 * @param mixed $id Item ID.
	 */
	public function forget_released( $id ): void {
		$this->forget_released_many( array( $id ) );
	}

	/**
	 * Items written to the files now match the site again.
	 *
	 * @param mixed $written Item IDs, or item ID => file.
	 */
	public function forget_exported( $written ): void {
		$this->forget_released_many( is_array( $written ) ? array_keys( $written ) : array() );
	}

	/**
	 * Takes items off the released list.
	 *
	 * @param mixed $ids Item IDs.
	 */
	public function forget_released_many( $ids ): void {
		$released = $this->released();
		if ( ! $released || ! is_array( $ids ) ) {
			return;
		}

		$left = array_diff_key( $released, array_flip( array_map( 'strval', $ids ) ) );
		if ( count( $left ) !== count( $released ) ) {
			update_option( self::RELEASED, $left, false );
		}
	}

	/**
	 * Registers the REST routes.
	 */
	public function routes(): void {
		register_rest_route(
			Rest\Controller::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_override' ),
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
				'args'                => array(
					'option' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Rest\Controller::NAMESPACE,
			'/lock',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => static function (): bool {
					return current_user_can( Settings::capability() );
				},
			)
		);
	}

	/**
	 * POST /lock/override.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function rest_override( \WP_REST_Request $request ) {
		$name  = sanitize_text_field( (string) $request->get_param( 'option' ) );
		$until = $this->grant( $name );
		if ( is_wp_error( $until ) ) {
			return $until;
		}

		return array(
			'option'  => $name,
			'until'   => $until,
			'message' => Messages::get( 'lock_released', $name, wp_date( get_option( 'time_format', 'H:i' ), $until ) ),
		);
	}

	/**
	 * GET /lock.
	 *
	 * @return array
	 */
	public function rest_status(): array {
		return $this->status();
	}

	/**
	 * The lock as data, for REST, the ability and WP-CLI.
	 *
	 * @return array{environment: string, locking: bool, enabled: bool, managed: string[], overrides: array, released: string[]}
	 */
	public function status(): array {
		$now  = time();
		$open = array();
		foreach ( $this->overrides() as $name => $entry ) {
			if ( $entry['until'] > $now ) {
				$open[] = array(
					'option' => $name,
					'until'  => $entry['until'],
				);
			}
		}

		return array(
			'environment' => AxleUi::environment_type(),
			'locking'     => $this->enabled() && $this->production(),
			'enabled'     => $this->enabled(),
			'managed'     => $this->managed_names(),
			'overrides'   => $open,
			'released'    => array_keys( $this->released() ),
		);
	}

	/**
	 * The "Change it here anyway" form post.
	 */
	public function handle_override(): void {
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$names = isset( $_POST['option'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['option'] ) ) : array();
		$names = array_slice( array_values( array_filter( $names ) ), 0, 50 );
		if ( ! $names ) {
			wp_die( esc_html( Messages::get( 'lock_forbidden' ) ), 403 );
		}

		foreach ( $names as $name ) {
			if ( ! current_user_can( $this->capability_for( $name ) ) ) {
				wp_die( esc_html( Messages::get( 'lock_forbidden' ) ), 403 );
			}
			$until = $this->grant( $name );
			if ( is_wp_error( $until ) ) {
				wp_die( esc_html( $until->get_error_message() ), (int) ( $until->get_error_data()['status'] ?? 400 ) );
			}
		}

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'options-general.php' ) );
		exit;
	}

	/**
	 * Remembers a refusal or a grant for the next admin screen of this person.
	 *
	 * @param string $kind  refused or granted.
	 * @param string $name  Option name.
	 * @param int    $until When a grant ends.
	 */
	private function flash( string $kind, string $name, int $until = 0 ): void {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return;
		}

		$key   = self::FLASH . $user;
		$flash = get_transient( $key );
		$flash = is_array( $flash ) ? $flash : array();

		$flash[ $kind ][ $name ] = $until;
		$flash[ $kind ]          = array_slice( $flash[ $kind ], -20, null, true );
		set_transient( $key, $flash, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints what the last save did: which settings were refused, which were released.
	 */
	public function notices(): void {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return;
		}

		$key   = self::FLASH . $user;
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( array_keys( (array) ( $flash['granted'] ?? array() ) ) as $name ) {
			$until = (int) $flash['granted'][ $name ];
			echo '<div class="notice notice-warning is-dismissible axle-lock-notice" role="status"><p>' . esc_html( Messages::get( 'lock_released', (string) $name, wp_date( get_option( 'time_format', 'H:i' ), $until ) ) ) . '</p></div>';
		}

		$refused = array_keys( (array) ( $flash['refused'] ?? array() ) );
		if ( ! $refused ) {
			return;
		}

		echo '<div class="notice notice-error axle-lock-notice" role="alert"><p>' . esc_html__( 'Not saved. These settings are managed in git on production:', 'axle-config-sync' ) . '</p><ul>';
		foreach ( $refused as $name ) {
			echo '<li><code>' . esc_html( (string) $name ) . '</code> ';
			if ( current_user_can( $this->capability_for( (string) $name ) ) && ! $this->frozen() ) {
				echo $this->override_form( (string) $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in override_form().
			}
			echo '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'Change the file and deploy it, or choose "Change it here anyway" and save again.', 'axle-config-sync' ) . '</p></div>';
	}

	/**
	 * A small form with the "Change it here anyway" button.
	 *
	 * @param string $name Option name.
	 * @return string HTML.
	 */
	public function override_form( string $name ): string {
		return '<form method="post" class="axle-lock-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="option" value="' . esc_attr( $name ) . '">'
			. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
			. '<button type="submit" class="button button-small">' . esc_html__( 'Change it here anyway', 'axle-config-sync' ) . '</button></form>';
	}

	/**
	 * Marks the managed fields on the core settings screens.
	 *
	 * @param mixed $hook Admin page hook suffix.
	 */
	public function enqueue_admin( $hook ): void {
		$screens = array( 'options-general.php', 'options-writing.php', 'options-reading.php', 'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options-privacy.php' );
		if ( ! is_string( $hook ) || ! in_array( $hook, $screens, true ) ) {
			$this->enqueue_wall();
			return;
		}

		$this->enqueue_wall();
		$this->enqueue_script( 'settings' );
	}

	/**
	 * Marks the managed controls in the Customizer.
	 */
	public function enqueue_customizer(): void {
		$this->enqueue_script( 'customizer' );
	}

	/**
	 * Loads the shared "ask at the wall" script where the lock can refuse.
	 */
	public function enqueue_wall(): void {
		if ( $this->enforcing() ) {
			AxleUi::enqueue_wall();
		}
	}

	/**
	 * Enqueues the script that marks managed fields.
	 *
	 * @param string $context settings or customizer.
	 */
	private function enqueue_script( string $context ): void {
		if ( ! $this->enforcing() ) {
			return;
		}

		$names = array_values(
			array_filter(
				$this->managed_names(),
				function ( string $name ): bool {
					return ! $this->overridden( $name );
				}
			)
		);
		if ( ! $names ) {
			return;
		}

		$can = array();
		if ( ! $this->frozen() ) {
			foreach ( $names as $name ) {
				$can[ $name ] = current_user_can( $this->capability_for( $name ) );
			}
		}

		wp_enqueue_style( 'axle-config-sync-lock', plugins_url( 'assets/lock.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'axle-config-sync-lock', plugins_url( 'assets/lock.js', PLUGIN_FILE ), 'customizer' === $context ? array( 'customize-controls' ) : array(), VERSION, true );
		wp_localize_script(
			'axle-config-sync-lock',
			'axleConfigSyncLock',
			array(
				'context' => $context,
				'managed' => $names,
				'postUrl' => admin_url( 'admin-post.php' ),
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( self::ACTION ),
				'can'     => $can,
				'i18n'    => array(
					'badge'    => __( 'Managed in git', 'axle-config-sync' ),
					'summary'  => __( 'Managed in git. Saving these settings is refused on production.', 'axle-config-sync' ),
					/* translators: %d: number of settings on this screen. */
					'count'    => __( 'Show the %d managed settings on this screen', 'axle-config-sync' ),
					'override' => __( 'Change it here anyway', 'axle-config-sync' ),
					'help'     => __( 'Change the file and deploy it, or choose "Change it here anyway" for a setting and save again.', 'axle-config-sync' ),
				),
			)
		);
	}

	/**
	 * The records that name a person, for the privacy exporter.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, int> Option name => Unix time.
	 */
	public function records_for_user( int $user_id ): array {
		$out = array();
		foreach ( $this->overrides() as $name => $entry ) {
			if ( $entry['user'] === $user_id ) {
				$out[ $name ] = $entry['at'];
			}
		}

		return $out;
	}

	/**
	 * Clears a person from the override and released records, for the eraser.
	 *
	 * @param int $user_id User ID.
	 * @return int Records changed.
	 */
	public function forget_user( int $user_id ): int {
		$changed = 0;

		$overrides = $this->overrides();
		foreach ( $overrides as $name => $entry ) {
			if ( $entry['user'] === $user_id ) {
				$overrides[ $name ]['user'] = 0;
				++$changed;
			}
		}
		if ( $changed ) {
			update_option( self::OVERRIDES, $overrides, false );
		}

		$released = $this->released();
		$more     = 0;
		foreach ( $released as $id => $entry ) {
			if ( $entry['user'] === $user_id ) {
				$released[ $id ]['user'] = 0;
				++$more;
			}
		}
		if ( $more ) {
			update_option( self::RELEASED, $released, false );
		}

		return $changed + $more;
	}

	/**
	 * Writes the override to Activity Log with Undo, when it is active.
	 *
	 * @param string $name  Option name.
	 * @param int    $until When the window ends.
	 */
	private function log( string $name, int $until ): void {
		$class = '\AryanX\Axle\ActivityLogWithUndo\Plugin';
		if ( ! class_exists( $class ) || ! is_callable( array( $class, 'instance' ) ) ) {
			return;
		}

		try {
			$plugin = $class::instance();
			$logger = is_object( $plugin ) && method_exists( $plugin, 'logger' ) ? $plugin->logger() : null;
			if ( ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
				return;
			}

			$logger->log(
				array(
					'event'        => 'config_lock_override',
					'recorder'     => 'axle-config-sync',
					'object_type'  => 'option',
					'object_id'    => $name,
					'object_label' => $name,
					'before'       => array( 'managed_in_git' => true ),
					'after'        => array(
						'managed_in_git' => false,
						'until'          => gmdate( 'Y-m-d H:i:s', $until ),
					),
					'undoable'     => false,
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
