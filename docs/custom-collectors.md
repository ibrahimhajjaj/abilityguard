# Writing your own collector

AbilityGuard ships five built-in collectors: `post_meta`, `options`, `taxonomy`, `user_role`, and `files`. If your plugin manages state that none of those handle - custom database tables, external API state, Redis keys - you need a custom collector.

## The `CollectorInterface` contract

Namespace: `AbilityGuard\Snapshot\Collector\CollectorInterface`

```php
interface CollectorInterface {
    /**
     * Read current values for the declared spec.
     *
     * @param mixed $spec Surface-specific spec (your own shape).
     * @return array<mixed>
     */
    public function collect( $spec ): array;

    /**
     * Restore previously-captured state. Inverse of collect().
     *
     * @param array<mixed> $captured Payload returned by collect() earlier.
     */
    public function restore( array $captured ): void;
}
```

Rules:
- `collect()` must be a pure read: no side effects, no writes.
- `restore()` must be idempotent: calling it twice with the same captured payload must leave the system in the same state as calling it once.
- Both methods must be safe to call when the resource no longer exists (e.g. a row that was deleted between capture and restore).
- Large payloads are subject to `abilityguard_max_snapshot_bytes` truncation. Keep captured payloads as small as possible - capture only the fields you can restore, not entire rows.

## Worked example: `MailingListCollector`

A fictional collector for a custom `acme_subscribers` table.

```php
<?php

declare( strict_types=1 );

namespace AcmePlugin\AbilityGuard;

use AbilityGuard\Snapshot\Collector\CollectorInterface;

/**
 * Captures + restores rows from the acme_subscribers table.
 *
 * Spec shape:    int[] $subscriber_ids
 * Captured shape: array<int, array{ email: string, status: string }|null>
 *                 null means the row did not exist at snapshot time.
 */
final class MailingListCollector implements CollectorInterface {

    public function collect( $spec ): array {
        if ( ! is_array( $spec ) ) {
            return array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'acme_subscribers';
        $out   = array();

        foreach ( $spec as $id ) {
            $id  = (int) $id;
            $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare( "SELECT email, status FROM {$table} WHERE id = %d LIMIT 1", $id ),
                ARRAY_A
            );

            $out[ $id ] = null !== $row
                ? array( 'email' => (string) $row['email'], 'status' => (string) $row['status'] )
                : null; // row absent at snapshot time
        }

        ksort( $out );
        return $out;
    }

    public function restore( array $captured ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'acme_subscribers';

        foreach ( $captured as $id => $state ) {
            $id = (int) $id;

            if ( null === $state ) {
                // Row did not exist at snapshot time - delete it now.
                $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                continue;
            }

            // Restore the captured values (update or insert).
            $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d LIMIT 1", $id )
            );

            if ( $exists ) {
                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $table,
                    array( 'email' => $state['email'], 'status' => $state['status'] ),
                    array( 'id' => $id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $table,
                    array( 'id' => $id, 'email' => $state['email'], 'status' => $state['status'] ),
                    array( '%d', '%s', '%s' )
                );
            }
        }
    }
}
```

## Registering a custom collector for an ability

As of v0.8, the cleanest path is the **`safety.collectors` shorthand**:

```php
'safety' => array(
    'destructive' => true,
    'snapshot'    => array(
        'mailing_list' => array( 101, 102, 103 ),
    ),
    'collectors'  => array(
        'mailing_list' => new \AcmePlugin\AbilityGuard\MailingListCollector(),
    ),
),
```

What happens behind the scenes:

- At `wp_register_ability_args` time, AbilityGuard pulls each entry out of `safety.collectors` and passes it to `CollectorRegistry::register()`. The registration is global to the request - once you register a `mailing_list` collector for one ability, every other ability using the `mailing_list` surface name in this request will see it too.
- `safety.collectors` is then stripped before the rest of the wrapper sees `safety[]`, so it never reaches the audit row.
- Keys matching built-in surfaces (`post_meta`, `options`, `taxonomy`, `user_role`, `files`) are silently ignored - `CollectorRegistry::defaults()` always wins for those names. If you want to swap a built-in collector, construct your own `SnapshotService` directly instead.

That's it. No need to track `invocation_id` in a global, no `abilityguard_rollback` listener, no side-channel option to garbage-collect.

### Older patterns (still valid)

If you can't use `safety.collectors` (for example, you need a different collector instance per invocation, or you want to swap a built-in), two escape hatches still ship:

#### A. Action-based snapshot side-channel

Implement your own collector, run `collect()` inside your ability's `execute_callback`, persist the captured payload as a WordPress option keyed by `invocation_id`, and restore via the `abilityguard_rollback` action. AbilityGuard fires `abilityguard_invocation_started` with the invocation id before your callback runs, so you can stash it in a global for use during execute:

```php
add_action(
    'abilityguard_invocation_started',
    static function ( string $invocation_id ): void {
        $GLOBALS['abilityguard_current_invocation_id'] = $invocation_id;
    }
);

add_action(
    'abilityguard_rollback',
    static function ( array $log ): void {
        if ( 'acme-plugin/update-subscribers' !== ( $log['ability_name'] ?? '' ) ) {
            return;
        }
        $key      = 'acme_snapshot_' . $log['invocation_id'];
        $captured = get_option( $key );
        if ( is_array( $captured ) ) {
            ( new \AcmePlugin\AbilityGuard\MailingListCollector() )->restore( $captured );
            delete_option( $key );
        }
    },
    10,
    1
);
```

