<?php
/**
 * Personal data export, erase and the privacy policy text.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Change sets and the sync state store the ID of the user who exported or
 * applied. The exporter lists a user's change sets; the eraser sets the user
 * to 0 and keeps the history.
 */
final class Privacy implements Service {

	public const ID   = 'axle-config-sync';
	private const PER = 100;

	/**
	 * Change sets.
	 *
	 * @var Changesets
	 */
	private Changesets $changesets;

	/**
	 * Sync state.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param Changesets $changesets Change sets.
	 * @param State      $state      Sync state.
	 */
	public function __construct( Changesets $changesets, State $state ) {
		$this->changesets = $changesets;
		$this->state      = $state;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters             = (array) $exporters;
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Config Sync change sets', 'axle-config-sync' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers             = (array) $erasers;
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Config Sync change sets', 'axle-config-sync' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports one page of a user's change sets.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();
		$done = true;

		if ( $user ) {
			$rows = $this->changesets->for_user( (int) $user->ID, max( 1, (int) $page ), self::PER );
			$done = count( $rows ) < self::PER;
			foreach ( $rows as $row ) {
				$data[] = array(
					'group_id'    => 'axle-config-sync-changesets',
					'group_label' => __( 'Config Sync change sets', 'axle-config-sync' ),
					'item_id'     => 'axle-config-sync-changeset-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Time (UTC)', 'axle-config-sync' ),
							'value' => (string) $row['created_gmt'],
						),
						array(
							'name'  => __( 'Source', 'axle-config-sync' ),
							'value' => (string) $row['via'],
						),
						array(
							'name'  => __( 'Environment', 'axle-config-sync' ),
							'value' => (string) $row['environment'],
						),
						array(
							'name'  => __( 'Items', 'axle-config-sync' ),
							'value' => (string) $row['items_count'],
						),
						array(
							'name'  => __( 'Status', 'axle-config-sync' ),
							'value' => (string) $row['status'],
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Removes the user from change sets and the sync state, keeping the history.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$changed = 0;
		if ( $user ) {
			$changed = $this->changesets->anonymize_user( (int) $user->ID ) + $this->state->forget_user( (int) $user->ID );
		}

		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => $changed > 0,
			'messages'       => $changed > 0 ? array( __( 'Config Sync change sets were kept without the user, as a record of what changed.', 'axle-config-sync' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = sprintf(
			/* translators: %d: number of days. */
			__( 'When an administrator exports or applies site settings with Axle Config Sync, the site records who did it and when, so the change can be reviewed and undone. These records are deleted after %d days. Settings files may contain the site admin email address; it is not sent anywhere by this plugin.', 'axle-config-sync' ),
			Settings::retention_days()
		);

		wp_add_privacy_policy_content( __( 'Axle Config Sync', 'axle-config-sync' ), '<p>' . esc_html( $text ) . '</p>' );
	}
}
