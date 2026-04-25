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
}
