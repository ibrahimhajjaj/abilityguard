<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * Registers a real ability via wp_register_ability and exercises the wrapper
 * through WP_Ability::execute(). Skipped if the abilities-api plugin isn't
 * loaded.
 *
 * The abilities API enforces that wp_register_ability() only runs while
 * doing_action('wp_abilities_api_init'), so each test attaches its
 * registration as a callback on that action and then re-fires it with the
 * live registry instance.
 */
final class RegistrationFilterTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		$this->ensure_test_category();
	}

	/**
	 * Register the ability category used by these tests. The abilities-api
	 * plugin skips its own core category registration when running under
	 * WP_PHPUNIT__DIR, so the test owns that responsibility.
	 */
	private function ensure_test_category(): void {
		if ( wp_has_ability_category( 'abilityguard-tests' ) ) {
			return;
		}
		$cb = static function ( $registry ): void {
			$registry->register(
				'abilityguard-tests',
				array(
					'label'       => 'AbilityGuard tests',
					'description' => 'Category for AbilityGuard integration tests.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $cb );
		WP_Ability_Categories_Registry::get_instance();
		do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );
		remove_action( 'wp_abilities_api_categories_init', $cb );
	}

	/**
	 * Register an ability via the registry directly. Bypasses the
	 * doing_action() guard on the public wp_register_ability() helper while
	 * still running through the same registry::register() path - and crucially
	 * still firing our wp_register_ability_args filter (applied inside register()).
	 *
	 * @param string   $name  Ability name.
	 * @param callable $build Returns the args array.
	 */
	private function register_via_init( string $name, callable $build ): \WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		$args     = $build( $registry );
		$result   = $registry->register( $name, $args );
		if ( null === $result ) {
			$this->fail(
				"register() returned null for {$name}. Has category? "
				. ( wp_has_ability_category( (string) ( $args['category'] ?? '' ) ) ? 'yes' : 'no' )
				. '; already registered? '
				. ( $registry->is_registered( $name ) ? 'yes' : 'no' )
			);
		}
		return $result;
	}

	public function test_safety_enabled_ability_records_log_and_snapshot(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_demo', 'before' );

		$ability_name = 'abilityguard-tests/touch-meta';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Touch demo meta',
				'description'         => 'Updates _demo for a post',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_demo', 'after' );
					return array( 'updated' => $input['post_id'] );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'meta'                => array(
					'annotations' => array( 'destructive' => true ),
				),
				'safety'              => array(
					'snapshot' => static fn( $input ) => array(
						'post_meta' => array( (int) $input['post_id'] => array( '_demo' ) ),
					),
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( array( 'updated' => $post_id ), $result );
		$this->assertSame( 'after', get_post_meta( $post_id, '_demo', true ) );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['status'] );
		$this->assertSame( '1', (string) $rows[0]['destructive'] );

		$store    = new SnapshotStore();
		$snapshot = $store->find_by_invocation_id( $rows[0]['invocation_id'] );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 'before', $snapshot['surfaces']['post_meta'][ $post_id ]['_demo'] );
	}

	public function test_safety_key_is_stripped_so_core_validation_passes(): void {
		$ability_name = 'abilityguard-tests/no-safety-leak';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'No safety leak',
				'description'         => 'Verifies that safety is removed',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => 'ok',
				'meta'                => array(
					'annotations' => array( 'destructive' => false ),
				),
				'safety'              => array( 'snapshot' => array() ),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability, 'core registry should accept args after safety is stripped' );
	}

	public function test_legacy_safety_destructive_emits_deprecation_and_migrates(): void {
		$this->setExpectedIncorrectUsage( 'AbilityGuard' );

		$ability_name = 'abilityguard-tests/legacy-destructive';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Legacy destructive',
				'description'         => 'Uses the deprecated safety.destructive shape',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => 'ok',
				'safety'              => array( 'destructive' => true ),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );
		$annotations = $ability->get_meta()['annotations'] ?? array();
		$this->assertTrue( $annotations['destructive'] ?? null, 'legacy safety.destructive should migrate into meta.annotations' );
	}
}
