<?php
/**
 * REST routes: status, chunked apply and undo.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Rest;

use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Environment;
use AryanX\Axle\ConfigSync\Service;
use AryanX\Axle\ConfigSync\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * `axle-config-sync/v1`:
 *
 * - GET  /status           the same as the Status tab
 * - GET  /drift            settings changed on the site after their files were written
 * - POST /apply            apply items; the screen sends more than the batch size in chunks, with `changeset` to continue one change set
 * - POST /undo/<id>        undo a change set
 *
 * Every route needs the capability from axle_config_sync_capability.
 */
final class Controller implements Service {

	public const NAMESPACE = 'axle-config-sync/v1';

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
	 * Sets up the routes.
	 *
	 * @param Engine $engine The engine.
	 * @param Drift  $drift  The drift watch.
	 */
	public function __construct( Engine $engine, Drift $drift ) {
		$this->engine = $engine;
		$this->drift  = $drift;
	}

	/**
	 * Hooks the routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$permission = static function (): bool {
			return current_user_can( Settings::capability() );
		};

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/drift',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'drift' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => $permission,
				'args'                => array(
					'items'           => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'required' => true,
					),
					'allow_dangerous' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'force'           => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'inactive_theme'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'changeset'       => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/undo/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => $permission,
				'args'                => array(
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * GET /status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		$rows = $this->engine->rows(
			array(
				'provider' => (string) $request->get_param( 'provider' ),
				'state'    => (string) $request->get_param( 'state' ),
			)
		);

		return rest_ensure_response(
			array(
				'environment' => Environment::name(),
				'directory'   => $this->engine->directory()->path(),
				'counts'      => Engine::counts( $rows ),
				'items'       => array_map( array( Engine::class, 'public_row' ), $rows ),
			)
		);
	}

	/**
	 * GET /drift.
	 *
	 * @return \WP_REST_Response
	 */
	public function drift(): \WP_REST_Response {
		$items = array_values( $this->drift->items() );

		return rest_ensure_response(
			array(
				'checked_at' => $this->drift->state()['checked_at'],
				'count'      => count( $items ),
				'items'      => array_map( array( self::class, 'drift_item' ), $items ),
			)
		);
	}

	/**
	 * A drifted item for output. Values come masked from the engine.
	 *
	 * @param array $item Drifted item.
	 * @return array
	 */
	public static function drift_item( array $item ): array {
		$who = $item['who'];

		return array(
			'id'       => $item['id'],
			'label'    => $item['label'],
			'provider' => $item['provider'],
			'state'    => $item['state'],
			'before'   => $item['before'],
			'after'    => $item['after'],
			'by'       => $who ? Drift::who_label( $who ) : '',
			'user_id'  => $who ? (int) $who['user_id'] : 0,
			'since'    => (int) $item['since'],
		);
	}

	/**
	 * POST /apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply( \WP_REST_Request $request ) {
		$result = $this->engine->apply(
			array_map( 'strval', (array) $request->get_param( 'items' ) ),
			array(
				'allow_dangerous' => (bool) $request->get_param( 'allow_dangerous' ),
				'force'           => (bool) $request->get_param( 'force' ),
				'inactive_theme'  => (bool) $request->get_param( 'inactive_theme' ),
				'changeset'       => (int) $request->get_param( 'changeset' ),
				'via'             => 'admin',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'changeset' => (int) $result['changeset'],
				'applied'   => array_values( $result['applied'] ),
				'changed'   => array_values( $result['changed'] ),
				'skipped'   => (object) $result['skipped'],
			)
		);
	}

	/**
	 * POST /undo/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo( \WP_REST_Request $request ) {
		$result = $this->engine->undo( (int) $request->get_param( 'id' ), (bool) $request->get_param( 'force' ), 'admin' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
