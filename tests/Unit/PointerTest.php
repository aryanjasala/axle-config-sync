<?php
/**
 * Tests for JSON pointers.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Pointer;

/**
 * Covers Pointer.
 */
final class PointerTest extends SyncTestCase {

	public function test_get_and_set_by_pointer(): void {
		$value = array( 'a' => array( 'b/c' => array( 1, 2 ) ) );

		$this->assertSame( 2, Pointer::get( $value, '/a/b~1c/1', $found ) );
		$this->assertTrue( $found );
		$this->assertNull( Pointer::get( $value, '/a/x', $found ) );
		$this->assertFalse( $found );
		$this->assertSame( $value, Pointer::get( $value, '' ) );

		$set = Pointer::set( $value, '/a/new/deep', 'v' );
		$this->assertSame( 'v', $set['a']['new']['deep'] );
		$this->assertSame( 'whole', Pointer::set( $value, '', 'whole' ) );

		$object    = new \stdClass();
		$object->x = 1;
		$copy      = Pointer::set( $object, '/x', 2 );
		$this->assertSame( 1, $object->x, 'The original is not changed.' );
		$this->assertSame( 2, $copy->x );
	}

	public function test_flatten_lists_every_leaf(): void {
		$this->assertSame(
			array(
				'/a/0' => 1,
				'/b'   => 'x',
			),
			Pointer::flatten(
				array(
					'a' => array( 1 ),
					'b' => 'x',
				)
			)
		);
		$this->assertSame( array( '/' => 'scalar' ), Pointer::flatten( 'scalar' ) );
	}
}
