<?php
/**
 * A stand-in for Activity Log with Undo's public surface that Config Sync uses:
 * Plugin::instance()->logger()->log( array ).
 *
 * @package AryanX\Axle\ConfigSync
 */

// phpcs:ignoreFile

namespace AryanX\Axle\ActivityLogWithUndo;

if ( ! class_exists( __NAMESPACE__ . '\\Logger' ) ) {
	class Logger {
		/** @var array[] */
		public static array $logged = array();

		public function log( array $data ): int {
			self::$logged[] = $data;

			return count( self::$logged );
		}
	}

	class Plugin {
		public static function instance(): self {
			return new self();
		}

		public function logger(): Logger {
			return new Logger();
		}
	}
}
