<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Diff\DiffService;
use AbilityGuard\Installer;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Integration tests for DiffService.
 *
 * Covers:
 *  1. Happy-path: mixed changed/unchanged keys, post_meta + options surfaces.
 *  2. Graceful handling of a snapshot with no post_state.
 */
final class DiffServiceTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	public function test_compute_produces_correct_rows_for_mixed_surfaces(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '10.00' );
		update_post_meta( $post_id, '_sku', 'ABC' );
		update_option( 'demo_opt', 'before' );
		update_option( 'stable_opt', 'constant' );

		$store   = new SnapshotStore();
		$service = new SnapshotService( $store );

		$safety = array(
			'snapshot' => array(
				'post_meta' => array( $post_id => array( '_price', '_sku' ) ),
				'options'   => array( 'demo_opt', 'stable_opt' ),
			),
		);

		$snap = $service->capture( 'diff-test-1', $safety, null );
		$this->assertNotNull( $snap['snapshot_id'] );

		// Mutate only some values.
		update_post_meta( $post_id, '_price', '20.00' );
		// _sku stays 'ABC'
		update_option( 'demo_opt', 'after' );
		// stable_opt stays 'constant'

		$service->capture_post( $snap['snapshot_id'], $safety, null );

		$snapshot = $store->find_by_invocation_id( 'diff-test-1' );
		$this->assertNotNull( $snapshot );

		$diff = ( new DiffService() )->compute( $snapshot );
		$this->assertNotEmpty( $diff );

		// Index by surface+key for easy assertions.
		$indexed = array();
		foreach ( $diff as $row ) {
			$indexed[ $row['surface'] . '|' . $row['key'] ] = $row;
		}

		$price_key = 'post_meta · post #' . $post_id . '|_price';
		$sku_key   = 'post_meta · post #' . $post_id . '|_sku';

		$this->assertArrayHasKey( $price_key, $indexed );
		$this->assertSame( '10.00', $indexed[ $price_key ]['before'] );
		$this->assertSame( '20.00', $indexed[ $price_key ]['after'] );
		$this->assertTrue( $indexed[ $price_key ]['changed'] );

		$this->assertArrayHasKey( $sku_key, $indexed );
		$this->assertSame( 'ABC', $indexed[ $sku_key ]['before'] );
		$this->assertSame( 'ABC', $indexed[ $sku_key ]['after'] );
		$this->assertFalse( $indexed[ $sku_key ]['changed'] );

		$this->assertArrayHasKey( 'options|demo_opt', $indexed );
		$this->assertSame( 'before', $indexed['options|demo_opt']['before'] );
		$this->assertSame( 'after', $indexed['options|demo_opt']['after'] );
		$this->assertTrue( $indexed['options|demo_opt']['changed'] );

		$this->assertArrayHasKey( 'options|stable_opt', $indexed );
		$this->assertSame( 'constant', $indexed['options|stable_opt']['before'] );
		$this->assertSame( 'constant', $indexed['options|stable_opt']['after'] );
		$this->assertFalse( $indexed['options|stable_opt']['changed'] );
	}

	public function test_compute_gracefully_handles_missing_post_state(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_flag', 'yes' );
		update_option( 'some_opt', 'val' );

		$store   = new SnapshotStore();
		$service = new SnapshotService( $store );

		$safety = array(
			'snapshot' => array(
				'post_meta' => array( $post_id => array( '_flag' ) ),
				'options'   => array( 'some_opt' ),
			),
		);

		$snap = $service->capture( 'diff-test-2', $safety, null );
		// Intentionally do NOT call capture_post - post_state stays null.

		$snapshot = $store->find_by_invocation_id( 'diff-test-2' );
		$this->assertNotNull( $snapshot );
		$this->assertNull( $snapshot['post_state'] );

		$diff = ( new DiffService() )->compute( $snapshot );
		$this->assertNotEmpty( $diff );

		foreach ( $diff as $row ) {
			$this->assertNull( $row['after'], 'after must be null when post_state is absent' );
			$this->assertFalse( $row['changed'], 'changed must be false when post_state is absent' );
		}
	}
}
