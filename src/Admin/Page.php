<?php
/**
 * Settings > Config Sync.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Admin;

use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Environment;
use AryanX\Axle\ConfigSync\Providers\Options;
use AryanX\Axle\ConfigSync\Rules;
use AryanX\Axle\ConfigSync\Service;
use AryanX\Axle\ConfigSync\Settings;

use const AryanX\Axle\ConfigSync\PLUGIN_FILE;
use const AryanX\Axle\ConfigSync\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The screen with five tabs: Status, Apply, Export, Track and History.
 * Forms that change something post to admin-post.php and come back with a
 * notice. Views live in View.
 */
final class Page implements Service {

	public const SLUG = 'axle-config-sync';
	public const TABS = array( 'status', 'apply', 'export', 'track', 'history' );

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Sets up the screen.
	 *
	 * @param Engine $engine The engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Adds the menu and the form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		foreach ( array( 'apply', 'export', 'track', 'track_add', 'undo' ) as $action ) {
			add_action( 'admin_post_axle_config_sync_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( string $tab = 'status', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Adds Settings > Config Sync.
	 */
	public function add_page(): void {
		$hook = add_options_page( __( 'Config Sync', 'axle-config-sync' ), __( 'Config Sync', 'axle-config-sync' ), Settings::capability(), self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue' ) );
		}
	}

	/**
	 * Styles and the script with the wp.hooks filters.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'axle-config-sync-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'axle-config-sync-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-api-fetch', 'wp-i18n' ), VERSION, true );
		wp_localize_script(
			'axle-config-sync-admin',
			'axleConfigSyncData',
			array(
				'batchSize' => Settings::batch_size(),
				'history'   => self::url( 'history' ),
				'i18n'      => array(
					/* translators: %d: number of items. */
					'confirm'  => __( 'Apply %d item(s) to this site? You can undo it from History.', 'axle-config-sync' ),
					/* translators: 1: items applied, 2: items in total. */
					'progress' => __( 'Applied %1$d of %2$d items.', 'axle-config-sync' ),
					/* translators: %s: error message. */
					'failed'   => __( 'The apply stopped: %s', 'axle-config-sync' ),
				),
			)
		);
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	public static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';

		return in_array( $tab, self::TABS, true ) ? $tab : 'status';
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'axle-config-sync' ), 403 );
		}

		$view = new View( $this->engine );
		$view->render( self::tab(), $this->take_notice() );
	}

	/**
	 * Apply: from the Preview form.
	 */
	public function handle_apply(): void {
		$this->guard( 'axle_config_sync_apply' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$ids     = isset( $_POST['items'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['items'] ) ) : array();
		$force   = ! empty( $_POST['force'] );
		$confirm = ! empty( $_POST['confirm_dangerous'] );
		$theme   = ! empty( $_POST['inactive_theme'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->engine->apply(
			$ids,
			array(
				'allow_dangerous' => $confirm,
				'force'           => $force,
				'inactive_theme'  => $theme,
				'via'             => 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->done( 'error', $result->get_error_message(), 'apply' );
			return;
		}
		if ( ! $result['applied'] ) {
			$this->done( 'warning', \AryanX\Axle\ConfigSync\Messages::get( 'nothing' ), 'apply' );
			return;
		}

		$message = sprintf(
			/* translators: 1: number of items, 2: change set ID. */
			_n( 'Applied %1$d item as change set #%2$d.', 'Applied %1$d items as change set #%2$d.', count( $result['applied'] ), 'axle-config-sync' ),
			count( $result['applied'] ),
			(int) $result['changeset']
		);
		if ( $result['changed'] ) {
			/* translators: %s: item IDs. */
			$message .= ' ' . sprintf( __( 'Applied with changes (a sanitize callback changed the value): %s.', 'axle-config-sync' ), implode( ', ', $result['changed'] ) );
		}

		$this->done( 'success', $message, 'history', array( 'applied' => (int) $result['changeset'] ) );
	}

	/**
	 * Export: write files, or download them as a zip.
	 */
	public function handle_export(): void {
		$this->guard( 'axle_config_sync_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$all = isset( $_POST['scope'] ) && 'all' === sanitize_key( wp_unslash( $_POST['scope'] ) );
		$ids = isset( $_POST['items'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['items'] ) ) : array();
		$zip = ! empty( $_POST['zip'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $all && ! $ids ) {
			$this->done( 'error', __( 'Pick the items to export, or export all tracked items.', 'axle-config-sync' ), 'export' );
			return;
		}

		if ( $zip ) {
			$file = $this->engine->export_zip( $all ? null : $ids );
			if ( is_wp_error( $file ) ) {
				$this->done( 'error', $file->get_error_message(), 'export' );
				return;
			}
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="axle-config-' . gmdate( 'Y-m-d' ) . '.zip"' );
			header( 'Content-Length: ' . (string) filesize( $file ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.Security.EscapeOutput.OutputNotEscaped -- A zip file this request just built, sent as the download.
			echo file_get_contents( $file );
			wp_delete_file( $file );
			exit;
		}

		$result = $this->engine->export( $all ? null : $ids );
		if ( is_wp_error( $result ) ) {
			$this->done( 'error', $result->get_error_message(), 'export' );
			return;
		}

		$type    = $result['refused'] ? 'warning' : 'success';
		$message = sprintf(
			/* translators: 1: number of files, 2: directory. */
			_n( 'Wrote %1$d file to %2$s.', 'Wrote %1$d files to %2$s.', count( $result['written'] ), 'axle-config-sync' ),
			count( $result['written'] ),
			$result['directory']
		);

		$this->done(
			$type,
			$message,
			'export',
			array(),
			array(
				'files'   => array_values( $result['written'] ),
				'refused' => $result['refused'],
			)
		);
	}

	/**
	 * Track: providers, tracked options and "not a secret" marks.
	 */
	public function handle_track(): void {
		$this->guard( 'axle_config_sync_track' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$on         = isset( $_POST['providers'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['providers'] ) ) : array();
		$lines      = isset( $_POST['options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['options'] ) ) : '';
		$not_secret = isset( $_POST['not_secret'] ) ? sanitize_textarea_field( wp_unslash( $_POST['not_secret'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$providers = array();
		foreach ( array_keys( $this->engine->registry()->all() ) as $name ) {
			$providers[ $name ] = in_array( $name, $on, true );
		}

		$options = array();
		$owners  = array();
		foreach ( preg_split( '/\R/', $lines ) ? preg_split( '/\R/', $lines ) : array() as $line ) {
			$parts   = array_map( 'trim', explode( '=', (string) $line, 2 ) );
			$pattern = Rules::clean_pattern( $parts[0] );
			if ( '' === $pattern ) {
				continue;
			}
			$options[] = $pattern;
			if ( isset( $parts[1] ) && '' !== $parts[1] ) {
				$owners[ $pattern ] = sanitize_key( $parts[1] );
			}
		}

		$written = $this->engine->rules()->save(
			array(
				'providers'  => $providers,
				'options'    => $options,
				'owners'     => $owners,
				'not_secret' => array_filter( array_map( 'trim', (array) preg_split( '/\R/', $not_secret ) ) ),
			)
		);

		$this->done(
			$written ? 'success' : 'warning',
			$written
				/* translators: %s: file name. */
				? sprintf( __( 'Tracking rules saved to %s.', 'axle-config-sync' ), Rules::FILE )
				: __( 'Tracking rules saved on this site only: the config directory cannot be written.', 'axle-config-sync' ),
			'track'
		);
	}

	/**
	 * Track: options picked in the list.
	 */
	public function handle_track_add(): void {
		$this->guard( 'axle_config_sync_track_add' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$names = isset( $_POST['names'] ) ? array_map( array( Rules::class, 'clean_pattern' ), array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['names'] ) ) ) : array();
		$names = array_values( array_filter( $names, array( Options::class, 'allowed' ) ) );

		if ( ! $names ) {
			$this->done( 'error', __( 'Pick at least one option.', 'axle-config-sync' ), 'track' );
			return;
		}

		$rules            = $this->engine->rules()->all();
		$rules['options'] = array_merge( $rules['options'], $names );
		$this->engine->rules()->save( $rules );

		/* translators: %s: option names. */
		$this->done( 'success', sprintf( __( 'Now tracking: %s.', 'axle-config-sync' ), implode( ', ', $names ) ), 'track' );
	}

	/**
	 * History: undo a change set.
	 */
	public function handle_undo(): void {
		$this->guard( 'axle_config_sync_undo' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$id    = isset( $_POST['changeset'] ) ? absint( wp_unslash( $_POST['changeset'] ) ) : 0;
		$force = ! empty( $_POST['force'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->engine->undo( $id, $force, 'admin' );
		if ( is_wp_error( $result ) ) {
			$extra = 'axle_config_sync_changed_since' === $result->get_error_code() ? array( 'undo_anyway' => $id ) : array();
			$this->done( 'error', $result->get_error_message(), 'history', array(), $extra );
			return;
		}

		/* translators: 1: undone change set, 2: new change set. */
		$this->done( 'success', sprintf( __( 'Undid change set #%1$d. The undo is change set #%2$d, so you can undo it too.', 'axle-config-sync' ), (int) $result['undone'], (int) $result['changeset'] ), 'history', array( 'undone' => (int) $result['undone'] ) );
	}

	/**
	 * Checks the capability and nonce of a form.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'axle-config-sync' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Stores a notice for the next screen and redirects there.
	 *
	 * @param string $type    success, warning or error.
	 * @param string $message Message.
	 * @param string $tab     Tab to go to.
	 * @param array  $args    Extra query arguments.
	 * @param array  $extra   Extra data for the notice.
	 */
	private function done( string $type, string $message, string $tab, array $args = array(), array $extra = array() ): void {
		set_transient(
			'axle_config_sync_notice_' . get_current_user_id(),
			array_merge(
				$extra,
				array(
					'type'    => $type,
					'message' => $message,
				)
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * The stored notice, once.
	 *
	 * @return array|null
	 */
	private function take_notice(): ?array {
		$key    = 'axle_config_sync_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( $key );

		return $notice;
	}

	/**
	 * Environment name and source, for the header.
	 *
	 * @return array{name: string, source: string}
	 */
	public static function environment(): array {
		return Environment::detect();
	}
}
