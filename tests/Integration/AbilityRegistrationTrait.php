<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use WP_Abilities_Registry;
use WP_Ability;
use WP_Ability_Categories_Registry;

/**
 * Helpers shared by integration tests that exercise abilities through the
 * proper WP_Abilities_Registry::register() + WP_Ability::execute() flow.
 *
 * Going through the registry is what fires wp_register_ability_args (so
 * AbilityGuard's wrap is installed with the safety config baked in) and
 * wp_before_execute_ability (so the observer pushes the InvocationContext
 * the wrap expects).
 */
trait AbilityRegistrationTrait {

	/**
	 * Make sure the test category exists exactly once. Idempotent.
	 */
	protected function ensure_test_category(): void {
		if ( ! function_exists( 'wp_has_ability_category' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
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
	 * Register an ability through the real registry so the registration filter
	 * wraps execute_callback and the observer wires up.
	 *
	 * @param string                                                                  $name  Ability name.
	 * @param callable(WP_Abilities_Registry):array<string,mixed>|array<string,mixed> $build Args or builder.
	 */
	protected function register_via_init( string $name, callable|array $build ): WP_Ability {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		$registry = WP_Abilities_Registry::get_instance();
		$args     = is_callable( $build ) ? $build( $registry ) : $build;
		$result   = $registry->register( $name, $args );
		$this->assertNotNull( $result );
		return $result;
	}

	/**
	 * Register an ability with the given safety + callback, execute it via
	 * the abilities-api flow, and return the resulting audit log row.
	 *
	 * @param string               $name     Unique ability name.
	 * @param array<string, mixed> $safety   Safety config.
	 * @param callable             $callback execute_callback.
	 * @param mixed                $input        Input passed to execute().
	 * @param array<string, mixed> $input_schema Optional input schema override.
	 *
	 * @return array<string, mixed>
	 */
	protected function execute_and_get_log_row(
		string $name,
		array $safety,
		callable $callback,
		mixed $input = null,
		array $input_schema = array( 'type' => array( 'object', 'null' ) )
	): array {
		$this->ensure_test_category();
		$this->register_via_init(
			$name,
			static fn() => array(
				'label'               => 'Test',
				'description'         => 'integration test ability',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => $callback,
				'input_schema'        => $input_schema,
				'safety'              => $safety,
			)
		);
		$ability = wp_get_ability( $name );
		$ability->execute( $input );

		$repo = new \AbilityGuard\Audit\LogRepository();
		$rows = $repo->list( array( 'ability_name' => $name ) );
		$this->assertNotEmpty( $rows, "Log row not found for {$name}" );
		return $rows[0];
	}
}
