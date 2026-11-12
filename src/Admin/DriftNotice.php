<?php
/**
 * The admin notice that says settings drifted.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Admin;

use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Service;
use AryanX\Axle\ConfigSync\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows on every admin screen to people who can apply config, until they
 * dismiss it. A dismissal is per user and lasts until the set of drifted
 * items changes. It reads the stored result of the last check, so it costs
 * one option read.
 */
final class DriftNotice implements Service {

	/**
	 * The drift watch.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Sets up the notice.
	 *
	 * @param Drift $drift The drift watch.
	 */
	public function __construct( Drift $drift ) {
		$this->drift = $drift;
	}

	/**
	 * Hooks the notice and its dismiss link.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_axle_config_sync_drift_dismiss', array( $this, 'dismiss' ) );
	}

	/**
	 * Prints the notice.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}

		$print = $this->drift->count() > 0;

		/**
		 * Filters whether the drift notice is shown.
		 *
		 * @param bool $show Whether drift was found and the notice is not dismissed.
		 */
		$print = (bool) apply_filters( 'axle_config_sync_drift_notice', $print );

		$fingerprint = $this->drift->fingerprint();
		if ( ! $print || '' === $fingerprint || get_user_meta( get_current_user_id(), Drift::SEEN_META, true ) === $fingerprint ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		if ( isset( $_GET['page'], $_GET['tab'] ) && Page::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) && 'drift' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		$count = $this->drift->count();
		$ids   = array_slice( array_keys( $this->drift->state()['items'] ), 0, 3 );
		$more  = $count - count( $ids );
		$list  = implode( ', ', $ids ) . ( $more > 0 ? sprintf( /* translators: %d: number of further items. */ __( ' and %d more', 'axle-config-sync' ), $more ) : '' );

		$dismiss = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'axle_config_sync_drift_dismiss',
					'fp'     => $fingerprint,
				),
				admin_url( 'admin-post.php' )
			),
			'axle_config_sync_drift_dismiss'
		);

		echo '<div class="notice notice-warning axle-drift-notice"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: number of items, 2: item IDs. */
				_n( 'Config Sync: %1$d setting was changed on this site after its file was written (%2$s). The next deploy would overwrite it.', 'Config Sync: %1$d settings were changed on this site after their files were written (%2$s). The next deploy would overwrite them.', $count, 'axle-config-sync' ),
				$count,
				$list
			)
		);
		echo ' <a href="' . esc_url( Page::url( 'drift' ) ) . '">' . esc_html__( 'Review the changes', 'axle-config-sync' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss until something else changes', 'axle-config-sync' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Hides the notice for this user.
	 */
	public function dismiss(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'axle-config-sync' ), 403 );
		}
		check_admin_referer( 'axle_config_sync_drift_dismiss' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$fingerprint = isset( $_GET['fp'] ) ? sanitize_key( wp_unslash( $_GET['fp'] ) ) : '';
		update_user_meta( get_current_user_id(), Drift::SEEN_META, $fingerprint );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}
}
