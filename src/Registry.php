<?php
/**
 * The providers, and which one owns an item.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

use AryanX\Axle\ConfigSync\Providers\Menus;
use AryanX\Axle\ConfigSync\Providers\Navigation;
use AryanX\Axle\ConfigSync\Providers\Options;
use AryanX\Axle\ConfigSync\Providers\Provider;
use AryanX\Axle\ConfigSync\Providers\Roles;
use AryanX\Axle\ConfigSync\Providers\ThemeMods;
use AryanX\Axle\ConfigSync\Providers\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the providers once, and keeps the ones the tracking rules switch on.
 */
final class Registry {

	public const DEFAULT_PROVIDERS = array( 'options', 'theme-mods', 'widgets', 'menus', 'navigation', 'roles' );

	/**
	 * Tracking rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Every provider, by name.
	 *
	 * @var array<string, Provider>|null
	 */
	private ?array $providers = null;

	/**
	 * Sets up the registry.
	 *
	 * @param Rules $rules Tracking rules.
	 */
	public function __construct( Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Every provider, on or off, by name.
	 *
	 * @return array<string, Provider>
	 */
	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		$defaults = array(
			'options'    => new Options( $this->rules ),
			'theme-mods' => new ThemeMods(),
			'widgets'    => new Widgets(),
			'menus'      => new Menus(),
			'navigation' => new Navigation(),
			'roles'      => new Roles(),
		);

		/**
		 * Filters the providers, by name. Add a class that implements
		 * AryanX\Axle\ConfigSync\Providers\Provider to sync settings that live
		 * somewhere else, such as a plugin's own table.
		 *
		 * @param array<string, Provider> $providers Providers.
		 */
		$filtered = Messages::as_array( apply_filters( 'axle_config_sync_providers', $defaults ) );

		$this->providers = array();
		foreach ( $filtered as $provider ) {
			if ( $provider instanceof Provider ) {
				$this->providers[ $provider->name() ] = $provider;
			}
		}

		return $this->providers;
	}

	/**
	 * The providers that are on.
	 *
	 * @return array<string, Provider>
	 */
	public function enabled(): array {
		return array_filter(
			$this->all(),
			function ( Provider $provider ): bool {
				return $this->rules->provider_on( $provider->name() );
			}
		);
	}

	/**
	 * The provider that owns an item, when it is on.
	 *
	 * @param string $id Item ID.
	 * @return Provider|null
	 */
	public function for_item( string $id ): ?Provider {
		foreach ( $this->enabled() as $provider ) {
			if ( $provider->owns( $id ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * The item ID of a file in the config directory, or null.
	 *
	 * @param string $relative File path.
	 * @return string|null
	 */
	public function id_for_file( string $relative ): ?string {
		foreach ( $this->enabled() as $provider ) {
			$id = $provider->id_for_file( $relative );
			if ( null !== $id ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * The order providers are applied in. References need their targets to
	 * exist first: roles, then options, then theme mods, then menus and
	 * navigation, then widgets (which point at menus).
	 *
	 * @return string[]
	 */
	public static function order(): array {
		$default = array( 'roles', 'options', 'theme-mods', 'menus', 'navigation', 'widgets' );

		/**
		 * Filters the order providers are applied in, by name. Providers not
		 * listed go last.
		 *
		 * @param string[] $order Provider names.
		 */
		$order = array_values( array_map( 'strval', (array) apply_filters( 'axle_config_sync_apply_order', $default ) ) );

		return $order ? $order : $default;
	}

	/**
	 * Sorts item IDs into apply order. Within the menus provider the menus
	 * come before the locations that point at them.
	 *
	 * @param string[] $ids Item IDs.
	 * @return string[]
	 */
	public function sort( array $ids ): array {
		$order = array_flip( self::order() );
		$rank  = function ( string $id ) use ( $order ): array {
			$provider = $this->for_item( $id );
			$name     = $provider ? $provider->name() : '';
			return array( $order[ $name ] ?? 999, Providers\Menus::LOCATIONS === $id ? 1 : 0, $id );
		};

		usort(
			$ids,
			static function ( string $a, string $b ) use ( $rank ): int {
				return $rank( $a ) <=> $rank( $b );
			}
		);

		return $ids;
	}
}
