<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Examples;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * Integration test for the Fluent Forms Pack demo plugin.
 *
 * Invokes acme-forms/duplicate-form, asserts that a new post is created,
 * the audit log and snapshot rows are present, and that rollback restores
 * the options surface but does NOT delete the duplicated post.
 */
final class FluentFormsPackTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		$this->ensure_acme_forms_category();
	}

	private function ensure_acme_forms_category(): void {
		if ( wp_has_ability_category( 'acme-forms' ) ) {
			return;
		}
		$cb = static function ( $registry ): void {
			$registry->register(
				'acme-forms',
				array(
					'label'       => 'Acme Forms',
					'description' => 'Abilities that manage Acme Forms.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $cb );
		WP_Ability_Categories_Registry::get_instance();
		do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );
		remove_action( 'wp_abilities_api_categories_init', $cb );
	}

	private function register_via_init( string $name, callable $build ): \WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		$args     = $build( $registry );
		$result   = $registry->register( $name, $args );
		if ( null === $result ) {
			$this->fail(
				"register() returned null for {$name}. Category registered? "
				. ( wp_has_ability_category( (string) ( $args['category'] ?? '' ) ) ? 'yes' : 'no' )
			);
		}
		return $result;
	}

	public function test_execute_creates_clone_and_writes_log_and_snapshot(): void {
		update_option( 'acme_forms_last_duplicated_at', '' );

		$form_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Contact Form',
				'post_status' => 'publish',
			)
		);

		$ability_name = 'acme-forms/duplicate-form';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Duplicate form',
				'description'         => 'Clones an acme_form post and records the timestamp option.',
				'category'            => 'acme-forms',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( mixed $input ): mixed {
					$form_id = (int) ( $input['form_id'] ?? 0 );
					$source  = get_post( $form_id );
					if ( ! $source instanceof \WP_Post ) {
						return new \WP_Error( 'acme_forms_not_found', 'Form post not found.' );
					}
					$new_id = wp_insert_post(
						array(
							'post_title'  => $source->post_title . ' (copy)',
							'post_type'   => $source->post_type,
							'post_status' => 'draft',
							'post_author' => $source->post_author,
						),
						true
					);
					if ( is_wp_error( $new_id ) ) {
						return $new_id;
					}
					update_option( 'acme_forms_last_duplicated_at', gmdate( 'Y-m-d H:i:s' ) );
					return array(
						'original_id' => $form_id,
						'new_id'      => $new_id,
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'form_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'form_id' ),
				),
				'safety'              => array(
					'destructive' => true,
					'snapshot'    => static function ( mixed $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
						return array(
							'options'   => array( 'acme_forms_last_duplicated_at' ),
							'post_meta' => array(),
						);
					},
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array( 'form_id' => $form_id ) );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'new_id', $result );

		$new_id = (int) $result['new_id'];
		$this->assertGreaterThan( 0, $new_id );

		$clone = get_post( $new_id );
		$this->assertNotNull( $clone );
		$this->assertSame( 'Contact Form (copy)', $clone->post_title );
		$this->assertSame( 'draft', $clone->post_status );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['status'] );
		$this->assertSame( '1', (string) $rows[0]['destructive'] );

		$store    = new SnapshotStore();
		$snapshot = $store->find_by_invocation_id( $rows[0]['invocation_id'] );
		$this->assertNotNull( $snapshot );
		$this->assertArrayHasKey( 'options', $snapshot['surfaces'] );
	}

	public function test_rollback_restores_option_but_clone_persists(): void {
		update_option( 'acme_forms_last_duplicated_at', 'before' );

		$form_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Survey Form',
				'post_status' => 'publish',
			)
		);

		$ability_name = 'acme-forms/duplicate-form-rollback';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Duplicate form (rollback test)',
				'description'         => 'Clones an acme_form post and records the timestamp option.',
				'category'            => 'acme-forms',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( mixed $input ): mixed {
					$form_id = (int) ( $input['form_id'] ?? 0 );
					$source  = get_post( $form_id );
					if ( ! $source instanceof \WP_Post ) {
						return new \WP_Error( 'acme_forms_not_found', 'Form post not found.' );
					}
					$new_id = wp_insert_post(
						array(
							'post_title'  => $source->post_title . ' (copy)',
							'post_type'   => $source->post_type,
							'post_status' => 'draft',
							'post_author' => $source->post_author,
						),
						true
					);
					if ( is_wp_error( $new_id ) ) {
						return $new_id;
					}
					update_option( 'acme_forms_last_duplicated_at', gmdate( 'Y-m-d H:i:s' ) );
					return array(
						'original_id' => $form_id,
						'new_id'      => $new_id,
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'form_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'form_id' ),
				),
				'safety'              => array(
					'destructive' => true,
					'snapshot'    => static function ( mixed $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
						return array(
							'options'   => array( 'acme_forms_last_duplicated_at' ),
							'post_meta' => array(),
						);
					},
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );
		$result = $ability->execute( array( 'form_id' => $form_id ) );
		$new_id = (int) $result['new_id'];

		$repo          = new LogRepository();
		$rows          = $repo->list( array( 'ability_name' => $ability_name ) );
		$invocation_id = $rows[0]['invocation_id'];

		$rollback = new \AbilityGuard\Rollback\RollbackService( new LogRepository(), new SnapshotStore() );
		$ok       = $rollback->rollback( $invocation_id );

		$this->assertTrue( $ok );

		// Option is restored to pre-run value.
		$this->assertSame( 'before', get_option( 'acme_forms_last_duplicated_at' ) );

		// The cloned post is intentionally NOT deleted by rollback - teaching moment.
		$clone = get_post( $new_id );
		$this->assertNotNull( $clone, 'Rollback does not auto-delete the duplicated post; the clone persists.' );
	}
}
