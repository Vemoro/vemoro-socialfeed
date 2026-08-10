<?php
namespace Vemoro\SocialFeed;

final class Config {
	public const OPTION                            = 'vemoro_settings';
	public const STATUS_OPTION                     = 'vemoro_status';
	public const TOKEN_OPTION                      = 'vemoro_token';
	public const DB_VERSION_OPTION                 = 'vemoro_db_version';
	public const DB_VERSION                        = '1.2.0';
	public const DEFAULT_API_VERSION               = 'v25.0';
	public const DEFAULT_CONNECT_URL               = 'https://connect.vemoro.de';
	public const POST_TYPE                         = 'vemoro_socialfeed';
	public const CRON_HOOK                         = 'vemoro_sync_instagram_feed';
	public const LOCK_KEY                          = 'vemoro_sync_lock';
	public const REFRESH_GENERATION_OPTION         = 'vemoro_refresh_generation';
	public const APPLIED_REFRESH_GENERATION_OPTION = 'vemoro_applied_refresh_generation';
	public const LIBERAPAY_URL                     = 'https://liberapay.com/vemoro/donate';
	public const GITHUB_SPONSORS_URL               = 'https://github.com/sponsors/vemoro';
	public const SUPPORT_EMAIL                     = 'support@vemoro.de';
	public const TERMS_VERSION                     = '2026-08-01';

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'oauth_provider'        => 'vemoro',
			'connect_url'           => self::DEFAULT_CONNECT_URL,
			'app_id'                => '',
			'app_secret'            => '',
			'redirect_uri'          => '',
			'api_version'           => self::DEFAULT_API_VERSION,
			'terms_accepted'        => false,
			'terms_accepted_at'     => 0,
			'terms_accepted_by'     => 0,
			'terms_version'         => '',
			'post_limit'            => 12,
			'sync_interval'         => 'vemoro_two_hours',
			'caption_length'        => 300,
			'excess_retention_days' => 30,
			'deleted_behavior'      => 'inactive',
			'missing_grace_hours'   => 0,
			'show_link'             => false,
			'new_tab'               => true,
			'show_reels'            => true,
			'show_carousels'        => true,
			'mirror_videos'         => true,
			'video_autoplay'        => true,
			'columns'               => 3,
			'columns_tablet'        => 2,
			'columns_mobile'        => 1,
			'aspect_ratio'          => '9/16',
			'show_caption'          => true,
			'show_date'             => true,
			'show_username'         => true,
			'show_metrics'          => true,
			'local_detail'          => false,
			'image_max_mb'          => 15,
			'video_max_mb'          => 100,
			'max_api_pages'         => 10,
			'cache_ttl'             => 7200,
			'log_limit'             => 500,
			'debug'                 => false,
			'delete_on_uninstall'   => false,
		);
	}

	/** @return array<string,mixed> */
	public static function settings(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		// Preserve working pre-2.0 installations as expert-mode connections.
		if ( ! isset( $value['oauth_provider'] ) && ( ! empty( $value['app_id'] ) || defined( 'VEMORO_INSTAGRAM_APP_ID' ) ) ) {
			$value['oauth_provider'] = 'custom';
		}
		return wp_parse_args( $value, self::defaults() );
	}

	public static function usesHostedOAuth(): bool {
		return 'vemoro' === (string) self::settings()['oauth_provider'];
	}

	public static function termsAccepted(): bool {
		$settings = self::settings();
		return ! empty( $settings['terms_accepted'] ) && self::TERMS_VERSION === (string) ( $settings['terms_version'] ?? '' );
	}

	public static function connectUrl(): string {
		if ( defined( 'VEMORO_CONNECT_URL' ) ) {
			return untrailingslashit( (string) VEMORO_CONNECT_URL );
		}
		$url = (string) self::settings()['connect_url'];
		return untrailingslashit( $url ?: self::DEFAULT_CONNECT_URL );
	}

	public static function appId(): string {
		return defined( 'VEMORO_INSTAGRAM_APP_ID' ) ? (string) VEMORO_INSTAGRAM_APP_ID : (string) self::settings()['app_id'];
	}

	public static function appSecret(): string {
		if ( defined( 'VEMORO_INSTAGRAM_APP_SECRET' ) ) {
			return (string) VEMORO_INSTAGRAM_APP_SECRET; }
		return (string) ( new \Vemoro\SocialFeed\Security\SecretStore() )->get( 'app_secret' );
	}

	public static function redirectUri(): string {
		// The hosted broker owns the public Meta callback. WordPress only needs a
		// stable local return endpoint; never carry a legacy admin-page slug into
		// a new hosted OAuth flow.
		if ( self::usesHostedOAuth() ) {
			return admin_url( 'admin.php' );
		}
		$configured = (string) ( self::settings()['redirect_uri'] ?? '' );
		$uri        = $configured ?: admin_url( 'admin.php' );
		return (string) preg_replace( '/[?#].*$/', '', $uri );
	}
}
