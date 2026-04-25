<?php
/**
 * Schedules the daily retention cron event.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Retention;

/**
 * Wires WP-Cron for the retention prune job.
 */
final class Scheduler {

	/**
	 * Cron hook name.
	 */
	public const HOOK = 'abilityguard_retention_prune';

	/**
	 * Register the cron action and ensure the event is scheduled on `init`.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the daily event if it is not already scheduled.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Called on `init` to ensure the event exists after activation.
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove all scheduled occurrences of the prune event.
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Execute the prune job. Called by cron.
	 */
	public function run(): void {
		( new RetentionService() )->prune();
	}
}
