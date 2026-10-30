<?php
/**
 * Change sets: what each apply wrote and what was there before.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * One row per apply or undo. The payload holds, per item, the value before
 * and the value written (encoded with Codec), so undo can put the before
 * values back. Kept for 30 days or 50 sets, whichever is less.
 */
final class Changesets implements Service {

	/**
	 * Hooks the daily purge.
	 */
	public function register(): void {
		add_action( Schema::CRON_HOOK, array( $this, 'run_purge' ) );
	}

	/**
	 * Stores a change set.
	 *
	 * @param array  $items  Item ID => array{before: mixed, after: mixed, existed: bool}.
	 * @param string $via    admin, cli, ability or rest.
	 * @param int    $undo_of Change set this undoes, or 0.
	 * @param string $status applied, rolled_back or undone.
	 * @return int The ID, or 0 on failure.
	 */
	public function create( array $items, string $via, int $undo_of = 0, string $status = 'applied' ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; cached reads are flushed after writes.
		$ok = $wpdb->insert(
			Schema::table(),
			array(
				'created_gmt' => current_time( 'mysql', true ),
				'user_id'     => get_current_user_id(),
				'via'         => sanitize_key( $via ),
				'environment' => Environment::name(),
				'items_count' => count( $items ),
				'status'      => $status,
				'undo_of'     => $undo_of,
				'payload'     => (string) wp_json_encode( array( 'items' => $items ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		$this->flush();

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates a change set's items and status.
	 *
	 * @param int        $id     Change set ID.
	 * @param array|null $items  New items, or null to keep them.
	 * @param string     $status New status, or empty to keep it.
	 */
	public function update( int $id, ?array $items, string $status = '' ): void {
		global $wpdb;

		$data   = array();
		$format = array();
		if ( null !== $items ) {
			$data['payload']     = (string) wp_json_encode( array( 'items' => $items ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
			$data['items_count'] = count( $items );
			$format[]            = '%s';
			$format[]            = '%d';
		}
		if ( '' !== $status ) {
			$data['status'] = $status;
			$format[]       = '%s';
		}
		if ( $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; cached reads are flushed after writes.
			$wpdb->update( Schema::table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
		}

		$this->flush();
	}

	/**
	 * One change set with its items.
	 *
	 * @param int $id Change set ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$key    = 'changeset_' . $id . '_' . wp_cache_get_last_changed( 'axle_config_sync_changesets' );
		$cached = wp_cache_get( $key, 'axle_config_sync_changesets' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table, cached below.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table(), $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$row = self::shape( $row, true );
		wp_cache_set( $key, $row, 'axle_config_sync_changesets', HOUR_IN_SECONDS );

		return $row;
	}

	/**
	 * Recent change sets, newest first, without their payloads.
	 *
	 * @param int $limit  Rows.
	 * @param int $offset Offset.
	 * @return array[]
	 */
	public function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; small, paged list.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_gmt, user_id, via, environment, items_count, status, undo_of, payload FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', Schema::table(), max( 1, $limit ), max( 0, $offset ) ), ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return self::shape( $row, false );
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * How many change sets exist.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table() ) );
	}

	/**
	 * Change sets of a user, for the privacy exporter.
	 *
	 * @param int $user_id User ID.
	 * @param int $page    Page from 1.
	 * @param int $per     Per page.
	 * @return array[]
	 */
	public function for_user( int $user_id, int $page, int $per ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, only from the privacy tools.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_gmt, user_id, via, environment, items_count, status, undo_of FROM %i WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d', Schema::table(), $user_id, $per, ( max( 1, $page ) - 1 ) * $per ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sets user_id to 0 on a user's change sets, keeping the history.
	 *
	 * @param int $user_id User ID.
	 * @return int Rows changed.
	 */
	public function anonymize_user( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, only from the privacy tools.
		$changed = (int) $wpdb->update( Schema::table(), array( 'user_id' => 0 ), array( 'user_id' => $user_id ), array( '%d' ), array( '%d' ) );
		$this->flush();

		return $changed;
	}

	/**
	 * The daily purge, for the cron event.
	 */
	public function run_purge(): void {
		$this->purge();
	}

	/**
	 * Deletes change sets past the retention days and count.
	 *
	 * @return int Rows deleted.
	 */
	public function purge(): int {
		global $wpdb;

		$table   = Schema::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - Settings::retention_days() * DAY_IN_SECONDS );
		$deleted = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, daily clean-up.
		$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_gmt < %s', $table, $cutoff ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, daily clean-up.
		$keep_from = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, Settings::retention_count() - 1 ) );
		if ( $keep_from ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, daily clean-up.
			$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, (int) $keep_from ) );
		}

		$this->flush();

		return $deleted;
	}

	/**
	 * A row with typed fields.
	 *
	 * @param array $row          Database row.
	 * @param bool  $with_payload Decode the items.
	 * @return array
	 */
	private static function shape( array $row, bool $with_payload ): array {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$items   = is_array( $payload ) && isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();

		$out = array(
			'id'          => (int) $row['id'],
			'created_gmt' => (string) $row['created_gmt'],
			'user_id'     => (int) $row['user_id'],
			'via'         => (string) $row['via'],
			'environment' => (string) $row['environment'],
			'items_count' => (int) $row['items_count'],
			'status'      => (string) $row['status'],
			'undo_of'     => (int) $row['undo_of'],
			'item_ids'    => array_map( 'strval', array_keys( $items ) ),
		);
		if ( $with_payload ) {
			$out['items'] = $items;
		}

		return $out;
	}

	/**
	 * Clears cached change sets.
	 */
	private function flush(): void {
		wp_cache_delete( 'last_changed', 'axle_config_sync_changesets' );
	}
}
