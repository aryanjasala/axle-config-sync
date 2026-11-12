<?php
/**
 * `wp axle-config-sync` commands.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Cli;

use AryanX\Axle\ConfigSync\Codec;
use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Environment;
use AryanX\Axle\ConfigSync\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps site settings in git: status, diff, export, apply, undo.
 *
 * The deploy one-liner: `git pull && wp axle-config-sync apply --all --yes`
 */
final class Command {

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
	 * Sets up the command.
	 *
	 * @param Engine $engine The engine.
	 * @param Drift  $drift  The drift watch.
	 */
	public function __construct( Engine $engine, Drift $drift ) {
		$this->engine = $engine;
		$this->drift  = $drift;
	}

	/**
	 * Shows every tracked item and how it compares with its file.
	 *
	 * Exits with 1 when anything differs and 2 when anything conflicts, so CI
	 * can fail on drift.
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<provider>]
	 * : Only this provider: options, theme-mods, widgets, menus, navigation or roles.
	 *
	 * [--state=<state>]
	 * : Only this state: in_sync, changed_in_files, changed_on_site, conflict, only_in_files, only_on_site or blocked.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync status
	 *     wp axle-config-sync status --state=conflict --format=ids
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ): void {
		$all  = $this->engine->rows();
		$rows = $this->engine->rows(
			array(
				'provider' => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'provider', '' ),
				'state'    => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'state', '' ),
			)
		);

		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
		} else {
			$items = array_map(
				static function ( array $row ): array {
					return array(
						'item'     => $row['id'],
						'provider' => $row['provider'],
						'state'    => $row['state'],
						'danger'   => $row['danger'],
						'notes'    => implode( ' ', array_merge( $row['problems'], $row['warnings'] ) ),
					);
				},
				$rows
			);
			\WP_CLI\Utils\format_items( $format, $items, array( 'item', 'provider', 'state', 'danger', 'notes' ) );
		}

		$counts = Engine::counts( $all );
		if ( $counts['conflict'] > 0 ) {
			\WP_CLI::halt( 2 );
		}
		if ( count( $all ) - $counts['in_sync'] - $counts['only_on_site'] > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Lists settings that were changed on this site after their files were written.
	 *
	 * Exits with 1 when anything drifted, so a deploy script or CI can stop
	 * before it overwrites a live change. Items on the expected list are left out.
	 *
	 * ## OPTIONS
	 *
	 * [--check]
	 * : Run the full check as the daily job does: store the result, and send the email and webhook for new drift when they are on.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync drift
	 *     wp axle-config-sync drift --format=json
	 *     wp axle-config-sync drift --check
	 *     wp axle-config-sync drift --format=count || echo "live changes would be overwritten"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function drift( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI passes both.
		$items = \WP_CLI\Utils\get_flag_value( $assoc_args, 'check', false ) ? $this->drift->check( 'cli' )['items'] : $this->drift->items();

		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_keys( $items ) ) );
		} elseif ( 'count' === $format ) {
			\WP_CLI::line( (string) count( $items ) );
		} else {
			$rows = array();
			foreach ( $items as $item ) {
				$rows[] = array(
					'item'     => $item['id'],
					'provider' => $item['provider'],
					'state'    => $item['state'],
					'files'    => $item['before'],
					'site'     => $item['after'],
					'by'       => Drift::who_label( $item['who'] ),
					'at'       => $item['since'] > 0 ? gmdate( 'Y-m-d H:i:s', (int) $item['since'] ) : '',
				);
			}
			\WP_CLI\Utils\format_items( $format, $rows, array( 'item', 'provider', 'state', 'files', 'site', 'by', 'at' ) );
		}

		if ( $items ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Manages the expected list: items a site may change on purpose, never reported as drift.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, add or remove.
	 *
	 * [<item>...]
	 * : Item IDs or patterns, such as options/blogdescription or options/woocommerce_*.
	 *
	 * [--format=<format>]
	 * : For list: table, json, csv, yaml or ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync expected add options/blogdescription
	 *     wp axle-config-sync expected list
	 *     wp axle-config-sync expected remove options/blogdescription
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function expected( $args, $assoc_args ): void {
		$action = (string) array_shift( $args );
		if ( 'list' === $action ) {
			$list = $this->drift->expected();
			if ( 'ids' === (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
				\WP_CLI::line( implode( ' ', $list ) );
				return;
			}
			\WP_CLI\Utils\format_items(
				(string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
				array_map(
					static function ( string $item ): array {
						return array( 'item' => $item );
					},
					$list
				),
				array( 'item' )
			);
			return;
		}

		if ( ! in_array( $action, array( 'add', 'remove' ), true ) || ! $args ) {
			\WP_CLI::error( 'Use: expected list, expected add <item>... or expected remove <item>...' );
			return;
		}

		if ( 'add' === $action ) {
			$this->drift->expect( array_map( 'strval', $args ) );
			\WP_CLI::success( sprintf( 'Added %d item(s) to the expected list.', count( $args ) ) );
			return;
		}

		$this->drift->unexpect( array_map( 'strval', $args ) );
		\WP_CLI::success( sprintf( 'Took %d item(s) off the expected list.', count( $args ) ) );
	}

	/**
	 * Shows or sets the drift alerts. Email and webhook are off until you turn them on.
	 *
	 * ## OPTIONS
	 *
	 * [--email=<on|off>]
	 * : Email new drift.
	 *
	 * [--email-to=<address>]
	 * : Recipient. Empty means the site admin email.
	 *
	 * [--webhook=<url>]
	 * : A public http or https URL that gets a JSON POST for new drift. Empty turns it off.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync drift-alerts --email=on --email-to=ops@example.com
	 *     wp axle-config-sync drift-alerts --webhook=https://hooks.example.com/abc
	 *     wp axle-config-sync drift-alerts
	 *
	 * @subcommand drift-alerts
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function drift_alerts( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI passes both.
		$changes = array();
		if ( isset( $assoc_args['email'] ) ) {
			$changes['email'] = in_array( strtolower( (string) $assoc_args['email'] ), array( 'on', 'yes', '1', 'true' ), true );
		}
		if ( isset( $assoc_args['email-to'] ) ) {
			$changes['email_to'] = (string) $assoc_args['email-to'];
		}
		if ( isset( $assoc_args['webhook'] ) ) {
			$changes['webhook'] = (string) $assoc_args['webhook'];
		}

		if ( $changes ) {
			$this->drift->save_settings( $changes );
			if ( isset( $changes['webhook'] ) && '' !== $changes['webhook'] && '' === $this->drift->settings()['webhook'] ) {
				\WP_CLI::warning( 'The webhook was not saved: it must be a public http or https URL.' );
			}
		}

		$settings = $this->drift->settings();
		\WP_CLI::line( 'email:    ' . ( $settings['email'] ? 'on' : 'off' ) );
		\WP_CLI::line( 'email-to: ' . ( '' !== $settings['email_to'] ? $settings['email_to'] : '(site admin email)' ) );
		\WP_CLI::line( 'webhook:  ' . ( '' !== $settings['webhook'] ? $settings['webhook'] : '(off)' ) );
	}

	/**
	 * Shows the providers, or turns one on or off.
	 *
	 * The same as the checkboxes on the Track tab. The rules are saved to
	 * config-sync.json in the config directory.
	 *
	 * ## OPTIONS
	 *
	 * [<provider>]
	 * : options, theme-mods, widgets, menus, navigation or roles.
	 *
	 * [<state>]
	 * : on or off.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync providers
	 *     wp axle-config-sync providers roles off
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function providers( $args, $assoc_args ): void {
		$registry = $this->engine->registry();
		$rules    = $this->engine->rules();

		if ( isset( $args[0] ) ) {
			if ( ! isset( $args[1] ) || ! in_array( $args[1], array( 'on', 'off' ), true ) || ! isset( $registry->all()[ $args[0] ] ) ) {
				\WP_CLI::error( 'Use: providers <provider> on|off, with a provider from: ' . implode( ', ', array_keys( $registry->all() ) ) );
				return;
			}
			$all                          = $rules->all();
			$all['providers'][ $args[0] ] = 'on' === $args[1];
			if ( ! $rules->save( $all ) ) {
				\WP_CLI::warning( 'Saved on this site only: the config directory cannot be written.' );
			}
		}

		$rows = array();
		foreach ( $registry->all() as $name => $provider ) {
			$rows[] = array(
				'provider' => $name,
				'title'    => $provider->title(),
				'tracked'  => $rules->provider_on( $name ) ? 'on' : 'off',
			);
		}
		\WP_CLI\Utils\format_items( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array( 'provider', 'title', 'tracked' ) );
	}

	/**
	 * Shows the file and site values of one item.
	 *
	 * ## OPTIONS
	 *
	 * <item>
	 * : Item ID, such as options/blogname or menus/primary. An option name alone works too.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync diff blogname
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function diff( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI passes both.
		$row = $this->engine->row( $this->engine->normalize_id( (string) $args[0] ) );
		if ( ! $row ) {
			\WP_CLI::error( 'There is no such item.' );
			return;
		}

		\WP_CLI::line( sprintf( '%s: %s', $row['id'], Engine::state_label( $row['state'] ) ) );
		foreach ( array_merge( $row['problems'], $row['warnings'] ) as $note ) {
			\WP_CLI::line( '  ' . $note );
		}
		if ( '' !== $row['danger'] ) {
			\WP_CLI::warning( 'Dangerous: ' . $row['danger'] );
		}

		$file = $row['has_file'] ? explode( "\n", Codec::pretty( $row['file_value'] ) ) : array();
		$site = $row['on_site'] ? explode( "\n", Codec::pretty( $row['site_value'] ) ) : array();
		\WP_CLI::line( '--- files' );
		\WP_CLI::line( '+++ site' );
		foreach ( self::line_diff( $file, $site ) as $line ) {
			\WP_CLI::line( $line );
		}
	}

	/**
	 * Writes live values to files in the config directory.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Item IDs. Leave out with --all.
	 *
	 * [--all]
	 * : Every tracked item on the site.
	 *
	 * [--dir=<dir>]
	 * : Write to this folder instead of the config directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync export --all
	 *     wp axle-config-sync export blogname menus/primary
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function export( $args, $assoc_args ): void {
		$all = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		if ( ! $all && ! $args ) {
			\WP_CLI::error( 'Name the items to export, or pass --all.' );
			return;
		}

		$result = $this->engine->export( $all ? null : array_map( 'strval', $args ), (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dir', '' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		foreach ( $result['written'] as $file ) {
			\WP_CLI::line( $file );
		}
		foreach ( $result['refused'] as $id => $reason ) {
			\WP_CLI::warning( $id . ': ' . $reason );
		}
		\WP_CLI::success( sprintf( 'Wrote %d file(s) to %s.', count( $result['written'] ), $result['directory'] ) );
	}

	/**
	 * Applies file values to the site, with a change set for undo.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Item IDs. Leave out with --all.
	 *
	 * [--all]
	 * : Every item that differs from its file.
	 *
	 * [--dry-run]
	 * : Show what would change and stop.
	 *
	 * [--allow-dangerous]
	 * : Apply dangerous changes (roles gaining admin capabilities, the default role, registration, the admin email, permalinks).
	 *
	 * [--force]
	 * : Apply items that changed both in the files and on the site.
	 *
	 * [--inactive-theme]
	 * : Apply theme mods of a theme that is not active.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     git pull && wp axle-config-sync apply --all --yes
	 *     wp axle-config-sync apply options/default_role --allow-dangerous
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function apply( $args, $assoc_args ): void {
		$all = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		if ( ! $all && ! $args ) {
			\WP_CLI::error( 'Name the items to apply, or pass --all.' );
			return;
		}

		$ids     = $all ? $this->engine->applicable_ids() : array_map( 'strval', $args );
		$options = array(
			'allow_dangerous' => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'allow-dangerous', false ),
			'force'           => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ),
			'inactive_theme'  => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'inactive-theme', false ),
			'via'             => 'cli',
		);

		$preview = $this->engine->apply( $ids, array_merge( $options, array( 'dry_run' => true ) ) );
		if ( is_wp_error( $preview ) ) {
			\WP_CLI::error( $preview->get_error_message() );
			return;
		}

		$this->print_preview( $preview['preview'] );
		$todo = array_filter(
			$preview['preview'],
			static function ( array $row ): bool {
				return 'apply' === $row['action'];
			}
		);
		if ( ! $todo ) {
			\WP_CLI::success( 'Nothing to apply.' );
			return;
		}
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			\WP_CLI::success( sprintf( 'Dry run: %d item(s) would be applied.', count( $todo ) ) );
			return;
		}

		\WP_CLI::confirm( sprintf( 'Apply %d item(s)?', count( $todo ) ), $assoc_args );

		$result = $this->engine->apply( $ids, $options );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		foreach ( $result['changed'] as $id ) {
			\WP_CLI::warning( $id . ': applied with changes (a sanitize callback changed the value).' );
		}
		\WP_CLI::success( sprintf( 'Applied %d item(s) as change set %d. Undo with: wp axle-config-sync undo %2$d', count( $result['applied'] ), (int) $result['changeset'] ) );
	}

	/**
	 * Restores the values a change set replaced.
	 *
	 * ## OPTIONS
	 *
	 * <changeset>
	 * : Change set ID, from `wp axle-config-sync changesets`.
	 *
	 * [--force]
	 * : Undo even items that changed after the apply.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync undo 12
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function undo( $args, $assoc_args ): void {
		$result = $this->engine->undo( (int) $args[0], (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ), 'cli' );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		\WP_CLI::success( sprintf( 'Undid change set %d (%d item(s)) as change set %d.', (int) $result['undone'], count( $result['items'] ), (int) $result['changeset'] ) );
	}

	/**
	 * Lists past applies and undos.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function changesets( $args, $assoc_args ): void {
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $this->engine->changesets()->recent( 100 ), 'id' ) ) );
			return;
		}

		$rows = array_map(
			static function ( array $row ): array {
				$user = $row['user_id'] ? get_userdata( $row['user_id'] ) : false;
				return array(
					'id'          => $row['id'],
					'created_gmt' => $row['created_gmt'],
					'user'        => $user ? $user->user_login : '',
					'via'         => $row['via'],
					'environment' => $row['environment'],
					'items'       => $row['items_count'],
					'status'      => $row['status'],
					'undo_of'     => $row['undo_of'] ? $row['undo_of'] : '',
				);
			},
			$this->engine->changesets()->recent( 100 )
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'created_gmt', 'user', 'via', 'environment', 'items', 'status', 'undo_of' ) );
	}

	/**
	 * Tracks a plugin option, or every option matching a `prefix_*` pattern.
	 *
	 * ## OPTIONS
	 *
	 * <option-or-pattern>
	 * : Option name or pattern.
	 *
	 * [--owner=<plugin>]
	 * : The plugin folder name that owns it, for the version check.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-config-sync track 'woocommerce_*' --owner=woocommerce
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function track( $args, $assoc_args ): void {
		$pattern = Rules::clean_pattern( (string) $args[0] );
		if ( '' === $pattern ) {
			\WP_CLI::error( 'That is not an option name or pattern.' );
			return;
		}

		$written = $this->engine->rules()->track( $pattern, sanitize_key( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'owner', '' ) ) );
		\WP_CLI::success( sprintf( 'Tracking %s.%s', $pattern, $written ? ' Saved to ' . Rules::FILE . '.' : ' The config directory cannot be written, so it is saved on this site only.' ) );
	}

	/**
	 * Stops tracking an option or pattern.
	 *
	 * ## OPTIONS
	 *
	 * <option-or-pattern>
	 * : Option name or pattern, as tracked.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function untrack( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI passes both.
		if ( ! $this->engine->rules()->untrack( Rules::clean_pattern( (string) $args[0] ) ) ) {
			\WP_CLI::warning( sprintf( '%s was not tracked.', (string) $args[0] ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Stopped tracking %s.', (string) $args[0] ) );
	}

	/**
	 * Prints the environment this site thinks it is in, and where that came from.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function env( $args, $assoc_args ): void {
		$env = Environment::detect();
		$dir = $this->engine->directory();

		$row = array(
			'environment' => $env['name'],
			'source'      => $env['source'],
			'env_file'    => Environment::file(),
			'directory'   => $dir->path(),
			'writable'    => $dir->writable() ? 'yes' : 'no',
		);

		if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $row ) );
			return;
		}

		foreach ( $row as $key => $value ) {
			\WP_CLI::line( sprintf( '%-12s %s', $key . ':', $value ) );
		}
	}

	/**
	 * Prints a preview table.
	 *
	 * @param array[] $rows Preview rows.
	 */
	private function print_preview( array $rows ): void {
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'item'   => $row['id'],
				'state'  => $row['base_state'],
				'action' => $row['action'],
				'danger' => $row['danger'],
				'notes'  => implode( ' ', array_merge( $row['problems'], $row['warnings'] ) ),
			);
		}
		if ( $items ) {
			\WP_CLI\Utils\format_items( 'table', $items, array( 'item', 'state', 'action', 'danger', 'notes' ) );
		}
	}

	/**
	 * A plain line diff (LCS), - for files and + for site.
	 *
	 * @param string[] $a File lines.
	 * @param string[] $b Site lines.
	 * @return string[]
	 */
	public static function line_diff( array $a, array $b ): array {
		$n   = count( $a );
		$m   = count( $b );
		$lcs = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$lcs[ $i ][ $j ] = $a[ $i ] === $b[ $j ] ? $lcs[ $i + 1 ][ $j + 1 ] + 1 : max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
			}
		}

		$out = array();
		$i   = 0;
		$j   = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$out[] = '  ' . $a[ $i ];
				++$i;
				++$j;
			} elseif ( $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) {
				$out[] = '- ' . $a[ $i ];
				++$i;
			} else {
				$out[] = '+ ' . $b[ $j ];
				++$j;
			}
		}
		for ( ; $i < $n; $i++ ) {
			$out[] = '- ' . $a[ $i ];
		}
		for ( ; $j < $m; $j++ ) {
			$out[] = '+ ' . $b[ $j ];
		}

		return $out;
	}
}
