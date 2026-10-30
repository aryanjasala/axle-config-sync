<?php
/**
 * Tests for status, export, apply and undo.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Marker;
use Brain\Monkey\Filters;

/**
 * Covers Engine with the in-memory provider.
 */
final class EngineTest extends SyncTestCase {

	/**
	 * The provider.
	 *
	 * @var MemoryProvider
	 */
	private MemoryProvider $memory;

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	protected function setUp(): void {
		parent::setUp();
		$this->memory        = new MemoryProvider();
		$this->memory->store = array(
			'title' => 'My site',
			'count' => 10,
		);
		$this->engine        = $this->engine( $this->memory );
	}

	/**
	 * State of one item.
	 *
	 * @param string $id Item ID.
	 * @return string
	 */
	private function state( string $id ): string {
		return (string) $this->engine->row( $id )['state'];
	}

	/**
	 * Writes an item file by hand, as git would.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value JSON value.
	 */
	private function file( string $key, $value ): void {
		$this->put(
			'memory/' . $key . '.json',
			array(
				'schema'   => 1,
				'provider' => 'memory',
				'id'       => 'memory/' . $key,
				'owner'    => 'core',
				'value'    => $value,
			)
		);
		$this->engine->flush();
	}

	public function test_export_writes_one_file_per_item_and_the_manifest(): void {
		$result = $this->engine->export();

		$this->assertSame(
			array(
				'memory/title' => 'memory/title.json',
				'memory/count' => 'memory/count.json',
			),
			$result['written']
		);
		$this->assertSame( 'My site', $this->read( 'memory/title.json' )['value'] );
		$this->assertSame( 10, $this->read( 'memory/count.json' )['value'] );
		$this->assertSame( 'production', $this->read( 'manifest.json' )['environment'] );
		$this->assertFileExists( $this->dir . '/config-sync.json' );
		$this->assertSame( array( array( $result['written'], $this->dir ) ), $this->fired['axle_config_sync_exported'] );
		$this->assertSame( 'in_sync', $this->state( 'memory/title' ) );
	}

	public function test_three_way_status_tells_which_side_moved(): void {
		$this->engine->export();

		$this->memory->store['title'] = 'Changed here';
		$this->assertSame( 'changed_on_site', $this->state( 'memory/title' ) );

		$this->file( 'count', 11 );
		$this->assertSame( 'changed_in_files', $this->state( 'memory/count' ) );

		$this->file( 'title', 'Changed in git' );
		$this->assertSame( 'conflict', $this->state( 'memory/title' ) );

		$this->file( 'new', 'only here' );
		$this->assertSame( 'only_in_files', $this->state( 'memory/new' ) );

		$this->memory->store['extra'] = 1;
		$this->assertSame( 'only_on_site', $this->state( 'memory/extra' ) );

		$rows = $this->engine->rows();
		$this->assertSame( 1, Engine::counts( $rows )['conflict'] );
		$this->assertSame( array( array( array( 'memory/title' ) ) ), $this->fired['axle_config_sync_conflict_detected'] );
		$this->assertSame( array( 'memory/count', 'memory/new', 'memory/title' ), $this->engine->applicable_ids() );
	}

	public function test_without_a_previous_sync_a_difference_counts_as_changed_in_files(): void {
		$this->file( 'title', 'From git' );

		$this->assertSame( 'changed_in_files', $this->state( 'memory/title' ) );
	}

	public function test_apply_writes_records_a_change_set_and_fires_hooks(): void {
		$this->engine->export();
		$this->file( 'title', 'From git' );

		Filters\expectApplied( 'axle_config_sync_apply_value' )->atLeast()->once()->with( 'From git', 'memory/title', 'My site' )->andReturn( 'From git!' );
		$result = $this->engine->apply( array( 'memory/title' ), array( 'via' => 'cli' ) );

		$this->assertSame( array( 'memory/title' ), $result['applied'] );
		$this->assertSame( 'From git!', $this->memory->store['title'] );
		$this->assertSame( 1, $result['changeset'] );

		$changeset = $this->engine->changesets()->get( 1 );
		$this->assertSame( 'cli', $changeset['via'] );
		$this->assertSame( 'My site', $changeset['items']['memory/title']['before'] );
		$this->assertSame( 'From git!', $changeset['items']['memory/title']['after'] );

		$this->assertSame( array( array( 1, array( 'memory/title' ) ) ), $this->fired['axle_config_sync_apply_started'] );
		$this->assertSame( array( array( 'memory/title', 'My site', 'From git!' ) ), $this->fired['axle_config_sync_item_applied'] );
		$this->assertSame( array( array( 1 ) ), $this->fired['axle_config_sync_apply_finished'] );
		$this->assertSame( 1, $this->engine->state()->all()['last_apply']['count'] );
	}

