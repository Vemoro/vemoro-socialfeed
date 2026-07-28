<?php
declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) { exit; }

$settings = get_option('lif_settings', array());
if (empty($settings['delete_on_uninstall'])) { return; }

$posts = get_posts(array('post_type'=>'lif_instagram_post','post_status'=>'any','numberposts'=>-1,'fields'=>'ids'));
foreach ($posts as $postId) {
	$attachments = get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1,'post_parent'=>(int)$postId,'fields'=>'ids','meta_key'=>'_lif_owned','meta_value'=>'1'));
	foreach ($attachments as $attachmentId) { wp_delete_attachment((int)$attachmentId, true); }
	wp_delete_post((int)$postId, true);
}
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lif_instagram_media");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lif_logs");
foreach (array('lif_settings','lif_status','lif_token','lif_db_version','lif_cache_version','lif_refresh_generation','lif_applied_refresh_generation','lif_activated_at','lif_secret_app_secret','lif_secret_access_token') as $option) { delete_option($option); }
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('lif_support_notice_dismissed','lif_support_notice_remind_at')");
delete_transient('lif_sync_lock'); delete_transient('lif_sync_progress'); wp_clear_scheduled_hook('lif_sync_instagram_feed'); wp_clear_scheduled_hook('lif_refresh_token_retry');
