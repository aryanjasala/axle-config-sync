<?php
/**
 * Site Health tests.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Health;

use AryanX\Axle\ConfigSync\Admin\Page;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Three tests under Tools > Site Health: the config directory is served over
 * HTTP (critical), secrets are in the files (critical), and items conflict
 * (recommended).
 */
final class SiteHealth implements Service {

	/**
	 * The engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Sets up the tests.
	 *
	 * @param Engine $engine The engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Registers the tests.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Adds the tests.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public function add_tests( $tests ): array {
		$tests                                       = (array) $tests;
		$tests['direct']['axle_config_sync_served']  = array(
			'label' => __( 'Config Sync files are not served', 'axle-config-sync' ),
			'test'  => array( $this, 'test_served' ),
		);
		$tests['direct']['axle_config_sync_secrets'] = array(
			'label' => __( 'No secrets in Config Sync files', 'axle-config-sync' ),
			'test'  => array( $this, 'test_secrets' ),
		);
		$tests['direct']['axle_config_sync_conflicts'] = array(
			'label' => __( 'No Config Sync conflicts', 'axle-config-sync' ),
			'test'  => array( $this, 'test_conflicts' ),
		);

		return $tests;
	}

	/**
	 * Whether the web server hands out the config files.
	 *
	 * @return array
	 */
	public function test_served(): array {
		$directory = $this->engine->directory();
		$result    = $directory->exists() ? $directory->reachability( true ) : 'outside';

		if ( 'reachable' === $result ) {
			return $this->result(
				'critical',
				__( 'Config Sync files can be downloaded by anyone', 'axle-config-sync' ),
				'<p>' . esc_html__( 'The web server hands out files from the config directory, which hold site settings. Add this rule to the nginx site config, or move the directory outside the web root with AXLE_CONFIG_SYNC_DIR:', 'axle-config-sync' ) . '</p><pre>' . esc_html( $directory->nginx_rule() ) . '</pre>',
				'axle_config_sync_served'
			);
		}

		return $this->result(
			'good',
			__( 'Config Sync files are not served', 'axle-config-sync' ),
			'<p>' . esc_html( 'unknown' === $result ? __( 'The check request did not get through, so this could not be confirmed. If the site runs on nginx, make sure the config directory is denied.', 'axle-config-sync' ) : __( 'The config directory is outside the web root or the web server refuses it.', 'axle-config-sync' ) ) . '</p>',
			'axle_config_sync_served'
		);
	}

	/**
	 * Whether a secret sits in plain text in a file.
	 *
	 * @return array
	 */
	public function test_secrets(): array {
		$found = $this->engine->scan_secrets();
		if ( $found ) {
			$list = '';
			foreach ( array_slice( $found, 0, 20 ) as $hit ) {
				$list .= '<li><code>' . esc_html( $hit['file'] . ' ' . $hit['pointer'] ) . '</code></li>';
			}
			return $this->result(
				'critical',
				__( 'Secrets are in the Config Sync files', 'axle-config-sync' ),
				'<p>' . esc_html__( 'These values look like keys or passwords. Anyone with the git repository can read them. Replace them with {"$secret":"NAME"} and set AXLE_SECRET_NAME on each site.', 'axle-config-sync' ) . '</p><ul>' . $list . '</ul>',
				'axle_config_sync_secrets'
			);
		}

		return $this->result( 'good', __( 'No secrets in Config Sync files', 'axle-config-sync' ), '<p>' . esc_html__( 'No value in the config files looks like a key or password.', 'axle-config-sync' ) . '</p>', 'axle_config_sync_secrets' );
	}

	/**
	 * Whether items changed on both sides.
	 *
	 * @return array
	 */
	public function test_conflicts(): array {
		$conflicts = array();
		foreach ( $this->engine->rows() as $row ) {
			if ( 'conflict' === $row['base_state'] ) {
				$conflicts[] = $row['id'];
			}
		}

		if ( $conflicts ) {
			return $this->result(
				'recommended',
				__( 'Config Sync has conflicts', 'axle-config-sync' ),
				'<p>' . esc_html(
					sprintf(
						/* translators: %s: item IDs. */
						__( 'These settings changed both in the files and on this site since the last sync: %s. Review them on Settings > Config Sync.', 'axle-config-sync' ),
						implode( ', ', $conflicts )
					)
				) . '</p>',
				'axle_config_sync_conflicts'
			);
		}

		return $this->result( 'good', __( 'No Config Sync conflicts', 'axle-config-sync' ), '<p>' . esc_html__( 'No setting changed on both sides since the last sync.', 'axle-config-sync' ) . '</p>', 'axle_config_sync_conflicts' );
	}

	/**
	 * A Site Health result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Label.
	 * @param string $description HTML.
	 * @param string $test        Test name.
	 * @return array
	 */
	private function result( string $status, string $label, string $description, string $test ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'axle-config-sync' ),
				'color' => 'good' === $status ? 'blue' : 'red',
			),
			'description' => $description,
			'actions'     => sprintf( '<a href="%s">%s</a>', esc_url( Page::url() ), esc_html__( 'Open Config Sync', 'axle-config-sync' ) ),
			'test'        => $test,
		);
	}
}
