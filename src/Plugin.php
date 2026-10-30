<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

use AryanX\Axle\ConfigSync\Admin\Page;
use AryanX\Axle\ConfigSync\Cli\Command;
use AryanX\Axle\ConfigSync\Cli\SecretsCommand;
use AryanX\Axle\ConfigSync\Health\SiteHealth;
use AryanX\Axle\ConfigSync\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shared objects once and registers every service.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Change sets.
	 *
	 * @var Changesets
	 */
	private Changesets $changesets;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$directory        = new Directory();
		$rules            = new Rules( $directory );
		$this->changesets = new Changesets();
		$this->engine     = new Engine( $directory, $rules, new State(), $this->changesets, new Registry( $rules ) );
	}

	/**
	 * The engine, for other plugins and deploy tooling.
	 *
	 * @return Engine
	 */
	public function engine(): Engine {
		return $this->engine;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Schema::maybe_upgrade();

		$services = array(
			$this->changesets,
			new Page( $this->engine ),
			new Controller( $this->engine ),
			new Abilities( $this->engine ),
			new SiteHealth( $this->engine ),
			new Privacy( $this->changesets, $this->engine->state() ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-config-sync', new Command( $this->engine ) );
			\WP_CLI::add_command( 'axle-config-sync secrets', new SecretsCommand( $this->engine ) );
		}
	}
}
