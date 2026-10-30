<?php
/**
 * Status, export, preview, apply and undo.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

use AryanX\Axle\ConfigSync\Providers\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the screen, the REST routes, the abilities and WP-CLI go
 * through.
 *
 * Values move through two pipelines:
 *
 * - Site to file: provider reads the live value, IDs become references,
 *   site URLs become placeholders, secrets become `{"$secret":"NAME"}`.
 * - File to site: the environment file's values go in, secrets resolve,
 *   placeholders expand, references resolve to IDs on this site.
 *
 * Status compares both sides in their portable form and against the hashes
 * from the last sync, so it can say which side moved.
 */
final class Engine {

	public const STATES = array( 'in_sync', 'changed_in_files', 'changed_on_site', 'conflict', 'only_in_files', 'only_on_site', 'blocked' );

	/**
	 * States an apply writes.
	 */
	public const APPLY_STATES = array( 'changed_in_files', 'changed_on_site', 'conflict', 'only_in_files' );

	/**
	 * The config directory.
	 *
	 * @var Directory
	 */
	private Directory $directory;

	/**
	 * Tracking rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Hashes from the last sync.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Change sets.
	 *
	 * @var Changesets
	 */
	private Changesets $changesets;

	/**
	 * Providers.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Portable references.
	 *
	 * @var References
	 */
	private References $refs;

	/**
	 * Secret handling.
	 *
	 * @var Secrets
	 */
	private Secrets $secrets;

	/**
	 * Environment values.
	 *
	 * @var Environment
	 */
	private Environment $env;

	/**
	 * The manifest file.
	 *
	 * @var Manifest
	 */
	private Manifest $manifest;

	/**
	 * Parsed files, per request.
	 *
	 * @var array<string, array|null>
	 */
	private array $files = array();

	/**
	 * Sets up the engine.
	 *
	 * @param Directory  $directory  The config directory.
	 * @param Rules      $rules      Tracking rules.
	 * @param State      $state      Hashes from the last sync.
	 * @param Changesets $changesets Change sets.
	 * @param Registry   $registry   Providers.
	 */
	public function __construct( Directory $directory, Rules $rules, State $state, Changesets $changesets, Registry $registry ) {
		$this->directory  = $directory;
		$this->rules      = $rules;
		$this->state      = $state;
		$this->changesets = $changesets;
		$this->registry   = $registry;
		$this->refs       = new References();
		$this->secrets    = new Secrets( $rules );
		$this->env        = new Environment( $directory );
		$this->manifest   = new Manifest( $directory );
	}

	/**
	 * The config directory.
	 *
	 * @return Directory
	 */
	public function directory(): Directory {
		return $this->directory;
	}

	/**
	 * Tracking rules.
	 *
	 * @return Rules
	 */
	public function rules(): Rules {
		return $this->rules;
	}

	/**
	 * Hashes and last export/apply.
	 *
	 * @return State
	 */
	public function state(): State {
		return $this->state;
	}

	/**
	 * Change sets.
	 *
	 * @return Changesets
	 */
	public function changesets(): Changesets {
		return $this->changesets;
	}

	/**
	 * Providers.
	 *
	 * @return Registry
	 */
	public function registry(): Registry {
		return $this->registry;
	}

	/**
	 * Secret handling.
	 *
	 * @return Secrets
	 */
	public function secrets(): Secrets {
		return $this->secrets;
	}

	/**
	 * A state for people.
	 *
	 * @param string $state State key.
	 * @return string
	 */
	public static function state_label( string $state ): string {
		$labels = array(
			'in_sync'          => __( 'In sync', 'axle-config-sync' ),
			'changed_in_files' => __( 'Changed in files', 'axle-config-sync' ),
			'changed_on_site'  => __( 'Changed on site', 'axle-config-sync' ),
			'conflict'         => __( 'Conflict', 'axle-config-sync' ),
			'only_in_files'    => __( 'Only in files', 'axle-config-sync' ),
			'only_on_site'     => __( 'Only on site', 'axle-config-sync' ),
			'blocked'          => __( 'Blocked', 'axle-config-sync' ),
		);

		return $labels[ $state ] ?? $state;
	}

	/**
	 * Whether Axle Read-Only Mode has frozen the site.
	 *
	 * @return bool
	 */
	public function frozen(): bool {
		$class = '\AryanX\Axle\ReadOnlyMode\Plugin';
		if ( ! class_exists( $class ) ) {
			return false;
		}

		$plugin = $class::instance();

		return $plugin && method_exists( $plugin, 'state' ) && $plugin->state()->is_frozen();
	}

