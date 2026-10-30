<?php
/**
 * Dangerous changes: the ones that could hand over the site.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

use AryanX\Axle\ConfigSync\Providers\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Anyone who can write to the config directory or the git repository could
 * otherwise grant themselves admin through an apply. So these need an
 * explicit "I have read this" on the screen and `--allow-dangerous` on the
 * command line: who can sign up and as what, the admin email, search engine
 * visibility on production, permalinks, and roles gaining powerful
 * capabilities.
 */
final class Danger {

	/**
	 * Item IDs (and `*` patterns) whose changes are dangerous.
	 *
	 * @return string[]
	 */
	public static function items(): array {
		$defaults = array( 'options/users_can_register', 'options/default_role', 'options/admin_email', 'options/blog_public', 'options/permalink_structure', 'options/active_plugins', 'roles/*' );

		/**
		 * Filters the items whose changes count as dangerous. Roles only count
		 * when they gain a capability from axle_config_sync_dangerous_capabilities,
		 * and blog_public only when it hides production from search engines.
		 *
		 * @param string[] $items Item IDs and `*` patterns.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'axle_config_sync_dangerous_items', $defaults ) ) );
	}

	/**
	 * Why a change is dangerous, or empty when it is not.
	 *
	 * @param Provider $provider Provider.
	 * @param string   $id       Item ID.
	 * @param mixed    $before   Live value (null when missing).
	 * @param mixed    $after    Value to write.
	 * @return string
	 */
	public static function reason( Provider $provider, string $id, $before, $after ): string {
		$reason = '';
		if ( ! Codec::same( $before, $after ) && Rules::matches_any( $id, self::items() ) ) {
			if ( 0 === strpos( $id, 'roles/' ) ) {
				$reason = $provider->danger( $id, $before, $after );
			} elseif ( 'options/blog_public' === $id ) {
				$reason = 'production' === Environment::name() && (int) $before && ! (int) $after ? Messages::get( 'dangerous_public' ) : '';
			} else {
				$reason = Messages::get( 'dangerous_change' );
			}
		} elseif ( ! Codec::same( $before, $after ) ) {
			$reason = $provider->danger( $id, $before, $after );
		}

		/**
		 * Filters whether applying an item is a dangerous change.
		 *
		 * @param bool   $dangerous Whether it is.
		 * @param string $id        Item ID.
		 * @param mixed  $before    Live value.
		 * @param mixed  $after     Value to write.
		 */
		$dangerous = (bool) apply_filters( 'axle_config_sync_is_dangerous', '' !== $reason, $id, $before, $after );

		if ( ! $dangerous ) {
			return '';
		}

		return '' !== $reason ? $reason : Messages::get( 'dangerous_change' );
	}
}
