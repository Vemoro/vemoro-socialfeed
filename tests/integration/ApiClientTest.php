<?php
use LocalInstagramFeed\Api\InstagramApiClient;
use LocalInstagramFeed\Api\TokenService;
use LocalInstagramFeed\Config;
use LocalInstagramFeed\Repository\LogRepository;
use LocalInstagramFeed\Security\SecretStore;

final class ApiClientTest extends WP_UnitTestCase {
	protected function tearDown(): void { remove_all_filters('pre_http_request');parent::tearDown(); }
	public function test_maps_paginated_mock_without_real_meta_request(): void { $store=new SecretStore();$store->store('access_token','test-token');update_option(Config::TOKEN_OPTION,array('user_id'=>'123','expires_at'=>time()+DAY_IN_SECONDS));add_filter('pre_http_request',static fn()=>array('headers'=>array(),'body'=>file_get_contents(dirname(__DIR__).'/fixtures/paginated-media.json'),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null));$client=new InstagramApiClient(new TokenService($store,new LogRepository()));$result=$client->media(10,2);$this->assertCount(2,$result['items']);$this->assertTrue($result['complete']); }
	public function test_normalizes_api_error(): void { $this->expectException(LocalInstagramFeed\Api\ApiException::class);$store=new SecretStore();$store->store('access_token','test-token');update_option(Config::TOKEN_OPTION,array('user_id'=>'123','expires_at'=>time()+DAY_IN_SECONDS));add_filter('pre_http_request',static fn()=>array('headers'=>array(),'body'=>file_get_contents(dirname(__DIR__).'/fixtures/api-error.json'),'response'=>array('code'=>429,'message'=>'Too Many Requests'),'cookies'=>array(),'filename'=>null));(new InstagramApiClient(new TokenService($store,new LogRepository())))->media(10,2); }
}
