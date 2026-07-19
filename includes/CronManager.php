<?php
namespace LocalInstagramFeed;

final class CronManager {
	public static function register(): void {
		add_filter('cron_schedules', array(self::class, 'intervals'));
		add_action(Config::CRON_HOOK, array(Plugin::instance(), 'runCron'));
	}
	/** @param array<string,array<string,mixed>> $schedules @return array<string,array<string,mixed>> */
	public static function intervals(array $schedules): array {
		$schedules['lif_15_minutes'] = array('interval' => 900, 'display' => __('Every 15 minutes', 'local-instagram-feed'));
		$schedules['lif_30_minutes'] = array('interval' => 1800, 'display' => __('Every 30 minutes', 'local-instagram-feed'));
		$schedules['lif_two_hours'] = array('interval' => 7200, 'display' => __('Every two hours', 'local-instagram-feed'));
		$schedules['lif_six_hours'] = array('interval' => 21600, 'display' => __('Every six hours', 'local-instagram-feed'));
		return $schedules;
	}
	public static function schedule(): void {
		add_filter('cron_schedules', array(self::class, 'intervals'));
		$interval = (string) (Config::settings()['sync_interval'] ?? 'lif_two_hours');
		if (function_exists('as_schedule_recurring_action') && function_exists('as_next_scheduled_action')) {
			if (! as_next_scheduled_action(Config::CRON_HOOK, array(), 'local-instagram-feed')) { as_schedule_recurring_action(time() + 60, self::seconds($interval), Config::CRON_HOOK, array(), 'local-instagram-feed'); }
		} elseif (! wp_next_scheduled(Config::CRON_HOOK)) { wp_schedule_event(time() + 60, $interval, Config::CRON_HOOK); }
	}
	public static function reschedule(): void { self::unschedule(); self::schedule(); }
	public static function nextScheduled(): int {
		if (function_exists('as_next_scheduled_action')) { return (int) as_next_scheduled_action(Config::CRON_HOOK, array(), 'local-instagram-feed'); }
		return (int) wp_next_scheduled(Config::CRON_HOOK);
	}
	public static function unschedule(): void {
		if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions(Config::CRON_HOOK, array(), 'local-instagram-feed'); }
		wp_clear_scheduled_hook(Config::CRON_HOOK);
	}
	private static function seconds(string $interval): int { return array('lif_15_minutes'=>900,'lif_30_minutes'=>1800,'hourly'=>3600,'lif_two_hours'=>7200,'lif_six_hours'=>21600,'daily'=>86400)[$interval] ?? 7200; }
}
