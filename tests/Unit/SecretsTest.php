<?php
/**
 * Tests for secret detection and resolution.
 *
 * @package AryanX\Axle\ConfigSync
 */

namespace AryanX\Axle\ConfigSync\Tests\Unit;

use AryanX\Axle\ConfigSync\Directory;
use AryanX\Axle\ConfigSync\Marker;
use AryanX\Axle\ConfigSync\Rules;
use AryanX\Axle\ConfigSync\Secrets;
use Brain\Monkey\Filters;

/**
 * Covers Secrets.
 */
final class SecretsTest extends SyncTestCase {

	/**
	 * Secrets with rules over the temp directory.
	 *
	 * @param array $rules Rules to save first.
	 * @return Secrets
	 */
	private function secrets( array $rules = array() ): Secrets {
		Filters\expectApplied( 'axle_config_sync_directory' )->zeroOrMoreTimes()->andReturn( $this->dir );
		if ( $rules ) {
			$this->put( 'config-sync.json', $rules );
		}

		return new Secrets( new Rules( new Directory() ) );
	}

	public function test_key_words_match_whole_words_only(): void {
		$this->assertTrue( Secrets::key_matches( 'options/acme_api_key', '' ) );
		$this->assertTrue( Secrets::key_matches( 'options/mailer', '/smtpPassword' ) );
		$this->assertTrue( Secrets::key_matches( 'options/x', '/license_keys' ) );
		$this->assertFalse( Secrets::key_matches( 'options/author_box', '' ), '"author" is not "auth".' );
		$this->assertFalse( Secrets::key_matches( 'options/rapid_mode', '' ), '"rapid" is not "api".' );
	}

	public function test_values_under_secret_keys_need_some_length(): void {
		$secrets = $this->secrets();

		$this->assertTrue( $secrets->is_secret( 'options/acme_api_key', '', 'sk_live_12345678' ) );
		$this->assertFalse( $secrets->is_secret( 'options/license_status', '', 'valid' ), 'A short flag is not a secret.' );
		$this->assertFalse( $secrets->is_secret( 'options/acme_api_key', '', 123456789 ), 'Only strings.' );
		$this->assertFalse( $secrets->is_secret( 'options/moderation_keys', '', 'spamword-longer' ), 'Core comment word lists are not secrets.' );
	}

	public function test_random_looking_values_are_secrets_anywhere(): void {
		$secrets = $this->secrets();

		$this->assertTrue( $secrets->is_secret( 'options/acme_settings', '/endpoint_id', 'x7Gq9LmP2vR8sT4wK1zN6bY3cH5jD0fQ' ) );
		$this->assertFalse( $secrets->is_secret( 'options/blogdescription', '', 'Just another WordPress site with a long tagline' ) );
		$this->assertFalse( Secrets::looks_random( 'https://example.com/a-very-long-path/that-goes-on-123' ) );
		$this->assertFalse( Secrets::looks_random( 'twentytwentyfivechildthemeforthesite' ), 'No digits.' );
	}

	public function test_not_a_secret_marks_win(): void {
		$secrets = $this->secrets( array( 'not_secret' => array( 'options/acme_api_key', 'options/acme#/token' ) ) );

		$this->assertFalse( $secrets->is_secret( 'options/acme_api_key', '', 'sk_live_12345678' ) );
		$this->assertFalse( $secrets->is_secret( 'options/acme', '/token', 'sk_live_12345678' ) );
		$this->assertTrue( $secrets->is_secret( 'options/acme', '/other_token', 'sk_live_12345678' ) );
	}

	public function test_the_is_secret_filter_has_the_last_word(): void {
		$secrets = $this->secrets();
		Filters\expectApplied( 'axle_config_sync_is_secret' )->once()->with( false, 'options/blogname', '', 'My site' )->andReturn( true );

		$this->assertTrue( $secrets->is_secret( 'options/blogname', '', 'My site' ) );
	}

