<?php
namespace LocalInstagramFeed;

final class Deactivation {
	public static function deactivate(): void {
		CronManager::unschedule();
		delete_transient( Config::LOCK_KEY );
		flush_rewrite_rules( false );
	}
}
