<?php
/**
 * Settings > Config Sync.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Admin;

use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Environment;
use AryanX\Axle\ConfigSync\Import\WpCfm;
use AryanX\Axle\ConfigSync\Messages;
use AryanX\Axle\ConfigSync\Providers\Options;
use AryanX\Axle\ConfigSync\Rules;
use AryanX\Axle\ConfigSync\Service;
use AryanX\Axle\ConfigSync\Settings;

use const AryanX\Axle\ConfigSync\PLUGIN_FILE;
use const AryanX\Axle\ConfigSync\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The screen with seven tabs: Status, Drift, Apply, Export, Import, Track and History.
 * Forms that change something post to admin-post.php and come back with a
 * notice. Views live in View.
 */
final class Page implements Service {

	public const SLUG = 'axle-config-sync';
	public const TABS = array( 'status', 'drift', 'apply', 'export', 'import', 'track', 'history' );

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * The drift watch.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * The WP-CFM importer.
	 *
	 * @var WpCfm
	 */
	private WpCfm $wpcfm;

	/**
	 * Sets up the screen.
	 *
	 * @param Engine $engine The engine.
	 * @param Drift  $drift  The drift watch.
	 * @param WpCfm  $wpcfm  The WP-CFM importer.
	 */
	public function __construct( Engine $engine, Drift $drift, WpCfm $wpcfm ) {
		$this->engine = $engine;
		$this->drift  = $drift;
		$this->wpcfm  = $wpcfm;
	}

