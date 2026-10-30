<?php
/**
 * Tests for value encoding.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Codec;
use AryanX\Axle\ConfigSync\Marker;
use AryanX\Axle\ConfigSync\UnsupportedValue;
use Brain\Monkey\Filters;

/**
 * Covers Codec and Marker.
 */
final class CodecTest extends SyncTestCase {

	/**
	 * Through JSON text and back.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function round_trip( $value ) {
		return Codec::decode( Codec::from_json( Codec::to_json( array( 'value' => Codec::encode( $value ) ) ) )['value'] );
	}

	public function test_scalars_keep_their_types(): void {
		$this->assertSame( 1, $this->round_trip( 1 ) );
		$this->assertSame( '1', $this->round_trip( '1' ) );
		$this->assertSame( 1.0, $this->round_trip( 1.0 ) );
		$this->assertSame( 0.5, $this->round_trip( 0.5 ) );
		$this->assertTrue( $this->round_trip( true ) );
		$this->assertFalse( $this->round_trip( false ) );
		$this->assertNull( $this->round_trip( null ) );
		$this->assertSame( '', $this->round_trip( '' ) );
	}

	public function test_arrays_lists_and_sparse_keys_round_trip(): void {
		$this->assertSame( array( 'a', 'b' ), $this->round_trip( array( 'a', 'b' ) ) );
		$this->assertSame( array( 3 => 'x', 7 => 'y' ), $this->round_trip( array( 3 => 'x', 7 => 'y' ) ) );
		$this->assertSame( array(), $this->round_trip( array() ) );
		$this->assertSame( array( 'a' => array( 'b' => 1 ) ), $this->round_trip( array( 'a' => array( 'b' => 1 ) ) ) );
	}

	public function test_std_class_is_tagged_and_comes_back(): void {
		$object       = new \stdClass();
		$object->name = 'x';
		$object->list = array( 1, 2 );

		$encoded = Codec::encode( $object );
		$this->assertSame( 'stdClass', $encoded['$object'] );

		$back = $this->round_trip( $object );
		$this->assertInstanceOf( \stdClass::class, $back );
		$this->assertSame( 'x', $back->name );
		$this->assertSame( array( 1, 2 ), $back->list );
	}

	public function test_other_objects_are_refused(): void {
		$this->expectException( UnsupportedValue::class );
		Codec::encode( array( 'when' => new \DateTime() ) );
	}

	public function test_nan_is_refused(): void {
		$this->expectException( UnsupportedValue::class );
		Codec::encode( NAN );
	}

	public function test_a_tagged_object_of_another_class_never_becomes_that_class(): void {
		$back = Codec::decode(
			array(
				'$object' => 'WP_User',
				'value'   => array( 'ID' => 1 ),
			)
		);

		$this->assertInstanceOf( \stdClass::class, $back );
		$this->assertFalse( isset( $back->ID ) );
	}

	public function test_a_serialized_object_in_a_file_stays_a_string(): void {
		$text = 'O:8:"stdClass":1:{s:1:"a";s:1:"b";}';

		$this->assertSame( $text, Codec::decode( Codec::from_json( (string) json_encode( array( 'v' => $text ) ) )['v'] ) );
	}

	public function test_arrays_with_tag_keys_are_wrapped(): void {
		$value = array(
			'$ref' => 'not a reference',
			'x'    => 1,
		);

		$encoded = Codec::encode( $value );
		$this->assertArrayHasKey( '$array', $encoded );
		$this->assertSame( $value, Codec::decode( $encoded ) );
	}

	public function test_binary_strings_survive(): void {
		$bytes = "\xff\xfe\x00raw";

		$this->assertSame( array( '$binary' => base64_encode( $bytes ) ), Codec::encode( $bytes ) );
		$this->assertSame( $bytes, $this->round_trip( $bytes ) );
	}

	public function test_markers_encode_to_their_tags(): void {
		$ref = Marker::ref(
			'post',
			array(
				'type' => 'page',
				'path' => 'about',
			)
		);
		$this->assertSame(
			array(
				'$ref' => 'post',
				'path' => 'about',
				'type' => 'page',
			),
			Codec::encode( $ref )
		);

		$secret = Marker::secret( 'ACME_API_KEY', 'abc' );
		$this->assertSame( array( '$secret' => 'ACME_API_KEY' ), Codec::encode( $secret ) );
		$this->assertSame(
			array(
				'$secret' => 'ACME_API_KEY',
				'#'       => 'abc',
			),
			Codec::encode( $secret, true ),
			'The fingerprint only goes into hashes.'
		);

		$back = Codec::decode( array( '$secret' => 'acme api-key' ) );
		$this->assertInstanceOf( Marker::class, $back );
		$this->assertSame( 'ACME_API_KEY', $back->name() );
		$this->assertSame( '$ref:post:about:page', Codec::decode( Codec::encode( $ref ) )->describe() );
	}

	public function test_keys_are_sorted_and_the_hash_ignores_order(): void {
		$json = Codec::to_json(
			Codec::encode(
				array(
					'b' => 1,
					'a' => 2,
				)
			)
		);

		$this->assertLessThan( strpos( $json, '"b"' ), strpos( $json, '"a"' ) );
		$this->assertStringEndsWith( "\n", $json );
		$this->assertTrue(
			Codec::same(
				array(
					'a' => 1,
					'b' => 2,
				),
				array(
					'b' => 2,
					'a' => 1,
				)
			)
		);
		$this->assertFalse( Codec::same( 1, '1' ) );
	}

	public function test_the_json_flags_filter_keeps_zero_fractions(): void {
		Filters\expectApplied( 'axle_config_sync_file_json_flags' )->once()->andReturn( 0 );

		$this->assertSame( "{\"a\":1.0}\n", Codec::to_json( array( 'a' => 1.0 ) ) );
	}

	public function test_bad_json_reads_as_null(): void {
		$this->assertNull( Codec::from_json( 'not json' ) );
		$this->assertNull( Codec::from_json( '"just a string"' ) );
	}

	public function test_pretty_never_throws(): void {
		$this->assertStringContainsString( 'Unsupported value', Codec::pretty( new \DateTime() ) );
	}
}