	/**
	 * Forgets parsed files and environment values read in this request.
	 */
	public function flush(): void {
		$this->files = array();
		$this->env->flush();
		$this->refs->flush();
	}

	/**
	 * Item IDs in files, mapped to their file.
	 *
	 * @return array<string, string> Item ID => relative path.
	 */
	public function file_ids(): array {
		$out = array();
		foreach ( $this->directory->files() as $relative ) {
			$id = $this->registry->id_for_file( $relative );
			if ( null !== $id && $this->allowed( $id ) ) {
				$out[ $id ] = $relative;
			}
		}

		return $out;
	}

	/**
	 * Every item: on the site, in the files, or both.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		$ids = array_keys( $this->file_ids() );
		foreach ( $this->registry->enabled() as $provider ) {
			$ids = array_merge( $ids, $provider->items() );
		}

		$ids = array_values( array_unique( array_filter( $ids, array( $this, 'allowed' ) ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Accepts `blogname` as short for `options/blogname`.
	 *
	 * @param string $id Item ID or option name.
	 * @return string
	 */
	public function normalize_id( string $id ): string {
		$id = trim( $id );
		if ( '.json' === substr( $id, -5 ) ) {
			$id = substr( $id, 0, -5 );
		}

		return $this->registry->for_item( $id ) ? $id : 'options/' . $id;
	}

	/**
	 * The parsed file of an item: its value (decoded) and owner, an error, or
	 * null when there is no file.
	 *
	 * @param string $id Item ID.
	 * @return array{value: mixed, owner: string, error: string}|null
	 */
	public function read_file( string $id ): ?array {
		if ( array_key_exists( $id, $this->files ) ) {
			return $this->files[ $id ];
		}

		$provider = $this->registry->for_item( $id );
		$text     = $provider ? $this->directory->read( $provider->file( $id ) ) : null;
		if ( null === $text ) {
			$this->files[ $id ] = null;
			return null;
		}

		$data = Codec::from_json( $text );
		if ( ! is_array( $data ) || ! array_key_exists( 'value', $data ) || ( isset( $data['id'] ) && $data['id'] !== $id ) ) {
			$this->files[ $id ] = array(
				'value' => null,
				'owner' => '',
				'error' => Messages::get( 'bad_file' ),
			);
			return $this->files[ $id ];
		}

		$this->files[ $id ] = array(
			'value' => Codec::decode( $data['value'] ),
			'owner' => is_string( $data['owner'] ?? null ) ? $data['owner'] : '',
			'error' => '',
		);

		return $this->files[ $id ];
	}

	/**
	 * A live value in its portable form: references, placeholders, secrets masked.
	 *
	 * @param Provider $provider Provider.
	 * @param string   $id       Item ID.
	 * @param mixed    $live     Live value.
	 * @return mixed
	 * @throws UnsupportedValue When the value holds something a file cannot.
	 */
	public function portable( Provider $provider, string $id, $live ) {
		Codec::encode( $live );

		$value = $provider->references( $id, $live, $this->refs );
		$value = Placeholders::compact( $value );

		return $this->secrets->mask( $id, $value );
	}

