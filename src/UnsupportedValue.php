<?php
/**
 * Thrown for a value that cannot go into a file.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * A PHP object of a class other than stdClass, a resource, or a number JSON
 * cannot hold. Such an item is reported as "unsupported value" and skipped.
 */
final class UnsupportedValue extends \RuntimeException {
}
