<?php
namespace Vemoro\SocialFeed\Repository;

use Vemoro\SocialFeed\Config;

final class LogRepository {
	/** @param array<string,mixed> $context */
	public function add( string $level, string $message, array $context = array() ): void {
		$settings = Config::settings();
		if ( 'debug' === $level && empty( $settings['debug'] ) ) {
			return; }
		global $wpdb;
		$clean = $this->redact( $context );
		$wpdb->insert(
			$wpdb->prefix . 'vemoro_logs',
			array(
				'created_at' => current_time( 'mysql', true ),
				'level'      => sanitize_key( $level ),
				'message'    => sanitize_text_field( $message ),
				'context'    => wp_json_encode( $clean ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		$limit = max( 10, min( 5000, (int) $settings['log_limit'] ) );
		$table = $wpdb->prefix . 'vemoro_logs';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id NOT IN (SELECT id FROM (SELECT id FROM %i ORDER BY id DESC LIMIT %d) vemoro_keep)', $table, $table, $limit ) );
	}

	/** @return array<int,object> */
	public function latest( int $limit = 100 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'vemoro_logs';
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, level, message, context FROM %i ORDER BY id DESC LIMIT %d', $table, max( 1, min( 500, $limit ) ) ) );
	}

	/** @param mixed $value @return mixed */
	private function redact( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}
			$clean = sanitize_text_field( (string) $value );
			$clean = (string) preg_replace( '/((?:access_token|refresh_token|client_secret|authorization|oauth_code|code_verifier)=)[^&\\s]+/i', '$1[redacted]', $clean );
			$clean = (string) preg_replace( '/Bearer\\s+[A-Za-z0-9._~-]+/i', 'Bearer [redacted]', $clean );
			return $clean;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$name        = strtolower( (string) $key );
			$out[ $key ] = preg_match( '/(^|_)(access_?token|refresh_?token|token|secret|authorization|oauth_?code|code_?verifier|grant)(_|$)/', $name ) ? '[redacted]' : $this->redact( $item );
		}
		return $out;
	}
}
