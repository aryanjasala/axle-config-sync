<?php
/**
 * Options: the core settings screens plus tracked plugin options.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Providers;

use AryanX\Axle\ConfigSync\Messages;
use AryanX\Axle\ConfigSync\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * One file per option in options/<name>.json.
 *
 * Tracked: the core settings screens (General, Writing, Reading, Discussion,
 * Media, Permalinks, Privacy), and plugin options matched by the tracking
 * rules. Never synced: URLs, versions, salts and keys, cron, this plugin's own
 * options, and options other providers own.
 */
final class Options extends Base {

	private const MISSING = '__axle_config_sync_missing__';

	/**
	 * The core settings screens.
	 */
	public const CORE = array(
		// General.
		'blogname',
		'blogdescription',
		'admin_email',
		'users_can_register',
		'default_role',
		'WPLANG',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		// Writing.
		'default_category',
		'default_post_format',
		'use_smilies',
		'default_pingback_flag',
		// Reading.
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'posts_per_rss',
		'rss_use_excerpt',
		'blog_public',
		// Discussion.
		'default_ping_status',
		'default_comment_status',
		'require_name_email',
		'comment_registration',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'show_comments_cookies_opt_in',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'comments_notify',
		'moderation_notify',
		'comment_moderation',
		'comment_previously_approved',
		'comment_max_links',
		'moderation_keys',
		'disallowed_keys',
		'show_avatars',
		'avatar_rating',
		'avatar_default',
		// Media.
		'thumbnail_size_w',
		'thumbnail_size_h',
		'thumbnail_crop',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'uploads_use_yearmonth_folders',
		// Permalinks.
		'permalink_structure',
		'category_base',
		'tag_base',
		// Privacy.
		'wp_page_for_privacy_policy',
	);

	/**
	 * Names that are never synced, even after the filter.
	 */
	private const LOCKED = array( 'siteurl', 'home', 'auth_*', 'secure_auth_*', 'logged_in_*', 'nonce_*', 'recovery_keys', 'axle_config_sync_*' );

	/**
	 * Names other providers own.
	 */
	private const OTHER_PROVIDERS = array( 'theme_mods_*', 'widget_*', 'sidebars_widgets', '*user_roles', 'nav_menu_options', 'stylesheet', 'template', 'current_theme' );

	/**
	 * Tracking rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Sets up the provider.
	 *
	 * @param Rules $rules Tracking rules.
	 */
	public function __construct( Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Short name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'options';
	}

	/**
	 * Name for people.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Options', 'axle-config-sync' );
	}

	/**
	 * Option names that are never synced.
	 *
	 * @return string[]
	 */
	public static function never_sync(): array {
		$defaults = array_merge(
			self::LOCKED,
			array( 'db_version', 'initial_db_version', 'cron', 'active_plugins', 'rewrite_rules', 'fresh_site', 'db_upgraded', 'recently_activated', 'uninstall_plugins', 'can_compress_scripts', 'recovery_mode_email_last_sent', 'auto_update_core_*', 'finished_*', 'site_icon_*', 'upload_path', 'upload_url_path', 'WPLANG_*', '_transient_*', '_site_transient_*', 'wp_force_deactivated_plugins', 'user_count', 'https_detection_errors' )
		);

		/**
		 * Filters the option names and `*` patterns that are never synced.
		 *
		 * The URLs, salts and keys, recovery keys and this plugin's own
		 * options stay blocked whatever the filter returns.
		 *
		 * @param string[] $names Names and patterns.
		 */
		$names = (array) apply_filters( 'axle_config_sync_never_sync', $defaults );

		return array_values( array_unique( array_merge( array_map( 'strval', $names ), self::LOCKED ) ) );
	}