End-to-end reference: `examples/abilityguard-transient-collector/`. It ships a working `TransientCollector` exercised through this pattern.

Notes:
- Use a `safety.snapshot` callable returning `array()` (or any built-in spec you also need) so AbilityGuard treats your ability as safety-bearing - that's what triggers the wrapper, the lock, and the audit row. Without `safety` set, none of the actions fire.
- The `abilityguard_rollback` action receives `( $log, $snapshot, $drifted_surfaces )` (3 args). Subscribe with `accepted_args=1` if you only need the log.
- Clean up your stash option in `abilityguard_retention_prune` if you want it pruned alongside log rows; AbilityGuard does not garbage-collect side-channel options for you.

#### B. Inject your own services

If you control the dispatch path (e.g. you call `wp_get_ability(...)->execute(...)` from your own code rather than going through REST/MCP), you can construct your own `SnapshotService` + `RollbackService` with a custom collector map:

```php
$service = new \AbilityGuard\Snapshot\SnapshotService(
    new \AbilityGuard\Snapshot\SnapshotStore(),
    array(
        // built-ins …
        'post_meta'    => new \AbilityGuard\Snapshot\Collector\PostMetaCollector(),
        // your custom one:
        'mailing_list' => new \AcmePlugin\AbilityGuard\MailingListCollector(),
    )
);
```

This bypasses the global wrapper, so you also lose the lock, the audit row, the approval gate, and drift detection - only useful when you're building a parallel pipeline (e.g. your own bulk import tool that wants snapshot+restore semantics without going through the abilities API).

### Drift-check semantics for custom surfaces

When you register a custom surface via `safety.collectors`, AbilityGuard's drift detector at rollback time calls your collector's `collect()` again with `array_keys($captured)` as the spec - i.e. it derives the spec from the captured state's top-level keys. That works for collectors that capture by id (`string[]` or `int[]` keyed maps, like the `MailingListCollector` example above). If your spec is richer (nested filter arrays, time windows), you have two options:

1. Make your collector tolerant of `string[]` spec input (treat each as an id and re-read live state).
2. Skip drift detection for that ability with `safety.skip_drift_check = true` and accept that rollback will overwrite drifted state.

## Key pitfalls

**Stale captures.**
If `collect()` is called and the resource is locked, in a transaction, or behind a cache layer, you may capture stale data. Flush caches before reading:

```php
wp_cache_delete( $id, 'acme_subscribers' );
```

**Large payloads.**
Capturing entire table rows for hundreds of ids can blow past `abilityguard_max_snapshot_bytes` (default 1 MB). Capture only the columns you can restore: avoid BLOBs, large text fields, or computed columns.

**Restore failures.**
Your `restore()` must not throw. Wrap database operations in a try/catch or check for `$wpdb->last_error` and log failures gracefully. A thrown exception from `restore()` will surface as an unhandled error in the rollback path.

**Not handling the null/absent case.**
If a row is created by your ability and did not exist at snapshot time, `collect()` must record `null` (or equivalent) for that id. `restore()` must then delete the row. If you omit this, rolling back a create operation will leave an orphan row.

## Testing your collector

AbilityGuard's integration suite uses real `$wpdb` and real WordPress functions against a live test database. Match that pattern:

```php
<?php

declare( strict_types=1 );

use AcmePlugin\AbilityGuard\MailingListCollector;
use WP_UnitTestCase;

final class MailingListCollectorTest extends WP_UnitTestCase {

    private function insert_subscriber( string $email, string $status ): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'acme_subscribers',
            array( 'email' => $email, 'status' => $status ),
            array( '%s', '%s' )
        );
        return (int) $wpdb->insert_id;
    }

    public function test_collect_and_restore_round_trip(): void {
        $id = $this->insert_subscriber( 'user@example.com', 'active' );

        $collector = new MailingListCollector();
        $captured  = $collector->collect( array( $id ) );

        // Mutate.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'acme_subscribers',
            array( 'status' => 'unsubscribed' ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );

        // Restore.
        $collector->restore( $captured );

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT status FROM ' . $wpdb->prefix . 'acme_subscribers WHERE id = %d', $id ),
            ARRAY_A
        );
        $this->assertSame( 'active', $row['status'] );
    }

    public function test_restore_deletes_row_absent_at_capture_time(): void {
        $collector = new MailingListCollector();
        // Capture state when id=999 does not exist.
        $captured  = $collector->collect( array( 999 ) );
        $this->assertNull( $captured[999] );

        // Create the row after capture.
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'acme_subscribers',
            array( 'id' => 999, 'email' => 'ghost@example.com', 'status' => 'active' ),
            array( '%d', '%s', '%s' )
        );

        // Restore should delete it.
        $collector->restore( $captured );

        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'acme_subscribers WHERE id = %d', 999 )
        );
        $this->assertNull( $exists );
    }
}
```

Run your test alongside the AbilityGuard suite:

```bash
composer env:start
composer test:integration
composer env:stop
```

Or, if you have a separate wp-env for your plugin, reference AbilityGuard's bootstrap:

```bash
phpunit -c your-phpunit.xml.dist --bootstrap path/to/abilityguard/tests/bootstrap-integration.php
```
