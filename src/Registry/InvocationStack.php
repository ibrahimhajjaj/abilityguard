<?php
/**
 * Per-request stack of in-flight ability invocation ids.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

/**
 * Tracks the chain of currently-executing invocations so a nested ability
 * call can record its parent's invocation_id for log correlation.
 *
 * Stack lifetime is the PHP request only. Each `push()` MUST be matched
 * by a `pop()` in a finally block - leaving entries behind would leak
 * fake parents into unrelated invocations on long-running CLI processes.
 */
final class InvocationStack {

	/**
	 * Per-request stack of invocation ids, oldest at index 0.
	 *
	 * @var string[]
	 */
	private static array $stack = array();

	/**
	 * Push the current invocation id onto the stack.
	 *
	 * @param string $invocation_id UUID v4 of the invocation about to start.
	 */
	public static function push( string $invocation_id ): void {
		self::$stack[] = $invocation_id;
	}

	/**
	 * Pop the most recent entry. No-op when the stack is empty.
	 */
	public static function pop(): void {
		array_pop( self::$stack );
	}

	/**
	 * Return the parent invocation id (the entry below the top), or null
	 * if there is no parent yet. Call this BEFORE push()ing the current id.
	 */
	public static function current(): ?string {
		if ( array() === self::$stack ) {
			return null;
		}
		return self::$stack[ array_key_last( self::$stack ) ];
	}

	/**
	 * Test helper: clear the stack between tests.
	 */
	public static function reset(): void {
		self::$stack = array();
	}
}
