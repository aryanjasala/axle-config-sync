<?php
/**
 * Drift watch: settings changed on the live site since the files were written.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * A daily check (and one on demand) compares the live values with the files.
 * An item has drifted when its site side moved after the last export or
 * apply: "changed on site" and "conflict". New drift raises an admin notice,
 * a Site Health test and, when switched on, an email and a webhook.
 *
 * Who changed it comes from a cheap hook on the tracked option names only, or
 * from Activity Log with Undo when that plugin has the entry.
 */
final class Drift implements Service {

	public const OPTION      = 'axle_config_sync_drift';
	public const ACTORS      = 'axle_config_sync_drift_actors';
	public const CRON_HOOK   = 'axle_config_sync_drift_check';
	public const SEEN_META   = 'axle_config_sync_drift_seen';
	public const STATES      = array( 'changed_on_site', 'conflict' );
	public const ACTOR_LIMIT = 300;

	/**
	 * Greater than zero while the plugin itself writes settings.
	 *
	 * @var int
	 */
	private static int $silent = 0;

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Option names of the core settings, as a lookup, built on first use.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $names = null;

	/**
	 * Sets up the watch.
	 *
	 * @param Engine $engine The engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Hooks the daily check and the "who changed it" recorder.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
		add_action( 'updated_option', array( $this, 'record' ), 10, 1 );
		add_action( 'added_option', array( $this, 'record' ), 10, 1 );
		add_action( 'deleted_option', array( $this, 'record' ), 10, 1 );
	}

	/**
	 * Runs a callback while the plugin's own writes are not counted as
	 * someone else's changes.
	 *
	 * @param callable $callback Callback.
	 */
	public static function silently( callable $callback ): void {
		++self::$silent;
		try {
			$callback();
		} finally {
			--self::$silent;
		}
	}

	/**
	 * The schedule name for the daily check.
	 *
	 * @return string
	 */
	public static function recurrence(): string {
		/**
		 * Filters how often the drift check runs: a registered cron schedule.
		 *
		 * @param string $recurrence Default daily.
		 */
		$recurrence = (string) apply_filters( 'axle_config_sync_drift_schedule', 'daily' );
		$known      = wp_get_schedules();

		return isset( $known[ $recurrence ] ) ? $recurrence : 'daily';
	}

	/**
	 * Cron entry point.
	 */
	public function run_cron(): void {
		$this->check( 'cron' );
	}

	/**
	 * The notification settings and the expected list as saved.
	 *
	 * @return array{expected: string[], email: bool, email_to: string, webhook: string}
	 */
	public function settings(): array {
		$saved = get_option( Settings::OPTION, array() );
		$drift = is_array( $saved ) && isset( $saved['drift'] ) && is_array( $saved['drift'] ) ? $saved['drift'] : array();

		return array(
			'expected' => array_values( array_filter( array_map( array( self::class, 'clean_id' ), array_map( 'strval', (array) ( $drift['expected'] ?? array() ) ) ) ) ),
			'email'    => ! empty( $drift['email'] ),
			'email_to' => isset( $drift['email_to'] ) ? sanitize_email( (string) $drift['email_to'] ) : '',
			'webhook'  => isset( $drift['webhook'] ) && '' !== (string) $drift['webhook'] ? esc_url_raw( (string) $drift['webhook'], array( 'http', 'https' ) ) : '',
		);
	}

	/**
	 * Saves some of the drift settings.
	 *
	 * @param array $changes expected, email, email_to or webhook.
	 */
	public function save_settings( array $changes ): void {
		$now = $this->settings();

		if ( array_key_exists( 'expected', $changes ) ) {
			$now['expected'] = array_values( array_unique( array_filter( array_map( array( self::class, 'clean_id' ), array_map( 'strval', (array) $changes['expected'] ) ) ) ) );
			sort( $now['expected'] );
		}
		if ( array_key_exists( 'email', $changes ) ) {
			$now['email'] = (bool) $changes['email'];
		}
		if ( array_key_exists( 'email_to', $changes ) ) {
			$now['email_to'] = sanitize_email( (string) $changes['email_to'] );
		}
		if ( array_key_exists( 'webhook', $changes ) ) {
			$url            = esc_url_raw( trim( (string) $changes['webhook'] ), array( 'http', 'https' ) );
			$now['webhook'] = '' !== $url && wp_http_validate_url( $url ) ? $url : '';
		}

		$saved          = get_option( Settings::OPTION, array() );
		$saved          = is_array( $saved ) ? $saved : array();
		$saved['drift'] = $now;
		update_option( Settings::OPTION, $saved, false );
	}