	public function test_the_key_patterns_filter_adds_words(): void {
		Filters\expectApplied( 'axle_config_sync_secret_key_patterns' )->once()->andReturnUsing(
			static function ( array $words ) {
				$words[] = 'pin';
				return $words;
			}
		);

		$this->assertTrue( Secrets::key_matches( 'options/door_pin', '' ) );
	}

	public function test_mask_replaces_secrets_with_named_markers(): void {
		$masked = $this->secrets()->mask(
			'options/mailchimp',
			array(
				'api_key' => 'abc123def456',
				'list'    => 'Newsletter',
			)
		);

		$this->assertInstanceOf( Marker::class, $masked['api_key'] );
		$this->assertSame( 'MAILCHIMP_API_KEY', $masked['api_key']->name() );
		$this->assertNotSame( '', $masked['api_key']->fingerprint );
		$this->assertSame( 'Newsletter', $masked['list'] );
		$this->assertSame( array(), $this->secrets()->find( 'options/mailchimp', $masked ) );
	}

	public function test_names_come_from_the_item_and_path(): void {
		$this->assertSame( 'ACME_API_KEY', Secrets::name_for( 'options/acme_api_key', '' ) );
		$this->assertSame( 'MAILCHIMP_API_KEY', Secrets::name_for( 'options/mailchimp', '/api-key' ) );
	}

	public function test_resolve_reads_the_constant_then_the_environment_then_the_filter(): void {
		$this->assertSame( 'constant-value', Secrets::resolve( 'FROM_CONSTANT' ) );

		putenv( 'AXLE_SECRET_FROM_ENV=env-value' );
		$this->assertSame( 'env-value', Secrets::resolve( 'FROM_ENV' ) );
		putenv( 'AXLE_SECRET_FROM_ENV' );

		Filters\expectApplied( 'axle_config_sync_secret' )->once()->with( null, 'FROM_VAULT' )->andReturn( 'vault-value' );
		$this->assertSame( 'vault-value', Secrets::resolve( 'FROM_VAULT' ) );
	}

	public function test_an_unresolved_secret_keeps_the_live_value_or_blocks(): void {
		$problems = array();
		$value    = $this->secrets()->resolve_all(
			'options/acme',
			array(
				'key'   => Marker::secret( 'NOT_SET' ),
				'token' => Marker::secret( 'ALSO_NOT_SET' ),
				'set'   => Marker::secret( 'FROM_CONSTANT' ),
			),
			array( 'key' => 'live-key' ),
			$problems
		);

		$this->assertSame( 'live-key', $value['key'] );
		$this->assertInstanceOf( Marker::class, $value['token'] );
		$this->assertSame( 'constant-value', $value['set'] );
		$this->assertCount( 2, $problems );
		$this->assertFalse( $problems[0]['block'] );
		$this->assertTrue( $problems[1]['block'] );
		$this->assertSame( array( array( 'options/acme', 'NOT_SET' ), array( 'options/acme', 'ALSO_NOT_SET' ) ), $this->fired['axle_config_sync_secret_unresolved'] );
		$this->assertSame( array( 'NOT_SET' ), Secrets::unresolved( array( Marker::secret( 'NOT_SET' ), Marker::secret( 'FROM_CONSTANT' ) ) ) );
	}

	public function test_fingerprints_follow_the_resolved_value(): void {
		$file = Secrets::fingerprint_all( array( 'k' => Marker::secret( 'FROM_CONSTANT' ) ), array( 'k' => 'live' ) );
		$this->assertSame( Secrets::fingerprint( 'constant-value' ), $file['k']->fingerprint );

		$kept = Secrets::fingerprint_all( array( 'k' => Marker::secret( 'NOT_SET' ) ), array( 'k' => 'live' ) );
		$this->assertSame( Secrets::fingerprint( 'live' ), $kept['k']->fingerprint );
	}

	public function test_entropy(): void {
		$this->assertSame( 0.0, Secrets::entropy( 'aaaa' ) );
		$this->assertGreaterThan( 3.5, Secrets::entropy( 'x7Gq9LmP2vR8sT4wK1zN6bY3cH5jD0fQ' ) );
	}
}
