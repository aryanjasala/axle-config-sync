<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

use AryanX\Axle\ConfigSync\Admin\Page;
use AryanX\Axle\ConfigSync\Admin\DriftNotice;
use AryanX\Axle\ConfigSync\Cli\Command;
use AryanX\Axle\ConfigSync\Cli\ImportCommand;
use AryanX\Axle\ConfigSync\Cli\SecretsCommand;
use AryanX\Axle\ConfigSync\Health\SiteHealth;
use AryanX\Axle\ConfigSync\Import\WpCfm;
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
	 * The drift watch.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * The WP-CFM importer.
	 *
	 * @var WpCfm
	 */
	private WpCfm $wpcfm;

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
		$this->drift      = new Drift( $this->engine );
		$this->wpcfm      = new WpCfm( $this->engine );
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
	 * The drift watch.
	 *
	 * @return Drift
	 */
	public function drift(): Drift {
		return $this->drift;
	}

	/**
	 * The WP-CFM importer.
	 *
	 * @return WpCfm
	 */
	public function wpcfm(): WpCfm {
		return $this->wpcfm;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Schema::maybe_upgrade();

		$services = array(
			$this->changesets,
			$this->drift,
			new Page( $this->engine, $this->drift, $this->wpcfm ),
			new DriftNotice( $this->drift ),
			new Controller( $this->engine, $this->drift ),
			new Abilities( $this->engine, $this->drift, $this->wpcfm ),
			new SiteHealth( $this->engine, $this->drift ),
			new Privacy( $this->changesets, $this->engine->state(), $this->drift ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-config-sync', new Command( $this->engine, $this->drift ) );
			\WP_CLI::add_command( 'axle-config-sync import', new ImportCommand( $this->wpcfm ) );
			\WP_CLI::add_command( 'axle-config-sync secrets', new SecretsCommand( $this->engine ) );
		}
	}
}
