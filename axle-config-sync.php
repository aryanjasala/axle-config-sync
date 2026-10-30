<?php
/**
 * Plugin Name:       Axle Config Sync
 * Plugin URI:        https://wordpress.org/plugins/axle-config-sync/
 * Description:       Keep site settings in git as JSON files, see what differs between the files and the live site, and apply a chosen set with a preview and undo.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Aryan Jasala
 * Author URI:        https://profiles.wordpress.org/aryanjasala/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axle-config-sync
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

// PSR-4: AryanX\Axle\ConfigSync\Foo\Bar lives in src/Foo/Bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Schema::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Schema::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
