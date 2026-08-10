<?php
use Vemoro\SocialFeed\Api\InstagramApiClient;
use Vemoro\SocialFeed\Api\TokenService;
use Vemoro\SocialFeed\Config;
use Vemoro\SocialFeed\Repository\LogRepository;
use Vemoro\SocialFeed\Security\SecretStore;

final class ApiClientTest extends WP_UnitTestCase {
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown(); }
	public function test_maps_paginated_mock_without_real_meta_request(): void {
		$store = new SecretStore();
		$store->store( 'access_token', 'test-token' );
		update_option(
			Config::TOKEN_OPTION,
			array(
				'user_id' => '123',
				'expires_at' => time() + DAY_IN_SECONDS,
			)
		);
		$requestedUrl = '';
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requestedUrl ) {
				$requestedUrl = $url;
				return array(
					'headers'  => array(),
					'body'     => file_get_contents( dirname( __DIR__ ) . '/fixtures/paginated-media.json' ),
					'response' => array(
						'code' => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
		$client = new InstagramApiClient( new TokenService( $store, new LogRepository() ) );
		$result = $client->media( 10, 2 );
		$this->assertStringContainsString( '/v25.0/me/media?', $requestedUrl );
		$this->assertCount( 2, $result['items'] );
		$this->assertTrue( $result['complete'] );
	}
	public function test_profile_uses_the_token_bound_me_endpoint(): void {
		$store = new SecretStore();
		$store->store( 'access_token', 'test-token' );
		update_option(
			Config::TOKEN_OPTION,
			array(
				'user_id' => '123',
				'expires_at' => time() + DAY_IN_SECONDS,
			)
		);
		$requestedUrl = '';
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requestedUrl ) {
				$requestedUrl = $url;
				return array(
					'headers'  => array(),
					'body'     => '{"id":"123","username":"example"}',
					'response' => array(
						'code' => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
		$profile = ( new InstagramApiClient( new TokenService( $store, new LogRepository() ) ) )->profile();
		$this->assertStringContainsString( '/v25.0/me?', $requestedUrl );
		$this->assertSame( 'example', $profile['username'] );
	}
	public function test_normalizes_api_error(): void {
		$this->expectException( Vemoro\SocialFeed\Api\ApiException::class );
		$store = new SecretStore();
		$store->store( 'access_token', 'test-token' );
		update_option(
			Config::TOKEN_OPTION,
			array(
				'user_id' => '123',
				'expires_at' => time() + DAY_IN_SECONDS,
			)
		);
		add_filter(
			'pre_http_request',
			static fn()=>array(
				'headers' => array(),
				'body' => file_get_contents( dirname( __DIR__ ) . '/fixtures/api-error.json' ),
				'response' => array(
					'code' => 429,
					'message' => 'Too Many Requests',
				),
				'cookies' => array(),
				'filename' => null,
			)
		);
		( new InstagramApiClient( new TokenService( $store, new LogRepository() ) ) )->media( 10, 2 ); }
}
