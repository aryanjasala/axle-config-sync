<?php
/**
 * The config directory: where it is, reading and writing its files, and
 * whether the web server hands them out.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * One JSON file per item lives here, meant to be committed to git.
 *
 * The default is wp-content/axle-config/, which works on every host, with an
 * .htaccess deny and an empty index.php. nginx ignores .htaccess, so the
 * screen and Site Health request a probe file over HTTP and show the nginx
 * rule when it is served. AXLE_CONFIG_SYNC_DIR or the
 * axle_config_sync_directory filter move it, ideally outside the web root.
 * On multisite each site has its own sites/<blog_id>/ inside it.
 */
final class Directory {

	public const PROBE     = 'reachability-check.txt';
	public const TRANSIENT = 'axle_config_sync_reachable';

	/**
	 * The folder, set for one call (wp axle-config-sync export --dir).
	 *
	 * @var string
	 */
	private string $override = '';

	/**
	 * File system access.
	 *
	 * @var \WP_Filesystem_Direct|null
	 */
	private ?\WP_Filesystem_Direct $fs = null;

	/**
	 * The directory, without a trailing slash.
	 *
	 * @return string
	 */
	public function path(): string {
		if ( '' !== $this->override ) {
			return $this->override;
		}

		$base = defined( 'AXLE_CONFIG_SYNC_DIR' ) && is_string( AXLE_CONFIG_SYNC_DIR ) && '' !== AXLE_CONFIG_SYNC_DIR
			? AXLE_CONFIG_SYNC_DIR
			: WP_CONTENT_DIR . '/axle-config';

		$path = untrailingslashit( wp_normalize_path( $base ) );
		if ( is_multisite() ) {
			/**
			 * Filters the subfolder of a site on multisite.
			 *
			 * @param string $folder  Default sites/<blog_id>.
			 * @param int    $blog_id Site ID.
			 */
			$folder = trim( (string) apply_filters( 'axle_config_sync_site_directory', 'sites/' . get_current_blog_id(), get_current_blog_id() ), '/' );
			if ( '' !== $folder && false === strpos( $folder, '..' ) ) {
				$path .= '/' . $folder;
			}
		}

		/**
		 * Filters the config directory, the folder with one JSON file per item.
		 *
		 * @param string $path Absolute path. Default wp-content/axle-config, or AXLE_CONFIG_SYNC_DIR.
		 */
		$filtered = (string) apply_filters( 'axle_config_sync_directory', $path );

		return '' !== $filtered ? untrailingslashit( wp_normalize_path( $filtered ) ) : $path;
	}

	/**
	 * Uses another folder until reset (export --dir).
	 *
	 * @param string $path Absolute path, or empty to go back.
	 */
	public function use_path( string $path ): void {
		$this->override = '' === $path ? '' : untrailingslashit( wp_normalize_path( $path ) );
	}

	/**
	 * Absolute path of a file in the directory.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	public function file( string $relative ): string {
		return $this->path() . '/' . ltrim( $relative, '/' );
	}

	/**
	 * Whether the directory exists.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return $this->fs()->is_dir( $this->path() );
	}

	/**
	 * Whether the directory (or its nearest existing parent) can be written.
	 *
	 * @return bool
	 */
	public function writable(): bool {
		$path = $this->path();
		while ( ! $this->fs()->is_dir( $path ) ) {
			$parent = dirname( $path );
			if ( $parent === $path ) {
				return false;
			}
			$path = $parent;
		}

		return $this->fs()->is_writable( $path );
	}

	/**
	 * Whether the directory is inside a folder the web server serves.
	 *
	 * @return bool
	 */
	public function inside_web_root(): bool {
		return '' !== $this->url( '' );
	}

	/**
	 * The URL of a file, when the directory is inside ABSPATH or wp-content.
	 *
	 * @param string $relative Relative path.
	 * @return string Empty when the directory is outside both.
	 */
	public function url( string $relative ): string {
		$path    = trailingslashit( $this->path() ) . ltrim( $relative, '/' );
		$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$root    = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( 0 === strpos( $path, $content ) ) {
			return content_url( substr( $path, strlen( $content ) ) );
		}
		if ( 0 === strpos( $path, $root ) ) {
			return site_url( substr( $path, strlen( $root ) ) );
		}

		return '';
	}

