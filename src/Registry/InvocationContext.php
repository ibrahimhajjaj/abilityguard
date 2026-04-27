<?php
/**
 * Per-invocation observability state shared between hook listeners and the wrap.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Bundles the data the WP 6.9 before/after hook listeners need to thread
 * across the wrapped execute_callback.
 *
 * Lifecycle on 6.9+:
 *   1. wp_before_execute_ability listener mints the context, captures the
 *      pre-snapshot, inserts the placeholder audit row, push()es onto the
 *      stack, fires abilityguard_invocation_started.
 *   2. The wrapped callback peeks current() to read invocation_id / log_id /
 *      snapshot. It owns enforcement (lock, approval, pre-execute filter,
 *      execute) and the error-path audit completion.
 *   3. wp_after_execute_ability listener (success only) captures the
 *      post-snapshot, completes the audit row, fires
 *      abilityguard_invocation_completed, pops.
 *   4. Anything left on the stack at request end (output validation
 *      failure, fatal between hooks) is swept by the observer's shutdown
 *      handler so long-running runners don't leak rows or stale state.
 */
final class InvocationContext {

	/**
	 * LIFO stack of in-flight contexts.
	 *
	 * @var array<int, self>
	 */
	private static array $stack = array();

	/**
	 * Construct.
	 *
	 * @param string                    $invocation_id        UUID v4.
	 * @param string|null               $parent_invocation_id Parent UUID or null.
	 * @param string                    $ability_name         Ability name.
	 * @param string                    $caller_type          'rest', 'mcp', 'cli', or 'internal'.
	 * @param string|null               $caller_id            MCP server id when caller_type is mcp.
	 * @param int                       $user_id              Current user id (0 if none).
	 * @param int                       $start_hr             hrtime(true) at observer entry.
	 * @param array<string, mixed>      $safety      Resolved safety array.
	 * @param mixed                     $input                Ability input as received by the before-hook.
	 * @param array<string, mixed>|null $snapshot Snapshot row from SnapshotStore once captured.
	 * @param int                       $log_id   Audit log row id once written.
	 * @param bool                      $approval_handled Whether the approval gate has run.
	 * @param bool                      $completed Whether the after-hook has finalized this context.
	 */
	public function __construct(
		public readonly string $invocation_id,
		public readonly ?string $parent_invocation_id,
		public readonly string $ability_name,
		public string $caller_type,
		public ?string $caller_id,
		public readonly int $user_id,
		public readonly int $start_hr,
		public readonly array $safety,
		public mixed $input,
		public ?array $snapshot = null,
		public int $log_id = 0,
		public bool $approval_handled = false,
		public bool $completed = false
	) {}

	/**
	 * Push a new context onto the stack and return it.
	 *
	 * @param self $ctx Context.
	 */
	public static function push( self $ctx ): void {
		self::$stack[] = $ctx;
	}

	/**
	 * Peek the top of stack.
	 */
	public static function current(): ?self {
		if ( array() === self::$stack ) {
			return null;
		}
		return self::$stack[ array_key_last( self::$stack ) ];
	}

	/**
	 * Find the most-recent context for a given ability name.
	 *
	 * Re-entrant calls share a stack; the LIFO scan makes sure we return the
	 * deepest active invocation, not an outer one for the same name.
	 *
	 * @param string $ability_name Ability name.
	 */
	public static function find_for( string $ability_name ): ?self {
		for ( $i = count( self::$stack ) - 1; $i >= 0; $i-- ) {
			if ( self::$stack[ $i ]->ability_name === $ability_name && ! self::$stack[ $i ]->completed ) {
				return self::$stack[ $i ];
			}
		}
		return null;
	}

	/**
	 * Pop the top of stack. No-op when empty.
	 */
	public static function pop(): ?self {
		return array_pop( self::$stack );
	}

	/**
	 * Drain the stack and return the leftovers.
	 *
	 * @return array<int, self>
	 */
	public static function drain(): array {
		$out         = self::$stack;
		self::$stack = array();
		return $out;
	}

	/**
	 * Test helper.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$stack = array();
	}
}
