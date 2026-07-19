<?php
namespace LocalInstagramFeed;

final class Config {
	public const OPTION = 'lif_settings';
	public const STATUS_OPTION = 'lif_status';
	public const TOKEN_OPTION = 'lif_token';
	public const DB_VERSION_OPTION = 'lif_db_version';
	public const DB_VERSION = '1.1.0';
	public const DEFAULT_API_VERSION = 'v25.0';
	public const POST_TYPE = 'lif_instagram_post';
	public const CRON_HOOK = 'lif_sync_instagram_feed';
	public const LOCK_KEY = 'lif_sync_lock';
	public const REFRESH_GENERATION_OPTION = 'lif_refresh_generation';
	public const APPLIED_REFRESH_GENERATION_OPTION = 'lif_applied_refresh_generation';

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'app_id' => '', 'app_secret' => '', 'redirect_uri' => '', 'api_version' => self::DEFAULT_API_VERSION,
			'post_limit' => 12, 'sync_interval' => 'lif_two_hours', 'caption_length' => 300, 'excess_retention_days' => 30,
			'deleted_behavior' => 'inactive', 'show_link' => false, 'new_tab' => true,
			'show_reels' => true, 'show_carousels' => true, 'mirror_videos' => true, 'video_autoplay' => true,
			'columns' => 3, 'columns_tablet' => 2, 'columns_mobile' => 1, 'aspect_ratio' => '9/16',
			'show_caption' => true, 'show_date' => true, 'show_username' => true,
			'show_metrics' => true,
			'local_detail' => false, 'image_max_mb' => 15, 'video_max_mb' => 100, 'max_api_pages' => 10,
			'cache_ttl' => 7200, 'log_limit' => 500, 'debug' => false, 'delete_on_uninstall' => false,
		);
	}

	/** @return array<string,mixed> */
	public static function settings(): array {
		$value = get_option(self::OPTION, array());
		return wp_parse_args(is_array($value) ? $value : array(), self::defaults());
	}

	public static function appId(): string {
		return defined('LIF_INSTAGRAM_APP_ID') ? (string) LIF_INSTAGRAM_APP_ID : (string) self::settings()['app_id'];
	}

	public static function appSecret(): string {
		if (defined('LIF_INSTAGRAM_APP_SECRET')) { return (string) LIF_INSTAGRAM_APP_SECRET; }
		return (string) (new \LocalInstagramFeed\Security\SecretStore())->get('app_secret');
	}

	public static function redirectUri(): string {
		$configured = (string) (self::settings()['redirect_uri'] ?? '');
		$uri = $configured ?: admin_url('admin.php');
		return (string) preg_replace('/[?#].*$/', '', $uri);
	}
}
