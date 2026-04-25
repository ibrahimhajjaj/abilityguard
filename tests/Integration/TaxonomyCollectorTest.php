<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Installer;
use AbilityGuard\Snapshot\Collector\TaxonomyCollector;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Integration tests for TaxonomyCollector against real $wpdb.
 */
final class TaxonomyCollectorTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	public function test_collect_and_restore_original_terms(): void {
		$post_id = self::factory()->post->create();

		// Create two terms and assign them.
		$term_a = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$term_b = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_set_object_terms( $post_id, array( $term_a, $term_b ), 'category' );

		$collector = new TaxonomyCollector();
		$spec      = array( $post_id => array( 'category' ) );

		// Snapshot original state.
		$captured = $collector->collect( $spec );

		// Verify captured structure.
		$this->assertArrayHasKey( $post_id, $captured );
		$this->assertArrayHasKey( 'category', $captured[ $post_id ] );
		$this->assertCount( 2, $captured[ $post_id ]['category'] );
		$this->assertContains( $term_a, $captured[ $post_id ]['category'] );
		$this->assertContains( $term_b, $captured[ $post_id ]['category'] );

		// Mutate: replace with a different term.
		$term_c = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_set_object_terms( $post_id, array( $term_c ), 'category' );

		$mutated = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$this->assertIsArray( $mutated );
		$this->assertContains( $term_c, $mutated );
		$this->assertNotContains( $term_a, $mutated );

		// Restore.
		$collector->restore( $captured );

		$restored = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$this->assertIsArray( $restored );
		$this->assertCount( 2, $restored );
		$this->assertContains( $term_a, array_map( 'intval', $restored ) );
		$this->assertContains( $term_b, array_map( 'intval', $restored ) );
	}

	public function test_collect_skips_wp_error_taxonomy(): void {
		$post_id   = self::factory()->post->create();
		$collector = new TaxonomyCollector();

		// 'nonexistent_tax' is not registered; wp_get_object_terms returns WP_Error.
		$captured = $collector->collect( array( $post_id => array( 'nonexistent_tax' ) ) );

		// Should have the post key but no entry for the invalid taxonomy.
		$this->assertArrayHasKey( $post_id, $captured );
		$this->assertArrayNotHasKey( 'nonexistent_tax', $captured[ $post_id ] );
	}

	public function test_defaults_wiring_persists_taxonomy_surface(): void {
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		wp_set_object_terms( $post_id, array( $term_id ), 'post_tag' );

		$service = new SnapshotService( new SnapshotStore() );
		$result  = $service->capture(
			'inv-tax-wire-1',
			array(
				'snapshot' => array(
					'taxonomy' => array( $post_id => array( 'post_tag' ) ),
				),
			),
			null
		);

		$this->assertNotNull( $result['snapshot_id'] );
		$this->assertArrayHasKey( 'taxonomy', $result['surfaces'] );
	}
}
