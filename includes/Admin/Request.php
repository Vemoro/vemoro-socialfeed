<?php
namespace LocalInstagramFeed\Admin;

final class Request {
	public static function query( string $key ): string {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
		if ( null === $value && isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth callbacks are state-validated and sanitized below.
			$value = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on return.
		}
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	public static function post( string $key ): string {
		$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
		if ( null === $value && isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callers verify the nonce; value is sanitized below.
			$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on return.
		}
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}