	/**
	 * Everything about one item: its state, both values, what would be
	 * written, problems, warnings and danger.
	 *
	 * @param string $id   Item ID.
	 * @param array  $args Apply options: inactive_theme, batch (item IDs applied together).
	 * @return array|null Null when no provider owns the item.
	 */
	public function row( string $id, array $args = array() ): ?array {
		$provider = $this->registry->for_item( $id );
		if ( ! $provider || ! $this->allowed( $id ) ) {
			return null;
		}

		$entry    = $this->read_file( $id );
		$has_file = null !== $entry;
		$on_site  = $provider->exists( $id );
		if ( ! $has_file && ! $on_site ) {
			return null;
		}
		$live     = $on_site ? $provider->read( $id ) : null;
		$problems = array();
		$warnings = array();
		$site     = null;
		$file     = null;
		$hashes   = array(
			'file' => '',
			'site' => '',
		);

		if ( $has_file && '' !== $entry['error'] ) {
			$problems[] = $entry['error'];
			$has_file   = false;
		}

		if ( $on_site ) {
			try {
				$site           = $this->portable( $provider, $id, $live );
				$hashes['site'] = Codec::hash( $site );
			} catch ( UnsupportedValue $e ) {
				$problems[] = $e->getMessage();
			}
		}

		if ( $has_file ) {
			$file           = $this->env->apply( $id, $entry['value'] );
			$hashes['file'] = Codec::hash( Secrets::fingerprint_all( $file, $live, $on_site ) );
			foreach ( Secrets::unresolved( $file ) as $name ) {
				$warnings[] = Messages::get( 'secret_kept', $name, 'AXLE_SECRET_' . $name );
			}
		}

		$state = $this->three_way( $id, $has_file, $on_site, $hashes );

		$resolved = null;
		$danger   = '';
		$summary  = '';
		if ( $has_file && in_array( $state, self::APPLY_STATES, true ) ) {
			$found    = array();
			$resolved = $this->resolve( $provider, $id, $file, $live, $found, $on_site );
			foreach ( $found as $problem ) {
				$pending = (string) ( $problem['pending'] ?? '' );
				if ( ! empty( $problem['block'] ) && ! ( '' !== $pending && in_array( $pending, (array) ( $args['batch'] ?? array() ), true ) ) ) {
					$problems[] = (string) $problem['message'];
				} else {
					$warnings[] = (string) $problem['message'];
				}
			}

			$gate = $provider->gate( $id, $args );
			if ( '' !== $gate ) {
				$problems[] = $gate;
			}

			$danger  = Danger::reason( $provider, $id, $live, $resolved );
			$summary = $provider->summary( $id, $site, $file );

			$version = $this->manifest->warning( $entry['owner'] );
			if ( '' !== $version ) {
				$warnings[] = $version;
			}
			if ( ! isset( $this->env->overrides()[ $id ] ) && self::has_email( $resolved ) ) {
				$warnings[] = Messages::get( 'email_value' );
			}
		}

		return array(
			'id'         => $id,
			'provider'   => $provider->name(),
			'label'      => $provider->label( $id ),
			'file'       => $provider->file( $id ),
			'owner'      => $has_file && '' !== $entry['owner'] ? $entry['owner'] : $provider->owner( $id ),
			'has_file'   => $has_file,
			'on_site'    => $on_site,
			'state'      => $problems ? 'blocked' : $state,
			'base_state' => $state,
			'danger'     => $danger,
			'problems'   => array_values( array_unique( $problems ) ),
			'warnings'   => array_values( array_unique( $warnings ) ),
			'summary'    => $summary,
			'file_value' => $file,
			'site_value' => $site,
			'live'       => $live,
			'resolved'   => $resolved,
			'hashes'     => $hashes,
		);
	}

	/**
	 * Rows for every item, filtered.
	 *
	 * @param array $filters provider, state, search.
	 * @return array[]
	 */
	public function rows( array $filters = array() ): array {
		$rows      = array();
		$conflicts = array();
		foreach ( $this->ids() as $id ) {
			$row = $this->row( $id );
			if ( ! $row ) {
				continue;
			}
			if ( 'conflict' === $row['base_state'] ) {
				$conflicts[] = $id;
			}
			if ( ! empty( $filters['provider'] ) && $row['provider'] !== $filters['provider'] ) {
				continue;
			}
			if ( ! empty( $filters['state'] ) && $row['state'] !== $filters['state'] ) {
				continue;
			}
			if ( ! empty( $filters['search'] ) && false === stripos( $row['id'] . ' ' . $row['label'], (string) $filters['search'] ) ) {
				continue;
			}
			$rows[] = $row;
		}

		if ( $conflicts ) {
			/**
			 * Fires when a status run finds items that changed on both sides.
			 *
			 * @param string[] $items Item IDs.
			 */
			do_action( 'axle_config_sync_conflict_detected', $conflicts );
		}

		return $rows;
	}

	/**
	 * Counts per state.
	 *
	 * @param array[] $rows Rows.
	 * @return array<string, int>
	 */
	public static function counts( array $rows ): array {
		$counts = array_fill_keys( self::STATES, 0 );
		foreach ( $rows as $row ) {
			++$counts[ $row['state'] ];
		}

		return $counts;
	}

	/**
	 * A row for output: values encoded as in a file (secrets as their name
	 * only), internal fields left out.
	 *
	 * @param array $row         Row.
	 * @param bool  $with_values Include both values.
	 * @return array
	 */
	public static function public_row( array $row, bool $with_values = false ): array {
		$out = array(
			'id'       => $row['id'],
			'provider' => $row['provider'],
			'label'    => $row['label'],
			'file'     => $row['file'],
			'owner'    => $row['owner'],
			'state'    => $row['state'],
			'danger'   => $row['danger'],
			'problems' => $row['problems'],
			'warnings' => $row['warnings'],
			'summary'  => $row['summary'],
		);

		if ( $with_values ) {
			$out['file_value'] = self::safe_encode( $row['file_value'] );
			$out['site_value'] = self::safe_encode( $row['site_value'] );
		}

		return $out;
	}

