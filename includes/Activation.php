<?php
namespace Vemoro\SocialFeed;

final class Activation {
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( VEMORO_PLUGIN_FILE ) );
			wp_die( esc_html__( 'Vemoro SocialFeed for WP requires PHP 8.1 or newer.', 'vemoro-socialfeed' ) );
		}
		self::upgrade();
		Plugin::registerPostType();
		self::registerRewriteRules();
		flush_rewrite_rules( false );
	}

	/**
	 * Applies idempotent option and database upgrades without flushing rewrite rules.
	 */
	public static function upgrade(): void {
		$previousDbVersion = (string) get_option( Config::DB_VERSION_OPTION, '' );
		add_option( Config::OPTION, Config::defaults() );
		if ( $previousDbVersion && version_compare( $previousDbVersion, '1.1.0', '<' ) ) {
			$settings                   = Config::settings();
			$settings['mirror_videos']  = true;
			$settings['video_autoplay'] = true;
			$settings['aspect_ratio']   = '9/16';
			$settings['show_metrics']   = true;
			update_option( Config::OPTION, $settings, false );
			update_option( Config::REFRESH_GENERATION_OPTION, (int) get_option( Config::REFRESH_GENERATION_OPTION, 0 ) + 1, false );
		}
		add_option( Config::STATUS_OPTION, array() );
		self::createTables();
		CronManager::schedule();
	}

	/**
	 * Registers the local detail route during WordPress' normal init phase.
	 */
	public static function registerRewriteRules(): void {
		add_rewrite_rule( '^instagram-feed/([^/]+)/?$', 'index.php?vemoro_detail=$matches[1]', 'top' );
	}

	private static function createTables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$media   = $wpdb->prefix . 'vemoro_instagram_media';
		$logs    = $wpdb->prefix . 'vemoro_logs';
		dbDelta(
			"CREATE TABLE {$media} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			media_id varchar(191) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			parent_media_id varchar(191) NOT NULL DEFAULT '',
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			video_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			position smallint unsigned NOT NULL DEFAULT 0,
			media_type varchar(32) NOT NULL DEFAULT '',
			data_hash char(64) NOT NULL DEFAULT '',
			file_status varchar(32) NOT NULL DEFAULT '',
			last_error text NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY media_id (media_id),
			KEY post_id (post_id),
			KEY parent_media_id (parent_media_id)
		) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(16) NOT NULL,
			message text NOT NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset};"
		);
		update_option( Config::DB_VERSION_OPTION, Config::DB_VERSION, false );
	}
}
