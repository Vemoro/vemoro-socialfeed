<?php
use LocalInstagramFeed\Api\OAuthService;
use LocalInstagramFeed\Config;

final class HostedOAuthTest extends WP_UnitTestCase {
	protected function tearDown(): void {
		remove_all_filters('pre_http_request');
		delete_option(Config::OPTION);
		parent::tearDown();
	}

	public function test_hosted_authorization_uses_vemoro_without_meta_credentials(): void {
		update_option(Config::OPTION, array('oauth_provider' => 'vemoro'));
		$url = (new OAuthService())->authorizationUrl(1);
		$this->assertSame('connect.vemoro.de', wp_parse_url($url, PHP_URL_HOST));
		$this->assertStringContainsString('callback_url=', $url);
		$this->assertStringNotContainsString('client_secret', $url);
	}

	public function test_hosted_grant_is_exchanged_server_side(): void {
		update_option(Config::OPTION, array('oauth_provider' => 'vemoro'));
		add_filter('pre_http_request', function($preempt, array $args, string $url) {
			$this->assertSame('https://connect.vemoro.de/v1/instagram/token', $url);
			$this->assertSame('grant-value', $args['body']['grant_code']);
			return array('headers'=>array(),'body'=>wp_json_encode(array('access_token'=>'long-token','user_id'=>'123','expires_in'=>5184000)),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
		}, 10, 3);
		$result = (new OAuthService())->exchangeCode('grant-value');
		$this->assertSame('long-token', $result['access_token']);
		$this->assertSame('123', $result['user_id']);
	}
}