	/**
	 * Encodes a portable value for output. Never fails.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function safe_encode( $value ) {
		try {
			return Codec::encode( $value );
		} catch ( UnsupportedValue $e ) {
			return null;
		}
	}

	/**
	 * Writes the live values of items to files.
	 *
	 * @param string[]|null $ids Item IDs, or null for every item on the site.
	 * @param string        $dir Another folder, or empty for the config directory.
	 * @return array{written: array<string, string>, refused: array<string, string>, directory: string}|\WP_Error
	 */
	public function export( ?array $ids = null, string $dir = '' ) {
		if ( '' !== $dir ) {
			$this->directory->use_path( $dir );
		}

		try {
			return $this->export_to_directory( $ids, '' === $dir );
		} finally {
			$this->directory->use_path( '' );
			$this->files = array();
			$this->env->flush();
		}
	}

	/**
	 * Builds a zip of exported files in a temporary folder.
	 *
	 * @param string[]|null $ids Item IDs, or null for all.
	 * @return string|\WP_Error Path to the zip.
	 */
	public function export_zip( ?array $ids = null ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'axle_config_sync_no_zip', __( 'This server has no ZipArchive, so a zip cannot be built.', 'axle-config-sync' ) );
		}

		$folder = trailingslashit( get_temp_dir() ) . 'axle-config-sync-' . wp_generate_password( 12, false );
		$result = $this->export( $ids, $folder );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$zip_file = $folder . '.zip';
		$zip      = new \ZipArchive();
		if ( true !== $zip->open( $zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'axle_config_sync_zip', __( 'The zip could not be created.', 'axle-config-sync' ) );
		}

		$this->directory->use_path( $folder );
		foreach ( $this->directory->files() as $relative ) {
			$zip->addFile( $folder . '/' . $relative, 'axle-config/' . $relative );
		}
		$zip->close();
		foreach ( $this->directory->files() as $relative ) {
			$this->directory->delete( $relative );
		}
		$this->directory->use_path( '' );

		return $zip_file;
	}

	/**
	 * What applying the items would do.
	 *
	 * @param string[] $ids  Item IDs.
	 * @param array    $args Apply options.
	 * @return array[] Rows with an `action`: apply, skip or blocked.
	 */
	public function preview( array $ids, array $args = array() ): array {
		$ids           = array_values( array_unique( array_map( array( $this, 'normalize_id' ), $ids ) ) );
		$args['batch'] = $ids;
		$rows          = array();
		foreach ( $this->registry->sort( $ids ) as $id ) {
			$row = $this->row( $id, $args );
			if ( ! $row ) {
				continue;
			}
			if ( 'blocked' === $row['state'] ) {
				$row['action'] = 'blocked';
			} elseif ( in_array( $row['state'], self::APPLY_STATES, true ) ) {
				$row['action'] = 'apply';
			} else {
				$row['action'] = 'skip';
			}
			$rows[ $id ] = $row;
		}

		return $rows;
	}

	/**
	 * Every item that differs and can be applied: the --all of apply.
	 *
	 * @return string[]
	 */
	public function applicable_ids(): array {
		$ids = array();
		foreach ( $this->rows() as $row ) {
			if ( in_array( $row['base_state'], self::APPLY_STATES, true ) ) {
				$ids[] = $row['id'];
			}
		}

		return $ids;
	}

	/**
	 * Applies file values to the site, with a change set for undo.
	 *
	 * @param string[] $ids  Item IDs.
	 * @param array    $args allow_dangerous, force, via (admin, cli, ability, rest), inactive_theme, dry_run, changeset (continue one, for chunks).
	 * @return array|\WP_Error
	 */
	public function apply( array $ids, array $args = array() ) {
		$args = array_merge(
			array(
				'allow_dangerous' => false,
				'force'           => false,
				'via'             => 'admin',
				'inactive_theme'  => false,
				'dry_run'         => false,
				'changeset'       => 0,
			),
			$args
		);

		if ( $this->frozen() ) {
			return new \WP_Error( 'axle_config_sync_frozen', Messages::get( 'frozen' ), array( 'status' => 423 ) );
		}

		$rows      = $this->preview( $ids, $args );
		$conflicts = array();
		$dangerous = array();
		$skipped   = array();
		$to_apply  = array();
		foreach ( $rows as $id => $row ) {
			if ( 'blocked' === $row['action'] ) {
				$skipped[ $id ] = implode( ' ', $row['problems'] );
				continue;
			}
			if ( 'apply' !== $row['action'] ) {
				continue;
			}
			$to_apply[] = $id;
			if ( 'conflict' === $row['base_state'] ) {
				$conflicts[] = $id;
			}
			if ( '' !== $row['danger'] ) {
				$dangerous[] = $id;
			}
		}

		if ( $conflicts && ! $args['force'] ) {
			return new \WP_Error(
				'axle_config_sync_conflict',
				Messages::get( 'conflict', implode( ', ', $conflicts ) ),
				array(
					'status' => 409,
					'items'  => $conflicts,
				)
			);
		}

		if ( $dangerous && ! $args['allow_dangerous'] ) {
			return new \WP_Error(
				'axle_config_sync_dangerous',
				Messages::get( 'dangerous', implode( ', ', $dangerous ) ),
				array(
					'status' => 403,
					'items'  => $dangerous,
				)
			);
		}

		foreach ( $skipped as $id => $reason ) {
			/**
			 * Fires for each chosen item that was not applied.
			 *
			 * @param string $id     Item ID.
			 * @param string $reason Why.
			 */
			do_action( 'axle_config_sync_item_skipped', $id, $reason );
		}

		$result = array(
			'changeset' => 0,
			'applied'   => array(),
			'changed'   => array(),
			'skipped'   => $skipped,
			'preview'   => $rows,
		);

		if ( $args['dry_run'] || ! $to_apply ) {
			return $result;
		}

		return $this->write_items( $rows, $to_apply, $args, $result );
	}

	/**
	 * Restores the before values of a change set.
	 *
	 * @param int    $changeset_id Change set ID.
	 * @param bool   $force        Undo items that changed since.
	 * @param string $via          admin, cli, ability or rest.
	 * @return array|\WP_Error
	 */
	public function undo( int $changeset_id, bool $force = false, string $via = 'admin' ) {
		if ( $this->frozen() ) {
			return new \WP_Error( 'axle_config_sync_frozen', Messages::get( 'frozen' ), array( 'status' => 423 ) );
		}

		$changeset = $this->changesets->get( $changeset_id );
		if ( ! $changeset ) {
			return new \WP_Error( 'axle_config_sync_not_found', __( 'There is no such change set.', 'axle-config-sync' ), array( 'status' => 404 ) );
		}
		if ( 'applied' !== $changeset['status'] ) {
			return new \WP_Error( 'axle_config_sync_not_applied', __( 'This change set was already undone or rolled back.', 'axle-config-sync' ), array( 'status' => 409 ) );
		}

		$plan    = array();
		$changed = array();
		foreach ( $changeset['items'] as $id => $entry ) {
			$provider = $this->registry->for_item( (string) $id );
			if ( ! $provider ) {
				continue;
			}
			$live  = $provider->exists( (string) $id ) ? $provider->read( (string) $id ) : null;
			$after = null === ( $entry['after'] ?? null ) ? null : Codec::decode( $entry['after'] );
			if ( ! Codec::same( $live, $after ) ) {
				$changed[] = (string) $id;
			}
			$plan[ (string) $id ] = array(
				'provider' => $provider,
				'live'     => $live,
				'target'   => empty( $entry['existed'] ) ? null : Codec::decode( $entry['before'] ),
			);
		}

		if ( $changed && ! $force ) {
			return new \WP_Error(
				'axle_config_sync_changed_since',
				Messages::get( 'changed_since', implode( ', ', $changed ) ),
				array(
					'status' => 409,
					'items'  => $changed,
				)
			);
		}

		$items = array();
		foreach ( $plan as $id => $step ) {
			$items[ $id ] = array(
				'before'  => self::safe_encode( $step['live'] ),
				'after'   => self::safe_encode( $step['target'] ),
				'existed' => null !== $step['live'],
			);
		}

		$new_id = $this->changesets->create( $items, $via, $changeset_id );

		// Undo in reverse apply order, so widgets let go of menus before menus go.
		foreach ( array_reverse( $this->registry->sort( array_keys( $plan ) ) ) as $id ) {
			try {
				$plan[ $id ]['provider']->write( $id, $plan[ $id ]['target'] );
			} catch ( \Throwable $e ) {
				$this->changesets->update( $new_id, null, 'rolled_back' );
				return new \WP_Error( 'axle_config_sync_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
		}

		$this->changesets->update( $changeset_id, null, 'undone' );
		$this->files = array();

		/**
		 * Fires after a change set was undone.
		 *
		 * @param int $changeset_id The new change set that did the undo.
		 * @param int $undone       The change set that was undone.
		 */
		do_action( 'axle_config_sync_undone', $new_id, $changeset_id );

		return array(
			'changeset' => $new_id,
			'undone'    => $changeset_id,
			'items'     => array_keys( $plan ),
			'forced'    => $changed,
		);
	}

	/**
	 * Secrets in plain text in the files, for `secrets check` and Site Health.
	 *
	 * @param string $dir Another folder, or empty for the config directory.
	 * @return array<int, array{file: string, pointer: string}>
	 */
	public function scan_secrets( string $dir = '' ): array {
		if ( '' !== $dir ) {
			$this->directory->use_path( $dir );
		}

		$found = array();
		try {
			foreach ( $this->directory->files() as $relative ) {
				$data = $this->directory->read_json( $relative );
				if ( ! is_array( $data ) ) {
					continue;
				}
				if ( 0 === strpos( $relative, 'env/' ) ) {
					foreach ( (array) ( $data['values'] ?? array() ) as $id => $pointers ) {
						foreach ( (array) $pointers as $pointer => $value ) {
							foreach ( $this->secrets->find( (string) $id, Codec::decode( $value ) ) as $at ) {
								$found[] = array(
									'file'    => $relative,
									'pointer' => (string) $id . ( '/' === $at ? '' : $at ) . ( '' !== (string) $pointer ? '#' . $pointer : '' ),
								);
							}
						}
					}
					continue;
				}
				$id = $this->registry->id_for_file( $relative );
				if ( null === $id || ! array_key_exists( 'value', $data ) ) {
					continue;
				}
				foreach ( $this->secrets->find( $id, Codec::decode( $data['value'] ) ) as $pointer ) {
					$found[] = array(
						'file'    => $relative,
						'pointer' => $pointer,
					);
				}
			}
		} finally {
			$this->directory->use_path( '' );
		}

		return $found;
	}

	/**
	 * Export into the current directory.
	 *
	 * @param string[]|null $ids          Item IDs, or null for all on the site.
	 * @param bool          $update_state Record the sync (the real config directory only).
	 * @return array|\WP_Error
	 */
	private function export_to_directory( ?array $ids, bool $update_state ) {
		if ( ! $this->directory->ensure() ) {
			return new \WP_Error( 'axle_config_sync_not_writable', Messages::get( 'not_writable', $this->directory->path() ), array( 'status' => 500 ) );
		}

		if ( null === $ids ) {
			$ids = array();
			foreach ( $this->registry->enabled() as $provider ) {
				$ids = array_merge( $ids, $provider->items() );
			}
		}
		$ids = array_values( array_unique( array_map( array( $this, 'normalize_id' ), $ids ) ) );

		$written = array();
		$refused = array();
		$owners  = array();
		foreach ( $ids as $id ) {
			$provider = $this->registry->for_item( $id );
			if ( ! $provider || ! $this->allowed( $id ) ) {
				$refused[ $id ] = Messages::get( 'never_sync' );
				continue;
			}
			if ( ! $provider->exists( $id ) ) {
				$refused[ $id ] = __( 'Not on this site.', 'axle-config-sync' );
				continue;
			}

			$live = $provider->read( $id );
			try {
				$value = $this->portable( $provider, $id, $live );
			} catch ( UnsupportedValue $e ) {
				$refused[ $id ] = $e->getMessage();
				continue;
			}

			$existing = $this->read_file( $id );
			$value    = $this->env->protect( $id, $value, $existing['value'] ?? null, null !== $existing && '' === $existing['error'] );

			/**
			 * Filters an item's value just before it is written to its file.
			 *
			 * The value is in its portable form: references, placeholders and
			 * secret markers. A secret left in plain text stops the write.
			 *
			 * @param mixed  $value Value.
			 * @param string $id    Item ID.
			 * @param mixed  $live  The live value it came from.
			 */
			$value = apply_filters( 'axle_config_sync_export_value', $value, $id, $live );

			$leftovers = $this->secrets->find( $id, $value );
			if ( $leftovers ) {
				$refused[ $id ] = Messages::get( 'secret_in_file', implode( ', ', $leftovers ) );
				continue;
			}

			$owner = $provider->owner( $id );
			$data  = array(
				'schema'   => Codec::SCHEMA,
				'provider' => $provider->name(),
				'id'       => $id,
				'owner'    => $owner,
				'value'    => Codec::encode( $value ),
			);

			if ( ! $this->directory->write( $provider->file( $id ), Codec::to_json( $data ) ) ) {
				$refused[ $id ] = Messages::get( 'not_writable', $this->directory->path() );
				continue;
			}

			$written[ $id ] = $provider->file( $id );
			$owners[]       = $owner;
			unset( $this->files[ $id ] );
		}

		$this->write_manifest( $owners );
		if ( null === $this->directory->read( Rules::FILE ) ) {
			$this->rules->write_file();
		}

		if ( $update_state && $written ) {
			$hashes = array();
			foreach ( array_keys( $written ) as $id ) {
				$row = $this->row( $id );
				if ( $row ) {
					$hashes[ $id ] = $row['hashes'];
				}
			}
			$this->state->synced( $hashes, 'export', count( $written ) );
		}

		/**
		 * Fires after an export wrote files.
		 *
		 * @param array<string, string> $items     Item ID => file written.
		 * @param string                $directory The folder.
		 */
		do_action( 'axle_config_sync_exported', $written, $this->directory->path() );

		return array(
			'written'   => $written,
			'refused'   => $refused,
			'directory' => $this->directory->path(),
		);
	}

	/**
	 * Writes manifest.json, keeping plugin versions of items not in this export.
	 *
	 * @param string[] $owners Owners of the items just written.
	 */
	private function write_manifest( array $owners ): void {
		$manifest = Manifest::build( $owners );
		$old      = $this->manifest->read();
		$plugins  = array_merge( (array) ( $old['plugins'] ?? array() ), (array) $manifest['plugins'] );
		ksort( $plugins );
		$manifest['plugins'] = $plugins ? $plugins : new \stdClass();

		$this->directory->write( Manifest::FILE, Codec::to_json( $manifest ) );
	}

	/**
	 * File value to the live form: environment values are already in;
	 * secrets resolve, placeholders expand, references resolve.
	 *
	 * @param Provider $provider Provider.
	 * @param string   $id       Item ID.
	 * @param mixed    $value    File value with environment values applied.
	 * @param mixed    $live     Live value.
	 * @param array    $problems Collects problems.
	 * @param bool     $on_site  Whether the item exists on the site.
	 * @return mixed
	 */
	private function resolve( Provider $provider, string $id, $value, $live, array &$problems, bool $on_site ) {
		$value = $this->secrets->resolve_all( $id, $value, $live, $problems, $on_site );
		$value = Placeholders::expand( $value );
		$value = $provider->resolve( $id, $value, $this->refs, $problems );

		/**
		 * Filters a value just before it is written to the site.
		 *
		 * @param mixed  $value Value with real IDs and URLs.
		 * @param string $id    Item ID.
		 * @param mixed  $live  The value on the site now.
		 */
		return apply_filters( 'axle_config_sync_apply_value', $value, $id, $live );
	}

	/**
	 * The three-way state.
	 *
	 * @param string $id       Item ID.
	 * @param bool   $has_file Whether there is a file.
	 * @param bool   $on_site  Whether it exists on the site.
	 * @param array  $hashes   file and site hashes now.
	 * @return string
	 */
	private function three_way( string $id, bool $has_file, bool $on_site, array $hashes ): string {
		if ( ! $has_file ) {
			return 'only_on_site';
		}
		if ( ! $on_site ) {
			return 'only_in_files';
		}
		if ( '' !== $hashes['site'] && $hashes['file'] === $hashes['site'] ) {
			return 'in_sync';
		}

		$base = $this->state->item( $id );
		if ( ! isset( $base['file'], $base['site'] ) ) {
			return 'changed_in_files';
		}

		$file_moved = $hashes['file'] !== $base['file'];
		$site_moved = $hashes['site'] !== $base['site'];

		if ( $file_moved && $site_moved ) {
			return 'conflict';
		}
		if ( $site_moved ) {
			return 'changed_on_site';
		}
		if ( $file_moved ) {
			return 'changed_in_files';
		}

		// Neither moved since the last apply: a sanitize callback changed the value on the way in.
		return 'in_sync';
	}

	/**
	 * Writes the chosen items with a change set, rolling back on failure.
	 *
	 * @param array[]  $rows     Preview rows.
	 * @param string[] $to_apply Item IDs to write, in order.
	 * @param array    $args     Apply options.
	 * @param array    $result   Result so far.
	 * @return array|\WP_Error
	 */
	private function write_items( array $rows, array $to_apply, array $args, array $result ) {
		$items = array();
		foreach ( $to_apply as $id ) {
			$items[ $id ] = array(
				'before'  => self::safe_encode( $rows[ $id ]['live'] ),
				'after'   => self::safe_encode( $rows[ $id ]['resolved'] ),
				'existed' => (bool) $rows[ $id ]['on_site'],
			);
		}

		$changeset_id = 0;
		$continued    = (int) $args['changeset'] > 0 ? $this->changesets->get( (int) $args['changeset'] ) : null;
		if ( $continued && 'applied' === $continued['status'] && get_current_user_id() === (int) $continued['user_id'] && strtotime( $continued['created_gmt'] . ' UTC' ) > time() - HOUR_IN_SECONDS ) {
			$changeset_id = (int) $continued['id'];
			$items        = array_merge( (array) $continued['items'], $items );
			$this->changesets->update( $changeset_id, $items );
		} else {
			$changeset_id = $this->changesets->create( $items, (string) $args['via'] );
		}

		/**
		 * Fires before an apply writes anything. Activity Log with Undo uses it
		 * to group the writes into one change set.
		 *
		 * @param int      $changeset_id Change set ID.
		 * @param string[] $items        Item IDs, in apply order.
		 */
		do_action( 'axle_config_sync_apply_started', $changeset_id, $to_apply );

		$written = array();
		$hashes  = array();
		try {
			foreach ( $to_apply as $id ) {
				// Resolve again: an item applied earlier (a menu) may be what this one points at.
				$this->refs->flush();
				$row = $this->row( $id, $args );
				if ( $row && ! in_array( $row['state'], self::APPLY_STATES, true ) && 'blocked' !== $row['state'] ) {
					unset( $items[ $id ] );
					continue;
				}
				if ( ! $row || 'blocked' === $row['state'] ) {
					$reason                   = $row ? implode( ' ', $row['problems'] ) : '';
					$result['skipped'][ $id ] = $reason;
					unset( $items[ $id ] );
					/** This action is documented in src/Engine.php */
					do_action( 'axle_config_sync_item_skipped', $id, $reason );
					continue;
				}

				$provider = $this->registry->for_item( $id );
				if ( ! $provider ) {
					continue;
				}

				$items[ $id ]['before']  = self::safe_encode( $row['live'] );
				$items[ $id ]['existed'] = (bool) $row['on_site'];
				$provider->write( $id, $row['resolved'] );
				$written[] = $id;

				$readback = $provider->exists( $id ) ? $provider->read( $id ) : null;
				$changed  = ! Codec::same( $readback, $row['resolved'] );

				$items[ $id ]['after']   = self::safe_encode( $readback );
				$items[ $id ]['changed'] = $changed;
				if ( $changed ) {
					$result['changed'][] = $id;
				}

				/**
				 * Fires after each item was written.
				 *
				 * @param string $id     Item ID.
				 * @param mixed  $before Value before.
				 * @param mixed  $after  Value after, as read back.
				 */
				do_action( 'axle_config_sync_item_applied', $id, $row['live'], $readback );
			}
		} catch ( \Throwable $e ) {
			foreach ( array_reverse( $written ) as $id ) {
				$provider = $this->registry->for_item( $id );
				try {
					if ( $provider ) {
						$provider->write( $id, empty( $items[ $id ]['existed'] ) ? null : Codec::decode( $items[ $id ]['before'] ) );
					}
				} catch ( \Throwable $ignored ) {
					// Keep rolling back the rest.
					unset( $ignored );
				}
			}

			$this->changesets->update( $changeset_id, $items, 'rolled_back' );
			$error = new \WP_Error( 'axle_config_sync_failed', $e->getMessage(), array( 'status' => 500 ) );

			/**
			 * Fires after a failed apply was rolled back.
			 *
			 * @param int $changeset_id Change set ID.
			 */
			do_action( 'axle_config_sync_rolled_back', $changeset_id );

			/**
			 * Fires when an apply failed.
			 *
			 * @param int       $changeset_id Change set ID.
			 * @param \WP_Error $error        What went wrong.
			 */
			do_action( 'axle_config_sync_apply_failed', $changeset_id, $error );

			return $error;
		}

		$this->changesets->update( $changeset_id, $items );
		$this->refs->flush();
		$this->files = array();

		foreach ( $written as $id ) {
			$row = $this->row( $id );
			if ( $row ) {
				$hashes[ $id ] = $row['hashes'];
			}
		}
		$this->state->synced( $hashes, 'apply', count( $written ) );

		/**
		 * Fires after an apply finished.
		 *
		 * @param int $changeset_id Change set ID.
		 */
		do_action( 'axle_config_sync_apply_finished', $changeset_id );

		$result['changeset'] = $changeset_id;
		$result['applied']   = $written;

		return $result;
	}

	/**
	 * Whether an item may be synced at all.
	 *
	 * @param string $id Item ID.
	 * @return bool
	 */
	private function allowed( string $id ): bool {
		return 0 !== strpos( $id, 'options/' ) || Providers\Options::allowed( substr( $id, 8 ) );
	}

	/**
	 * Whether a value holds an email address.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function has_email( $value ): bool {
		$found = false;
		Pointer::map_leaves(
			$value,
			static function ( $leaf ) use ( &$found ) {
				if ( is_string( $leaf ) && preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $leaf ) ) {
					$found = true;
				}
				return $leaf;
			}
		);

		return $found;
	}
}
