<?php
/**
 * The markup of Settings > Config Sync.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Admin;

use AryanX\Axle\ConfigSync\Codec;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Environment;
use AryanX\Axle\ConfigSync\Import\WpCfm;
use AryanX\Axle\ConfigSync\Pointer;
use AryanX\Axle\ConfigSync\Providers\Options;
use AryanX\Axle\ConfigSync\Rules;
use AryanX\Axle\ConfigSync\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints each tab. Everything is escaped where it is printed.
 */
final class View {

	private const PER_PAGE = 50;

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
	 * Sets up the view.
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
	 * Prints the screen.
	 *
	 * @param string     $tab    Current tab.
	 * @param array|null $notice Notice from the last action.
	 */
	public function render( string $tab, ?array $notice ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flags for the JS actions.
		$applied = isset( $_GET['applied'] ) ? absint( $_GET['applied'] ) : 0;
		$undone  = isset( $_GET['undone'] ) ? absint( $_GET['undone'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$env  = Environment::detect();
		$tone = array(
			'production' => 'warning',
			'staging'    => 'info',
		);

		printf( '<div class="wrap axle-ui acs-page" data-applied="%d" data-undone="%d">', (int) $applied, (int) $undone );
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Config Sync', 'axle-config-sync' ) . '</h1>';
		echo ' <span class="axle-status axle-badge axle-badge--' . esc_attr( $tone[ $env['name'] ] ?? 'neutral' ) . ' acs-env">' . esc_html( ucfirst( $env['name'] ) ) . '</span>';
		echo '<hr class="wp-header-end">';

		$this->notice( $notice );
		$this->tabs( $tab );
		$this->intro( $tab );
		$this->header( $env );

		echo '<div class="acs-tab acs-tab-' . esc_attr( $tab ) . '">';
		switch ( $tab ) {
			case 'drift':
				$this->drift_tab();
				break;
			case 'apply':
				$this->apply_tab();
				break;
			case 'export':
				$this->export_tab();
				break;
			case 'import':
				$this->import_tab();
				break;
			case 'track':
				$this->track_tab();
				break;
			case 'history':
				$this->history_tab( is_array( $notice ) ? (int) ( $notice['undo_anyway'] ?? 0 ) : 0 );
				break;
			default:
				$this->status_tab();
		}
		echo '</div>';
		AxleUi::footer();
		echo '</div>';
	}

	/**
	 * The one sentence that says what the current screen is for.
	 *
	 * @param string $tab Current tab.
	 */
	private function intro( string $tab ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view switches.
		$item    = isset( $_GET['item'] ) ? sanitize_text_field( wp_unslash( $_GET['item'] ) ) : '';
		$preview = isset( $_GET['step'] ) && 'preview' === sanitize_key( wp_unslash( $_GET['step'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$intros = array(
			'status'  => __( 'Compare the settings on this site with the files in the config directory, one item at a time.', 'axle-config-sync' ),
			'apply'   => __( 'Pick the items to copy from the files onto this site. You see a preview and a diff before anything changes.', 'axle-config-sync' ),
			'drift'   => __( 'Settings someone changed on this site after the files were written. Keep the live value in the files, or put the files back before the next deploy overwrites it.', 'axle-config-sync' ),
			'export'  => __( 'Write the live values to one JSON file per item in the config directory. Commit the directory to git. Secrets are written by name only.', 'axle-config-sync' ),
			'import'  => __( 'Coming from WP-CFM? Bring its bundles over as Config Sync files. The site is not changed, and the WP-CFM files stay as they are.', 'axle-config-sync' ),
			/* translators: %s: file name. */
			'track'   => sprintf( __( 'Choose what Config Sync tracks. The rules are saved to %s in the config directory, so they are in git and reviewed like the rest.', 'axle-config-sync' ), Rules::FILE ),
			'history' => __( 'Every apply is a change set. Undo puts the earlier values back, and asks you to confirm if something changed since.', 'axle-config-sync' ),
		);

		$text = $intros[ $tab ] ?? '';
		if ( 'import' === $tab && isset( $_GET['step'] ) && 'preview' === sanitize_key( wp_unslash( $_GET['step'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
			$text = __( 'Check how each WP-CFM item maps. Nothing is written until you confirm, and the site is never changed.', 'axle-config-sync' );
		}
		if ( 'status' === $tab && '' !== $item ) {
			$text = __( 'This item in the files and on this site. Apply the file value after you have read the difference.', 'axle-config-sync' );
		} elseif ( 'apply' === $tab && $preview ) {
			$text = __( 'Check what will change. Nothing is written until you apply, and you can undo an apply from History.', 'axle-config-sync' );
		}

		echo '<p class="axle-intro">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The notice from the last action.
	 *
	 * @param array|null $notice Notice.
	 */
	private function notice( ?array $notice ): void {
		if ( ! $notice ) {
			return;
		}

		$type = in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' acs-notice" role="status"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) );
		if ( ! empty( $notice['undo'] ) ) {
			/* translators: %d: change set ID. */
			echo ' <a href="' . esc_url( Page::url( 'history', array( 'undo' => (int) $notice['undo'] ) ) . '#acs-undo-' . (int) $notice['undo'] ) . '">' . esc_html( sprintf( __( 'Undo change set #%d', 'axle-config-sync' ), (int) $notice['undo'] ) ) . '</a>';
		}
		echo '</p>';

		if ( ! empty( $notice['files'] ) ) {
			echo '<details><summary>' . esc_html__( 'Files written', 'axle-config-sync' ) . '</summary><ul class="acs-files">';
			foreach ( (array) $notice['files'] as $file ) {
				echo '<li><code>' . esc_html( (string) $file ) . '</code></li>';
			}
			echo '</ul></details>';
		}
		if ( ! empty( $notice['refused'] ) ) {
			echo '<ul class="acs-refused">';
			foreach ( (array) $notice['refused'] as $id => $reason ) {
				echo '<li><code>' . esc_html( (string) $id ) . '</code> ' . esc_html( (string) $reason ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Environment, directory, last export and apply.
	 *
	 * @param array $env Environment name and source.
	 */
	private function header( array $env ): void {
		$directory = $this->engine->directory();
		$state     = $this->engine->state()->all();

		echo '<section class="axle-card acs-header" aria-label="' . esc_attr__( 'This site', 'axle-config-sync' ) . '">';
		echo '<dl class="axle-facts">';
		/* translators: 1: environment name, 2: where the environment came from. */
		echo '<dt>' . esc_html__( 'Environment', 'axle-config-sync' ) . '</dt><dd>' . esc_html( sprintf( __( '%1$s, from %2$s', 'axle-config-sync' ), ucfirst( $env['name'] ), $env['source'] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Config directory', 'axle-config-sync' ) . '</dt><dd><code>' . esc_html( $directory->path() ) . '</code> ';
		echo $directory->writable() ? '<span class="axle-badge axle-badge--good">' . esc_html__( 'Writable', 'axle-config-sync' ) . '</span>' : '<span class="axle-badge axle-badge--error">' . esc_html__( 'Not writable', 'axle-config-sync' ) . '</span>';
		echo '</dd>';
		echo '<dt>' . esc_html__( 'Last export', 'axle-config-sync' ) . '</dt><dd>' . self::when_who( $state['last_export'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in when_who().
		echo '<dt>' . esc_html__( 'Last apply', 'axle-config-sync' ) . '</dt><dd>' . self::when_who( $state['last_apply'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in when_who().
		echo '<dt>' . esc_html__( 'Last drift check', 'axle-config-sync' ) . '</dt><dd>' . AxleUi::relative_time( $this->drift->state()['checked_at'], __( 'Not yet', 'axle-config-sync' ) ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().
		echo '</dl></section>';

		$this->web_root_notice();

		if ( $this->engine->frozen() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( \AryanX\Axle\ConfigSync\Messages::get( 'frozen' ) ) . '</p></div>';
		}
	}

	/**
	 * Warns when the directory may be served over HTTP, with the rule to add
	 * for the web server this site runs on.
	 */
	private function web_root_notice(): void {
		$directory = $this->engine->directory();
		if ( ! $directory->inside_web_root() || ! $directory->exists() ) {
			return;
		}

		$reach = $directory->reachability();
		$nginx = Directory::ignores_htaccess();
		$again = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="acs-recheck"><input type="hidden" name="action" value="axle_config_sync_recheck">' . wp_nonce_field( 'axle_config_sync_recheck', '_wpnonce', true, false ) . '<button type="submit" class="button button-small">' . esc_html__( 'Check again', 'axle-config-sync' ) . '</button></form>';

		if ( 'blocked' === $reach ) {
			echo '<div class="acs-webroot acs-webroot-ok"><span class="axle-badge axle-badge--good">' . esc_html__( 'Protected', 'axle-config-sync' ) . '</span> ' . esc_html__( 'The directory is inside the web root, and the web server refuses requests for it.', 'axle-config-sync' ) . '</div>';
			return;
		}

		if ( 'reachable' === $reach ) {
			$class = 'notice-error';
			$text  = $nginx
				? __( 'Anyone can download these files: the web server hands out the config directory, and nginx ignores the .htaccess file Config Sync wrote. Add this rule to the server block of the site config, reload nginx, then check again. Or move the directory outside the web root with AXLE_CONFIG_SYNC_DIR in wp-config.php.', 'axle-config-sync' )
				: __( 'Anyone can download these files: the web server hands out the config directory. Allow .htaccess overrides for it (AllowOverride All), or add a rule like this one for your server, or move the directory outside the web root with AXLE_CONFIG_SYNC_DIR in wp-config.php.', 'axle-config-sync' );
		} elseif ( $nginx ) {
			$class = 'notice-warning';
			$text  = __( 'This site runs on nginx, which ignores the .htaccess file Config Sync wrote, and the check request did not get through, so the directory may be public. Add this rule to the server block of the site config, reload nginx, then check again.', 'axle-config-sync' );
		} else {
			$class = 'notice-info';
			$text  = __( 'The directory is inside the web root, and the check request did not get through. Apache follows the .htaccess deny; on nginx, add this rule to the site config:', 'axle-config-sync' );
		}

		echo '<div class="notice ' . esc_attr( $class ) . ' inline acs-webroot acs-webroot-' . esc_attr( $reach ) . '"><p>' . esc_html( $text ) . '</p><pre>' . esc_html( $directory->nginx_rule() ) . '</pre>' . $again . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * The tab links.
	 *
	 * @param string $current Current tab.
	 */
	private function tabs( string $current ): void {
		$labels = array(
			'status'  => __( 'Status', 'axle-config-sync' ),
			'drift'   => __( 'Drift', 'axle-config-sync' ),
			'apply'   => __( 'Apply', 'axle-config-sync' ),
			'export'  => __( 'Export', 'axle-config-sync' ),
			'import'  => __( 'Import', 'axle-config-sync' ),
			'track'   => __( 'Track', 'axle-config-sync' ),
			'history' => __( 'History', 'axle-config-sync' ),
		);

		echo '<nav class="nav-tab-wrapper acs-tabs" aria-label="' . esc_attr__( 'Config Sync sections', 'axle-config-sync' ) . '">';
		foreach ( $labels as $tab => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( Page::url( $tab ) ),
				$tab === $current ? ' nav-tab-active' : '',
				$tab === $current ? ' aria-current="page"' : '',
				esc_html( $label ) . ( 'drift' === $tab && $this->drift->count() > 0 ? ' <span class="axle-badge axle-badge--warning">' . (int) $this->drift->count() . '</span>' : '' )
			);
		}
		echo '</nav>';
	}

	/**
	 * Status: counts, filters, the list, or one item's diff.
	 */
	private function status_tab(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$item    = isset( $_GET['item'] ) ? sanitize_text_field( wp_unslash( $_GET['item'] ) ) : '';
		$filters = array(
			'provider' => isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '',
			'state'    => isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $item ) {
			$this->item_view( $this->engine->normalize_id( $item ) );
			return;
		}

		$this->get_started();
		$this->block_theme_ops_line();

		$all  = $this->engine->rows();
		$rows = Engine::filter_rows( $all, $filters );

		$links  = array();
		$counts = Engine::counts( $all );
		$last   = array_key_last( $counts );
		foreach ( $counts as $state => $count ) {
			$links[] = sprintf(
				'<li><a href="%1$s" class="acs-count acs-state-%2$s%5$s"%6$s>%4$s <span class="count">(%3$d)</span></a>%7$s</li>',
				esc_url( Page::url( 'status', array( 'state' => $state ) ) ),
				esc_attr( $state ),
				(int) $count,
				esc_html( Engine::state_label( $state ) ),
				$filters['state'] === $state ? ' current' : '',
				$filters['state'] === $state ? ' aria-current="true"' : '',
				$state === $last ? '' : ' |'
			);
		}
		echo '<ul class="subsubsub acs-counts">' . implode( '', $links ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		$this->filter_form( $filters );

		if ( ! $rows ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'No items match these filters. Clear them to see every tracked item.', 'axle-config-sync' ) . '</p><a class="button" href="' . esc_url( Page::url( 'status' ) ) . '">' . esc_html__( 'Clear filters', 'axle-config-sync' ) . '</a></div>';
			return;
		}

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Tracked items', 'axle-config-sync' ) . '"><table class="widefat striped acs-items"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Item', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Provider', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'State', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Notes', 'axle-config-sync' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr data-item="' . esc_attr( $row['id'] ) . '" data-state="' . esc_attr( $row['state'] ) . '">';
			printf(
				'<td><a class="row-title" href="%1$s">%2$s</a><br><code>%3$s</code></td>',
				esc_url( Page::url( 'status', array( 'item' => $row['id'] ) ) ),
				esc_html( $row['label'] ),
				esc_html( $row['id'] )
			);
			echo '<td>' . esc_html( $this->provider_title( $row['provider'] ) ) . '</td>';
			echo '<td>' . self::state_badge( $row['state'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().
			echo '<td>';
			$this->notes( $row );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One item: state, notes, the text diff and a key/value table.
	 *
	 * @param string $id Item ID.
	 */
	private function item_view( string $id ): void {
		$row = $this->engine->row( $id );
		echo '<p><a href="' . esc_url( Page::url( 'status' ) ) . '">' . esc_html__( 'Back to all items', 'axle-config-sync' ) . '</a></p>';
		if ( ! $row ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'There is no item with this ID. It may have been removed from the tracking rules.', 'axle-config-sync' ) . '</p></div>';
			return;
		}

		echo '<section class="axle-card">';
		echo '<h2 class="axle-card__title">' . esc_html( $row['label'] ) . ' <code>' . esc_html( $row['id'] ) . '</code></h2>';
		echo '<dl class="axle-facts"><dt>' . esc_html__( 'State', 'axle-config-sync' ) . '</dt><dd>' . self::state_badge( $row['state'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().
		echo '<dt>' . esc_html__( 'File', 'axle-config-sync' ) . '</dt><dd><code class="acs-file">' . esc_html( $row['file'] ) . '</code></dd></dl>';
		$this->notes( $row );
		echo '</section>';

		echo '<section class="axle-card">';
		$this->diff( $row, __( 'In files', 'axle-config-sync' ), __( 'On this site', 'axle-config-sync' ), $row['file_value'], $row['site_value'] );
		echo '</section>';

		if ( in_array( $row['state'], Engine::APPLY_STATES, true ) ) {
			printf(
				'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
				esc_url(
					wp_nonce_url(
						Page::url(
							'apply',
							array(
								'step'  => 'preview',
								'items' => array( $row['id'] ),
							) 
						),
						'axle_config_sync_preview' 
					) 
				),
				esc_html__( 'Preview applying the file value', 'axle-config-sync' )
			);
		}
	}

	/**
	 * Apply: pick items, then the preview.
	 */
	private function apply_tab(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The preview step checks its nonce below.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'preview' === $step ) {
			check_admin_referer( 'axle_config_sync_preview' );
			$this->preview_step();
			return;
		}

		$rows = array_filter(
			$this->engine->rows(),
			static function ( array $row ): bool {
				return in_array( $row['base_state'], Engine::APPLY_STATES, true );
			}
		);

		if ( ! $rows ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'Nothing to apply: every item is in sync with its file, or only on this site. Items show up here when a file changes.', 'axle-config-sync' ) . '</p><a class="button" href="' . esc_url( Page::url( 'status' ) ) . '">' . esc_html__( 'See the status', 'axle-config-sync' ) . '</a></div>';
			return;
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" class="acs-apply-pick">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '"><input type="hidden" name="tab" value="apply"><input type="hidden" name="step" value="preview">';
		wp_nonce_field( 'axle_config_sync_preview', '_wpnonce', false );

		echo '<div class="axle-toolbar acs-select" role="group" aria-label="' . esc_attr__( 'Select items', 'axle-config-sync' ) . '"><span>' . esc_html__( 'Select', 'axle-config-sync' ) . '</span> ';
		echo '<button type="button" class="button button-small" data-acs-select="all">' . esc_html__( 'All', 'axle-config-sync' ) . '</button> ';
		echo '<button type="button" class="button button-small" data-acs-select="none">' . esc_html__( 'None', 'axle-config-sync' ) . '</button> ';
		foreach ( Engine::APPLY_STATES as $state ) {
			echo '<button type="button" class="button button-small" data-acs-select="state:' . esc_attr( $state ) . '">' . esc_html( Engine::state_label( $state ) ) . '</button> ';
		}
		foreach ( array_unique( wp_list_pluck( $rows, 'provider' ) ) as $provider ) {
			echo '<button type="button" class="button button-small" data-acs-select="provider:' . esc_attr( $provider ) . '">' . esc_html( $this->provider_title( $provider ) ) . '</button> ';
		}
		echo '</div>';

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Items that differ', 'axle-config-sync' ) . '"><table class="widefat striped acs-items acs-pick"><thead><tr><td class="check-column"></td>';
		echo '<th scope="col">' . esc_html__( 'Item', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'State', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Notes', 'axle-config-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$field = 'acs-item-' . md5( $row['id'] );
			echo '<tr data-state="' . esc_attr( $row['base_state'] ) . '" data-provider="' . esc_attr( $row['provider'] ) . '">';
			printf( '<th scope="row" class="check-column"><input type="checkbox" name="items[]" value="%1$s" id="%2$s"></th>', esc_attr( $row['id'] ), esc_attr( $field ) );
			printf( '<td><label for="%1$s"><strong>%2$s</strong> <code>%3$s</code></label></td>', esc_attr( $field ), esc_html( $row['label'] ), esc_html( $row['id'] ) );
			echo '<td>' . self::state_badge( $row['state'] ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().
			$this->notes( $row );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<p><label><input type="checkbox" name="inactive_theme" value="1"> ' . esc_html__( 'Apply to an inactive theme (theme mods saved for a theme that is not active here)', 'axle-config-sync' ) . '</label></p>';
		submit_button( __( 'Preview', 'axle-config-sync' ), 'primary', 'acs-preview', false );
		echo '</form>';
	}

	/**
	 * The preview: before and after, warnings, and the Apply form.
	 */
	private function preview_step(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked by the caller.
		$ids   = isset( $_GET['items'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['items'] ) ) : array();
		$theme = ! empty( $_GET['inactive_theme'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<p><a href="' . esc_url( Page::url( 'apply' ) ) . '">' . esc_html__( 'Back to the list', 'axle-config-sync' ) . '</a></p>';
		if ( ! $ids ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'No items are picked. Go back to the list and tick the items to apply.', 'axle-config-sync' ) . '</p></div>';
			return;
		}

		$rows      = $this->engine->preview( $ids, array( 'inactive_theme' => $theme ) );
		$apply     = array_filter(
			$rows,
			static function ( array $row ): bool {
				return 'apply' === $row['action'];
			}
		);
		$dangerous = array_filter(
			$apply,
			static function ( array $row ): bool {
				return '' !== $row['danger'];
			}
		);
		$conflicts = array_filter(
			$apply,
			static function ( array $row ): bool {
				return 'conflict' === $row['base_state'];
			}
		);

		echo '<h2>' . esc_html__( 'Preview', 'axle-config-sync' ) . '</h2>';
		printf(
			'<form method="post" action="%1$s" class="acs-apply-form" data-count="%2$d" data-batch="%3$d">',
			esc_url( admin_url( 'admin-post.php' ) ),
			count( $apply ),
			(int) Settings::batch_size()
		);
		echo '<input type="hidden" name="action" value="axle_config_sync_apply">';
		wp_nonce_field( 'axle_config_sync_apply' );
		if ( $theme ) {
			echo '<input type="hidden" name="inactive_theme" value="1">';
		}

		foreach ( $rows as $row ) {
			$this->preview_item( $row );
			if ( 'apply' === $row['action'] ) {
				echo '<input type="hidden" name="items[]" value="' . esc_attr( $row['id'] ) . '">';
			}
		}

		if ( ! $apply ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html( \AryanX\Axle\ConfigSync\Messages::get( 'nothing' ) ) . '</p></div></form>';
			return;
		}

		$count = count( $apply );
		echo '<section class="axle-card' . ( $dangerous ? ' axle-card--danger' : '' ) . ' acs-confirm">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Apply to this site', 'axle-config-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Applying writes the file values to this site and replaces what is there now. You can undo it from History.', 'axle-config-sync' ) . '</p>';
		if ( $conflicts ) {
			echo '<p><label><input type="checkbox" name="force" value="1" required> ' . esc_html__( 'Apply the items that changed both in the files and on this site, overwriting the site values', 'axle-config-sync' ) . '</label></p>';
		}
		if ( $dangerous ) {
			echo '<p class="acs-danger-confirm"><label><input type="checkbox" name="confirm_dangerous" value="1" required> ' . esc_html__( 'I have read the dangerous changes above and want to apply them', 'axle-config-sync' ) . '</label></p>';
		}
		$frozen = $this->engine->frozen();
		/* translators: %d: number of items. */
		$label = sprintf( _n( 'Apply %d item', 'Apply %d items', $count, 'axle-config-sync' ), $count );
		echo '<p class="acs-apply-start"><button type="button" class="button button-primary" id="acs-apply" data-acs-reveal="acs-apply-confirm"' . ( $frozen ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</button></p>';
		echo '<div class="axle-confirm' . ( $dangerous ? ' axle-confirm--danger' : '' ) . '" id="acs-apply-confirm" role="group" aria-labelledby="acs-apply-confirm-text" hidden>';
		echo '<p id="acs-apply-confirm-text" class="acs-confirm-text">' . esc_html(
			sprintf(
				/* translators: %d: number of items. */
				_n( 'This writes %d item from the files to this site and replaces what is there now. You can undo it from History.', 'This writes %d items from the files to this site and replaces what is there now. You can undo it from History.', $count, 'axle-config-sync' ),
				$count
			)
		) . '</p>';
		echo '<div class="axle-actions"><button type="submit" class="button button-primary" id="acs-apply-now">' . esc_html__( 'Apply now', 'axle-config-sync' ) . '</button> <button type="button" class="button" data-acs-cancel="acs-apply">' . esc_html__( 'Cancel', 'axle-config-sync' ) . '</button><span class="spinner"></span></div>';
		echo '</div>';
		echo '<p class="acs-progress" role="status" aria-live="polite"></p>';
		echo '</section></form>';
	}

	/**
	 * One item in the preview.
	 *
	 * @param array $row Preview row.
	 */
	private function preview_item( array $row ): void {
		$labels = array(
			'apply'   => __( 'Will be applied', 'axle-config-sync' ),
			'skip'    => __( 'Nothing to do', 'axle-config-sync' ),
			'blocked' => __( 'Blocked, will be skipped', 'axle-config-sync' ),
		);
		$tones  = array(
			'apply'   => 'info',
			'blocked' => 'error',
		);

		printf(
			'<section class="axle-card acs-preview-item acs-action-%1$s" data-item="%2$s" data-warnings="%3$s" aria-labelledby="%4$s">',
			esc_attr( $row['action'] ),
			esc_attr( $row['id'] ),
			esc_attr( (string) wp_json_encode( $row['warnings'] ) ),
			esc_attr( 'acs-h-' . md5( $row['id'] ) )
		);
		printf( '<h3 class="axle-card__title" id="%1$s">%2$s <code>%3$s</code></h3>', esc_attr( 'acs-h-' . md5( $row['id'] ) ), esc_html( $row['label'] ), esc_html( $row['id'] ) );
		echo '<p>' . self::state_badge( $row['base_state'] ) . ' <span class="axle-badge axle-badge--' . esc_attr( $tones[ $row['action'] ] ?? 'neutral' ) . ' acs-action">' . esc_html( $labels[ $row['action'] ] ?? '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().
		if ( '' !== $row['summary'] ) {
			echo ' <span class="acs-summary">' . esc_html( $row['summary'] ) . '</span>';
		}
		echo '</p>';
		$this->notes( $row );

		if ( 'skip' !== $row['action'] ) {
			$this->diff( $row, __( 'On this site now', 'axle-config-sync' ), __( 'After apply', 'axle-config-sync' ), $row['site_value'], $row['file_value'] );
		}
		echo '</section>';
	}

	/**
	 * Export: pick items or all tracked; write files or download a zip.
	 */
	private function export_tab(): void {
		$directory = $this->engine->directory();
		$rows      = array_filter(
			$this->engine->rows(),
			static function ( array $row ): bool {
				return $row['on_site'];
			}
		);

		if ( ! $directory->writable() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( \AryanX\Axle\ConfigSync\Messages::get( 'not_writable', $directory->path() ) ) . '</p></div>';
		}

		if ( ! $rows ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'Nothing is tracked on this site yet. Turn on a provider or add options in Track, then export.', 'axle-config-sync' ) . '</p><a class="button" href="' . esc_url( Page::url( 'track' ) ) . '">' . esc_html__( 'Open Track', 'axle-config-sync' ) . '</a></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="acs-export-form">';
		echo '<input type="hidden" name="action" value="axle_config_sync_export">';
		wp_nonce_field( 'axle_config_sync_export' );

		$has_files = (bool) $directory->files();
		echo '<div class="axle-toolbar acs-export-buttons">';
		if ( $has_files ) {
			echo '<button type="button" class="button button-primary" id="acs-export-start" data-acs-reveal="acs-export-confirm">' . esc_html__( 'Export all tracked items', 'axle-config-sync' ) . '</button> ';
		} else {
			echo '<button type="submit" name="scope" value="all" class="button button-primary">' . esc_html__( 'Export all tracked items', 'axle-config-sync' ) . '</button> ';
		}
		echo '<button type="submit" name="scope" value="selected" class="button">' . esc_html__( 'Export selected', 'axle-config-sync' ) . '</button> ';
		echo '<label class="acs-zip"><input type="checkbox" name="zip" value="1"> ' . esc_html__( 'Download as a zip instead of writing to the server', 'axle-config-sync' ) . '</label>';
		echo '</div>';

		if ( $has_files ) {
			echo '<div class="axle-confirm" id="acs-export-confirm" role="group" aria-labelledby="acs-export-confirm-text" hidden>';
			echo '<p id="acs-export-confirm-text">' . esc_html__( 'This rewrites the files in the config directory with the live values. Files that differ show up as changes in git, so you can review or revert them there. The old contents are not kept here.', 'axle-config-sync' ) . '</p>';
			echo '<div class="axle-actions"><button type="submit" name="scope" value="all" class="button button-primary">' . esc_html__( 'Rewrite all files', 'axle-config-sync' ) . '</button> <button type="button" class="button" data-acs-cancel="acs-export-start">' . esc_html__( 'Cancel', 'axle-config-sync' ) . '</button></div>';
			echo '</div>';
		}

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Items to export', 'axle-config-sync' ) . '"><table class="widefat striped acs-items acs-export-list"><thead><tr><td class="check-column"></td><th scope="col">' . esc_html__( 'Item', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'File', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'State', 'axle-config-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$field = 'acs-export-' . md5( $row['id'] );
			echo '<tr>';
			printf( '<th scope="row" class="check-column"><input type="checkbox" name="items[]" value="%1$s" id="%2$s"></th>', esc_attr( $row['id'] ), esc_attr( $field ) );
			printf( '<td><label for="%1$s">%2$s <code>%3$s</code></label></td>', esc_attr( $field ), esc_html( $row['label'] ), esc_html( $row['id'] ) );
			echo '<td><code>' . esc_html( $row['file'] ) . '</code></td><td>' . self::state_badge( $row['state'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().
		}
		echo '</tbody></table></div></form>';
	}

	/**
	 * Track: providers, tracked options, "not a secret" marks, and the option picker.
	 */
	private function track_tab(): void {
		$rules = $this->engine->rules()->all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="acs-track-form">';
		echo '<input type="hidden" name="action" value="axle_config_sync_track">';
		wp_nonce_field( 'axle_config_sync_track' );
		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Tracking rules', 'axle-config-sync' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Providers', 'axle-config-sync' ) . '</th><td><fieldset class="acs-providers"><legend class="screen-reader-text">' . esc_html__( 'Providers', 'axle-config-sync' ) . '</legend>';
		foreach ( $this->engine->registry()->all() as $name => $provider ) {
			printf(
				'<label><input type="checkbox" name="providers[]" value="%1$s"%2$s> %3$s</label><br>',
				esc_attr( $name ),
				checked( $this->engine->rules()->provider_on( $name ), true, false ),
				esc_html( $provider->title() )
			);
		}
		echo '</fieldset></td></tr>';

		$lines = array();
		foreach ( $rules['options'] as $pattern ) {
			$lines[] = $pattern . ( isset( $rules['owners'][ $pattern ] ) ? '=' . $rules['owners'][ $pattern ] : '' );
		}

		echo '<tr><th scope="row"><label for="acs-options">' . esc_html__( 'Tracked plugin options', 'axle-config-sync' ) . '</label></th><td>';
		echo '<textarea id="acs-options" name="options" rows="6" class="large-text code" aria-describedby="acs-options-help">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea>';
		echo '<p class="description" id="acs-options-help">' . esc_html__( 'One per line: an option name or a prefix_* pattern, optionally =plugin-folder for the version check. The core settings screens are always tracked.', 'axle-config-sync' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="acs-not-secret">' . esc_html__( 'Not secrets', 'axle-config-sync' ) . '</label></th><td>';
		echo '<textarea id="acs-not-secret" name="not_secret" rows="3" class="large-text code" aria-describedby="acs-not-secret-help">' . esc_textarea( implode( "\n", $rules['not_secret'] ) ) . '</textarea>';
		echo '<p class="description" id="acs-not-secret-help">' . esc_html__( 'One per line: an item ID, or item#/pointer, that looks like a secret but is not, so it is written to files as is.', 'axle-config-sync' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save tracking rules', 'axle-config-sync' ) );
		echo '</section></form>';

		$this->option_picker();
	}

	/**
	 * The paged list of options to pick from.
	 */
	private function option_picker(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only search and paging.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$hidden = ! empty( $_GET['hidden'] );
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$list = OptionList::page( $search, $hidden, $paged, self::PER_PAGE );

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Find options to track', 'axle-config-sync' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" class="axle-toolbar acs-picker-search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '"><input type="hidden" name="tab" value="track">';
		echo '<label for="acs-picker-s">' . esc_html__( 'Option name contains', 'axle-config-sync' ) . '</label> ';
		echo '<input type="search" id="acs-picker-s" name="s" value="' . esc_attr( $search ) . '"> ';
		echo '<label><input type="checkbox" name="hidden" value="1"' . checked( $hidden, true, false ) . '> ' . esc_html__( 'Show caches, counters and timestamps', 'axle-config-sync' ) . '</label> ';
		submit_button( __( 'Search', 'axle-config-sync' ), 'secondary', '', false );
		echo '</form>';

		if ( ! $list['rows'] ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'No options match this search. Try a shorter name, or show caches, counters and timestamps.', 'axle-config-sync' ) . '</p></div></section>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="acs-picker">';
		echo '<input type="hidden" name="action" value="axle_config_sync_track_add">';
		wp_nonce_field( 'axle_config_sync_track_add' );
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Options', 'axle-config-sync' ) . '"><table class="widefat striped acs-options-list"><thead><tr><td class="check-column"></td>';
		echo '<th scope="col">' . esc_html__( 'Option', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Owner (guessed)', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Size', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Autoload', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Tracked', 'axle-config-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $list['rows'] as $option ) {
			$tracked = $this->engine->rules()->is_tracked( $option['name'] ) || in_array( $option['name'], Options::core_names(), true );
			$field   = 'acs-opt-' . md5( $option['name'] );
			echo '<tr>';
			if ( $tracked ) {
				echo '<th scope="row" class="check-column"></th>';
			} else {
				printf( '<th scope="row" class="check-column"><input type="checkbox" name="names[]" value="%1$s" id="%2$s"></th>', esc_attr( $option['name'] ), esc_attr( $field ) );
			}
			printf( '<td><label for="%1$s"><code>%2$s</code></label></td>', esc_attr( $field ), esc_html( $option['name'] ) );
			echo '<td>' . esc_html( $option['owner'] ) . '</td><td>' . esc_html( size_format( $option['size'] ) ? (string) size_format( $option['size'] ) : '0 B' );
			if ( $option['size'] > 100 * KB_IN_BYTES ) {
				echo ' <span class="axle-badge axle-badge--warning">' . esc_html__( 'Large', 'axle-config-sync' ) . '</span>';
			}
			echo '</td><td>' . esc_html( $option['autoload'] ) . '</td>';
			echo '<td>' . ( $tracked ? '<span class="axle-badge axle-badge--good">' . esc_html__( 'Tracked', 'axle-config-sync' ) . '</span>' : '<span class="axle-badge axle-badge--neutral">' . esc_html__( 'Not tracked', 'axle-config-sync' ) . '</span>' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		$pages = (int) ceil( $list['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<nav class="acs-pages" aria-label="' . esc_attr__( 'Option pages', 'axle-config-sync' ) . '">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', Page::url( 'track', array_filter( array( 's' => $search, 'hidden' => $hidden ? 1 : 0 ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short list.
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</nav>';
		}

		submit_button( __( 'Track selected options', 'axle-config-sync' ), 'secondary' );
		echo '</form></section>';
	}

	/**
	 * History: change sets with Undo.
	 *
	 * @param int $undo_anyway Change set to offer "Undo anyway" for.
	 */
	private function history_tab( int $undo_anyway ): void {
		$rows = $this->engine->changesets()->recent( 50 );
		if ( ! $rows ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'Nothing applied yet. Each apply shows up here with an Undo button.', 'axle-config-sync' ) . '</p><a class="button" href="' . esc_url( Page::url( 'apply' ) ) . '">' . esc_html__( 'Open Apply', 'axle-config-sync' ) . '</a></div>';
			return;
		}

		$statuses = array(
			'applied'     => __( 'Applied', 'axle-config-sync' ),
			'undone'      => __( 'Undone', 'axle-config-sync' ),
			'rolled_back' => __( 'Rolled back after an error', 'axle-config-sync' ),
		);
		$tones    = array(
			'applied'     => 'good',
			'rolled_back' => 'error',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which row to open the undo step on.
		$open = isset( $_GET['undo'] ) ? absint( $_GET['undo'] ) : 0;

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Change sets', 'axle-config-sync' ) . '"><table class="widefat striped acs-history"><thead><tr>';
		foreach ( array( __( 'Change set', 'axle-config-sync' ), __( 'When', 'axle-config-sync' ), __( 'Who', 'axle-config-sync' ), __( 'Source', 'axle-config-sync' ), __( 'Items', 'axle-config-sync' ), __( 'Status', 'axle-config-sync' ), __( 'Actions', 'axle-config-sync' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user = $row['user_id'] ? get_userdata( $row['user_id'] ) : false;
			echo '<tr data-changeset="' . esc_attr( (string) $row['id'] ) . '">';
			echo '<td>#' . esc_html( (string) $row['id'] ) . ( $row['undo_of'] ? ' <span class="acs-undo-of">' . esc_html( sprintf( /* translators: %d: change set ID. */ __( 'undo of #%d', 'axle-config-sync' ), $row['undo_of'] ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . AxleUi::relative_time( (int) strtotime( $row['created_gmt'] . ' UTC' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().
			echo '<td>' . esc_html( $user ? $user->display_name : '' ) . '</td>';
			echo '<td>' . esc_html( $row['via'] ) . ' <span class="acs-envname">' . esc_html( $row['environment'] ) . '</span></td>';
			echo '<td>' . esc_html( (string) $row['items_count'] ) . ' <span class="acs-item-ids">' . esc_html( implode( ', ', array_slice( $row['item_ids'], 0, 5 ) ) . ( count( $row['item_ids'] ) > 5 ? ', ...' : '' ) ) . '</span></td>';
			echo '<td><span class="axle-badge axle-badge--' . esc_attr( $tones[ $row['status'] ] ?? 'neutral' ) . '">' . esc_html( $statuses[ $row['status'] ] ?? $row['status'] ) . '</span></td><td>';
			if ( 'applied' === $row['status'] ) {
				$this->undo_button( (int) $row['id'], $undo_anyway === (int) $row['id'], $open === (int) $row['id'] || $undo_anyway === (int) $row['id'], (int) $row['items_count'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The Undo (or Undo anyway) button, with its in-place confirm step.
	 *
	 * @param int  $id    Change set ID.
	 * @param bool $force Offer Undo anyway.
	 * @param bool $open  Show the confirm step already.
	 * @param int  $items Items in the change set.
	 */
	private function undo_button( int $id, bool $force, bool $open, int $items ): void {
		$box   = 'acs-undo-' . $id;
		$start = $box . '-start';
		/* translators: %d: change set ID. */
		$label = $force ? sprintf( __( 'Undo #%d anyway', 'axle-config-sync' ), $id ) : sprintf( __( 'Undo #%d', 'axle-config-sync' ), $id );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="acs-undo-form">';
		echo '<input type="hidden" name="action" value="axle_config_sync_undo"><input type="hidden" name="changeset" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'axle_config_sync_undo', '_wpnonce', true, true );
		if ( $force ) {
			echo '<input type="hidden" name="force" value="1">';
		}
		echo '<button type="button" id="' . esc_attr( $start ) . '" class="button' . ( $force ? ' axle-button--danger' : '' ) . '" data-acs-reveal="' . esc_attr( $box ) . '" aria-expanded="' . ( $open ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
		echo '<div class="axle-confirm' . ( $force ? ' axle-confirm--danger' : '' ) . '" id="' . esc_attr( $box ) . '" role="group" aria-labelledby="' . esc_attr( $box ) . '-text"' . ( $open ? '' : ' hidden' ) . '>';
		echo '<p id="' . esc_attr( $box ) . '-text">' . esc_html(
			$force
				? sprintf(
					/* translators: %d: number of items. */
					_n( 'Something changed after this apply. This puts back the earlier value of %d item and overwrites the newer change. The undo is a change set too, so you can undo it.', 'Something changed after this apply. This puts back the earlier values of %d items and overwrites the newer changes. The undo is a change set too, so you can undo it.', $items, 'axle-config-sync' ),
					$items
				)
				: sprintf(
					/* translators: %d: number of items. */
					_n( 'This puts back the earlier value of %d item. If something changed after the apply, it stops and tells you. The undo is a change set too, so you can undo it.', 'This puts back the earlier values of %d items. If something changed after the apply, it stops and tells you. The undo is a change set too, so you can undo it.', $items, 'axle-config-sync' ),
					$items
				)
		) . '</p>';
		/* translators: %d: change set ID. */
		echo '<div class="axle-actions"><button type="submit" class="button button-primary">' . esc_html( sprintf( __( 'Undo change set #%d', 'axle-config-sync' ), $id ) ) . '</button> <button type="button" class="button" data-acs-cancel="' . esc_attr( $start ) . '">' . esc_html__( 'Cancel', 'axle-config-sync' ) . '</button></div>';
		echo '</div></form>';
	}

	/**
	 * The filter form on Status.
	 *
	 * @param array $filters Current filters.
	 */
	private function filter_form( array $filters ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" class="axle-toolbar acs-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '"><input type="hidden" name="tab" value="status">';

		echo '<label for="acs-f-provider">' . esc_html__( 'Provider', 'axle-config-sync' ) . '</label> <select id="acs-f-provider" name="provider"><option value="">' . esc_html__( 'All', 'axle-config-sync' ) . '</option>';
		foreach ( $this->engine->registry()->enabled() as $name => $provider ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $name ), selected( $filters['provider'], $name, false ), esc_html( $provider->title() ) );
		}
		echo '</select> ';

		echo '<label for="acs-f-state">' . esc_html__( 'State', 'axle-config-sync' ) . '</label> <select id="acs-f-state" name="state"><option value="">' . esc_html__( 'All', 'axle-config-sync' ) . '</option>';
		foreach ( Engine::STATES as $state ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $state ), selected( $filters['state'], $state, false ), esc_html( Engine::state_label( $state ) ) );
		}
		echo '</select> ';

		echo '<label for="acs-f-s">' . esc_html__( 'Search', 'axle-config-sync' ) . '</label> <input type="search" id="acs-f-s" name="s" value="' . esc_attr( $filters['search'] ) . '"> ';
		submit_button( __( 'Filter', 'axle-config-sync' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Danger badge, problems and warnings of a row.
	 *
	 * @param array $row Row.
	 */
	private function notes( array $row ): void {
		if ( '' !== $row['danger'] ) {
			/* translators: %s: why the change is dangerous. */
			echo '<p class="acs-danger"><span class="axle-badge axle-badge--error acs-badge-danger">' . esc_html__( 'Dangerous', 'axle-config-sync' ) . '</span> ' . esc_html( $row['danger'] ) . '</p>';
		}
		if ( $row['problems'] ) {
			echo '<ul class="acs-problems">';
			foreach ( $row['problems'] as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<ul class="acs-warnings">';
		foreach ( $row['warnings'] as $warning ) {
			echo '<li>' . esc_html( $warning ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Text diff of pretty JSON, plus a key/value table for arrays.
	 *
	 * @param array  $row         Row.
	 * @param string $left_title  Left column title.
	 * @param string $right_title Right column title.
	 * @param mixed  $left        Left value.
	 * @param mixed  $right       Right value.
	 */
	private function diff( array $row, string $left_title, string $right_title, $left, $right ): void {
		$left_text  = null === $left ? '' : Codec::pretty( $left );
		$right_text = null === $right ? '' : Codec::pretty( $right );

		printf(
			'<div class="acs-diff" data-item="%1$s" data-before="%2$s" data-after="%3$s">',
			esc_attr( $row['id'] ),
			esc_attr( $left_text ),
			esc_attr( $right_text )
		);

		$diff = wp_text_diff(
			$left_text,
			$right_text,
			array(
				'title_left'  => $left_title,
				'title_right' => $right_title,
			)
		);
		if ( $diff ) {
			echo wp_kses_post( $diff );
		} else {
			echo '<p class="acs-same">' . esc_html__( 'No difference in the values.', 'axle-config-sync' ) . '</p>';
		}

		$flat_left  = is_array( $left ) || $left instanceof \stdClass ? Pointer::flatten( Engine::safe_encode( $left ) ) : array();
		$flat_right = is_array( $right ) || $right instanceof \stdClass ? Pointer::flatten( Engine::safe_encode( $right ) ) : array();
		$keys       = array_unique( array_merge( array_keys( $flat_left ), array_keys( $flat_right ) ) );
		$changed    = array();
		foreach ( $keys as $key ) {
			$a = array_key_exists( $key, $flat_left ) ? (string) wp_json_encode( $flat_left[ $key ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
			$b = array_key_exists( $key, $flat_right ) ? (string) wp_json_encode( $flat_right[ $key ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
			if ( $a !== $b ) {
				$changed[ $key ] = array( $a, $b );
			}
		}

		if ( $changed ) {
			echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Changed keys', 'axle-config-sync' ) . '"><table class="widefat acs-kv"><caption>' . esc_html__( 'Changed keys', 'axle-config-sync' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Key', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html( $left_title ) . '</th><th scope="col">' . esc_html( $right_title ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $changed, 0, 200, true ) as $key => $pair ) {
				echo '<tr><th scope="row"><code>' . esc_html( (string) $key ) . '</code></th><td><code>' . esc_html( $pair[0] ) . '</code></td><td><code>' . esc_html( $pair[1] ) . '</code></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';
	}

	/**
	 * The "Get started" card: three steps, each done when its data exists.
	 */
	private function get_started(): void {
		if ( get_user_meta( get_current_user_id(), 'axle_config_sync_steps_dismissed', true ) ) {
			return;
		}

		$settings = get_option( \AryanX\Axle\ConfigSync\Settings::OPTION, array() );
		$tracked  = ( is_array( $settings ) && isset( $settings['rules'] ) ) || null !== $this->engine->directory()->read( Rules::FILE );
		$exported = ! empty( $this->engine->state()->all()['last_export']['at'] );
		$checked  = $this->drift->state()['checked_at'] > 0;

		if ( $tracked && $exported && $checked ) {
			return;
		}

		$steps = array(
			array(
				'done'  => $tracked,
				'title' => __( 'Choose what to track', 'axle-config-sync' ),
				'text'  => __( 'The core settings are tracked already. Add the options of your plugins by name or prefix.', 'axle-config-sync' ),
				'url'   => Page::url( 'track' ),
				'label' => __( 'Open Track', 'axle-config-sync' ),
			),
			array(
				'done'  => $exported,
				'title' => __( 'Write the settings to files', 'axle-config-sync' ),
				'text'  => __( 'One JSON file per setting, in a folder you commit to git.', 'axle-config-sync' ),
				'url'   => Page::url( 'export' ),
				'label' => __( 'Open Export', 'axle-config-sync' ),
			),
			array(
				'done'  => $checked,
				'title' => __( 'Watch for drift', 'axle-config-sync' ),
				'text'  => __( 'Find out the same day when someone changes a tracked setting on the live site.', 'axle-config-sync' ),
				'url'   => Page::url( 'drift' ),
				'label' => __( 'Open Drift', 'axle-config-sync' ),
			),
		);

		echo '<section class="axle-card acs-get-started" aria-labelledby="acs-get-started-title"><h2 class="axle-card__title" id="acs-get-started-title">' . esc_html__( 'Get started', 'axle-config-sync' ) . '</h2><ol class="axle-steps">';
		foreach ( $steps as $step ) {
			echo '<li class="axle-step"><div class="axle-step__body"><strong class="axle-step__title">' . esc_html( $step['title'] ) . '</strong><p>' . esc_html( $step['text'] ) . '</p></div>';
			echo $step['done'] ? '<span class="axle-badge axle-badge--good">' . esc_html__( 'Done', 'axle-config-sync' ) . '</span>' : '<a class="button" href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['label'] ) . '</a>';
			echo '</li>';
		}
		echo '</ol>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="axle-actions"><input type="hidden" name="action" value="axle_config_sync_dismiss_steps">';
		wp_nonce_field( 'axle_config_sync_dismiss_steps' );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Hide this card', 'axle-config-sync' ) . '</button></form>';
		echo '</section>';
	}

	/**
	 * Drift: what changed on the site, who did it, and what to do about it.
	 */
	private function drift_tab(): void {
		$directory = $this->engine->directory();
		$state     = $this->drift->state();
		$items     = $this->drift->items();
		$writable  = $directory->writable();

		echo '<div class="axle-toolbar acs-drift-check">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="axle_config_sync_drift_check">';
		wp_nonce_field( 'axle_config_sync_drift_check' );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Check for drift now', 'axle-config-sync' ) . '</button></form>';
		echo '<span class="axle-toolbar__end">' . esc_html__( 'Last check:', 'axle-config-sync' ) . ' ' . AxleUi::relative_time( $state['checked_at'], __( 'not yet', 'axle-config-sync' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().
		echo '</div>';

		if ( ! $items ) {
			echo '<div class="axle-empty acs-empty"><p>';
			echo esc_html( $state['checked_at'] > 0 ? __( 'No drift. Every tracked setting still matches its file, or was not changed since the files were written. A check runs once a day, and settings that changed will show up here with who changed them.', 'axle-config-sync' ) : __( 'No drift found yet. A check runs once a day in the background. Settings that someone changes on this site after the files were written will show up here, with who changed them and the choice to keep or put back. Export to files first, so there is something to compare with.', 'axle-config-sync' ) );
			echo '</p><a class="button" href="' . esc_url( Page::url( 'export' ) ) . '">' . esc_html__( 'Open Export', 'axle-config-sync' ) . '</a></div>';
		} else {
			$this->drift_list( $items, $writable );
		}

		$this->drift_expected();
		$this->drift_alerts( $state );
	}

	/**
	 * The drifted items with their actions.
	 *
	 * @param array<string, array> $items    Drifted items.
	 * @param bool                 $writable Whether the config directory can be written.
	 */
	private function drift_list( array $items, bool $writable ): void {
		$count = count( $items );
		$ids   = array_keys( $items );

		echo '<section class="axle-card acs-drift-list" aria-labelledby="acs-drift-title"><h2 class="axle-card__title" id="acs-drift-title">' . esc_html(
			sprintf(
				/* translators: %d: number of items. */
				_n( '%d setting changed on this site', '%d settings changed on this site', $count, 'axle-config-sync' ),
				$count
			)
		) . '</h2>';
		echo '<p>' . esc_html__( 'These were changed on this site after their files were written. The next apply from the files would overwrite them.', 'axle-config-sync' ) . '</p>';

		if ( ! $writable ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( \AryanX\Axle\ConfigSync\Messages::get( 'not_writable', $this->engine->directory()->path() ) ) . ' ' . esc_html__( 'Download or copy the file for each item instead, and commit it by hand.', 'axle-config-sync' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="acs-drift-form">';
		echo '<input type="hidden" name="action" value="axle_config_sync_drift_keep">';
		wp_nonce_field( 'axle_config_sync_drift_keep' );

		echo '<div class="axle-toolbar">';
		if ( $writable ) {
			echo '<button type="button" class="button" id="acs-keep-all-start" data-acs-reveal="acs-keep-all-confirm">' . esc_html__( 'Write all to files', 'axle-config-sync' ) . '</button>';
			echo '<button type="button" class="button" id="acs-keep-selected-start" data-acs-reveal="acs-keep-selected-confirm">' . esc_html__( 'Write selected to files', 'axle-config-sync' ) . '</button>';
		}
		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( wp_nonce_url( Page::url( 'apply', array( 'step' => 'preview', 'items' => $ids ) ), 'axle_config_sync_preview' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short list.
			esc_html__( 'Put all back from files (preview first)', 'axle-config-sync' )
		);
		echo '</div>';

		if ( $writable ) {
			foreach ( array( 'all', 'selected' ) as $scope ) {
				$box = 'acs-keep-' . $scope . '-confirm';
				echo '<div class="axle-confirm" id="' . esc_attr( $box ) . '" role="group" aria-labelledby="' . esc_attr( $box ) . '-text" hidden>';
				echo '<p id="' . esc_attr( $box ) . '-text">' . esc_html( 'all' === $scope ? __( 'This rewrites the file of every item listed here with the value the site has now. Files change in the config directory; git shows the difference, and you can revert it there. Nothing changes on the site.', 'axle-config-sync' ) : __( 'This rewrites the file of each ticked item with the value the site has now. Files change in the config directory; git shows the difference, and you can revert it there. Nothing changes on the site.', 'axle-config-sync' ) ) . '</p>';
				echo '<div class="axle-actions"><button type="submit" name="scope" value="' . esc_attr( $scope ) . '" class="button button-primary">' . esc_html( 'all' === $scope ? __( 'Write all files', 'axle-config-sync' ) : __( 'Write selected files', 'axle-config-sync' ) ) . '</button> <button type="button" class="button" data-acs-cancel="acs-keep-' . esc_attr( $scope ) . '-start">' . esc_html__( 'Cancel', 'axle-config-sync' ) . '</button></div></div>';
			}
		}

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Drifted items', 'axle-config-sync' ) . '"><table class="widefat striped acs-items acs-drift"><thead><tr><td class="check-column"></td>';
		echo '<th scope="col">' . esc_html__( 'Item', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Changed', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Files and site', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'axle-config-sync' ) . '</th></tr></thead><tbody>';

		foreach ( $items as $id => $item ) {
			$key = md5( $id );
			echo '<tr data-item="' . esc_attr( $id ) . '">';
			printf( '<th scope="row" class="check-column"><input type="checkbox" name="items[]" value="%1$s" id="%2$s" aria-label="%3$s"></th>', esc_attr( $id ), esc_attr( 'acs-drift-' . $key ), esc_attr( $item['label'] ) );
			printf( '<td><a class="row-title" href="%1$s">%2$s</a><br><code>%3$s</code><br>%4$s</td>', esc_url( Page::url( 'status', array( 'item' => $id ) ) ), esc_html( $item['label'] ), esc_html( $id ), self::state_badge( $item['state'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().

			echo '<td>';
			$who = Drift::who_label( $item['who'] );
			if ( '' !== $who ) {
				/* translators: %s: person or source that changed the setting. */
				echo '<span class="acs-who">' . esc_html( sprintf( __( 'by %s', 'axle-config-sync' ), $who ) ) . '</span><br>';
			} else {
				echo '<span class="axle-muted">' . esc_html__( 'Who: not recorded', 'axle-config-sync' ) . '</span><br>';
			}
			echo AxleUi::relative_time( (int) $item['since'], __( 'Unknown', 'axle-config-sync' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().

			echo '<td><div class="axle-diff"><del><span class="screen-reader-text">' . esc_html__( 'In the files:', 'axle-config-sync' ) . '</span> ' . esc_html( '' === $item['before'] ? '-' : $item['before'] ) . '</del><ins><span class="screen-reader-text">' . esc_html__( 'On this site:', 'axle-config-sync' ) . '</span> ' . esc_html( '' === $item['after'] ? '-' : $item['after'] ) . '</ins></div></td>';

			echo '<td class="acs-drift-actions">';
			if ( $writable ) {
				echo '<button type="button" class="button button-small" id="acs-keep-' . esc_attr( $key ) . '-start" data-acs-reveal="acs-keep-' . esc_attr( $key ) . '" aria-expanded="false">' . esc_html__( 'Write to file', 'axle-config-sync' ) . '</button>';
				echo '<div class="axle-confirm" id="acs-keep-' . esc_attr( $key ) . '" role="group" aria-labelledby="acs-keep-' . esc_attr( $key ) . '-text" hidden><p id="acs-keep-' . esc_attr( $key ) . '-text">' . esc_html__( 'This rewrites the file with the value the site has now. Git shows the change and can revert it. Nothing changes on the site.', 'axle-config-sync' ) . '</p>';
				echo '<div class="axle-actions"><button type="submit" name="items[]" value="' . esc_attr( $id ) . '" class="button button-primary button-small">' . esc_html__( 'Write this file', 'axle-config-sync' ) . '</button> <button type="button" class="button button-small" data-acs-cancel="acs-keep-' . esc_attr( $key ) . '-start">' . esc_html__( 'Cancel', 'axle-config-sync' ) . '</button></div></div>';
			} else {
				printf(
					'<a class="button button-small" href="%1$s">%2$s</a> ',
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=axle_config_sync_drift_download&item=' . rawurlencode( $id ) ), 'axle_config_sync_drift_download' ) ),
					esc_html__( 'Download file', 'axle-config-sync' )
				);
				$this->copy_file( $id, $key );
			}
			printf(
				'<a class="button button-small" href="%1$s">%2$s</a> ',
				esc_url( wp_nonce_url( Page::url( 'apply', array( 'step' => 'preview', 'items' => array( $id ) ) ), 'axle_config_sync_preview' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short list.
				esc_html__( 'Put back from files', 'axle-config-sync' )
			);
			echo '<button type="submit" form="acs-expect-form" name="items[]" value="' . esc_attr( $id ) . '" class="button-link">' . esc_html__( 'Expect this change', 'axle-config-sync' ) . '</button>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div></form>';

		echo '<form method="post" id="acs-expect-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="axle_config_sync_drift_expect"><input type="hidden" name="mode" value="add">';
		wp_nonce_field( 'axle_config_sync_drift_expect' );
		echo '</form>';
		echo '</section>';
	}

	/**
	 * A "Copy file" block for an item, for when the directory is not writable.
	 *
	 * @param string $id  Item ID.
	 * @param string $key Short key for element IDs.
	 */
	private function copy_file( string $id, string $key ): void {
		$provider = $this->engine->registry()->for_item( $id );
		if ( ! $provider || ! $provider->exists( $id ) ) {
			return;
		}
		$compose = $this->engine->compose_file( $id, $provider->read( $id ) );
		if ( '' !== $compose['error'] ) {
			echo '<span class="axle-muted">' . esc_html( $compose['error'] ) . '</span> ';
			return;
		}

		echo '<details class="acs-copy"><summary>' . esc_html__( 'Show the file', 'axle-config-sync' ) . '</summary>';
		echo '<label class="screen-reader-text" for="acs-file-' . esc_attr( $key ) . '">' . esc_html__( 'File contents', 'axle-config-sync' ) . '</label>';
		echo '<textarea readonly rows="8" class="large-text code" id="acs-file-' . esc_attr( $key ) . '">' . esc_textarea( $compose['json'] ) . '</textarea>';
		echo '<p><button type="button" class="button button-small axle-copy" data-acs-copy="acs-file-' . esc_attr( $key ) . '">' . esc_html__( 'Copy file', 'axle-config-sync' ) . '</button> <code>' . esc_html( $provider->file( $id ) ) . '</code></p></details>';
	}

	/**
	 * The expected list: items a site may change on purpose.
	 */
	private function drift_expected(): void {
		$saved    = $this->drift->settings()['expected'];
		$filtered = array_diff( $this->drift->expected(), $saved );

		echo '<section class="axle-card acs-expected" aria-labelledby="acs-expected-title"><h2 class="axle-card__title" id="acs-expected-title">' . esc_html__( 'Expected changes', 'axle-config-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Items a site changes on purpose, such as a counter or a notice on production. They are never reported as drift. An item ID or a pattern like options/woocommerce_* works.', 'axle-config-sync' ) . '</p>';

		if ( ! $saved && ! $filtered ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'Nothing is expected to change. Add an item below, or use "Expect this change" on a drifted item.', 'axle-config-sync' ) . '</p></div>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="axle_config_sync_drift_expect"><input type="hidden" name="mode" value="remove">';
			wp_nonce_field( 'axle_config_sync_drift_expect' );
			echo '<ul class="acs-expected-list">';
			foreach ( $saved as $pattern ) {
				echo '<li><code>' . esc_html( $pattern ) . '</code> <button type="submit" name="items[]" value="' . esc_attr( $pattern ) . '" class="button-link">' . esc_html__( 'Take off the list', 'axle-config-sync' ) . '</button></li>';
			}
			foreach ( $filtered as $pattern ) {
				echo '<li><code>' . esc_html( $pattern ) . '</code> <span class="axle-badge axle-badge--neutral">' . esc_html__( 'Set in code', 'axle-config-sync' ) . '</span></li>';
			}
			echo '</ul></form>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="axle-toolbar"><input type="hidden" name="action" value="axle_config_sync_drift_expect"><input type="hidden" name="mode" value="add">';
		wp_nonce_field( 'axle_config_sync_drift_expect' );
		echo '<label for="acs-expect-pattern">' . esc_html__( 'Item ID or pattern', 'axle-config-sync' ) . '</label> <input type="text" id="acs-expect-pattern" name="pattern" class="regular-text code" placeholder="options/blogdescription"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Add to the expected list', 'axle-config-sync' ) . '</button></form>';
		echo '</section>';
	}

	/**
	 * Email and webhook settings.
	 *
	 * @param array $state Drift state.
	 */
	private function drift_alerts( array $state ): void {
		$settings = $this->drift->settings();

		echo '<section class="axle-card acs-alerts" aria-labelledby="acs-alerts-title"><h2 class="axle-card__title" id="acs-alerts-title">' . esc_html__( 'Alerts', 'axle-config-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Admins always see a notice and a Site Health result. Email and a webhook are off until you turn them on. Each new drift is sent once, with the value in the files and on the site (secrets are masked), and who changed it.', 'axle-config-sync' ) . '</p>';

		foreach ( $state['errors'] as $channel => $message ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: email or webhook, 2: what went wrong. */
					__( 'The last %1$s alert failed: %2$s', 'axle-config-sync' ),
					'webhook' === $channel ? __( 'webhook', 'axle-config-sync' ) : __( 'email', 'axle-config-sync' ),
					$message
				)
			) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="axle_config_sync_drift_notify">';
		wp_nonce_field( 'axle_config_sync_drift_notify' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Email', 'axle-config-sync' ) . '</th><td><label><input type="checkbox" name="email" value="1"' . checked( $settings['email'], true, false ) . '> ' . esc_html__( 'Email me when new drift is found', 'axle-config-sync' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="acs-email-to">' . esc_html__( 'Send to', 'axle-config-sync' ) . '</label></th><td><input type="email" id="acs-email-to" name="email_to" class="regular-text" value="' . esc_attr( $settings['email_to'] ) . '" aria-describedby="acs-email-to-help"><p class="description" id="acs-email-to-help">' . esc_html__( 'Leave empty to use the site admin email.', 'axle-config-sync' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="acs-webhook">' . esc_html__( 'Webhook', 'axle-config-sync' ) . '</label></th><td><input type="url" id="acs-webhook" name="webhook" class="regular-text code" value="' . esc_attr( $settings['webhook'] ) . '" aria-describedby="acs-webhook-help"><p class="description" id="acs-webhook-help">' . esc_html__( 'A public http or https address. It gets a JSON POST for each new drift. This is the only time the plugin calls another server.', 'axle-config-sync' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save alerts', 'axle-config-sync' ), 'secondary' );
		echo '</form></section>';
	}

	/**
	 * Import: WP-CFM bundles.
	 */
	private function import_tab(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The preview step checks its nonce below.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'preview' === $step ) {
			check_admin_referer( 'axle_config_sync_import_preview' );
			$this->import_preview();
			return;
		}

		$bundles = $this->wpcfm->bundles();
		if ( ! $bundles ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html(
				sprintf(
					/* translators: %s: folder path. */
					__( 'No WP-CFM bundles found in %s, and none in its settings in the database. If your bundle files are somewhere else, point Config Sync at that folder with the axle_config_sync_wpcfm_directory filter.', 'axle-config-sync' ),
					$this->wpcfm->directory()
				)
			) . '</p><a class="button" href="' . esc_url( Page::url( 'export' ) ) . '">' . esc_html__( 'Open Export', 'axle-config-sync' ) . '</a></div>';
			$this->wpcfm_commands();
			return;
		}

		$sources = array(
			'files'    => __( 'Files', 'axle-config-sync' ),
			'database' => __( 'Database', 'axle-config-sync' ),
			'both'     => __( 'Files and database', 'axle-config-sync' ),
		);

		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" class="acs-import-pick">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '"><input type="hidden" name="tab" value="import"><input type="hidden" name="step" value="preview">';
		wp_nonce_field( 'axle_config_sync_import_preview', '_wpnonce', false );

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'WP-CFM bundles', 'axle-config-sync' ) . '"><table class="widefat striped acs-items acs-bundles"><thead><tr><td class="check-column"></td>';
		echo '<th scope="col">' . esc_html__( 'Bundle', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Found in', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Items', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Notes', 'axle-config-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $bundles as $name => $bundle ) {
			$field = 'acs-bundle-' . md5( $name );
			echo '<tr>';
			if ( '' !== $bundle['error'] ) {
				echo '<th scope="row" class="check-column"></th>';
			} else {
				printf( '<th scope="row" class="check-column"><input type="checkbox" name="bundles[]" value="%1$s" id="%2$s" checked></th>', esc_attr( $name ), esc_attr( $field ) );
			}
			printf( '<td><label for="%1$s"><strong>%2$s</strong> <code>%3$s</code></label></td>', esc_attr( $field ), esc_html( $bundle['label'] ), esc_html( $name ) );
			echo '<td>' . esc_html( $sources[ $bundle['source'] ] ?? $bundle['source'] ) . '</td><td>' . esc_html( (string) count( $bundle['keys'] ) ) . '</td><td>';
			if ( '' !== $bundle['error'] ) {
				echo '<span class="axle-badge axle-badge--error">' . esc_html__( 'Cannot read', 'axle-config-sync' ) . '</span> ' . esc_html( $bundle['error'] );
			} else {
				echo esc_html( $bundle['note'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		submit_button( __( 'Preview the import', 'axle-config-sync' ), 'primary', '', true, array( 'id' => 'acs-import-preview' ) );
		echo '</form>';

		$this->wpcfm_commands();
	}

	/**
	 * The preview of an import: each WP-CFM item and where it goes.
	 */
	private function import_preview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked by the caller.
		$names = isset( $_GET['bundles'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['bundles'] ) ) : array();

		echo '<p><a href="' . esc_url( Page::url( 'import' ) ) . '">' . esc_html__( 'Back to the bundles', 'axle-config-sync' ) . '</a></p>';
		if ( ! $names ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'No bundles are picked. Go back and tick the bundles to import.', 'axle-config-sync' ) . '</p></div>';
			return;
		}

		$plan   = $this->wpcfm->plan( $names );
		$counts = array_fill_keys( array( 'new', 'replace', 'same', 'skip' ), 0 );
		foreach ( $plan['items'] as $row ) {
			++$counts[ $row['action'] ];
		}

		echo '<h2>' . esc_html__( 'Preview', 'axle-config-sync' ) . '</h2>';
		echo '<div class="axle-stats">';
		$stats = array(
			'new'     => array( __( 'new files', 'axle-config-sync' ), 'info' ),
			'replace' => array( __( 'files replaced', 'axle-config-sync' ), 'warning' ),
			'same'    => array( __( 'already the same', 'axle-config-sync' ), 'good' ),
			'skip'    => array( __( 'left out', 'axle-config-sync' ), 'neutral' ),
		);
		foreach ( $stats as $key => $stat ) {
			echo '<div class="axle-stat axle-stat--' . esc_attr( $stat[1] ) . '"><span class="axle-stat__value">' . (int) $counts[ $key ] . '</span><span class="axle-stat__label">' . esc_html( $stat[0] ) . '</span></div>';
		}
		echo '</div>';

		foreach ( $plan['errors'] as $bundle => $error ) {
			echo '<div class="notice notice-error inline"><p><code>' . esc_html( $bundle ) . '</code> ' . esc_html( $error ) . '</p></div>';
		}

		$labels = array(
			'new'     => array( __( 'New file', 'axle-config-sync' ), 'info' ),
			'replace' => array( __( 'Replaces the file', 'axle-config-sync' ), 'warning' ),
			'same'    => array( __( 'Already the same', 'axle-config-sync' ), 'good' ),
			'skip'    => array( __( 'Left out', 'axle-config-sync' ), 'neutral' ),
		);

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'How each WP-CFM item maps', 'axle-config-sync' ) . '"><table class="widefat striped acs-items acs-import-plan"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'WP-CFM item', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Config Sync item', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Result', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'Value', 'axle-config-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $plan['items'] as $row ) {
			$label = $labels[ $row['action'] ];
			echo '<tr data-action="' . esc_attr( $row['action'] ) . '">';
			echo '<td><code>' . esc_html( $row['key'] ) . '</code><br><span class="axle-muted">' . esc_html( $row['bundle'] ) . '</span></td>';
			echo '<td>' . ( '' !== $row['id'] ? '<code>' . esc_html( $row['id'] ) . '</code>' : '<span class="axle-muted">' . esc_html__( 'None', 'axle-config-sync' ) . '</span>' ) . '</td>';
			echo '<td><span class="axle-badge axle-badge--' . esc_attr( $label[1] ) . '">' . esc_html( $label[0] ) . '</span>' . ( '' !== $row['reason'] ? '<br>' . esc_html( $row['reason'] ) : '' ) . '</td>';
			echo '<td><code>' . esc_html( $row['value'] ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';

		$write = $counts['new'] + $counts['replace'];
		if ( ! $write ) {
			echo '<div class="axle-empty acs-empty"><p>' . esc_html__( 'Nothing to write: every item is already in its file, or cannot be mapped.', 'axle-config-sync' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="acs-import-form"><input type="hidden" name="action" value="axle_config_sync_import">';
		wp_nonce_field( 'axle_config_sync_import' );
		foreach ( $plan['bundles'] as $bundle ) {
			echo '<input type="hidden" name="bundles[]" value="' . esc_attr( $bundle ) . '">';
		}
		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Write the files', 'axle-config-sync' ) . '</h2>';
		if ( ! $this->engine->directory()->writable() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( \AryanX\Axle\ConfigSync\Messages::get( 'not_writable', $this->engine->directory()->path() ) ) . '</p></div></section></form>';
			return;
		}
		echo '<p>' . esc_html__( 'This writes Config Sync files only. The site is not changed and the WP-CFM files are left alone. Plugin options in the bundles are added to the tracking rules.', 'axle-config-sync' ) . '</p>';
		echo '<p class="acs-import-start"><button type="button" class="button button-primary" id="acs-import-start" data-acs-reveal="acs-import-confirm">' . esc_html(
			sprintf(
				/* translators: %d: number of files. */
				_n( 'Write %d file', 'Write %d files', $write, 'axle-config-sync' ),
				$write
			)
		) . '</button></p>';
		echo '<div class="axle-confirm' . ( $counts['replace'] ? ' axle-confirm--danger' : '' ) . '" id="acs-import-confirm" role="group" aria-labelledby="acs-import-confirm-text" hidden>';
		echo '<p id="acs-import-confirm-text">' . esc_html(
			$counts['replace']
				? sprintf(
					/* translators: 1: number of files written, 2: number of files replaced. */
					__( 'This writes %1$d files into the config directory and replaces %2$d that are there now. Git shows the changes and can revert them. Nothing changes on the site.', 'axle-config-sync' ),
					$write,
					$counts['replace']
				)
				: sprintf(
					/* translators: %d: number of files written. */
					_n( 'This writes %d new file into the config directory. Nothing changes on the site.', 'This writes %d new files into the config directory. Nothing changes on the site.', $write, 'axle-config-sync' ),
					$write
				)
		) . '</p>';
		echo '<div class="axle-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Write the files', 'axle-config-sync' ) . '</button> <button type="button" class="button" data-acs-cancel="acs-import-start">' . esc_html__( 'Cancel', 'axle-config-sync' ) . '</button></div></div>';
		echo '</section></form>';
	}

	/**
	 * WP-CLI commands side by side: WP-CFM and Config Sync.
	 */
	private function wpcfm_commands(): void {
		$pairs = array(
			array( 'wp config bundles', 'wp axle-config-sync status' ),
			array( 'wp config diff all', 'wp axle-config-sync diff <item>' ),
			array( 'wp config push all', 'wp axle-config-sync export --all' ),
			array( 'wp config pull all', 'wp axle-config-sync apply --all --yes' ),
			array( '(nothing)', 'wp axle-config-sync import wp-cfm --dry-run' ),
		);

		echo '<details class="acs-commands"><summary>' . esc_html__( 'WP-CLI commands side by side', 'axle-config-sync' ) . '</summary>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'WP-CFM and Config Sync commands', 'axle-config-sync' ) . '"><table class="widefat striped"><thead><tr><th scope="col">WP-CFM</th><th scope="col">Config Sync</th></tr></thead><tbody>';
		foreach ( $pairs as $pair ) {
			echo '<tr><td><code>' . esc_html( $pair[0] ) . '</code></td><td><code>' . esc_html( $pair[1] ) . '</code></td></tr>';
		}
		echo '</tbody></table></div></details>';
	}

	/**
	 * The line that points at Block Theme Ops for templates and styles.
	 */
	private function block_theme_ops_line(): void {
		$active = class_exists( '\AryanX\Axle\BlockThemeOps\Plugin' ) || in_array( 'block-theme-ops/block-theme-ops.php', (array) get_option( 'active_plugins', array() ), true );
		$url    = $active ? admin_url( 'themes.php?page=block-theme-ops' ) : 'https://wordpress.org/plugins/block-theme-ops/';

		printf(
			'<p class="acs-bto">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Templates, template parts and global styles are not synced here: they belong in the theme.', 'axle-config-sync' ),
			esc_url( $url ),
			$active ? esc_html__( 'Export them with Block Theme Ops.', 'axle-config-sync' ) : esc_html__( 'Block Theme Ops exports them to your theme repository.', 'axle-config-sync' )
		);
	}

	/**
	 * A provider's title.
	 *
	 * @param string $name Provider name.
	 * @return string
	 */
	private function provider_title( string $name ): string {
		$all = $this->engine->registry()->all();

		return isset( $all[ $name ] ) ? $all[ $name ]->title() : $name;
	}

	/**
	 * A state badge.
	 *
	 * @param string $state State.
	 * @return string HTML.
	 */
	public static function state_badge( string $state ): string {
		$tones = array(
			'in_sync'          => 'good',
			'changed_in_files' => 'info',
			'only_in_files'    => 'info',
			'changed_on_site'  => 'warning',
			'only_on_site'     => 'warning',
			'conflict'         => 'error',
			'blocked'          => 'error',
		);

		return '<span class="axle-badge axle-badge--' . esc_attr( $tones[ $state ] ?? 'neutral' ) . ' acs-state-' . esc_attr( $state ) . '">' . esc_html( Engine::state_label( $state ) ) . '</span>';
	}

	/**
	 * "2 hours ago by Maria", or "Never". HTML, already escaped.
	 *
	 * @param array $entry at, by.
	 * @return string
	 */
	private static function when_who( array $entry ): string {
		$when = AxleUi::relative_time( (int) ( $entry['at'] ?? 0 ) );
		$user = ! empty( $entry['by'] ) ? get_userdata( (int) $entry['by'] ) : false;

		/* translators: 1: time ago, 2: user name. */
		return $user && ! empty( $entry['at'] ) ? sprintf( esc_html__( '%1$s by %2$s', 'axle-config-sync' ), $when, esc_html( $user->display_name ) ) : $when;
	}
}
