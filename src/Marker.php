<?php
/**
 * A portable reference or a secret placeholder inside a value.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync;

defined( 'ABSPATH' ) || exit;

/**
 * Stands in for an ID (`{"$ref":"post",...}`) or a secret (`{"$secret":"NAME"}`)
 * while a value travels between the site and a file.
 *
 * A secret marker may carry a fingerprint of the value it stands for. The
 * fingerprint only goes into the hashes that tell whether two sides differ,
 * never into a file or any output.
 */
final class Marker {

	public const REF    = 'ref';
	public const SECRET = 'secret';

	/**
	 * Kind: ref or secret.
	 *
	 * @var string
	 */
	public string $kind;

	/**
	 * For a ref: `$ref` (post, term, attachment, menu) and its fields. For a secret: the name.
	 *
	 * @var array<string, mixed>
	 */
	public array $data;

	/**
	 * A hash of the secret value, for comparisons only.
	 *
	 * @var string
	 */
	public string $fingerprint = '';

	/**
	 * Builds a marker.
	 *
	 * @param string $kind ref or secret.
	 * @param array  $data Fields.
	 */
	public function __construct( string $kind, array $data ) {
		$this->kind = $kind;
		$this->data = $data;
	}

	/**
	 * A reference marker.
	 *
	 * @param string $type   post, term, attachment or menu.
	 * @param array  $fields What finds the object on another site.
	 * @return self
	 */
	public static function ref( string $type, array $fields ): self {
		unset( $fields['$ref'] );
		return new self( self::REF, array_merge( array( '$ref' => $type ), $fields ) );
	}

	/**
	 * A secret marker.
	 *
	 * @param string $name        Secret name, such as ACME_API_KEY.
	 * @param string $fingerprint Hash of the value, for comparisons.
	 * @return self
	 */
	public static function secret( string $name, string $fingerprint = '' ): self {
		$marker              = new self( self::SECRET, array( 'name' => $name ) );
		$marker->fingerprint = $fingerprint;
		return $marker;
	}

	/**
	 * The secret name, or empty for a ref.
	 *
	 * @return string
	 */
	public function name(): string {
		return self::SECRET === $this->kind ? (string) ( $this->data['name'] ?? '' ) : '';
	}

	/**
	 * The ref type, or empty for a secret.
	 *
	 * @return string
	 */
	public function type(): string {
		return self::REF === $this->kind ? (string) ( $this->data['$ref'] ?? '' ) : '';
	}

	/**
	 * The fields of a ref, without its type.
	 *
	 * @return array<string, mixed>
	 */
	public function fields(): array {
		$fields = $this->data;
		unset( $fields['$ref'] );
		return $fields;
	}

	/**
	 * The JSON form.
	 *
	 * @param bool $canonical Add the fingerprint, for hashing.
	 * @return array
	 */
	public function to_array( bool $canonical = false ): array {
		if ( self::SECRET === $this->kind ) {
			$out = array( '$secret' => $this->name() );
			if ( $canonical && '' !== $this->fingerprint ) {
				$out['#'] = $this->fingerprint;
			}
			return $out;
		}

		$fields = $this->fields();
		ksort( $fields, SORT_STRING );

		return array_merge( array( '$ref' => $this->type() ), $fields );
	}

	/**
	 * Builds a marker from its JSON form.
	 *
	 * @param array $data Decoded JSON object with `$ref` or `$secret`.
	 * @return self|null Null when the form is not valid.
	 */
	public static function from_array( array $data ): ?self {
		if ( isset( $data['$secret'] ) && is_string( $data['$secret'] ) ) {
			$name = Secrets::sanitize_name( $data['$secret'] );
			return '' === $name ? null : self::secret( $name );
		}

		if ( isset( $data['$ref'] ) && is_string( $data['$ref'] ) ) {
			$fields = array();
			foreach ( $data as $key => $value ) {
				if ( '$ref' !== $key && is_scalar( $value ) ) {
					$fields[ (string) $key ] = (string) $value;
				}
			}
			return self::ref( sanitize_key( $data['$ref'] ), $fields );
		}

		return null;
	}

	/**
	 * A short readable form, for messages and the screen.
	 *
	 * @return string
	 */
	public function describe(): string {
		if ( self::SECRET === $this->kind ) {
			return '$secret:' . $this->name();
		}

		$fields = $this->fields();
		ksort( $fields, SORT_STRING );

		return '$ref:' . $this->type() . ':' . implode( ':', array_map( 'strval', $fields ) );
	}
}
