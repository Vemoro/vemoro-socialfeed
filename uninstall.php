<?php
declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; }

$vemoro_settings = get_option( 'vemoro_settings', array() );
if ( empty( $vemoro_settings['delete_on_uninstall'] ) ) {
	return; }

// Uninstall runs without loading the active plugin; share its reference-aware cleanup.
require_once __DIR__ . '/includes/Config.php';
require_once __DIR__ . '/includes/Repository/PostRepository.php';
require_once __DIR__ . '/includes/CronManager.php';
( new Vemoro\SocialFeed\Repository\PostRepository() )->deleteAll();
Vemoro\SocialFeed\CronManager::unschedule();
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vemoro_instagram_media" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vemoro_logs" );
foreach ( array( 'vemoro_settings', 'vemoro_status', 'vemoro_token', 'vemoro_db_version', 'vemoro_cache_version', 'vemoro_refresh_generation', 'vemoro_applied_refresh_generation', 'vemoro_activated_at', 'vemoro_secret_app_secret', 'vemoro_secret_access_token', 'vemoro_sync_lock' ) as $vemoro_option ) {
	delete_option( $vemoro_option ); }
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('vemoro_support_notice_dismissed','vemoro_support_notice_remind_at')" );
delete_transient( 'vemoro_sync_lock' );
delete_transient( 'vemoro_sync_progress' );
wp_clear_scheduled_hook( 'vemoro_sync_instagram_feed' );
wp_clear_scheduled_hook( 'vemoro_refresh_token_retry' );
