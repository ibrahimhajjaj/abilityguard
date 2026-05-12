<?php
/**
 * Procedural helpers exposed to plugin authors.
 *
 * Thin wrappers around namespaced services so plugin authors can call them
 * without pulling AbilityGuard\* into their code.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

// Composer's files autoload pulls this into CLI tools (phpunit, phpstan)
// where ABSPATH is never defined. Still block direct web access; allow
// CLI through so the test suite can load.
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

if ( ! function_exists( 'abilityguard_rollback' ) ) {

	/**
	 * Roll back an invocation by id.
	 *
	 * @param int|string $invocation_id Numeric log id or invocation uuid.
	 * @param bool       $force         When true, drift is ignored and restore proceeds.
	 *
	 * @return true|\WP_Error
	 */
	function abilityguard_rollback( $invocation_id, bool $force = false ) {
		$service = new \AbilityGuard\Rollback\RollbackService(
			new \AbilityGuard\Audit\LogRepository(),
			new \AbilityGuard\Snapshot\SnapshotStore()
		);
		return $service->rollback( $invocation_id, $force );
	}
}

if ( ! function_exists( 'abilityguard_snapshot_meta' ) ) {

	/**
	 * Read current post_meta values for the given post + keys.
	 *
	 * @param int      $post_id Post id.
	 * @param string[] $keys    Meta keys.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	function abilityguard_snapshot_meta( int $post_id, array $keys ): array {
		return ( new \AbilityGuard\Snapshot\Collector\PostMetaCollector() )->collect( array( $post_id => $keys ) );
	}
}

if ( ! function_exists( 'abilityguard_snapshot_options' ) ) {

	/**
	 * Read current option values for the given keys.
	 *
	 * @param string[] $keys Option keys.
	 *
	 * @return array<string, mixed>
	 */
	function abilityguard_snapshot_options( array $keys ): array {
		return ( new \AbilityGuard\Snapshot\Collector\OptionsCollector() )->collect( $keys );
	}
}

if ( ! function_exists( 'abilityguard_approval_url' ) ) {

	/**
	 * Build the wp-admin URL an approver clicks to review/decide a request.
	 *
	 * Used by the notification recipes in `docs/notifications.md`. The URL
	 * lands on the AbilityGuard admin screen with the approvals tab pre-
	 * selected and the focused approval id in the query string. Plugins
	 * should treat this as the canonical "where do I go to approve" link
	 * rather than building their own.
	 *
	 * @param int $approval_id Row id from wp_abilityguard_approvals.
	 *
	 * @return string Absolute URL.
	 */
	function abilityguard_approval_url( int $approval_id ): string {
		return add_query_arg(
			array(
				'page'        => 'abilityguard',
				'view'        => 'approvals',
				'approval_id' => $approval_id,
			),
			admin_url( 'tools.php' )
		);
	}
}

if ( ! function_exists( 'abilityguard_get_dry_run_result' ) ) {

	/**
	 * Fetch a dry-run diff for a previous invocation. Returns the same shape
	 * the GET /abilityguard/v1/dry-run/{invocation_id} REST endpoint returns.
	 *
	 * @param string $invocation_id Invocation UUID returned in the
	 *                              X-AbilityGuard-Invocation-Id header.
	 *
	 * @return array{invocation_id: string, ability_name: string, executed_at: string, rolled_back: bool, diff: array<string,mixed>}|\WP_Error
	 */
	function abilityguard_get_dry_run_result( string $invocation_id ): array|\WP_Error {
		return \AbilityGuard\Safety\DryRun::fetch_result( $invocation_id );
	}
}
