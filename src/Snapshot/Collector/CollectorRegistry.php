<?php
/**
 * Process-wide registry of collectors keyed by surface name.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

/**
 * Centralises the surface => collector map so plugin authors can register
 * additional collectors without forking SnapshotService or RollbackService.
 *
 * Built-in surfaces (`post_meta`, `options`, `taxonomy`, `user_role`,
 * `files`) are seeded by `defaults()` and cannot be overridden via the
 * registry - to swap a built-in collector you still need to pass an
 * explicit `$collectors` array to the service constructor.
 *
 * Custom registrations are global to the request: ability A's
 * `safety.collectors = ['my_surface' => $X]` makes `$X` visible to ability
 * B's wrapper if B also declares the `my_surface` surface. That's by design
 * - collectors are stateless service objects and de-duping by name keeps
 * the contract simple. If you need per-invocation collector swapping,
 * construct your own SnapshotService directly.
 */
final class CollectorRegistry {

	/**
	 * Custom collectors registered at runtime, layered over defaults.
	 *
	 * @var array<string, CollectorInterface>
	 */
	private static array $extras = array();

	/**
	 * Register (or replace) a custom collector for a surface name.
	 *
	 * @param string             $surface   Surface name (e.g. 'mailing_list').
	 * @param CollectorInterface $collector Collector instance.
	 */
	public static function register( string $surface, CollectorInterface $collector ): void {
		if ( '' === $surface ) {
			return;
		}
		self::$extras[ $surface ] = $collector;
	}

	/**
	 * Whether a custom collector is registered for the given surface name.
	 *
	 * @param string $surface Surface name to test.
	 */
	public static function has( string $surface ): bool {
		return isset( self::$extras[ $surface ] );
	}

	/**
	 * Built-in surfaces merged with any custom registrations.
	 *
	 * @return array<string, CollectorInterface>
	 */
	public static function defaults(): array {
		$built_in = array(
			'post_meta' => new PostMetaCollector(),
			'options'   => new OptionsCollector(),
			'taxonomy'  => new TaxonomyCollector(),
			'user_role' => new UserRoleCollector(),
			'files'     => new FilesCollector(),
		);

		// Built-ins win over custom registrations with the same surface name -
		// otherwise a misconfigured `safety.collectors = ['files' => ...]` could
		// silently replace the file collector for the whole site.
		return array_merge( self::$extras, $built_in );
	}

	/**
	 * Clear runtime registrations. Test-only helper.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$extras = array();
	}
}
