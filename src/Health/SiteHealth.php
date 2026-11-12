<?php
/**
 * Site Health tests.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Health;

use AryanX\Axle\ConfigSync\Admin\Page;
use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Drift;
use AryanX\Axle\ConfigSync\Engine;
use AryanX\Axle\ConfigSync\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Four tests under Tools > Site Health: the config directory is served over
 * HTTP (critical), secrets are in the files (critical), items conflict
 * (recommended), and settings drifted from the files (recommended).
 */
final class SiteHealth implements Service {

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
	 * Sets up the tests.
	 *
	 * @param Engine $engine The engine.
	 * @param Drift  $drift  The drift watch.
	 */
	public function __construct( Engine $engine, Drift $drift ) {
		$this->engine = $engine;
		$this->drift  = $drift;
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
		$tests['direct']['axle_config_sync_drift']   = array(
			'label' => __( 'No Config Sync drift', 'axle-config-sync' ),
			'test'  => array( $this, 'test_drift' ),
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
				'<p>' . esc_html__( 'The web server hands out files from the config directory, which hold site settings. Add this rule to the nginx site config and reload nginx, or move the directory outside the web root with AXLE_CONFIG_SYNC_DIR:', 'axle-config-sync' ) . '</p><pre>' . esc_html( $directory->nginx_rule() ) . '</pre>',
				'axle_config_sync_served'
			);
		}

		if ( 'unknown' === $result && Directory::ignores_htaccess() ) {
			return $this->result(
				'recommended',
				__( 'Config Sync files may be served', 'axle-config-sync' ),
				'<p>' . esc_html__( 'This site runs on nginx, which ignores the .htaccess file Config Sync wrote, and the check request did not get through. Add this rule to the nginx site config, reload nginx, then run the check again, or move the directory outside the web root with AXLE_CONFIG_SYNC_DIR:', 'axle-config-sync' ) . '</p><pre>' . esc_html( $directory->nginx_rule() ) . '</pre>',
				'axle_config_sync_served'
			);
		}

		return $this->result(
			'good',
			__( 'Config Sync files are not served', 'axle-config-sync' ),
			'<p>' . esc_html( 'unknown' === $result ? __( 'The check request did not get through, so this could not be confirmed. Apache follows the .htaccess deny; on other servers, make sure the config directory is denied.', 'axle-config-sync' ) : __( 'The config directory is outside the web root or the web server refuses it.', 'axle-config-sync' ) ) . '</p>',
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
	 * Whether settings changed on the site after their files were written.
	 * Reads what the last check stored, so the test is cheap.
	 *
	 * @return array
	 */
	public function test_drift(): array {
		$state = $this->drift->state();
		$ids   = array_keys( $state['items'] );

		if ( $ids ) {
			return $this->result(
				'recommended',
				__( 'Config Sync found settings that drifted from the files', 'axle-config-sync' ),
				'<p>' . esc_html(
					sprintf(
						/* translators: %s: item IDs. */
						__( 'These settings were changed on this site after their files were written, so the next deploy from the files would overwrite them: %s. Keep the live values in the files, or put the files back.', 'axle-config-sync' ),
						implode( ', ', array_slice( $ids, 0, 20 ) ) . ( count( $ids ) > 20 ? ', ...' : '' )
					)
				) . '</p>',
				'axle_config_sync_drift',
				Page::url( 'drift' ),
				__( 'Review the drift', 'axle-config-sync' )
			);
		}

		if ( 0 === $state['checked_at'] ) {
			return $this->result(
				'good',
				__( 'No Config Sync drift', 'axle-config-sync' ),
				'<p>' . esc_html__( 'The daily drift check has not run yet. Run it from Settings > Config Sync > Drift, or with wp axle-config-sync drift --check.', 'axle-config-sync' ) . '</p>',
				'axle_config_sync_drift',
				Page::url( 'drift' ),
				__( 'Open Drift', 'axle-config-sync' )
			);
		}

		return $this->result( 'good', __( 'No Config Sync drift', 'axle-config-sync' ), '<p>' . esc_html__( 'No tracked setting changed on this site after its file was written, as of the last check.', 'axle-config-sync' ) . '</p>', 'axle_config_sync_drift', Page::url( 'drift' ), __( 'Open Drift', 'axle-config-sync' ) );
	}

	/**
	 * A Site Health result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Label.
	 * @param string $description HTML.
	 * @param string $test        Test name.
	 * @param string $url         Link target, or empty for the screen.
	 * @param string $link        Link text, or empty.
	 * @return array
	 */
	private function result( string $status, string $label, string $description, string $test, string $url = '', string $link = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => 'axle_config_sync_drift' === $test || 'axle_config_sync_conflicts' === $test ? __( 'Configuration', 'axle-config-sync' ) : __( 'Security', 'axle-config-sync' ),
				'color' => 'good' === $status ? 'blue' : 'red',
			),
			'description' => $description,
			'actions'     => sprintf( '<a href="%s">%s</a>', esc_url( '' !== $url ? $url : Page::url() ), '' !== $link ? esc_html( $link ) : esc_html__( 'Open Config Sync', 'axle-config-sync' ) ),
			'test'        => $test,
		);
	}
}
