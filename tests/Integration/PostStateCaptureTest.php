<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * Integration tests for post-state capture.
 *
 * Covers:
 *  1. capture_post() writes the post_state field after successful invocation.
 *  2. AbilityWrapper writes post_state_json through real wpdb on success.
 *  3. AbilityWrapper does NOT write post_state_json when callback throws.
 */
final class PostStateCaptureTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}


	public function test_capture_post_stores_post_state_on_snapshot_row(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '10.00' );

		$store   = new SnapshotStore();
		$service = new SnapshotService( $store );

		$safety = array(
			'snapshot' => array(
				'post_meta' => array( $post_id => array( '_price' ) ),
			),
		);

		$snap = $service->capture( 'post-state-1', $safety, null );
		$this->assertNotNull( $snap['snapshot_id'] );

		// Mutate state before capturing post-state.
		update_post_meta( $post_id, '_price', '20.00' );

		$service->capture_post( $snap['snapshot_id'], $safety, null );

		$row = $store->find_by_invocation_id( 'post-state-1' );
		$this->assertNotNull( $row );
		$this->assertNotNull( $row['post_state'] );
		$this->assertSame( '20.00', $row['post_state']['post_meta'][ $post_id ]['_price'] );
		// Pre-state should still be original value.
		$this->assertSame( '10.00', $row['surfaces']['post_meta'][ $post_id ]['_price'] );
	}


	public function test_ability_wrapper_writes_post_state_on_successful_execution(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		$this->ensure_test_category();

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '10.00' );

		$ability_name = 'abilityguard-tests/post-state-success';
		$registry     = WP_Abilities_Registry::get_instance();
		$ability      = $registry->register(
			$ability_name,
			array(
				'label'               => 'Post state success',
				'description'         => 'Mutates _price and expects post_state captured.',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_price', '99.00' );
					return array( 'done' => true );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'destructive' => true,
					'snapshot'    => static fn( $input ) => array(
						'post_meta' => array( (int) $input['post_id'] => array( '_price' ) ),
					),
				),
			)
		);

		if ( null === $ability ) {
			$this->markTestSkipped( 'register() returned null - abilities-api version mismatch' );
		}

		$ability->execute( array( 'post_id' => $post_id ) );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['status'] );

		$store    = new SnapshotStore();
		$snapshot = $store->find_by_invocation_id( $rows[0]['invocation_id'] );
		$this->assertNotNull( $snapshot );

		// Pre-state has original value.
		$this->assertSame( '10.00', $snapshot['surfaces']['post_meta'][ $post_id ]['_price'] );

		// Post-state has mutated value.
		$this->assertNotNull( $snapshot['post_state'] );
		$this->assertSame( '99.00', $snapshot['post_state']['post_meta'][ $post_id ]['_price'] );
	}


	public function test_ability_wrapper_does_not_write_post_state_on_exception(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		$this->ensure_test_category();

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '10.00' );

		$ability_name = 'abilityguard-tests/post-state-throws';
		$registry     = WP_Abilities_Registry::get_instance();
		$ability      = $registry->register(
			$ability_name,
			array(
				'label'               => 'Post state throws',
				'description'         => 'Throws to verify post_state is not captured.',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ): never {
					unset( $input );
					throw new \RuntimeException( 'intentional failure' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'destructive' => false,
					'snapshot'    => array(
						'post_meta' => array( $post_id => array( '_price' ) ),
					),
				),
			)
		);

		if ( null === $ability ) {
			$this->markTestSkipped( 'register() returned null - abilities-api version mismatch' );
		}

		try {
			$ability->execute( array( 'post_id' => $post_id ) );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );

		$store    = new SnapshotStore();
		$snapshot = $store->find_by_invocation_id( $rows[0]['invocation_id'] );
		$this->assertNotNull( $snapshot );
		$this->assertNull( $snapshot['post_state'], 'post_state must be null when callback throws' );
	}

	/**
	 * Ensure the test ability category exists (mirrors RegistrationFilterTest).
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
}
