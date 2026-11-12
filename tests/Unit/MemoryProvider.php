<?php
/**
 * An in-memory provider for engine tests.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Providers\Base;

/**
 * Items are keys in an array. Writing `boom` throws, to test rollback;
 * writing `sanitize me` stores it upper case, to test "applied with changes".
 */
class MemoryProvider extends Base {

	/**
	 * The provider name, `memory` unless a test needs another.
	 *
	 * @var string
	 */
	public string $providerName = 'memory';

	/**
	 * Live values by key.
	 *
	 * @var array<string, mixed>
	 */
	public array $store = array();

	/**
	 * Keys written, in order.
	 *
	 * @var string[]
	 */
	public array $writes = array();

	/**
	 * Called on every write, for tests that watch what happens during one.
	 *
	 * @var callable|null
	 */
	public $onWrite = null;

	public function name(): string {
		return $this->providerName;
	}

	public function title(): string {
		return 'Memory';
	}

	public function items(): array {
		return array_map(
			function ( $key ) {
				return $this->providerName . '/' . $key;
			},
			array_keys( $this->store )
		);
	}

	public function exists( string $id ): bool {
		return array_key_exists( $this->key( $id ), $this->store );
	}

	public function read( string $id ) {
		return $this->store[ $this->key( $id ) ] ?? null;
	}

	public function write( string $id, $value ): void {
		if ( 'boom' === $value ) {
			throw new \RuntimeException( 'Boom.' );
		}
		if ( null !== $this->onWrite ) {
			( $this->onWrite )();
		}
		$this->writes[] = $this->key( $id );
		if ( null === $value ) {
			unset( $this->store[ $this->key( $id ) ] );
			return;
		}
		$this->store[ $this->key( $id ) ] = 'sanitize me' === $value ? 'SANITIZE ME' : $value;
	}

	public function danger( string $id, $before, $after ): string {
		return 'danger' === $after ? 'Too risky.' : '';
	}
}
