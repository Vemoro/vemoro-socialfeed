<?php
namespace LocalInstagramFeed\Diagnostics;

final class FailureTracker {
	private const DISPLAY_AFTER = 2;

	/** @param array<string,mixed> $status @return array<string,mixed> */
	public static function record( array $status, string $message, ?int $timestamp = null ): array {
		$fingerprint = hash( 'sha256', $message );
		$count       = hash_equals( (string) ( $status['failure_fingerprint'] ?? '' ), $fingerprint ) ? (int) ( $status['consecutive_failures'] ?? 0 ) + 1 : 1;

		$status['last_failure']        = $timestamp ?? time();
		$status['last_failure_error']  = sanitize_text_field( $message );
		$status['failure_fingerprint'] = $fingerprint;
		$status['consecutive_failures'] = $count;
		if ( $count >= self::DISPLAY_AFTER ) {
			$status['last_error'] = sanitize_text_field( $message );
		} else {
			unset( $status['last_error'] );
		}
		return $status;
	}

	/** @param array<string,mixed> $status @return array<string,mixed> */
	public static function clear( array $status ): array {
		unset( $status['last_error'], $status['last_failure'], $status['last_failure_error'], $status['failure_fingerprint'], $status['consecutive_failures'] );
		return $status;
	}
}
