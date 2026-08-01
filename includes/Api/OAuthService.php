<?php
namespace LocalInstagramFeed\Api;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never rendered directly and are escaped by their presentation boundary.

use LocalInstagramFeed\Config;

final class OAuthService {
	private const STATE_TTL = 600;

	public function authorizationUrl( int $userId ): string {
		if ( ! Config::usesHostedOAuth() && ( ! Config::appId() || ! Config::appSecret() ) ) {
			throw new \RuntimeException( __( 'Configure the Meta App ID and App Secret first.', 'vemoro-socialfeed' ) ); }
		$state = bin2hex( random_bytes( 32 ) );
		set_transient( 'lif_oauth_state_' . $userId, hash( 'sha256', $state ), self::STATE_TTL );
		if ( Config::usesHostedOAuth() ) {
			return Config::connectUrl() . '/v1/instagram/authorize?' . http_build_query(
				array(
					'callback_url'   => Config::redirectUri(),
					'state'          => $state,
					'plugin_version' => LIF_VERSION,
					'site'           => home_url( '/' ),
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			);
		}
		return 'https://www.instagram.com/oauth/authorize?' . http_build_query(
			array(
				'client_id'            => Config::appId(),
				'redirect_uri'         => Config::redirectUri(),
				'response_type'        => 'code',
				'scope'                => 'instagram_business_basic',
				'state'                => $state,
				'enable_fb_login'      => '0',
				'force_authentication' => '1',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	public function validateState( int $userId, string $state ): bool {
		$key      = 'lif_oauth_state_' . $userId;
		$expected = get_transient( $key );
		delete_transient( $key );
		return is_string( $expected ) && strlen( $state ) >= 32 && hash_equals( $expected, hash( 'sha256', $state ) );
	}

	public function hasPendingState( int $userId ): bool {
		return is_string( get_transient( 'lif_oauth_state_' . $userId ) );
	}

	public function isHosted(): bool {
		return Config::usesHostedOAuth();
	}

	/** @return array{access_token:string,user_id:string,expires_in:int} */
	public function exchangeCode( string $code ): array {
		if ( Config::usesHostedOAuth() ) {
			$response = wp_remote_post(
				Config::connectUrl() . '/v1/instagram/token',
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'headers'     => array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					),
					'body'        => wp_json_encode(
						array(
							'grant_code'   => $code,
							'callback_url' => Config::redirectUri(),
						)
					),
				)
			);
			return $this->tokenResponse( $response, __( 'Vemoro could not complete the Instagram connection.', 'vemoro-socialfeed' ) );
		}
		$response = wp_remote_post(
			'https://api.instagram.com/oauth/access_token',
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => Config::appId(),
					'client_secret' => Config::appSecret(),
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => Config::redirectUri(),
					'code'          => $code,
				),
			)
		);
		return $this->tokenResponse( $response, __( 'Could not exchange the Instagram authorization code.', 'vemoro-socialfeed' ) );
	}

	/** @param array|\WP_Error $response @return array{access_token:string,user_id:string,expires_in:int} */
	private function tokenResponse( array|\WP_Error $response, string $fallback ): array {
		if ( is_wp_error( $response ) ) {
			throw new ApiException( sanitize_text_field( $response->get_error_message() ), 0, 0, true ); }
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			throw new ApiException( sanitize_text_field( (string) ( $data['error_message'] ?? $data['error']['message'] ?? $fallback ) ), $status );
		}
		return array(
			'access_token' => (string) $data['access_token'],
			'user_id'      => sanitize_text_field( (string) ( $data['user_id'] ?? '' ) ),
			'expires_in'   => (int) ( $data['expires_in'] ?? 3600 ),
		);
	}
}
