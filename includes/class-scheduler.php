<?php
/**
 * Scheduled (automatic) backups.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the recurring WP-Cron event and starts background backup jobs.
 */
final class Scheduler {

	const HOOK = 'packvault_scheduled_backup';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( Engine::CONTINUE_HOOK, array( Engine::class, 'continue_job' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	}

	/**
	 * Make sure a weekly schedule exists on older WordPress versions.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'pack-vault' ),
			);
		}
		return $schedules;
	}

	/**
	 * (Re)create the recurring event from settings.
	 */
	public static function reschedule() {
		self::clear();
		$settings = Settings::all();
		if ( empty( $settings['schedule_enabled'] ) ) {
			return;
		}

		$tz = wp_timezone();
		try {
			$next = new \DateTime( 'today ' . $settings['schedule_time'], $tz );
		} catch ( \Exception $e ) {
			$next = new \DateTime( 'today 02:00', $tz );
		}
		$now = new \DateTime( 'now', $tz );
		while ( $next <= $now ) {
			$next->modify( 'hourly' === $settings['schedule_frequency'] ? '+1 hour' : '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), $settings['schedule_frequency'], self::HOOK );
	}

	/**
	 * Remove the recurring event.
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Next run timestamp, or null.
	 *
	 * @return int|null
	 */
	public static function next_run() {
		$next = wp_next_scheduled( self::HOOK );
		return $next ? (int) $next : null;
	}

	/**
	 * Cron callback: start a background backup.
	 */
	public static function run() {
		if ( Job::active() ) {
			return; // Something is already running; try again next time.
		}
		try {
			$job = Backup_Runner::create_job( array( 'note' => __( 'Scheduled backup', 'pack-vault' ) ), 'schedule' );
		} catch ( \RuntimeException $e ) {
			return;
		}
		Engine::run( $job );
	}
}
