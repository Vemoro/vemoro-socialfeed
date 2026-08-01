<?php
namespace LocalInstagramFeed;

final class CronManager {
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'intervals' ) );
		add_action( Config::CRON_HOOK, array( Plugin::instance(), 'runCron' ) );
		// Action Scheduler is initialized during `init`. Checking the schedule here
		// also repairs installations where activation happened before that API was
		// ready or where a host/plugin removed the previously scheduled event.
		add_action( 'init', array( self::class, 'ensureScheduled' ), 20 );
	}
	/** @param array<string,array<string,mixed>> $schedules @return array<string,array<string,mixed>> */
	public static function intervals( array $schedules ): array {
		$schedules['lif_15_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 minutes', 'vemoro-socialfeed' ),
		);
		$schedules['lif_30_minutes'] = array(
			'interval' => 1800,
			'display'  => __( 'Every 30 minutes', 'vemoro-socialfeed' ),
		);
		$schedules['lif_two_hours']  = array(
			'interval' => 7200,
			'display'  => __( 'Every two hours', 'vemoro-socialfeed' ),
		);
		$schedules['lif_six_hours']  = array(
			'interval' => 21600,
			'display'  => __( 'Every six hours', 'vemoro-socialfeed' ),
		);
		return $schedules;
	}
	public static function schedule(): void {
		add_filter( 'cron_schedules', array( self::class, 'intervals' ) );
		$interval = (string) ( Config::settings()['sync_interval'] ?? 'lif_two_hours' );
		if ( self::scheduleWithActionScheduler( $interval ) ) {
			return;
		}
		if ( ! wp_next_scheduled( Config::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, $interval, Config::CRON_HOOK );
		}
	}

	public static function ensureScheduled(): void {
		$interval = (string) ( Config::settings()['sync_interval'] ?? 'lif_two_hours' );
		if ( self::scheduleWithActionScheduler( $interval ) ) {
			// Remove an activation-time fallback only after Action Scheduler has
			// confirmed an existing or newly created recurring action.
			wp_clear_scheduled_hook( Config::CRON_HOOK );
			return;
		}
		self::schedule();
	}
	public static function reschedule(): void {
		self::unschedule();
		self::schedule(); }
	public static function nextScheduled(): int {
		$events = array_filter(
			array(
				self::actionSchedulerNext(),
				(int) wp_next_scheduled( Config::CRON_HOOK ),
			)
		);
		return $events ? min( $events ) : 0;
	}
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			try {
				as_unschedule_all_actions( Config::CRON_HOOK, array(), 'local-instagram-feed' );
			} catch ( \Throwable ) {
				wp_clear_scheduled_hook( Config::CRON_HOOK );
				return;
			}
		}
		wp_clear_scheduled_hook( Config::CRON_HOOK );
	}
	private static function actionSchedulerAvailable(): bool {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}
		if ( class_exists( '\\ActionScheduler' ) && method_exists( '\\ActionScheduler', 'is_initialized' ) ) {
			return \ActionScheduler::is_initialized();
		}
		return did_action( 'action_scheduler_init' ) > 0;
	}
	private static function actionSchedulerNext(): int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}
		try {
			return (int) as_next_scheduled_action( Config::CRON_HOOK, array(), 'local-instagram-feed' );
		} catch ( \Throwable ) {
			return 0;
		}
	}
	private static function scheduleWithActionScheduler( string $interval ): bool {
		if ( ! self::actionSchedulerAvailable() ) {
			return false;
		}
		try {
			return self::actionSchedulerNext() > 0 || (int) as_schedule_recurring_action( time() + 60, self::seconds( $interval ), Config::CRON_HOOK, array(), 'local-instagram-feed', true ) > 0;
		} catch ( \Throwable ) {
			// A loaded but unavailable Action Scheduler must not disable syncing.
			return false;
		}
	}
	private static function seconds( string $interval ): int {
		return array(
			'lif_15_minutes' => 900,
			'lif_30_minutes' => 1800,
			'hourly'         => 3600,
			'lif_two_hours'  => 7200,
			'lif_six_hours'  => 21600,
			'daily'          => 86400,
		)[ $interval ] ?? 7200; }
}
