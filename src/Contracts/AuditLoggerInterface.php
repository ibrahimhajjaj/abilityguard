<?php
/**
 * Audit logger contract.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Contracts;

/**
 * Persists one row per ability invocation.
 */
interface AuditLoggerInterface {

	/**
	 * Record an invocation.
	 *
	 * @param array<string, mixed> $entry Row keyed by column name; see AbilityWrapper for contract.
	 *
	 * @return int The inserted log id.
	 */
	public function log( array $entry ): int;

	/**
	 * Patch terminal fields onto a row written by log().
	 *
	 * The before/after-hook split (WP 6.9+) inserts a placeholder row in the
	 * before-listener and completes it in the after-listener (success) or in
	 * the wrap (error/approval-pending). Supported keys: result_json, status,
	 * duration_ms, post_hash, caller_type, caller_id.
	 *
	 * @param int                  $log_id Row id returned from log().
	 * @param array<string, mixed> $patch  Subset of entry keys.
	 *
	 * @return bool True on success.
	 */
	public function complete( int $log_id, array $patch ): bool;
}
