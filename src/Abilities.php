<?php
/**
 * Abilities for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Config Sync's abilities on WordPress 6.9 and later. On older
 * WordPress nothing here runs.
 *
 * The apply-config and undo-apply abilities are destructive, so Axle Read-Only Mode refuses
 * them during a freeze. No ability returns a secret's value: values come out
 * as they are in the files, with `{"$secret":"NAME"}` in place of secrets.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-config-sync';

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Sets up the abilities.
	 *
	 * @param Engine $engine The engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Config Sync', 'axle-config-sync' ),
				'description' => __( 'Keep site settings in files, see what differs from the live site, and apply or undo changes.', 'axle-config-sync' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$permission = static function (): bool {
			return current_user_can( Settings::capability() );
		};
		$ids        = array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => __( 'Item IDs, such as options/blogname or menus/primary.', 'axle-config-sync' ),
		);
		$row        = array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'string' ),
				'provider' => array( 'type' => 'string' ),
				'label'    => array( 'type' => 'string' ),
				'state'    => array(
					'type' => 'string',
					'enum' => Engine::STATES,
				),
				'danger'   => array( 'type' => 'string' ),
				'problems' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'warnings' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);

		$this->ability(
			'get-status',
			__( 'Get config status', 'axle-config-sync' ),
			__( 'The environment, the config directory, and every tracked setting with its state: in sync, changed in files, changed on site, conflict, only in files, only on site, or blocked.', 'axle-config-sync' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'provider' => array(
						'type'        => 'string',
						'description' => __( 'Only this provider: options, theme-mods, widgets, menus, navigation or roles.', 'axle-config-sync' ),
					),
					'state'    => array(
						'type' => 'string',
						'enum' => Engine::STATES,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'environment' => array( 'type' => 'string' ),
					'directory'   => array( 'type' => 'string' ),
					'counts'      => array( 'type' => 'object' ),
					'items'       => array(
						'type'  => 'array',
						'items' => $row,
					),
				),
			),
			array( $this, 'get_status' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-item-diff',
			__( 'Get the difference for one setting', 'axle-config-sync' ),
			__( 'The value in the file and on the site for one item, as they appear in files: IDs as references, URLs as placeholders, secrets by name only.', 'axle-config-sync' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'item' => array(
						'type'        => 'string',
						'description' => __( 'Item ID, such as options/blogname.', 'axle-config-sync' ),
					),
				),
				'required'             => array( 'item' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'id'         => array( 'type' => 'string' ),
					'state'      => array( 'type' => 'string' ),
					'file_value' => array( 'type' => array( 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ) ),
					'site_value' => array( 'type' => array( 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ) ),
				),
			),
			array( $this, 'get_item_diff' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-changesets',
			__( 'List change sets', 'axle-config-sync' ),
			__( 'Past applies and undos, newest first: when, by whom, from where, how many items and whether they were undone.', 'axle-config-sync' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'created_gmt' => array( 'type' => 'string' ),
						'user_id'     => array( 'type' => 'integer' ),
						'via'         => array( 'type' => 'string' ),
						'environment' => array( 'type' => 'string' ),
						'items_count' => array( 'type' => 'integer' ),
						'status'      => array( 'type' => 'string' ),
						'undo_of'     => array( 'type' => 'integer' ),
						'item_ids'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			),
			array( $this, 'list_changesets' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'export-config',
			__( 'Export config to files', 'axle-config-sync' ),
			__( 'Writes the live values of the chosen items (or every tracked item) to the config directory. Secrets are written by name only.', 'axle-config-sync' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'items' => $ids,
					'all'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'written'   => array( 'type' => 'object' ),
					'refused'   => array( 'type' => 'object' ),
					'directory' => array( 'type' => 'string' ),
				),
			),
			array( $this, 'export_config' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$apply_input = array(
			'type'                 => 'object',
			'properties'           => array(
				'items'           => $ids,
				'all'             => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Every item that differs.', 'axle-config-sync' ),
				),
				'allow_dangerous' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Apply dangerous changes, such as roles gaining manage_options or a new default role.', 'axle-config-sync' ),
				),
				'force'           => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Apply items that changed on both sides.', 'axle-config-sync' ),
				),
				'inactive_theme'  => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);

		$this->ability(
			'preview-apply',
			__( 'Preview an apply', 'axle-config-sync' ),
			__( 'What applying the chosen items would change, item by item, with warnings: dangerous changes, missing pages or menus, unset secrets and plugin version differences. Changes nothing.', 'axle-config-sync' ),
			$apply_input,
			array(
				'type'  => 'array',
				'items' => $row,
			),
			array( $this, 'preview_apply' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'apply-config',
			__( 'Apply config from files', 'axle-config-sync' ),
			__( 'Writes the file values of the chosen items to the site and stores a change set for undo. Refuses dangerous changes unless allow_dangerous is true, and conflicts unless force is true.', 'axle-config-sync' ),
			$apply_input,
			array(
				'type'       => 'object',
				'properties' => array(
					'changeset' => array( 'type' => 'integer' ),
					'applied'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'changed'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'skipped'   => array( 'type' => 'object' ),
				),
			),
			array( $this, 'apply_config' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'undo-apply',
			__( 'Undo an apply', 'axle-config-sync' ),
			__( 'Restores the values a change set replaced. Refuses when an item changed after the apply, unless force is true.', 'axle-config-sync' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'changeset' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'force'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'changeset' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'changeset' => array( 'type' => 'integer' ),
					'undone'    => array( 'type' => 'integer' ),
					'items'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			array( $this, 'undo_apply' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * Ability: status.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public function get_status( $input = null ): array {
		$input = (array) $input;
		$rows  = $this->engine->rows(
			array(
				'provider' => (string) ( $input['provider'] ?? '' ),
				'state'    => (string) ( $input['state'] ?? '' ),
			)
		);

		return array(
			'environment' => Environment::name(),
			'directory'   => $this->engine->directory()->path(),
			'counts'      => Engine::counts( $rows ),
			'items'       => array_map( array( Engine::class, 'public_row' ), $rows ),
		);
	}

	/**
	 * Ability: one item's values.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function get_item_diff( $input = null ) {
		$id  = $this->engine->normalize_id( (string) ( ( (array) $input )['item'] ?? '' ) );
		$row = $this->engine->row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'axle_config_sync_unknown_item', __( 'There is no such item.', 'axle-config-sync' ), array( 'status' => 404 ) );
		}

		return Engine::public_row( $row, true );
	}

	/**
	 * Ability: change sets.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public function list_changesets( $input = null ): array {
		$limit = max( 1, min( 100, (int) ( ( (array) $input )['limit'] ?? 20 ) ) );

		return $this->engine->changesets()->recent( $limit );
	}

	/**
	 * Ability: export.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function export_config( $input = null ) {
		$input = (array) $input;
		$ids   = ! empty( $input['all'] ) || empty( $input['items'] ) ? null : array_map( 'strval', (array) $input['items'] );

		$result = $this->engine->export( $ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['written'] = (object) $result['written'];
		$result['refused'] = (object) $result['refused'];

		return $result;
	}

	/**
	 * Ability: preview.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public function preview_apply( $input = null ): array {
		$input = (array) $input;
		$rows  = $this->engine->preview( $this->ids( $input ), $this->args( $input ) );

		return array_values(
			array_map(
				static function ( array $row ): array {
					return array_merge( Engine::public_row( $row, true ), array( 'action' => $row['action'] ) );
				},
				$rows
			)
		);
	}

	/**
	 * Ability: apply.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function apply_config( $input = null ) {
		$input  = (array) $input;
		$result = $this->engine->apply( $this->ids( $input ), array_merge( $this->args( $input ), array( 'via' => 'ability' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'changeset' => (int) $result['changeset'],
			'applied'   => array_values( $result['applied'] ),
			'changed'   => array_values( $result['changed'] ),
			'skipped'   => (object) $result['skipped'],
		);
	}

	/**
	 * Ability: undo.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function undo_apply( $input = null ) {
		$input  = (array) $input;
		$result = $this->engine->undo( (int) ( $input['changeset'] ?? 0 ), ! empty( $input['force'] ), 'ability' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'changeset' => (int) $result['changeset'],
			'undone'    => (int) $result['undone'],
			'items'     => array_values( $result['items'] ),
		);
	}

	/**
	 * Item IDs from the input.
	 *
	 * @param array $input Input.
	 * @return string[]
	 */
	private function ids( array $input ): array {
		return ! empty( $input['all'] ) ? $this->engine->applicable_ids() : array_map( 'strval', (array) ( $input['items'] ?? array() ) );
	}

	/**
	 * Apply options from the input.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private function args( array $input ): array {
		return array(
			'allow_dangerous' => ! empty( $input['allow_dangerous'] ),
			'force'           => ! empty( $input['force'] ),
			'inactive_theme'  => ! empty( $input['inactive_theme'] ),
		);
	}

	/**
	 * Registers one ability.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
