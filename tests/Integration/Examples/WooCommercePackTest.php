<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Examples;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * Integration test for the WooCommerce Pack demo plugin.
 *
 * Loads the example plugin, exercises acme-shop/update-product-price,
 * then asserts audit log, snapshot rows, and rollback behaviour.
 */
final class WooCommercePackTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		$this->ensure_acme_shop_category();
		// Ability requires manage_options; satisfy that.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	private function ensure_acme_shop_category(): void {
		if ( wp_has_ability_category( 'acme-shop' ) ) {
			return;
		}
		$cb = static function ( $registry ): void {
			$registry->register(
				'acme-shop',
				array(
					'label'       => 'Acme Shop',
					'description' => 'Abilities that manage Acme Shop products.',
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
		if ( $registry->is_registered( $name ) ) {
			return $registry->get_registered( $name );
		}
		$args   = $build( $registry );
		$result = $registry->register( $name, $args );
		if ( null === $result ) {
			$this->fail(
				"register() returned null for {$name}. Category registered? "
				. ( wp_has_ability_category( (string) ( $args['category'] ?? '' ) ) ? 'yes' : 'no' )
			);
		}
		return $result;
	}

	public function test_execute_writes_log_and_snapshot(): void {
		$product_id = self::factory()->post->create();
		update_post_meta( $product_id, '_price', '9.99' );
		update_post_meta( $product_id, '_regular_price', '9.99' );

		$ability_name = 'acme-shop/update-product-price';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Update product price',
				'description'         => 'Sets _regular_price and _price on a product post.',
				'category'            => 'acme-shop',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( mixed $input ): array {
					$product_id    = (int) ( $input['product_id'] ?? 0 );
					$regular_price = (string) ( $input['regular_price'] ?? '' );
					update_post_meta( $product_id, '_regular_price', $regular_price );
					update_post_meta( $product_id, '_price', $regular_price );
					return array(
						'success'    => true,
						'product_id' => $product_id,
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'    => array( 'type' => 'integer' ),
						'regular_price' => array( 'type' => 'string' ),
					),
					'required'   => array( 'product_id', 'regular_price' ),
				),
				'safety'              => array(
					'destructive' => true,
					'snapshot'    => static function ( mixed $input ): array {
						$pid = (int) ( $input['product_id'] ?? 0 );
						return array(
							'post_meta' => array(
								$pid => array( '_price', '_regular_price' ),
							),
						);
					},
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '19.99',
			)
		);
		$this->assertSame( true, $result['success'] );
		$this->assertSame( '19.99', get_post_meta( $product_id, '_price', true ) );
		$this->assertSame( '19.99', get_post_meta( $product_id, '_regular_price', true ) );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['status'] );
		$this->assertSame( '1', (string) $rows[0]['destructive'] );

		$store    = new SnapshotStore();
		$snapshot = $store->find_by_invocation_id( $rows[0]['invocation_id'] );
		$this->assertNotNull( $snapshot );
		$this->assertSame( '9.99', $snapshot['surfaces']['post_meta'][ $product_id ]['_price'] );
		$this->assertSame( '9.99', $snapshot['surfaces']['post_meta'][ $product_id ]['_regular_price'] );
	}

	public function test_rollback_restores_price_meta(): void {
		$product_id = self::factory()->post->create();
		update_post_meta( $product_id, '_price', '5.00' );
		update_post_meta( $product_id, '_regular_price', '5.00' );

		$ability_name = 'acme-shop/update-product-price-rollback';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Update product price (rollback test)',
				'description'         => 'Sets _regular_price and _price on a product post.',
				'category'            => 'acme-shop',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( mixed $input ): array {
					$product_id    = (int) ( $input['product_id'] ?? 0 );
					$regular_price = (string) ( $input['regular_price'] ?? '' );
					update_post_meta( $product_id, '_regular_price', $regular_price );
					update_post_meta( $product_id, '_price', $regular_price );
					return array(
						'success'    => true,
						'product_id' => $product_id,
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'    => array( 'type' => 'integer' ),
						'regular_price' => array( 'type' => 'string' ),
					),
					'required'   => array( 'product_id', 'regular_price' ),
				),
				'safety'              => array(
					'destructive' => true,
					'snapshot'    => static function ( mixed $input ): array {
						$pid = (int) ( $input['product_id'] ?? 0 );
						return array(
							'post_meta' => array(
								$pid => array( '_price', '_regular_price' ),
							),
						);
					},
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );
		$ability->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '99.00',
			)
		);

		$this->assertSame( '99.00', get_post_meta( $product_id, '_price', true ) );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$invocation_id = $rows[0]['invocation_id'];

		$rollback = new RollbackService( new LogRepository(), new SnapshotStore() );
		$ok       = $rollback->rollback( $invocation_id );

		$this->assertTrue( $ok );
		$this->assertSame( '5.00', get_post_meta( $product_id, '_price', true ) );
		$this->assertSame( '5.00', get_post_meta( $product_id, '_regular_price', true ) );

		$row = $repo->find_by_invocation_id( $invocation_id );
		$this->assertSame( 'rolled_back', $row['status'] );
	}
}
