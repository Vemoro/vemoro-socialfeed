<?php
namespace Vemoro\SocialFeed;

final class Deactivation {
	public static function deactivate(): void {
		CronManager::unschedule();
		delete_transient( Config::LOCK_KEY );
		delete_option( Config::LOCK_KEY );
		wp_clear_scheduled_hook( 'vemoro_refresh_token_retry' );
		flush_rewrite_rules( false );
	}
}
