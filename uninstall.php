<?php
declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; }

$vemoro_settings = get_option( 'vemoro_settings', array() );
if ( empty( $vemoro_settings['delete_on_uninstall'] ) ) {
	return; }

$vemoro_posts = get_posts(
	array(
		'post_type'   => 'vemoro_socialfeed',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $vemoro_posts as $vemoro_post_id ) {
	$vemoro_attachments = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => -1,
			'post_parent' => (int) $vemoro_post_id,
			'fields'      => 'ids',
			'meta_key'    => '_vemoro_owned',
			'meta_value'  => '1',
		)
	);
	foreach ( $vemoro_attachments as $vemoro_attachment_id ) {
		wp_delete_attachment( (int) $vemoro_attachment_id, true ); }
	wp_delete_post( (int) $vemoro_post_id, true );
}
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vemoro_instagram_media" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vemoro_logs" );
foreach ( array( 'vemoro_settings', 'vemoro_status', 'vemoro_token', 'vemoro_db_version', 'vemoro_cache_version', 'vemoro_refresh_generation', 'vemoro_applied_refresh_generation', 'vemoro_activated_at', 'vemoro_secret_app_secret', 'vemoro_secret_access_token' ) as $vemoro_option ) {
	delete_option( $vemoro_option ); }
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('vemoro_support_notice_dismissed','vemoro_support_notice_remind_at')" );
delete_transient( 'vemoro_sync_lock' );
delete_transient( 'vemoro_sync_progress' );
wp_clear_scheduled_hook( 'vemoro_sync_instagram_feed' );
wp_clear_scheduled_hook( 'vemoro_refresh_token_retry' );
