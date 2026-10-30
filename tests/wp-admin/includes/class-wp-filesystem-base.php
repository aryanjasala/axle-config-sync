<?php
/**
 * Stand-in for core's file system base class in unit tests.
 *
 * @package AryanX\Axle\ConfigSync
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
	class WP_Filesystem_Base {
	}
}
