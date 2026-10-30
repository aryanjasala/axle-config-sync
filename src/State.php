<?php
/**
 * Per-item hashes from the last sync, for three-way status.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * After every export and apply, each item gets two hashes: the file side and
 * the site side as they were at that moment. Comparing them with the hashes
 * now tells "changed in files" from "changed on site" and both (a conflict).
 * Stored in `axle_config_sync_state`, not autoloaded.
 */
final class State {

	public const OPTION = 'axle_config_sync_state';

	/**
	 * The whole state.
	 *
	 * @return array{items: array<string, array>, last_export: array, last_apply: array}
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'items'       => isset( $saved['items'] ) && is_array( $saved['items'] ) ? $saved['items'] : array(),
			'last_export' => isset( $saved['last_export'] ) && is_array( $saved['last_export'] ) ? $saved['last_export'] : array(),
			'last_apply'  => isset( $saved['last_apply'] ) && is_array( $saved['last_apply'] ) ? $saved['last_apply'] : array(),
		);
	}

	/**
	 * One item's state.
	 *
	 * @param string $id Item ID.
	 * @return array{file?: string, site?: string, at?: int, by?: int, via?: string}
	 */
	public function item( string $id ): array {
		return $this->all()['items'][ $id ] ?? array();
	}

	/**
	 * Records that items are in sync now.
	 *
	 * @param array<string, array{file: string, site: string}> $hashes Item ID => hashes.
	 * @param string                                           $via    export or apply.
	 * @param int                                              $count  Items in the run, for last export/apply.
	 */
	public function synced( array $hashes, string $via, int $count ): void {
		$state = $this->all();
		$now   = time();
		$user  = get_current_user_id();

		foreach ( $hashes as $id => $pair ) {
			$state['items'][ $id ] = array(
				'file' => (string) $pair['file'],
				'site' => (string) $pair['site'],
				'at'   => $now,
				'by'   => $user,
				'via'  => $via,
			);
		}

		$state[ 'apply' === $via ? 'last_apply' : 'last_export' ] = array(
			'at'    => $now,
			'by'    => $user,
			'count' => $count,
		);

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Forgets the state of items, for tests and uninstall.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Clears user IDs from the state, for the privacy eraser.
	 *
	 * @param int $user_id User ID.
	 * @return int How many entries changed.
	 */
	public function forget_user( int $user_id ): int {
		$state   = $this->all();
		$changed = 0;
		foreach ( $state['items'] as $id => $item ) {
			if ( (int) ( $item['by'] ?? 0 ) === $user_id ) {
				$state['items'][ $id ]['by'] = 0;
				++$changed;
			}
		}
		foreach ( array( 'last_export', 'last_apply' ) as $key ) {
			if ( (int) ( $state[ $key ]['by'] ?? 0 ) === $user_id ) {
				$state[ $key ]['by'] = 0;
				++$changed;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $state, false );
		}

		return $changed;
	}
}