	/**
	 * The path of the directory relative to the web root, for the nginx rule.
	 *
	 * @return string Such as /wp-content/axle-config/.
	 */
	public function web_path(): string {
		$url = $this->url( '' );
		if ( '' === $url ) {
			return '';
		}

		return trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/**
	 * The nginx rule that keeps the directory private.
	 *
	 * @return string
	 */
	public function nginx_rule(): string {
		return sprintf( "location ^~ %s {\n\tdeny all;\n\treturn 404;\n}", '' !== $this->web_path() ? $this->web_path() : '/wp-content/axle-config/' );
	}

	/**
	 * Creates the directory, with the deny rules when it is inside the web root.
	 *
	 * @return bool Whether it exists and can be written.
	 */
	public function ensure(): bool {
		$path = $this->path();
		if ( ! $this->fs()->is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return false;
		}

		if ( $this->inside_web_root() ) {
			$root = $this->root_inside_web();
			if ( ! $this->fs()->exists( $root . '/.htaccess' ) ) {
				$this->fs()->put_contents( $root . '/.htaccess', "# Axle Config Sync: these files are site settings, never serve them.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n", 0644 );
			}
			foreach ( array_unique( array( $root, $path ) ) as $folder ) {
				if ( ! $this->fs()->exists( $folder . '/index.php' ) ) {
					$this->fs()->put_contents( $folder . '/index.php', "<?php\n// Silence is golden.\n", 0644 );
				}
			}
		}

		return $this->fs()->is_writable( $path );
	}

	/**
	 * Reads a file. Null when it is missing.
	 *
	 * @param string $relative Relative path.
	 * @return string|null
	 */
	public function read( string $relative ): ?string {
		$file = $this->file( $relative );
		if ( ! $this->fs()->is_file( $file ) ) {
			return null;
		}

		$contents = $this->fs()->get_contents( $file );

		return false === $contents ? null : (string) $contents;
	}

	/**
	 * Reads and decodes a JSON file. Null when it is missing or not JSON.
	 *
	 * @param string $relative Relative path.
	 * @return array|null
	 */
	public function read_json( string $relative ): ?array {
		$text = $this->read( $relative );

		return null === $text ? null : Codec::from_json( $text );
	}

	/**
	 * Writes a file, creating folders on the way. Leaves it alone when the
	 * contents are the same, so git sees no change.
	 *
	 * @param string $relative Relative path.
	 * @param string $contents Contents.
	 * @return bool
	 */
	public function write( string $relative, string $contents ): bool {
		$file = $this->file( $relative );
		if ( ! $this->fs()->is_dir( dirname( $file ) ) && ! wp_mkdir_p( dirname( $file ) ) ) {
			return false;
		}
		if ( $this->fs()->is_file( $file ) && $this->fs()->get_contents( $file ) === $contents ) {
			return true;
		}

		return (bool) $this->fs()->put_contents( $file, $contents, 0644 );
	}

	/**
	 * Deletes a file.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	public function delete( string $relative ): bool {
		$file = $this->file( $relative );

		return ! $this->fs()->is_file( $file ) || $this->fs()->delete( $file );
	}

	/**
	 * Every JSON file, as relative paths, sorted.
	 *
	 * @return string[]
	 */
	public function files(): array {
		$path = $this->path();
		if ( ! $this->fs()->is_dir( $path ) ) {
			return array();
		}

		$out = array();
		$this->walk( $path, '', $out );
		sort( $out );

		return $out;
	}

	/**
	 * Whether the web server hands out files from the directory: reachable,
	 * blocked, outside (not in the web root) or unknown (the request failed).
	 *
	 * @param bool $fresh Ignore the cached result.
	 * @return string
	 */
	public function reachability( bool $fresh = false ): string {
		if ( ! $this->inside_web_root() ) {
			return 'outside';
		}

		$cached = get_transient( self::TRANSIENT );
		if ( ! $fresh && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$result = 'unknown';
		$token  = wp_generate_password( 24, false );
		// No ensure() here: the check must see the folder as it is, without a fresh .htaccess.
		if ( $this->exists() && $this->write( self::PROBE, $token ) ) {
			/**
			 * Filters the URL used to check whether the config directory is served
			 * over HTTP. Useful when the site cannot reach itself on its public URL.
			 *
			 * @param string $url Probe file URL.
			 */
			$url = (string) apply_filters( 'axle_config_sync_probe_url', $this->url( self::PROBE ) );
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- A loopback request to this site, cached for an hour; the safe variant refuses loopback hosts.
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 3,
					'redirection' => 0,
					'sslverify'   => false,
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$code   = (int) wp_remote_retrieve_response_code( $response );
				$result = 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $response ), $token ) ? 'reachable' : 'blocked';
			}
			$this->delete( self::PROBE );
		}

		set_transient( self::TRANSIENT, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * The top folder inside the web root that holds the config, where the
	 * .htaccess goes (the base folder on multisite, not each site's).
	 *
	 * @return string
	 */
	private function root_inside_web(): string {
		$path = $this->path();
		if ( is_multisite() && preg_match( '#^(.*)/sites/[^/]+$#', $path, $match ) ) {
			return $match[1];
		}

		return $path;
	}

	/**
	 * Collects JSON files under a folder.
	 *
	 * @param string   $base     Absolute base.
	 * @param string   $relative Folder relative to the base.
	 * @param string[] $out      Collected paths.
	 */
	private function walk( string $base, string $relative, array &$out ): void {
		$list = $this->fs()->dirlist( '' === $relative ? $base : $base . '/' . $relative, false, false );
		if ( ! is_array( $list ) ) {
			return;
		}

		foreach ( $list as $name => $entry ) {
			$child = '' === $relative ? (string) $name : $relative . '/' . $name;
			if ( 'd' === $entry['type'] ) {
				if ( 'sites' !== $child && '.git' !== $name ) {
					$this->walk( $base, $child, $out );
				}
			} elseif ( '.json' === substr( (string) $name, -5 ) ) {
				$out[] = $child;
			}
		}
	}

	/**
	 * Direct file system access, without asking for FTP credentials.
	 *
	 * @return \WP_Filesystem_Direct
	 */
	private function fs(): \WP_Filesystem_Direct {
		if ( null === $this->fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$this->fs = new \WP_Filesystem_Direct( null );
		}

		return $this->fs;
	}
}
