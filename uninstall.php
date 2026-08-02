<?php
declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; }

$vemoro_socialfeed_settings = get_option( 'lif_settings', array() );
if ( empty( $vemoro_socialfeed_settings['delete_on_uninstall'] ) ) {
	return; }

$vemoro_socialfeed_posts = get_posts(
	array(
		'post_type'   => 'lif_instagram_post',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $vemoro_socialfeed_posts as $vemoro_socialfeed_post_id ) {
	$vemoro_socialfeed_attachments = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => -1,
			'post_parent' => (int) $vemoro_socialfeed_post_id,
			'fields'      => 'ids',
			// The opt-in uninstall must identify only attachments explicitly owned by this plugin.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'    => '_lif_owned',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value'  => '1',
		)
	);
	foreach ( $vemoro_socialfeed_attachments as $vemoro_socialfeed_attachment_id ) {
		wp_delete_attachment( (int) $vemoro_socialfeed_attachment_id, true ); }
	wp_delete_post( (int) $vemoro_socialfeed_post_id, true );
}
global $wpdb;
// Explicit opt-in uninstall removes the two tables owned exclusively by this plugin.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lif_instagram_media" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lif_logs" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
foreach ( array( 'lif_settings', 'lif_status', 'lif_token', 'lif_db_version', 'lif_cache_version', 'lif_refresh_generation', 'lif_applied_refresh_generation', 'lif_activated_at', 'lif_secret_app_secret', 'lif_secret_access_token' ) as $vemoro_socialfeed_option ) {
	delete_option( $vemoro_socialfeed_option ); }
// User-specific notice preferences have no bulk WordPress API and must be removed on opt-in uninstall.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('lif_support_notice_dismissed','lif_support_notice_remind_at')" );
delete_transient( 'lif_sync_lock' );
delete_transient( 'lif_sync_progress' );
wp_clear_scheduled_hook( 'lif_sync_instagram_feed' );
wp_clear_scheduled_hook( 'lif_refresh_token_retry' );