	/**
	 * Whether an option name may be synced by this provider.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function allowed( string $name ): bool {
		return '' !== $name && ! Rules::matches_any( $name, self::never_sync() ) && ! Rules::matches_any( $name, self::OTHER_PROVIDERS );
	}

	/**
	 * Option names hidden in the Track picker as noise.
	 *
	 * @return string[]
	 */
	public static function ignored(): array {
		$defaults = array( '_transient_*', '_site_transient_*', 'cron', 'rewrite_rules', '*_version', '*_db_version', '*_activation*', '*_activated*', '*_last_run', '*_last_*', '*_cache*', '*_lock', '*_count', '*_counter', '*_counts', '*_timestamp', '*_time', '*_installed', '*_install_date' );

		/**
		 * Filters the option names the Track picker hides by default: state,
		 * caches, counters and timestamps rather than settings.
		 *
		 * @param string[] $names Names and `*` patterns.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'axle_config_sync_ignored_options', $defaults ) ) );
	}

	/**
	 * Core option names tracked on every site: the core list plus the
	 * settings core registers on its own screens.
	 *
	 * @return string[]
	 */
	public static function core_names(): array {
		$names = self::CORE;
		if ( function_exists( 'get_registered_settings' ) ) {
			foreach ( get_registered_settings() as $name => $setting ) {
				$setting = Messages::as_array( $setting );
				if ( in_array( $setting['group'] ?? '', array( 'general', 'writing', 'reading', 'discussion', 'media', 'permalink', 'privacy' ), true ) ) {
					$names[] = (string) $name;
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Tracked option IDs that exist.
	 *
	 * @return string[]
	 */
	public function items(): array {
		$names = self::core_names();
		foreach ( $this->rules->tracked() as $pattern ) {
			if ( false === strpos( $pattern, '*' ) ) {
				$names[] = $pattern;
			} else {
				$names = array_merge( $names, self::names_like( $pattern ) );
			}
		}

		$ids = array();
		foreach ( array_unique( $names ) as $name ) {
			if ( self::allowed( $name ) && $this->exists( 'options/' . $name ) ) {
				$ids[] = 'options/' . $name;
			}
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * Option names matching a `*` pattern.
	 *
	 * @param string $pattern Pattern.
	 * @return string[]
	 */
	public static function names_like( string $pattern ): array {
		global $wpdb;

		$key    = 'like_' . md5( $pattern );
		$cached = wp_cache_get( $key, 'axle_config_sync' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$like = implode( '%', array_map( array( $wpdb, 'esc_like' ), explode( '*', $pattern ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Option names by pattern; there is no API for it. Cached below.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 500", $like ) );
		$names = array_values(
			array_filter(
				array_map( 'strval', (array) $names ),
				static function ( string $name ) use ( $pattern ): bool {
					return Rules::matches( $name, $pattern );
				}
			)
		);

		wp_cache_set( $key, $names, 'axle_config_sync', MINUTE_IN_SECONDS );

		return $names;
	}

	/**
	 * Whether the option exists.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	public function exists( string $id ): bool {
		return self::MISSING !== get_option( $this->key( $id ), self::MISSING );
	}

	/**
	 * The live value.
	 *
	 * @param string $id Item ID.
	 * @return mixed
	 */
	public function read( string $id ) {
		$value = get_option( $this->key( $id ), self::MISSING );
		if ( self::MISSING === $value ) {
			return null;
		}

		// The database keeps a plain value as a string. Right after update_option()
		// the cache still has the number or boolean, so read it as the database would.
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		return is_int( $value ) || is_float( $value ) ? (string) $value : $value;
	}

	/**
	 * Writes the option with update_option(), so its sanitize callback runs.
	 *
	 * @param string $id    Item ID.
	 * @param mixed  $value Value, or null to delete the option.
	 * @throws \RuntimeException When the name may not be synced.
	 */
	public function write( string $id, $value ): void {
		$name = $this->key( $id );
		if ( ! self::allowed( $name ) ) {
			/* translators: %s: option name. */
			throw new \RuntimeException( sprintf( esc_html__( 'Option %s is never synced.', 'axle-config-sync' ), esc_html( $name ) ) );
		}

		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}

		if ( in_array( $name, array( 'permalink_structure', 'category_base', 'tag_base' ), true ) ) {
			// The rules are rebuilt on the next request.
			delete_option( 'rewrite_rules' );
		}
	}

	/**
	 * The plugin that owns the option.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	public function owner( string $id ): string {
		$name = $this->key( $id );
		$rule = $this->rules->owner_of( $name );
		if ( '' !== $rule ) {
			return $rule;
		}

		if ( in_array( $name, self::core_names(), true ) ) {
			return 'core';
		}

		return self::guess_owner( $name );
	}

	/**
	 * Guesses the plugin behind an option from its name: the longest active
	 * plugin folder name (with - as _) that the option starts with.
	 *
	 * @param string $name Option name.
	 * @return string Plugin folder name, or empty.
	 */
	public static function guess_owner( string $name ): string {
		$best = '';
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$slug   = dirname( (string) $file );
			$prefix = str_replace( '-', '_', $slug );
			if ( '.' !== $slug && ( $name === $prefix || 0 === strpos( $name, $prefix . '_' ) ) && strlen( $slug ) > strlen( $best ) ) {
				$best = $slug;
			}
		}

		return $best;
	}
}
