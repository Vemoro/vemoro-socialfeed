<?php
namespace Vemoro\SocialFeed\Sync;

use Vemoro\SocialFeed\Config;

final class SyncLock {
	private string $value = '';
	public function acquire( int $ttl = 900 ): bool {
		// Honor a synchronization started by an older plugin version until it expires.
		if ( get_transient( Config::LOCK_KEY ) ) {
			return false; }
		$value = (string) wp_json_encode(
			array(
				'token' => wp_generate_uuid4(),
				'expires' => time() + max( 1, $ttl ),
			)
		);
		if ( $this->insertValue( $value ) ) {
			$this->value = $value;
			return true;
		}
		$existing = get_option( Config::LOCK_KEY );
		$data = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( is_string( $existing ) && (int) ( $data['expires'] ?? 0 ) <= time() && $this->deleteValue( $existing ) && $this->insertValue( $value ) ) {
			$this->value = $value;
			return true;
		}
		return false;
	}
	public function refresh( int $ttl = 900 ): void {
		if ( '' === $this->value ) {
			return;
		}
		$data = json_decode( $this->value, true );
		$data['expires'] = time() + max( 1, $ttl );
		$value = (string) wp_json_encode( $data );
		global $wpdb;
		if ( $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $value ),
			array(
				'option_name' => Config::LOCK_KEY,
				'option_value' => $this->value,
			),
			array( '%s' ),
			array( '%s', '%s' )
		) ) {
			$this->value = $value;
			$this->clearCache();
		}
	}
	public function release(): void {
		if ( '' !== $this->value ) {
			$this->deleteValue( $this->value );
			$this->value = '';
		}
	}
	private function deleteValue( string $value ): bool {
		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name' => Config::LOCK_KEY,
				'option_value' => $value,
			),
			array( '%s', '%s' )
		);
		$this->clearCache();
		return (bool) $deleted;
	}
	private function insertValue( string $value ): bool {
		global $wpdb;
		// add_option() can update a competing insert; INSERT IGNORE gives exactly one owner.
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, Config::LOCK_KEY, $value, 'no' ) );
		$this->clearCache();
		return 1 === $inserted;
	}
	private function clearCache(): void {
		wp_cache_delete( Config::LOCK_KEY, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