	public function test_a_value_changed_by_a_sanitize_callback_is_applied_with_changes_and_in_sync(): void {
		$this->engine->export();
		$this->file( 'title', 'sanitize me' );

		$result = $this->engine->apply( array( 'memory/title' ) );

		$this->assertSame( array( 'memory/title' ), $result['changed'] );
		$this->assertSame( 'SANITIZE ME', $this->memory->store['title'] );
		$this->assertSame( 'in_sync', $this->state( 'memory/title' ), 'Neither side moved since the apply.' );
	}

	public function test_conflicts_need_force(): void {
		$this->engine->export();
		$this->memory->store['title'] = 'Here';
		$this->file( 'title', 'There' );

		$refused = $this->engine->apply( array( 'memory/title' ) );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'axle_config_sync_conflict', $refused->get_error_code() );
		$this->assertSame( 'Here', $this->memory->store['title'] );

		$this->engine->apply( array( 'memory/title' ), array( 'force' => true ) );
		$this->assertSame( 'There', $this->memory->store['title'] );
	}

	public function test_dangerous_items_need_consent_and_the_filters_decide(): void {
		$this->file( 'title', 'danger' );

		$refused = $this->engine->apply( array( 'memory/title' ) );
		$this->assertSame( 'axle_config_sync_dangerous', $refused->get_error_code() );
		$this->assertSame( 'My site', $this->memory->store['title'] );

		$this->engine->apply( array( 'memory/title' ), array( 'allow_dangerous' => true ) );
		$this->assertSame( 'danger', $this->memory->store['title'] );

		$this->file( 'count', 12 );
		Filters\expectApplied( 'axle_config_sync_dangerous_items' )->atLeast()->once()->andReturn( array( 'memory/count' ) );
		Filters\expectApplied( 'axle_config_sync_is_dangerous' )->atLeast()->once()->with( true, 'memory/count', 10, 12 )->andReturn( false );
		$this->assertSame( '', $this->engine->row( 'memory/count' )['danger'] );
	}

	public function test_a_failure_rolls_back_what_was_written(): void {
		$this->file( 'count', 99 );
		$this->file( 'title', 'boom' );

		$result = $this->engine->apply( array( 'memory/count', 'memory/title' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 10, $this->memory->store['count'], 'The item written before the failure is back.' );
		$this->assertSame( 'rolled_back', $this->engine->changesets()->get( 1 )['status'] );
		$this->assertSame( array( array( 1 ) ), $this->fired['axle_config_sync_rolled_back'] );
		$this->assertSame( 1, $this->fired['axle_config_sync_apply_failed'][0][0] );
	}

	public function test_undo_restores_and_refuses_items_changed_since(): void {
		$this->engine->export();
		$this->file( 'title', 'From git' );
		$this->engine->apply( array( 'memory/title' ) );

		$this->memory->store['title'] = 'Edited after';
		$refused                      = $this->engine->undo( 1 );
		$this->assertSame( 'axle_config_sync_changed_since', $refused->get_error_code() );
		$this->assertSame( array( 'memory/title' ), $refused->get_error_data()['items'] );

		$this->memory->store['title'] = 'From git';
		$result                       = $this->engine->undo( 1, false, 'ability' );
		$this->assertSame( 'My site', $this->memory->store['title'] );
		$this->assertSame( 2, $result['changeset'] );
		$this->assertSame( 'undone', $this->engine->changesets()->get( 1 )['status'] );
		$this->assertSame( 1, $this->engine->changesets()->get( 2 )['undo_of'] );
		$this->assertSame( array( array( 2, 1 ) ), $this->fired['axle_config_sync_undone'] );

		$this->assertSame( 'axle_config_sync_not_applied', $this->engine->undo( 1 )->get_error_code() );
		$this->engine->undo( 2 );
		$this->assertSame( 'From git', $this->memory->store['title'], 'Undoing the undo redoes the apply.' );
	}

	public function test_undo_anyway_with_force(): void {
		$this->file( 'title', 'From git' );
		$this->engine->apply( array( 'memory/title' ) );
		$this->memory->store['title'] = 'Edited after';

		$result = $this->engine->undo( 1, true );

		$this->assertSame( array( 'memory/title' ), $result['forced'] );
		$this->assertSame( 'My site', $this->memory->store['title'] );
	}

	public function test_undo_of_a_created_item_deletes_it(): void {
		$this->file( 'new', 'created' );
		$this->engine->apply( array( 'memory/new' ) );
		$this->assertSame( 'created', $this->memory->store['new'] );

		$this->engine->undo( 1 );
		$this->assertArrayNotHasKey( 'new', $this->memory->store );
	}

	public function test_secrets_are_written_by_name_and_resolve_on_apply(): void {
		$this->memory->store = array( 'acme_api_key' => 'sk_live_abcdefgh' );
		$this->engine->export();
		$this->assertSame( array( '$secret' => 'ACME_API_KEY' ), $this->read( 'memory/acme_api_key.json' )['value'] );
		$this->assertStringNotContainsString( 'sk_live', (string) file_get_contents( $this->dir . '/memory/acme_api_key.json' ) );

		$row = $this->engine->row( 'memory/acme_api_key' );
		$this->assertSame( 'in_sync', $row['state'] );
		$this->assertStringContainsString( 'AXLE_SECRET_ACME_API_KEY', $row['warnings'][0] );

		$this->file( 'acme_api_key', array( '$secret' => 'FROM_CONSTANT' ) );
		$this->engine->apply( array( 'memory/acme_api_key' ) );
		$this->assertSame( 'constant-value', $this->memory->store['acme_api_key'] );
		$this->assertSame( array( '$secret' => 'ACME_API_KEY' ), Engine::public_row( $this->engine->row( 'memory/acme_api_key' ), true )['site_value'], 'Output never shows the value.' );
	}

	public function test_the_export_guard_refuses_plain_secrets_left_by_the_filter(): void {
		Filters\expectApplied( 'axle_config_sync_export_value' )->twice()->andReturnUsing(
			static function ( $value, $id ) {
				return 'memory/title' === $id ? 'x7Gq9LmP2vR8sT4wK1zN6bY3cH5jD0fQ' : $value;
			}
		);

		$result = $this->engine->export();

		$this->assertArrayHasKey( 'memory/title', $result['refused'] );
		$this->assertFileDoesNotExist( $this->dir . '/memory/title.json' );
		$this->assertSame( array( array( 'file' => 'memory/x.json', 'pointer' => '/' ) ), $this->scan_after_planting() );
	}

	/**
	 * Plants a secret by hand and scans.
	 *
	 * @return array
	 */
	private function scan_after_planting(): array {
		$this->file( 'x', 'x7Gq9LmP2vR8sT4wK1zN6bY3cH5jD0fQ' );
		return array_values(
			array_filter(
				$this->engine->scan_secrets(),
				static function ( array $hit ): bool {
					return 'memory/x.json' === $hit['file'];
				}
			)
		);
	}

	public function test_blocked_items_are_skipped_with_the_reason(): void {
		$this->file( 'count', array( '$secret' => 'NOT_SET_ANYWHERE' ) );
		$this->file( 'gone', array( '$secret' => 'NOT_SET_ANYWHERE' ) );

		$result = $this->engine->apply( array( 'memory/gone' ) );

		$this->assertSame( array(), $result['applied'] );
		$this->assertArrayHasKey( 'memory/gone', $result['skipped'] );
		$this->assertSame( 'memory/gone', $this->fired['axle_config_sync_item_skipped'][0][0] );
	}

	public function test_the_apply_order_filter_sorts_items(): void {
		Filters\expectApplied( 'axle_config_sync_apply_order' )->atLeast()->once()->andReturn( array( 'memory' ) );

		$this->assertSame( array( 'memory/a', 'memory/b' ), $this->engine->registry()->sort( array( 'memory/b', 'memory/a' ) ) );
	}

	public function test_short_ids_and_dry_runs(): void {
		$this->file( 'title', 'From git' );

		$result = $this->engine->apply( array( 'memory/title' ), array( 'dry_run' => true ) );

		$this->assertSame( 'apply', $result['preview']['memory/title']['action'] );
		$this->assertSame( 'My site', $this->memory->store['title'] );
		$this->assertSame( 'options/blogname', $this->engine->normalize_id( 'blogname' ) );
		$this->assertSame( 'memory/title', $this->engine->normalize_id( 'memory/title.json' ) );
	}

	public function test_a_broken_file_blocks_the_item(): void {
		$this->put( 'memory/title.json', '{not json' );

		$row = $this->engine->row( 'memory/title' );
		$this->assertSame( 'blocked', $row['state'] );
		$this->assertSame( 'The file is not valid JSON for this plugin.', $row['problems'][0] );
	}

	public function test_chunks_continue_one_change_set(): void {
		$this->file( 'title', 'A' );
		$this->file( 'count', 2 );

		$first  = $this->engine->apply( array( 'memory/title' ) );
		$second = $this->engine->apply( array( 'memory/count' ), array( 'changeset' => $first['changeset'] ) );

		$this->assertSame( $first['changeset'], $second['changeset'] );
		$this->assertSame( array( 'memory/title', 'memory/count' ), array_keys( $this->engine->changesets()->get( 1 )['items'] ) );
	}

	public function test_secret_markers_in_output_carry_no_fingerprint(): void {
		$this->assertSame( array( '$secret' => 'X' ), Engine::safe_encode( Marker::secret( 'X', 'hash' ) ) );
	}
}
