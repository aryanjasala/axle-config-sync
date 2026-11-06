<?php
/**
 * The markup of Settings > Config Sync.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Admin;

use AryanX\Axle\ConfigSync\Codec;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Environment;
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
	 * Sets up the view.
	 *
	 * @param Engine $engine The engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
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
			case 'apply':
				$this->apply_tab();
				break;
			case 'export':
				$this->export_tab();
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
			'export'  => __( 'Write the live values to one JSON file per item in the config directory. Commit the directory to git. Secrets are written by name only.', 'axle-config-sync' ),
			/* translators: %s: file name. */
			'track'   => sprintf( __( 'Choose what Config Sync tracks. The rules are saved to %s in the config directory, so they are in git and reviewed like the rest.', 'axle-config-sync' ), Rules::FILE ),
			'history' => __( 'Every apply is a change set. Undo puts the earlier values back, and asks you to confirm if something changed since.', 'axle-config-sync' ),
		);

		$text = $intros[ $tab ] ?? '';
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
		echo '<div class="notice notice-' . esc_attr( $type ) . ' acs-notice"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p>';

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
		echo '<dt>' . esc_html__( 'Last export', 'axle-config-sync' ) . '</dt><dd>' . esc_html( self::when_who( $state['last_export'] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Last apply', 'axle-config-sync' ) . '</dt><dd>' . esc_html( self::when_who( $state['last_apply'] ) ) . '</dd>';
		echo '</dl></section>';

		$this->web_root_notice();

		if ( $this->engine->frozen() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( \AryanX\Axle\ConfigSync\Messages::get( 'frozen' ) ) . '</p></div>';
		}
	}

	/**
	 * Warns when the directory is served over HTTP, with the nginx rule.
	 */
	private function web_root_notice(): void {
		$directory = $this->engine->directory();
		if ( ! $directory->inside_web_root() || ! $directory->exists() ) {
			return;
		}

		$reach = $directory->reachability();
		if ( 'blocked' === $reach ) {
			echo '<p class="acs-webroot acs-webroot-ok"><span class="axle-badge axle-badge--good">' . esc_html__( 'Protected', 'axle-config-sync' ) . '</span> ' . esc_html__( 'The directory is inside the web root, and the web server refuses requests for it.', 'axle-config-sync' ) . '</p>';
			return;
		}

		$class = 'reachable' === $reach ? 'notice-error' : 'notice-info';
		$text  = 'reachable' === $reach
			? __( 'Anyone can download these files: the web server hands out the config directory. On nginx, add this rule to the site config, or move the directory outside the web root with AXLE_CONFIG_SYNC_DIR in wp-config.php.', 'axle-config-sync' )
			: __( 'The directory is inside the web root, and the check request did not get through. Apache follows the .htaccess deny; on nginx, add this rule to the site config:', 'axle-config-sync' );

		echo '<div class="notice ' . esc_attr( $class ) . ' inline acs-webroot acs-webroot-' . esc_attr( $reach ) . '"><p>' . esc_html( $text ) . '</p><pre>' . esc_html( $directory->nginx_rule() ) . '</pre></div>';
	}

	/**
	 * The tab links.
	 *
	 * @param string $current Current tab.
	 */
	private function tabs( string $current ): void {
		$labels = array(
			'status'  => __( 'Status', 'axle-config-sync' ),
			'apply'   => __( 'Apply', 'axle-config-sync' ),
			'export'  => __( 'Export', 'axle-config-sync' ),
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
				esc_html( $label )
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

		$this->block_theme_ops_line();

		$all  = $this->engine->rows();
		$rows = $this->engine->rows( $filters );

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

		echo '<table class="widefat striped acs-items"><thead><tr>';
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
		echo '</tbody></table>';
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

		echo '<p class="acs-select" role="group" aria-label="' . esc_attr__( 'Select items', 'axle-config-sync' ) . '"><span>' . esc_html__( 'Select', 'axle-config-sync' ) . '</span> ';
		echo '<button type="button" class="button button-small" data-acs-select="all">' . esc_html__( 'All', 'axle-config-sync' ) . '</button> ';
		echo '<button type="button" class="button button-small" data-acs-select="none">' . esc_html__( 'None', 'axle-config-sync' ) . '</button> ';
		foreach ( Engine::APPLY_STATES as $state ) {
			echo '<button type="button" class="button button-small" data-acs-select="state:' . esc_attr( $state ) . '">' . esc_html( Engine::state_label( $state ) ) . '</button> ';
		}
		foreach ( array_unique( wp_list_pluck( $rows, 'provider' ) ) as $provider ) {
			echo '<button type="button" class="button button-small" data-acs-select="provider:' . esc_attr( $provider ) . '">' . esc_html( $this->provider_title( $provider ) ) . '</button> ';
		}
		echo '</p>';

		echo '<table class="widefat striped acs-items acs-pick"><thead><tr><td class="check-column"></td>';
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
		echo '</tbody></table>';

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

		echo '<section class="axle-card' . ( $dangerous ? ' axle-card--danger' : '' ) . ' acs-confirm">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Apply to this site', 'axle-config-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Applying writes the file values to this site and replaces what is there now. You can undo it from History.', 'axle-config-sync' ) . '</p>';
		if ( $conflicts ) {
			echo '<p><label><input type="checkbox" name="force" value="1" required> ' . esc_html__( 'Apply the items that changed both in the files and on this site, overwriting the site values', 'axle-config-sync' ) . '</label></p>';
		}
		if ( $dangerous ) {
			echo '<p class="acs-danger-confirm"><label><input type="checkbox" name="confirm_dangerous" value="1" required> ' . esc_html__( 'I have read the dangerous changes above and want to apply them', 'axle-config-sync' ) . '</label></p>';
		}
		$disabled = $this->engine->frozen() ? array( 'disabled' => 'disabled' ) : array();
		/* translators: %d: number of items. */
		submit_button( sprintf( _n( 'Apply %d item', 'Apply %d items', count( $apply ), 'axle-config-sync' ), count( $apply ) ), 'primary', 'acs-apply', false, $disabled );
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

		echo '<p class="acs-export-buttons">';
		echo '<button type="submit" name="scope" value="all" class="button button-primary">' . esc_html__( 'Export all tracked items', 'axle-config-sync' ) . '</button> ';
		echo '<button type="submit" name="scope" value="selected" class="button">' . esc_html__( 'Export selected', 'axle-config-sync' ) . '</button> ';
		echo '<label class="acs-zip"><input type="checkbox" name="zip" value="1"> ' . esc_html__( 'Download as a zip instead of writing to the server', 'axle-config-sync' ) . '</label>';
		echo '</p>';

		echo '<table class="widefat striped acs-items acs-export-list"><thead><tr><td class="check-column"></td><th scope="col">' . esc_html__( 'Item', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'File', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html__( 'State', 'axle-config-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$field = 'acs-export-' . md5( $row['id'] );
			echo '<tr>';
			printf( '<th scope="row" class="check-column"><input type="checkbox" name="items[]" value="%1$s" id="%2$s"></th>', esc_attr( $row['id'] ), esc_attr( $field ) );
			printf( '<td><label for="%1$s">%2$s <code>%3$s</code></label></td>', esc_attr( $field ), esc_html( $row['label'] ), esc_html( $row['id'] ) );
			echo '<td><code>' . esc_html( $row['file'] ) . '</code></td><td>' . self::state_badge( $row['state'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().
		}
		echo '</tbody></table></form>';
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
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" class="acs-picker-search">';
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
		echo '<table class="widefat striped acs-options-list"><thead><tr><td class="check-column"></td>';
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
			echo '<td>' . ( $tracked ? '<span class="axle-badge axle-badge--good">' . esc_html__( 'Tracked', 'axle-config-sync' ) . '</span>' : '<span class="axle-badge">' . esc_html__( 'Not tracked', 'axle-config-sync' ) . '</span>' ) . '</td></tr>';
		}
		echo '</tbody></table>';

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

		echo '<table class="widefat striped acs-history"><thead><tr>';
		foreach ( array( __( 'Change set', 'axle-config-sync' ), __( 'When', 'axle-config-sync' ), __( 'Who', 'axle-config-sync' ), __( 'Source', 'axle-config-sync' ), __( 'Items', 'axle-config-sync' ), __( 'Status', 'axle-config-sync' ), __( 'Actions', 'axle-config-sync' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user = $row['user_id'] ? get_userdata( $row['user_id'] ) : false;
			echo '<tr data-changeset="' . esc_attr( (string) $row['id'] ) . '">';
			echo '<td>#' . esc_html( (string) $row['id'] ) . ( $row['undo_of'] ? ' <span class="acs-undo-of">' . esc_html( sprintf( /* translators: %d: change set ID. */ __( 'undo of #%d', 'axle-config-sync' ), $row['undo_of'] ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( self::when( strtotime( $row['created_gmt'] . ' UTC' ) ) ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '' ) . '</td>';
			echo '<td>' . esc_html( $row['via'] ) . ' <span class="acs-envname">' . esc_html( $row['environment'] ) . '</span></td>';
			echo '<td>' . esc_html( (string) $row['items_count'] ) . ' <span class="acs-item-ids">' . esc_html( implode( ', ', array_slice( $row['item_ids'], 0, 5 ) ) . ( count( $row['item_ids'] ) > 5 ? ', ...' : '' ) ) . '</span></td>';
			echo '<td><span class="axle-badge axle-badge--' . esc_attr( $tones[ $row['status'] ] ?? 'neutral' ) . '">' . esc_html( $statuses[ $row['status'] ] ?? $row['status'] ) . '</span></td><td>';
			if ( 'applied' === $row['status'] ) {
				$this->undo_button( (int) $row['id'], $undo_anyway === (int) $row['id'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The Undo (or Undo anyway) button.
	 *
	 * @param int  $id    Change set ID.
	 * @param bool $force Offer Undo anyway.
	 */
	private function undo_button( int $id, bool $force ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="acs-undo-form">';
		echo '<input type="hidden" name="action" value="axle_config_sync_undo"><input type="hidden" name="changeset" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'axle_config_sync_undo' );
		if ( $force ) {
			echo '<input type="hidden" name="force" value="1">';
		}
		/* translators: %d: change set ID. */
		$label = $force ? sprintf( __( 'Undo #%d anyway', 'axle-config-sync' ), $id ) : sprintf( __( 'Undo #%d', 'axle-config-sync' ), $id );
		echo '<button type="submit" class="button' . ( $force ? ' axle-button--danger' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * The filter form on Status.
	 *
	 * @param array $filters Current filters.
	 */
	private function filter_form( array $filters ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" class="acs-filters">';
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
			echo '<table class="widefat acs-kv"><caption>' . esc_html__( 'Changed keys', 'axle-config-sync' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Key', 'axle-config-sync' ) . '</th><th scope="col">' . esc_html( $left_title ) . '</th><th scope="col">' . esc_html( $right_title ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $changed, 0, 200, true ) as $key => $pair ) {
				echo '<tr><th scope="row"><code>' . esc_html( (string) $key ) . '</code></th><td><code>' . esc_html( $pair[0] ) . '</code></td><td><code>' . esc_html( $pair[1] ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
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
	 * "2 hours ago by Maria", or "Never".
	 *
	 * @param array $entry at, by.
	 * @return string
	 */
	private static function when_who( array $entry ): string {
		if ( empty( $entry['at'] ) ) {
			return __( 'Never', 'axle-config-sync' );
		}

		$user = ! empty( $entry['by'] ) ? get_userdata( (int) $entry['by'] ) : false;
		$when = self::when( (int) $entry['at'] );

		/* translators: 1: time ago, 2: user name. */
		return $user ? sprintf( __( '%1$s by %2$s', 'axle-config-sync' ), $when, $user->display_name ) : $when;
	}

	/**
	 * "2 hours ago".
	 *
	 * @param int|false $time Timestamp.
	 * @return string
	 */
	private static function when( $time ): string {
		if ( ! $time ) {
			return '';
		}

		/* translators: %s: time span. */
		return sprintf( __( '%s ago', 'axle-config-sync' ), human_time_diff( (int) $time ) );
	}
}
