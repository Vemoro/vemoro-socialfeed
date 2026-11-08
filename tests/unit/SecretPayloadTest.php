<?php
use Vemoro\SocialFeed\Security\SecretStore;

final class SecretPayloadTest extends WP_UnitTestCase {
	/** @dataProvider corruptPayloads */
	public function test_corrupt_payloads_return_null_instead_of_throwing( string $payload ): void {
		update_option( 'vemoro_secret_corrupt_test', $payload );
		$this->assertNull( ( new SecretStore() )->get( 'corrupt_test' ) );
	}

	public static function corruptPayloads(): array {
		return array(
			array( 'sodium:' . base64_encode( 'short' ) ),
			array( 'openssl:' . base64_encode( 'short' ) ),
			array( 'openssl:' ),
			array( 'sodium:invalid-base64' ),
		);
	}
}
