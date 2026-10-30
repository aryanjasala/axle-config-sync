<?php
/**
 * Stand-in for core's direct file system class in unit tests: plain PHP file calls.
 *
 * @package AryanX\Axle\ConfigSync
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
	class WP_Filesystem_Direct extends WP_Filesystem_Base {
		public function __construct( $arg ) {
		}
		public function is_dir( $path ) {
			return is_dir( $path );
		}
		public function is_file( $path ) {
			return is_file( $path );
		}
		public function exists( $path ) {
			return file_exists( $path );
		}
		public function is_writable( $path ) {
			return is_writable( $path );
		}
		public function get_contents( $file ) {
			return @file_get_contents( $file );
		}
		public function put_contents( $file, $contents, $mode = false ) {
			return false !== file_put_contents( $file, $contents );
		}
		public function delete( $file ) {
			return @unlink( $file );
		}
		public function dirlist( $path, $include_hidden = true, $recursive = false ) {
			if ( ! is_dir( $path ) ) {
				return false;
			}
			$out = array();
			foreach ( scandir( $path ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$out[ $name ] = array(
					'name' => $name,
					'type' => is_dir( $path . '/' . $name ) ? 'd' : 'f',
				);
			}
			return $out;
		}
	}
}
