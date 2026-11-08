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
	public function test_interleaved_acquisitions_cannot_both_succeed(): void {
		$first = new SyncLock();
		$second = new SyncLock();
		$secondResult = null;
		$interleave = null;
		$queryInterleave = null;
		$interleave = static function ( $value ) use ( &$interleave, &$queryInterleave, $second, &$secondResult ) {
			remove_filter( 'pre_set_transient_' . Config::LOCK_KEY, $interleave );
			remove_filter( 'query', $queryInterleave );
			$secondResult = $second->acquire();
			return $value;
		};
		$queryInterleave = static function ( $query ) use ( $interleave ) {
			return str_starts_with( $query, 'INSERT IGNORE' ) && str_contains( $query, Config::LOCK_KEY ) ? $interleave( $query ) : $query;
		};
		add_filter( 'pre_set_transient_' . Config::LOCK_KEY, $interleave );
		add_filter( 'query', $queryInterleave );
		try {
			$firstResult = $first->acquire();
			$this->assertNotNull( $secondResult );
			$this->assertTrue( $firstResult xor $secondResult );
			$loser = $firstResult ? $second : $first;
			$winner = $firstResult ? $first : $second;
			$loser->release();
			$this->assertFalse( ( new SyncLock() )->acquire() );
			$winner->refresh();
			$this->assertFalse( ( new SyncLock() )->acquire() );
		} finally {
			remove_filter( 'pre_set_transient_' . Config::LOCK_KEY, $interleave );
			remove_filter( 'query', $queryInterleave );
			$first->release();
			$second->release();
		}
	}
}