	/**
	 * Adds the menu and the form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
		foreach ( array( 'apply', 'export', 'track', 'track_add', 'undo', 'drift_check', 'drift_keep', 'drift_expect', 'drift_notify', 'drift_download', 'import', 'dismiss_steps', 'recheck' ) as $action ) {
			add_action( 'admin_post_axle_config_sync_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * Adds a link to the screen on the Plugins screen.
	 *
	 * @param mixed $links Action links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		if ( current_user_can( Settings::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open Config Sync', 'axle-config-sync' ) . '</a>' );
		}

		return $links;
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
			add_action( "load-{$hook}", array( $this, 'help' ) );
		}
	}

	/**
	 * The Help tabs: an overview and one tab for each job on the screen.
	 */
	public function help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-config-sync' ),
					'content' => '<p>' . esc_html__( 'Config Sync keeps your settings as JSON files in a folder you commit to git. This screen compares those files with the live site, one setting at a time, and moves values in either direction.', 'axle-config-sync' ) . '</p><p>' . esc_html__( 'Nothing is written to the site until you have seen a preview. Every apply can be undone from History.', 'axle-config-sync' ) . '</p>',
				),
				array(
					'id'      => 'status',
					'title'   => __( 'Status and apply', 'axle-config-sync' ),
					'content' => '<p>' . esc_html__( 'Status shows each setting as in sync, changed in files, changed on site, or both (a conflict). Apply copies file values onto this site after a preview with before and after.', 'axle-config-sync' ) . '</p><p>' . esc_html__( 'Dangerous changes, such as a new default role, need an extra tick before they apply.', 'axle-config-sync' ) . '</p>',
				),
				array(
					'id'      => 'drift',
					'title'   => __( 'Drift', 'axle-config-sync' ),
					'content' => '<p>' . esc_html__( 'Drift is a setting someone changed on this site after the files were written. A daily check finds it and, when you turn it on, emails you or calls a webhook.', 'axle-config-sync' ) . '</p><p>' . esc_html__( 'For each item you can keep the live value (write it to the files) or put the files back (apply with a preview). Settings a site changes on purpose go on the expected list and are never reported.', 'axle-config-sync' ) . '</p>',
				),
				array(
					'id'      => 'export',
					'title'   => __( 'Export and import', 'axle-config-sync' ),
					'content' => '<p>' . esc_html__( 'Export writes the live values to the files. Import brings WP-CFM bundles over: it shows how each item maps and what cannot be mapped, then writes Config Sync files. It never changes the site.', 'axle-config-sync' ) . '</p>',
				),
				array(
					'id'      => 'track',
					'title'   => __( 'Track', 'axle-config-sync' ),
					'content' => '<p>' . esc_html__( 'The core settings screens are always tracked. Add plugin options by name or with a prefix_* pattern, and turn providers on or off. The rules are saved in the config directory, so git has them too.', 'axle-config-sync' ) . '</p>',
				),
				array(
					'id'      => 'history',
					'title'   => __( 'History and undo', 'axle-config-sync' ),
					'content' => '<p>' . esc_html__( 'Every apply is a change set. Undo puts the earlier values back and checks nothing changed since. An undo is a change set too, so you can undo it.', 'axle-config-sync' ) . '</p>',
				),
			)
		);
	}

	/**
	 * Styles and the script with the wp.hooks filters.
	 */
	public function enqueue(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'axle-config-sync-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'axle-config-sync-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), VERSION, true );
		wp_localize_script(
			'axle-config-sync-admin',
			'axleConfigSyncData',
			array(
				'batchSize' => Settings::batch_size(),
				'history'   => self::url( 'history' ),
				'i18n'      => array(
					/* translators: %d: number of items. */
					'confirm'  => __( 'This writes %d item(s) from the files to this site and replaces what is there now. You can undo it from History.', 'axle-config-sync' ),
					/* translators: 1: items applied, 2: items in total. */
					'progress' => __( 'Applied %1$d of %2$d items.', 'axle-config-sync' ),
					/* translators: %s: error message. */
					'failed'   => __( 'The apply stopped: %s Items applied so far are in History, where you can undo them. Fix the problem and apply the rest again.', 'axle-config-sync' ),
					'copied'   => __( 'Copied.', 'axle-config-sync' ),
					'copyFail' => __( 'Could not copy. Select the text and copy it by hand.', 'axle-config-sync' ),
					'applied'  => __( 'Apply finished. Opening History.', 'axle-config-sync' ),
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

		$view = new View( $this->engine, $this->drift, $this->wpcfm );
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

		$this->done( 'success', $message, 'history', array( 'applied' => (int) $result['changeset'] ), array( 'undo' => (int) $result['changeset'] ) );
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
			$message . ' ' . __( 'Git shows what changed in them.', 'axle-config-sync' ),
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
		$this->done( 'success', sprintf( __( 'Undid change set #%1$d. The undo is change set #%2$d, so you can undo it too.', 'axle-config-sync' ), (int) $result['undone'], (int) $result['changeset'] ), 'history', array( 'undone' => (int) $result['undone'] ), array( 'undo' => (int) $result['changeset'] ) );
	}

	/**
	 * Drift: run the check now.
	 */
	public function handle_drift_check(): void {
		$this->guard( 'axle_config_sync_drift_check' );

		$result = $this->drift->check( 'admin' );
		$count  = count( $result['items'] );
		if ( ! $count ) {
			$this->done( 'success', __( 'No drift. Every tracked setting on this site still matches its file, or has not changed since the files were written.', 'axle-config-sync' ), 'drift' );
			return;
		}

		$this->done(
			'warning',
			sprintf(
				/* translators: 1: number of drifted items, 2: number of new ones. */
				_n( '%1$d setting changed on this site since the files were written (%2$d new).', '%1$d settings changed on this site since the files were written (%2$d new).', $count, 'axle-config-sync' ),
				$count,
				count( $result['new'] )
			),
			'drift'
		);
	}

	/**
	 * Drift: write the live values to the files.
	 */
	public function handle_drift_keep(): void {
		$this->guard( 'axle_config_sync_drift_keep' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$all = isset( $_POST['scope'] ) && 'all' === sanitize_key( wp_unslash( $_POST['scope'] ) );
		$ids = isset( $_POST['items'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['items'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $all && ! $ids ) {
			$this->done( 'error', __( 'Pick the items to keep, or keep all of them.', 'axle-config-sync' ), 'drift' );
			return;
		}
		if ( ! $this->engine->directory()->writable() ) {
			$this->done( 'error', Messages::get( 'not_writable', $this->engine->directory()->path() ), 'drift' );
			return;
		}

		$result = $this->drift->keep( $all ? array() : $ids );
		if ( is_wp_error( $result ) ) {
			$this->done( 'error', $result->get_error_message(), 'drift' );
			return;
		}

		$message = sprintf(
			/* translators: %d: number of files. */
			_n( 'Wrote %d file with the live value. Git shows the change; commit it to keep it.', 'Wrote %d files with the live values. Git shows the changes; commit them to keep them.', count( $result['written'] ), 'axle-config-sync' ),
			count( $result['written'] )
		);
		$this->done( $result['refused'] ? 'warning' : 'success', $message, 'drift', array(), array( 'refused' => $result['refused'] ) );
	}

	/**
	 * Drift: add items to, or take them off, the expected list.
	 */
	public function handle_drift_expect(): void {
		$this->guard( 'axle_config_sync_drift_expect' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$remove = isset( $_POST['mode'] ) && 'remove' === sanitize_key( wp_unslash( $_POST['mode'] ) );
		$ids    = isset( $_POST['items'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['items'] ) ) : array();
		$typed  = isset( $_POST['pattern'] ) ? Drift::clean_id( sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $typed ) {
			$ids[] = $typed;
		}
		if ( ! $ids ) {
			$this->done( 'error', __( 'Pick an item or type an item ID.', 'axle-config-sync' ), 'drift' );
			return;
		}

		if ( $remove ) {
			$this->drift->unexpect( $ids );
			$this->done( 'success', __( 'Taken off the expected list. Changes to these items are reported again.', 'axle-config-sync' ), 'drift' );
			return;
		}

		$this->drift->expect( $ids );
		$this->drift->check( 'admin', false );
		$this->done( 'success', __( 'Added to the expected list. Changes to these items are no longer reported. Take them off the list below to report them again.', 'axle-config-sync' ), 'drift' );
	}

	/**
	 * Drift: email and webhook settings.
	 */
	public function handle_drift_notify(): void {
		$this->guard( 'axle_config_sync_drift_notify' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$email    = ! empty( $_POST['email'] );
		$email_to = isset( $_POST['email_to'] ) ? sanitize_email( wp_unslash( $_POST['email_to'] ) ) : '';
		$webhook  = isset( $_POST['webhook'] ) ? esc_url_raw( wp_unslash( $_POST['webhook'] ), array( 'http', 'https' ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->drift->save_settings(
			array(
				'email'    => $email,
				'email_to' => $email_to,
				'webhook'  => $webhook,
			)
		);

		if ( '' !== $webhook && '' === $this->drift->settings()['webhook'] ) {
			$this->done( 'warning', __( 'The webhook address was not saved: it must be a public http or https URL. The other settings were saved.', 'axle-config-sync' ), 'drift' );
			return;
		}

		$this->done( 'success', __( 'Drift alerts saved.', 'axle-config-sync' ), 'drift' );
	}

	/**
	 * Drift: download the file an item would get, for when the folder is not writable.
	 */
	public function handle_drift_download(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'axle-config-sync' ), 403 );
		}
		check_admin_referer( 'axle_config_sync_drift_download' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$id       = isset( $_GET['item'] ) ? $this->engine->normalize_id( sanitize_text_field( wp_unslash( $_GET['item'] ) ) ) : '';
		$provider = $this->engine->registry()->for_item( $id );
		if ( ! $provider || ! $provider->exists( $id ) ) {
			wp_die( esc_html__( 'There is no such item on this site.', 'axle-config-sync' ), 404 );
		}

		$compose = $this->engine->compose_file( $id, $provider->read( $id ) );
		if ( '' !== $compose['error'] ) {
			wp_die( esc_html( $compose['error'] ), 422 );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $provider->file( $id ) ) ) . '"' );
		echo $compose['json']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON download, not HTML.
		exit;
	}

	/**
	 * Import: write Config Sync files from WP-CFM bundles.
	 */
	public function handle_import(): void {
		$this->guard( 'axle_config_sync_import' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$names = isset( $_POST['bundles'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bundles'] ) ) : array();
		if ( ! $names ) {
			$this->done( 'error', __( 'Pick at least one bundle to import.', 'axle-config-sync' ), 'import' );
			return;
		}

		$result = $this->wpcfm->import( $names );
		if ( is_wp_error( $result ) ) {
			$this->done( 'error', $result->get_error_message(), 'import' );
			return;
		}

		$skipped = array();
		foreach ( $result['plan']['items'] as $row ) {
			if ( 'skip' === $row['action'] ) {
				$skipped[ $row['bundle'] . ': ' . $row['key'] ] = $row['reason'];
			}
		}

		$message = sprintf(
			/* translators: 1: number of files, 2: number of items left out. */
			_n( 'Wrote %1$d Config Sync file from WP-CFM. %2$d item was left out.', 'Wrote %1$d Config Sync files from WP-CFM. %2$d items were left out.', count( $result['written'] ), 'axle-config-sync' ),
			count( $result['written'] ),
			count( $skipped ) + count( $result['refused'] )
		);
		$message .= ' ' . __( 'Nothing was applied to this site, and the WP-CFM files were not touched. Git shows the new files.', 'axle-config-sync' );

		$this->done(
			$skipped || $result['refused'] ? 'warning' : 'success',
			$message,
			'import',
			array(),
			array(
				'files'   => array_values( $result['written'] ),
				'refused' => array_merge( $skipped, $result['refused'] ),
			)
		);
	}

	/**
	 * Forgets the cached "is the directory served" result and looks again.
	 */
	public function handle_recheck(): void {
		$this->guard( 'axle_config_sync_recheck' );
		$this->engine->directory()->reachability( true );
		wp_safe_redirect( wp_get_referer() ? (string) wp_get_referer() : self::url() );
		exit;
	}

	/**
	 * Get started: hide the card for this user.
	 */
	public function handle_dismiss_steps(): void {
		$this->guard( 'axle_config_sync_dismiss_steps' );
		update_user_meta( get_current_user_id(), 'axle_config_sync_steps_dismissed', 1 );
		wp_safe_redirect( self::url() );
		exit;
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