	/**
	 * An item ID or `provider/*` pattern, cleaned.
	 *
	 * @param string $id Raw ID.
	 * @return string
	 */
	public static function clean_id( string $id ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-.*\/]/', '', trim( $id ) );
	}

	/**
	 * Items a site may change on purpose: never reported.
	 *
	 * @return string[] Item IDs and `*` patterns.
	 */
	public function expected(): array {
		$list = $this->settings()['expected'];

		/**
		 * Filters the items that may drift on purpose and are never reported.
		 *
		 * @param string[] $items Item IDs, or patterns such as options/woocommerce_*.
		 */
		$list = (array) apply_filters( 'axle_config_sync_drift_expected', $list );

		return array_values( array_filter( array_map( array( self::class, 'clean_id' ), array_map( 'strval', $list ) ) ) );
	}

	/**
	 * Adds an item to the expected list.
	 *
	 * @param string[] $ids Item IDs.
	 */
	public function expect( array $ids ): void {
		$list = array_merge( $this->settings()['expected'], array_map( array( $this->engine, 'normalize_id' ), $ids ) );
		$this->save_settings( array( 'expected' => $list ) );

		/**
		 * Fires after the expected drift list changed.
		 *
		 * @param string[] $items The saved list.
		 */
		do_action( 'axle_config_sync_drift_expected_changed', $this->settings()['expected'] );
	}

	/**
	 * Takes items off the expected list.
	 *
	 * @param string[] $ids Item IDs or patterns, as listed.
	 */
	public function unexpect( array $ids ): void {
		$ids = array_map( array( self::class, 'clean_id' ), $ids );
		$this->save_settings( array( 'expected' => array_diff( $this->settings()['expected'], $ids ) ) );

		/** This action is documented in src/Drift.php */
		do_action( 'axle_config_sync_drift_expected_changed', $this->settings()['expected'] );
	}

	/**
	 * The drifted items right now, from the live site.
	 *
	 * @return array<string, array> Item ID => item.
	 */
	public function items(): array {
		/**
		 * Filters the item states that count as drift.
		 *
		 * @param string[] $states Default changed_on_site and conflict.
		 */
		$states   = array_values( array_intersect( Engine::STATES, array_map( 'strval', (array) apply_filters( 'axle_config_sync_drift_states', self::STATES ) ) ) );
		$states   = $states ? $states : self::STATES;
		$expected = $this->expected();
		$stored   = $this->state()['items'];
		$items    = array();

		foreach ( $this->engine->rows() as $row ) {
			if ( ! in_array( $row['base_state'], $states, true ) || Rules::matches_any( $row['id'], $expected ) ) {
				continue;
			}
			$items[ $row['id'] ] = $this->shape( $row, (int) ( $stored[ $row['id'] ]['first'] ?? 0 ) );
		}

		/**
		 * Filters the drifted items before they are checked, shown or sent.
		 *
		 * @param array<string, array> $items Item ID => id, label, provider, state, before, after, who, since, hash.
		 */
		return Messages::as_array( apply_filters( 'axle_config_sync_drift_items', $items ) );
	}

	/**
	 * Runs a check: finds the drift, stores it and tells people about new drift.
	 *
	 * @param string $via    cron, admin, cli, rest or ability.
	 * @param bool   $notify Send the email and webhook for new drift.
	 * @return array{items: array<string, array>, new: array<string, array>, checked_at: int}
	 */
	public function check( string $via = 'cron', bool $notify = true ): array {
		/**
		 * Fires when a drift check starts.
		 *
		 * @param string $via Where it was asked from.
		 */
		do_action( 'axle_config_sync_drift_check_started', $via );

		$items = $this->items();
		$state = $this->state();
		$now   = time();
		$fresh = array();
		$next  = array();

		foreach ( $items as $id => $item ) {
			$before = $state['items'][ $id ] ?? null;
			if ( null === $before || $before['hash'] !== $item['hash'] ) {
				$fresh[ $id ] = $item;
				$before       = null;
			}
			$next[ $id ] = array(
				'hash'  => $item['hash'],
				'first' => null === $before ? $now : $before['first'],
			);
		}

		$state['items']      = $next;
		$state['checked_at'] = $now;
		$state['via']        = sanitize_key( $via );
		update_option( self::OPTION, $state, true );
		$this->prune_actors( array_keys( $items ) );

		/**
		 * Fires after a drift check, with every drifted item and the new ones.
		 *
		 * @param array<string, array> $items Drifted items.
		 * @param array<string, array> $fresh   Items not seen in the last check.
		 * @param string               $via   Where the check was asked from.
		 */
		do_action( 'axle_config_sync_drift_checked', $items, $fresh, $via );

		if ( $fresh ) {
			/**
			 * Fires when a check finds drift it has not seen before.
			 *
			 * @param array<string, array> $fresh   New items.
			 * @param array<string, array> $items Every drifted item.
			 */
			do_action( 'axle_config_sync_drift_detected', $fresh, $items );
			if ( $notify ) {
				$this->notify( $fresh );
			}
		}

		return array(
			'items'      => $items,
			'new'        => $fresh,
			'checked_at' => $now,
		);
	}

	/**
	 * What the last check stored.
	 *
	 * @return array{items: array<string, array{hash: string, first: int}>, checked_at: int, via: string, errors: array<string, string>}
	 */
	public function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$items = array();
		foreach ( isset( $saved['items'] ) && is_array( $saved['items'] ) ? $saved['items'] : array() as $id => $item ) {
			$items[ (string) $id ] = array(
				'hash'  => is_array( $item ) ? (string) ( $item['hash'] ?? '' ) : '',
				'first' => is_array( $item ) ? (int) ( $item['first'] ?? 0 ) : 0,
			);
		}

		return array(
			'items'      => $items,
			'checked_at' => (int) ( $saved['checked_at'] ?? 0 ),
			'via'        => (string) ( $saved['via'] ?? '' ),
			'errors'     => isset( $saved['errors'] ) && is_array( $saved['errors'] ) ? array_map( 'strval', $saved['errors'] ) : array(),
		);
	}

	/**
	 * How many items the last check found.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->state()['items'] );
	}

	/**
	 * A short code for the set of drifted items, so a dismissed notice stays
	 * away until something else drifts.
	 *
	 * @return string Empty when there is no drift.
	 */
	public function fingerprint(): string {
		$items = $this->state()['items'];
		if ( ! $items ) {
			return '';
		}

		$parts = array();
		foreach ( $items as $id => $item ) {
			$parts[] = $id . ':' . $item['hash'];
		}
		sort( $parts );

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Writes the live values of drifted items to the files: keeps what the
	 * site has.
	 *
	 * @param string[] $ids Item IDs, or empty for every drifted item.
	 * @return array|\WP_Error Result of the export.
	 */
	public function keep( array $ids = array() ) {
		$ids = $ids ? array_map( array( $this->engine, 'normalize_id' ), $ids ) : array_keys( $this->items() );
		if ( ! $ids ) {
			return array(
				'written'   => array(),
				'refused'   => array(),
				'directory' => $this->engine->directory()->path(),
			);
		}

		$result = $this->engine->export( $ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires after drifted items were written to the files.
		 *
		 * @param string[] $items Item IDs written.
		 */
		do_action( 'axle_config_sync_drift_kept', array_keys( $result['written'] ) );

		$this->check( 'admin', false );

		return $result;
	}

	/**
	 * Remembers who changed a tracked setting. Runs on every option write, so
	 * it leaves quickly for anything that is not a tracked name.
	 *
	 * @param mixed $name Option name.
	 */
	public function record( $name ): void {
		if ( self::$silent > 0 || ! is_string( $name ) || '' === $name || '_' === $name[0] || 0 === strpos( $name, 'axle_config_sync_' ) ) {
			return;
		}

		$id = $this->item_for_option( $name );
		if ( null === $id ) {
			return;
		}

		$actors        = $this->actors();
		$actors[ $id ] = array(
			'user' => get_current_user_id(),
			'at'   => time(),
			'via'  => self::via(),
		);

		/**
		 * Filters how many "who changed it" records are kept.
		 *
		 * @param int $limit Default 300.
		 */
		$limit = max( 10, min( 5000, (int) apply_filters( 'axle_config_sync_drift_actor_limit', self::ACTOR_LIMIT ) ) );
		if ( count( $actors ) > $limit ) {
			uasort(
				$actors,
				static function ( array $a, array $b ): int {
					return $b['at'] <=> $a['at'];
				}
			);
			$actors = array_slice( $actors, 0, $limit, true );
		}

		update_option( self::ACTORS, $actors, false );
	}

	/**
	 * Who changed an item, and when.
	 *
	 * @param string $id    Item ID.
	 * @param int    $since Ignore changes before this time (the last sync).
	 * @return array{user_id: int, name: string, at: int, via: string, source: string}|null
	 */
	public function who( string $id, int $since = 0 ): ?array {
		$found = $this->activity_log_actor( $id );

		if ( null === $found ) {
			$actors = $this->actors();
			$entry  = $actors[ $id ] ?? ( $actors[ strtok( $id, '/' ) . '/*' ] ?? null );
			if ( is_array( $entry ) ) {
				$found = array(
					'user_id' => (int) $entry['user'],
					'at'      => (int) $entry['at'],
					'via'     => (string) $entry['via'],
					'source'  => 'config-sync',
				);
			}
		}

		if ( null !== $found && $found['at'] < $since ) {
			$found = null;
		}

		if ( null !== $found ) {
			$user          = $found['user_id'] > 0 ? get_userdata( $found['user_id'] ) : false;
			$found['name'] = $user ? (string) $user->display_name : '';
		}

		/**
		 * Filters who changed a drifted item.
		 *
		 * @param array|null $who  user_id, name, at, via and source, or null when unknown.
		 * @param string     $id   Item ID.
		 */
		$who = apply_filters( 'axle_config_sync_drift_actor', $found, $id );

		return is_array( $who ) ? $who : null;
	}

	/**
	 * Plain words for who changed an item.
	 *
	 * @param array|null $who Result of who().
	 * @return string Empty when unknown.
	 */
	public static function who_label( ?array $who ): string {
		if ( null === $who ) {
			return '';
		}
		if ( '' !== (string) ( $who['name'] ?? '' ) ) {
			return (string) $who['name'];
		}
		$vias = array(
			'cli'  => __( 'WP-CLI', 'axle-config-sync' ),
			'cron' => __( 'a scheduled task', 'axle-config-sync' ),
			'rest' => __( 'a REST request', 'axle-config-sync' ),
		);

		return $vias[ $who['via'] ?? '' ] ?? __( 'someone not signed in, or a removed user', 'axle-config-sync' );
	}

	/**
	 * Removes a user from the "who changed it" records, for the privacy eraser.
	 *
	 * @param int $user_id User ID.
	 * @return int How many records changed.
	 */
	public function forget_user( int $user_id ): int {
		$actors  = $this->actors();
		$changed = 0;
		foreach ( $actors as $id => $entry ) {
			if ( (int) $entry['user'] === $user_id ) {
				$actors[ $id ]['user'] = 0;
				++$changed;
			}
		}
		if ( $changed ) {
			update_option( self::ACTORS, $actors, false );
		}

		return $changed;
	}

	/**
	 * The "who changed it" records of a user, for the privacy exporter.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, array{user: int, at: int, via: string}> Item ID => record.
	 */
	public function records_for_user( int $user_id ): array {
		return array_filter(
			$this->actors(),
			static function ( array $entry ) use ( $user_id ): bool {
				return (int) $entry['user'] === $user_id;
			}
		);
	}

	/**
	 * A value as short text for lists, emails and webhooks. Secrets are
	 * already masked in portable values.
	 *
	 * @param mixed $value Portable value.
	 * @return string
	 */
	public static function summarize( $value ): string {
		if ( null === $value ) {
			return '';
		}

		$text = is_string( $value ) ? $value : (string) wp_json_encode( Engine::safe_encode( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		/**
		 * Filters how many characters of a value drift lists, emails and webhooks show.
		 *
		 * @param int $length Default 200.
		 */
		$length = max( 20, min( 5000, (int) apply_filters( 'axle_config_sync_drift_value_length', 200 ) ) );

		return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) . '...' : $text;
	}

	/**
	 * One item, shaped for lists and messages.
	 *
	 * @param array $row   Engine row.
	 * @param int   $first When the last check first saw it, or 0.
	 * @return array
	 */
	private function shape( array $row, int $first ): array {
		$last = (int) ( $this->engine->state()->item( $row['id'] )['at'] ?? 0 );
		$who  = $this->who( $row['id'], $last );

		return array(
			'id'       => $row['id'],
			'label'    => $row['label'],
			'provider' => $row['provider'],
			'state'    => $row['base_state'],
			'before'   => self::summarize( $row['file_value'] ),
			'after'    => self::summarize( $row['site_value'] ),
			'who'      => $who,
			'since'    => $who ? (int) $who['at'] : $first,
			'hash'     => (string) $row['hashes']['site'],
		);
	}

	/**
	 * The item an option name belongs to, when a tracking rule covers it.
	 *
	 * @param string $name Option name.
	 * @return string|null Item ID, `provider/*` for providers that hold many names, or null.
	 */
	private function item_for_option( string $name ): ?string {
		if ( 0 === strpos( $name, 'theme_mods_' ) ) {
			return 'theme-mods/' . substr( $name, 11 );
		}
		if ( 'sidebars_widgets' === $name || 0 === strpos( $name, 'widget_' ) ) {
			return 'widgets/*';
		}
		if ( 'nav_menu_options' === $name ) {
			return 'menus/*';
		}
		if ( 'user_roles' === substr( $name, -10 ) ) {
			return 'roles/*';
		}
		if ( ! Providers\Options::allowed( $name ) ) {
			return null;
		}

		if ( null === $this->names ) {
			$this->names = array_fill_keys( Providers\Options::core_names(), true );
		}
		if ( isset( $this->names[ $name ] ) || $this->engine->rules()->is_tracked( $name ) ) {
			return 'options/' . $name;
		}

		return null;
	}

	/**
	 * The latest Activity Log with Undo entry for an option, when that plugin
	 * is active.
	 *
	 * @param string $id Item ID.
	 * @return array{user_id: int, at: int, via: string, source: string}|null
	 */
	private function activity_log_actor( string $id ): ?array {
		$class = '\AryanX\Axle\ActivityLogWithUndo\Plugin';
		if ( 0 !== strpos( $id, 'options/' ) || ! class_exists( $class ) ) {
			return null;
		}

		try {
			$plugin = $class::instance();
			if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'store' ) ) {
				return null;
			}
			$found = $plugin->store()->find(
				array(
					'object_type' => 'option',
					'object_id'   => substr( $id, 8 ),
					'per_page'    => 1,
				)
			);
			$entry = $found['entries'][0] ?? null;
			if ( ! is_object( $entry ) ) {
				return null;
			}

			return array(
				'user_id' => max( 0, (int) $entry->actor_user_id ),
				'at'      => (int) strtotime( $entry->created_gmt . ' UTC' ),
				'via'     => (string) $entry->actor_via,
				'source'  => 'activity-log',
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Every "who changed it" record.
	 *
	 * @return array<string, array{user: int, at: int, via: string}>
	 */
	private function actors(): array {
		$saved = get_option( self::ACTORS, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $id => $entry ) {
			if ( is_array( $entry ) ) {
				$out[ (string) $id ] = array(
					'user' => (int) ( $entry['user'] ?? 0 ),
					'at'   => (int) ( $entry['at'] ?? 0 ),
					'via'  => (string) ( $entry['via'] ?? '' ),
				);
			}
		}

		return $out;
	}

	/**
	 * Drops old records of items that are not drifted: the retention limit.
	 *
	 * @param string[] $drifted Item IDs that drift now.
	 */
	private function prune_actors( array $drifted ): void {
		$actors = $this->actors();
		$cutoff = time() - Settings::retention_days() * DAY_IN_SECONDS;
		$kept   = array();
		foreach ( $actors as $id => $entry ) {
			if ( in_array( $id, $drifted, true ) || $entry['at'] >= $cutoff ) {
				$kept[ $id ] = $entry;
			}
		}
		if ( count( $kept ) !== count( $actors ) ) {
			update_option( self::ACTORS, $kept, false );
		}
	}

	/**
	 * Where the current request comes from.
	 *
	 * @return string
	 */
	private static function via(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		return get_current_user_id() > 0 ? 'admin' : 'other';
	}

	/**
	 * Sends the email and the webhook, when they are switched on.
	 *
	 * @param array<string, array> $fresh New drifted items.
	 */
	private function notify( array $fresh ): void {
		$settings = $this->settings();
		$errors   = array();

		if ( $settings['email'] ) {
			$errors['email'] = $this->send_email( $fresh, $settings['email_to'] );
		}
		if ( '' !== $settings['webhook'] ) {
			$errors['webhook'] = $this->send_webhook( $fresh, $settings['webhook'] );
		}

		$saved           = get_option( self::OPTION, array() );
		$saved           = is_array( $saved ) ? $saved : array();
		$saved['errors'] = array_filter( $errors );
		update_option( self::OPTION, $saved, true );
	}

	/**
	 * Emails the new drift.
	 *
	 * @param array<string, array> $fresh New items.
	 * @param string               $to  Recipient, or empty for the admin email.
	 * @return string An error, or empty.
	 */
	private function send_email( array $fresh, string $to ): string {
		$lines = array();
		foreach ( $fresh as $item ) {
			$who  = self::who_label( $item['who'] );
			$line = $item['label'] . ' (' . $item['id'] . ')';
			/* translators: %s: value in the files. */
			$line .= "\n  " . sprintf( __( 'Files: %s', 'axle-config-sync' ), '' === $item['before'] ? '-' : $item['before'] );
			/* translators: %s: value on the site. */
			$line .= "\n  " . sprintf( __( 'Site: %s', 'axle-config-sync' ), '' === $item['after'] ? '-' : $item['after'] );
			if ( '' !== $who ) {
				/* translators: %s: person or source. */
				$line .= "\n  " . sprintf( __( 'Changed by: %s', 'axle-config-sync' ), $who );
			}
			$lines[] = $line;
		}

		$mail = array(
			'to'      => '' !== $to ? $to : (string) get_option( 'admin_email' ),
			'subject' => sprintf(
				/* translators: 1: site name, 2: number of items. */
				_n( '[%1$s] %2$d setting changed on the site since the files were written', '[%1$s] %2$d settings changed on the site since the files were written', count( $fresh ), 'axle-config-sync' ),
				wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				count( $fresh )
			),
			'message' => implode( "\n\n", $lines ) . "\n\n" . sprintf(
				/* translators: %s: URL. */
				__( 'Keep the changes or put the files back: %s', 'axle-config-sync' ),
				Admin\Page::url( 'drift' )
			),
		);

		/**
		 * Filters the drift email: to, subject and message. Secrets are masked in the values.
		 *
		 * @param array                $mail to, subject, message.
		 * @param array<string, array> $fresh  New items.
		 */
		$mail = (array) apply_filters( 'axle_config_sync_drift_email', $mail, $fresh );
		if ( empty( $mail['to'] ) || ! is_email( (string) $mail['to'] ) ) {
			return __( 'No valid email address to send to.', 'axle-config-sync' );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One short email per check, and only when the admin turned it on.
		$sent = wp_mail( (string) $mail['to'], (string) ( $mail['subject'] ?? '' ), (string) ( $mail['message'] ?? '' ) );
		if ( ! $sent ) {
			return __( 'WordPress could not send the email.', 'axle-config-sync' );
		}

		/**
		 * Fires after a drift notification went out.
		 *
		 * @param string               $channel email or webhook.
		 * @param array<string, array> $fresh     New items.
		 */
		do_action( 'axle_config_sync_drift_notified', 'email', $fresh );

		return '';
	}

	/**
	 * Posts the new drift to a webhook.
	 *
	 * @param array<string, array> $fresh New items.
	 * @param string               $url Webhook URL.
	 * @return string An error, or empty.
	 */
	private function send_webhook( array $fresh, string $url ): string {
		$items = array();
		foreach ( $fresh as $item ) {
			$who     = $item['who'];
			$items[] = array(
				'id'       => $item['id'],
				'label'    => $item['label'],
				'provider' => $item['provider'],
				'state'    => $item['state'],
				'before'   => $item['before'],
				'after'    => $item['after'],
				'by'       => self::who_label( $who ),
				'at'       => $who ? gmdate( 'c', (int) $who['at'] ) : '',
			);
		}

		$payload = array(
			'event'       => 'drift_detected',
			'site'        => home_url(),
			'environment' => Environment::name(),
			'items'       => $items,
		);

		/**
		 * Filters the JSON payload sent to the drift webhook. Secrets are masked in the values.
		 *
		 * @param array                $payload Payload.
		 * @param array<string, array> $fresh     New items.
		 */
		$payload = (array) apply_filters( 'axle_config_sync_drift_webhook_payload', $payload, $fresh );

		$response = wp_safe_remote_post(
			$url,
			array(
				/**
				 * Filters the drift webhook timeout in seconds.
				 *
				 * @param int $seconds Default 3, at most 3.
				 */
				'timeout' => max( 1, min( 3, (int) apply_filters( 'axle_config_sync_drift_webhook_timeout', 3 ) ) ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'The webhook answered with status %d.', 'axle-config-sync' ), $code );
		}

		/** This action is documented in src/Drift.php */
		do_action( 'axle_config_sync_drift_notified', 'webhook', $fresh );

		return '';
	}
}
