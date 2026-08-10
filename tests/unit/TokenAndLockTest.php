<?php
use Vemoro\SocialFeed\Api\TokenService;
use Vemoro\SocialFeed\Config;
use Vemoro\SocialFeed\Repository\LogRepository;
use Vemoro\SocialFeed\Security\SecretStore;
use Vemoro\SocialFeed\Sync\SyncLock;

final class TokenAndLockTest extends WP_UnitTestCase {
	public function test_secret_round_trip_and_delete(): void { $store=new SecretStore();$this->assertTrue($store->available());$store->store('test','sensitive');$this->assertSame('sensitive',$store->get('test'));$store->delete('test');$this->assertNull($store->get('test')); }
	public function test_token_refresh_decision(): void { $store=new SecretStore();$store->store('access_token','token');update_option(Config::TOKEN_OPTION,array('user_id'=>'123','expires_at'=>time()+6*DAY_IN_SECONDS));$tokens=new TokenService($store,new LogRepository());$this->assertTrue($tokens->needsRefresh());update_option(Config::TOKEN_OPTION,array('user_id'=>'123','expires_at'=>time()+20*DAY_IN_SECONDS));$this->assertFalse($tokens->needsRefresh()); }
	public function test_lock_excludes_parallel_run(): void { $first=new SyncLock();$second=new SyncLock();$this->assertTrue($first->acquire());$this->assertFalse($second->acquire());$first->release();$this->assertTrue($second->acquire());$second->release(); }
}
