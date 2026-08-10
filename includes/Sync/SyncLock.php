<?php
namespace Vemoro\SocialFeed\Sync;

use Vemoro\SocialFeed\Config;

final class SyncLock {
	private string $token = '';
	public function acquire( int $ttl = 900 ): bool {
		if ( get_transient( Config::LOCK_KEY ) ) {
			return false; }
		$this->token = wp_generate_uuid4();
		set_transient( Config::LOCK_KEY, $this->token, $ttl );
		return hash_equals( $this->token, (string) get_transient( Config::LOCK_KEY ) );
	}
	public function refresh( int $ttl = 900 ): void {
		if ( $this->owns() ) {
			set_transient( Config::LOCK_KEY, $this->token, $ttl ); } }
	public function release(): void {
		if ( $this->owns() ) {
			delete_transient( Config::LOCK_KEY ); } }
	private function owns(): bool {
		return $this->token && hash_equals( $this->token, (string) get_transient( Config::LOCK_KEY ) ); }
}
