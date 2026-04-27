# Adding the safety config to your ability

AbilityGuard intercepts any ability whose registration includes a `safety` key. You declare what state your ability touches; the library handles snapshot, audit, and rollback.

## Prerequisite: register a category before your abilities

> **Read this before copying any of the snippets below.** The Abilities API requires every ability to belong to a registered category. If the category in your `safety`-bearing registration doesn't exist yet, the registration fails silently - `wp_get_ability( 'my-plugin/x' )` returns `null`, the wrapper never runs, and you get a `_doing_it_wrong` notice in the debug log instead of a snapshot.

Register your category once, on `wp_abilities_api_categories_init`, before any `wp_register_ability()` call:

```php
add_action( 'wp_abilities_api_categories_init', static function ( $registry ): void {
    $registry->register( 'my-plugin', array(
        'label'       => 'My Plugin',
        'description' => 'Abilities exposed by My Plugin.',
    ) );
} );
```

Then use that category slug in every ability's `category` field. You can put both action callbacks (categories + abilities) in the same plugin file - order doesn't matter, but the category must be REGISTERED on its action before any ability that uses it.

---

## 30-second TL;DR

```php
// (Assumes you've registered the 'my-plugin' category - see above.)
wp_register_ability( 'my-plugin/do-something', array(
    'label'               => 'Do something',
    'description'         => 'Toggles my_plugin_setting.',
    'category'            => 'my-plugin',
    'input_schema'        => array( 'type' => 'object' ), // Abilities API requires this even when input is freeform.
    'permission_callback' => static fn() => current_user_can( 'manage_options' ),
    'execute_callback'    => static fn( $input ) => update_option( 'my_plugin_setting', 'on' ),
    'safety' => array(
        'destructive' => true,
        'snapshot'    => array(
            'options' => array( 'my_plugin_setting' ),
        ),
    ),
) );
```

That is the minimum. AbilityGuard will capture `my_plugin_setting` before execution, record a diff after, and make the invocation rollback-eligible.

---

## Testing your ability via wp-cli

`wp eval` and `wp eval-file` run with no current user. If your `permission_callback` checks a capability (it should), the ability will refuse to run with `"does not have necessary permission"`. Set the current user explicitly in test scripts:

```bash
wp eval '
    $admin = get_users(["role" => "administrator", "number" => 1])[0];
    wp_set_current_user( $admin->ID );

    $ability = wp_get_ability( "my-plugin/do-something" );
    $result  = $ability->execute( ["foo" => "bar"] );
    var_dump( $result );
'
```

Same applies to direct PHP scripts run via cron, queue workers, or any context that doesn't pass through the REST stack.

For multi-line tests or anything touching `AbilityGuard\` namespaced classes (`LogMeta`, `CriticalFileRegistry`, etc.), prefer `wp eval-file path/to/script.php` over inline `wp eval '...'`. Backslashes in fully-qualified class names don't survive most shells, and you'll get a confusing "critical error on this website" instead of a real error message.

---

## Full `safety` config schema

| Key | Type | Default | Description |
|---|---|---|---|
| `snapshot` | `array\|callable` | - | Declares which surfaces to capture. See [Snapshot resolver](#snapshot-resolver) below. |
| `destructive` | `bool` | `false` | Marks the invocation as destructive. Currently: longer retention window (180 days vs 30) and a highlight in the admin UI. |
| `requires_approval` | `bool` | `false` | When `true`, execution is blocked until a human approves. See [approval-workflow.md](approval-workflow.md). |
| `redact` | `array` | `[]` | Per-kind paths to redact before logging. Shape: `['input' => [...], 'result' => [...], 'surfaces' => ['post_meta' => [...]]]`. See [Redaction](#redaction). |
| `scrub` | `callable` | `null` | Full replace for redaction. Receives `($value, $kind)` where `$kind` is `'input'` or `'result'`. When set, `redact` is ignored for input/result logging. |
| `max_payload_bytes` | `int` | filter-driven | Per-ability byte cap for args, result, and snapshot payloads. `0` = unlimited. See [Payload caps](#payload-caps). |
| `skip_drift_check` | `bool` | `false` | When `true`, RollbackService bypasses the live-vs-snapshot post-state hash comparison and restores regardless. The `abilityguard_rollback_drift` action still fires for observability. AbilityGuard wires this into a `log_meta` row automatically - you don't need to write the meta yourself. |
| `lock_timeout` | `int` | filter-driven (default `5`) | Per-ability seconds to wait for the per-surface MySQL advisory lock before refusing the invocation. Set to a negative value to disable locking entirely for this ability. |

---

## Snapshot resolver

`safety.snapshot` can be a **static array** or a **callable**.

### Static array form

Use when the surfaces to capture are the same for every invocation:

```php
'safety' => array(
    'snapshot' => array(
        'options' => array( 'my_plugin_setting', 'my_plugin_last_run' ),
    ),
),
```

### Callable form

Use when the surfaces depend on the input (the common case for `post_meta`). The callable receives the raw `$input` array and must return a spec array:

```php
'safety' => array(
    'snapshot' => static function ( mixed $input ): array {
        $product_id = (int) ( $input['product_id'] ?? 0 );
        return array(
            'post_meta' => array(
                $product_id => array( '_price', '_regular_price' ),
            ),
        );
    },
),
```

### Worked example - WooCommerce product price

Lifted from `examples/abilityguard-woocommerce-pack/`:

```php
wp_register_ability( 'acme-shop/update-product-price', array(
    'label'               => 'Update product price',
    'description'         => 'Sets _regular_price and _price on a product post.',
    'category'            => 'acme-shop',
    'permission_callback' => static function (): bool {
        return current_user_can( 'manage_options' );
    },
    'execute_callback'    => static function ( mixed $input ): array {
        $product_id    = (int) ( $input['product_id'] ?? 0 );
        $regular_price = (string) ( $input['regular_price'] ?? '' );

        update_post_meta( $product_id, '_regular_price', $regular_price );
        update_post_meta( $product_id, '_price', $regular_price );

        return array( 'success' => true, 'product_id' => $product_id );
    },
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'product_id'    => array( 'type' => 'integer' ),
            'regular_price' => array( 'type' => 'string' ),
        ),
        'required' => array( 'product_id', 'regular_price' ),
    ),
    'safety' => array(
        'destructive' => true,
        'snapshot'    => static function ( mixed $input ): array {
            $product_id = (int) ( $input['product_id'] ?? 0 );
            return array(
                'post_meta' => array(
                    $product_id => array( '_price', '_regular_price' ),
                ),
            );
        },
    ),
) );
```

---

## Supported surfaces

### `post_meta`

**Spec shape:** `array<int $post_id, string[] $meta_keys>`

```php
'post_meta' => array(
    42 => array( '_price', '_regular_price' ),
    99 => array( '_stock_status' ),
),
```

**Captures:** the single-value result of `get_post_meta( $post_id, $key, true )` for each declared key.

**Restores:** calls `update_post_meta` for keys that had a value; calls `delete_post_meta` for keys that were empty/null at capture time.

---

### `options`

**Spec shape:** `string[]` (option names)

```php
'options' => array( 'woocommerce_currency', 'my_plugin_last_run' ),
```

**Captures:** `get_option( $name )` for each name. Missing options are stored with a sentinel (`__abilityguard_missing__`) so restore can delete rather than write an empty value.

**Restores:** `update_option` for present values; `delete_option` for options that were absent at capture time.

---

### `taxonomy`

**Spec shape:** `array<int $post_id, string[] $taxonomy_names>`

```php
'taxonomy' => array(
    42 => array( 'product_cat', 'product_tag' ),
),
```

**Captures:** term ids (`wp_get_object_terms( $post_id, $taxonomy, ['fields' => 'ids'] )`).

**Restores:** `wp_set_object_terms( $post_id, $term_ids, $taxonomy )`.

---

### `user_role`

**Spec shape:** `int[]` (user ids)

```php
'user_role' => array( $user_id ),
```

**Captures:** `WP_User->roles` and `WP_User->caps` for each user id.

**Restores:** calls `remove_all_caps()` then re-adds each captured role and capability. Silently skips user ids that no longer exist.

---

### `files`

**Spec shape:** `string[]` of absolute paths, OR an array `{ paths, strategy?, exclude_dirs? }` for tier control. `paths` may be any `Traversable` so generators work for very large path universes.

```php
// Simple form - string[] of paths.
'files' => array(
    WP_CONTENT_DIR . '/uploads/my-plugin/export.csv',
),

// Explicit form - choose a detection tier.
'files' => array(
    'paths'    => array( ABSPATH . '/.htaccess', ABSPATH . '/wp-config.php' ),
    'strategy' => 'critical_hash',
),
```

**Captures:** for each path: `exists`, `sha256` (only when the strategy hashes), `size`, `mtime`.

**Detection strategies:**

| Strategy | Hashes | Use when |
|---|---|---|
| `mtime` | never | Cheapest. Catches naïve edits, misses content-preserving rsync/touch tricks. Drift-only - restore is a no-op. |
| `mtime_size` | never | Better signal than mtime alone for very large path counts. Drift-only. |
| `critical_hash` | only paths matching `CriticalFileRegistry::matches()` | Default-ish: hash the high-value files (`wp-config.php`, `.env`, `.htaccess`, anything plugins register), stat the rest. Drift-only. |
| `full_hash` | every path | Most accurate, slowest. Default for back-compat. Drift-only. |
| `full_content` | every path AND captures bytes | The only strategy that actually rewrites file contents on rollback. Use for small config-style files (256 KB cap per file by default). |

Override the global default with the `abilityguard_files_default_strategy` filter.

**`full_content` - real file rollback (v0.9).** Set `strategy => 'full_content'` to opt into actual byte-level capture and restore:

```php
'snapshot' => array(
    'files' => array(
        'paths'    => array( WP_CONTENT_DIR . '/uploads/my-plugin/config.json' ),
        'strategy' => 'full_content',
    ),
),
```

What you get:

- File bytes are AES-256-GCM encrypted and stored in `wp-content/abilityguard-staging/<sha256>` - content-addressed, so identical files dedupe across snapshots.
- Octal mode (e.g. 0644) is captured and restored. Owner/group are not preserved (avoids www-data/nginx UID-mismatch headaches).
- On rollback, files are rewritten via temp-file + `rename()` (POSIX-atomic on the same filesystem). Deleted files are re-created from their captured blob.
- Path safety: every restore target is validated against directory traversal, null bytes, and `realpath()` containment within `ABSPATH`. Paths failing validation are skipped silently.
- Tampered blobs (anyone editing the files in the staging dir) fail the post-decrypt sha256 check and the file is left alone - failsafe rather than corrupt.
- Files larger than `abilityguard_max_file_bytes` (default 256 KB) fall back to fingerprint-only with a `_doing_it_wrong` notice. Raise via the filter if you genuinely need bigger captures, but consider whether the file belongs in the snapshot at all.
- Successfully-rewritten files fire `abilityguard_files_restored` (in addition to the existing `abilityguard_files_changed_since_snapshot` action) so observers can distinguish "we rewrote bytes" from "drift was detected".

Retention runs an orphan sweep on the staging dir alongside the snapshots table - blobs no longer referenced by any live snapshot get unlinked.

**`CriticalFileRegistry`.** Plugins can register additional critical-path matchers imperatively:

```php
use AbilityGuard\Snapshot\Collector\CriticalFileRegistry;

CriticalFileRegistry::add( '/wp-config-sample.php' );
CriticalFileRegistry::add( '.env.production' );
```

The `abilityguard_files_critical_suffixes` filter still wins for global overrides - the registry just seeds defaults.

**Does NOT restore file contents.** `FilesCollector::restore()` is a no-op for content. On rollback it fires two actions:

- `abilityguard_files_changed_since_snapshot` - paths whose state differs from the snapshot (any drift signal).
- `abilityguard_files_deleted_since_snapshot` - paths that existed at snapshot time and are now gone (a strict subset of the above).

`RollbackService` automatically pins both lists onto the log row as `files_changed_on_rollback` / `files_deleted_on_rollback` meta, and the admin UI surfaces them on the detail screen. If you also want to perform a real file restore, hook the actions:

```php
add_action( 'abilityguard_files_changed_since_snapshot', function ( array $paths ): void {
    foreach ( $paths as $path ) {
        // restore from your own backup store
    }
} );

add_action( 'abilityguard_files_deleted_since_snapshot', function ( array $paths ): void {
    // Notify oncall / re-create from a known good source.
} );
```

---

## `destructive` flag

Setting `'destructive' => true` does two things in v0.4:

1. The audit log row is stored with `destructive = 1`, which extends its retention window (default 180 days vs 30 days for non-destructive rows).
2. The admin timeline highlights destructive rows visually.

It does not change execution flow - the ability runs regardless. Use it for any operation that overwrites or deletes data that cannot be trivially recreated.

---

## `requires_approval` flag

When `'requires_approval' => true`, AbilityGuard intercepts the invocation before executing the callback:

- Captures a pre-state snapshot.
- Logs the invocation row with `status = pending`.
- Creates an approval request row.
- Returns `WP_Error( 'abilityguard_pending_approval', '...', ['status' => 202, 'approval_id' => N] )` to the caller.

The caller receives a 202-equivalent and must poll or be notified when the decision is made.

See [approval-workflow.md](approval-workflow.md) for the full lifecycle.

---

## Redaction

AbilityGuard redacts values in two places: the **audit log** (`args_json`, `result_json`) and the **snapshot surfaces**.

### Default redacted keys

By default, any key matching (case-insensitive): `password`, `pass`, `passwd`, `token`, `auth_token`, `access_token`, `refresh_token`, `api_key`, `apikey`, `secret`, `authorization`, `cookie`, `set-cookie`.

### Per-ability `redact`

Add paths in dot-notation relative to the value being logged:

```php
'safety' => array(
    'redact' => array(
        'input'    => array( 'payment.card_number', 'pin' ),
        'result'   => array( 'auth_token' ),
        'surfaces' => array(
            'options' => array( 'my_plugin_license_key' ),
        ),
    ),
),
```

### `scrub` callable

For full control over input/result logging, provide a callable. It receives `($value, $kind)` where `$kind` is `'input'` or `'result'`. The `redact` key is ignored for those two kinds when `scrub` is set:

```php
'safety' => array(
    'scrub' => static function ( mixed $value, string $kind ): mixed {
        if ( 'input' === $kind && is_array( $value ) ) {
            unset( $value['raw_password'] );
        }
        return $value;
    },
),
```

**Important:** redacted keys in snapshot surfaces are stored as the sentinel `[redacted]`. Rollback skips any key with that sentinel value and returns `WP_Error( 'abilityguard_rollback_partial' )` unless you pass `force = true`. Do not redact keys you expect to roll back.

---

## Payload caps

### `max_payload_bytes`

Controls the byte limit for `args_json`, `result_json`, and snapshot surface payloads.

Resolution order:
1. `safety.max_payload_bytes` (per-ability; `0` = unlimited).
2. WordPress filters: `abilityguard_max_args_bytes` (default 65,536), `abilityguard_max_result_bytes` (default 131,072), `abilityguard_max_snapshot_bytes` (default 1,048,576).

When a payload is truncated, the stored JSON is replaced with a truncation marker object, and a `_doing_it_wrong` notice fires when `WP_DEBUG` is true.

To disable all caps for a single ability:

```php
'safety' => array(
    'max_payload_bytes' => 0,
    'snapshot'          => ...,
),
```

To raise the cap site-wide for snapshots:

```php
add_filter( 'abilityguard_max_snapshot_bytes', fn() => 4 * 1024 * 1024 ); // 4 MB
```

Truncated snapshots cannot be reliably rolled back. If you see truncation notices, either raise the cap or narrow the snapshot spec.

---

## `skip_drift_check`

Rollback normally compares live state to the post-invocation snapshot before restoring. If another process mutated the same surface after your ability ran, rollback aborts with `WP_Error( 'abilityguard_rollback_drift' )`.

For idempotent or append-only operations where overwriting drifted state is safe, declare the shorthand in your registration:

```php
'safety' => array(
    'destructive'      => true,
    'skip_drift_check' => true,   // bypass the drift check on every invocation of this ability
    'snapshot'         => array( /* ... */ ),
),
```

AbilityGuard writes a `log_meta` row keyed to the invocation; `RollbackService` reads it back when you trigger a rollback. You don't need to write the meta yourself.

For per-invocation overrides (e.g. an emergency one-off), write the row directly:

```php
\AbilityGuard\Audit\LogMeta::set( $log_id, 'skip_drift_check', '1' );
```

When the drift check is skipped, the `abilityguard_rollback_drift` action still fires for observability if drift is present.

---

## `dry_run` (1.3+)

Per-call opt-in. When set, the wrap captures the post-snapshot, computes the diff against pre-state, auto-rolls-back, and persists the diff to log_meta. Your `execute_callback` runs normally and its raw result is returned to the caller, so it still validates against `output_schema`.

```php
'safety' => array(
    'dry_run'  => true,
    'snapshot' => array( 'options' => array( 'my_plugin_setting' ) ),
),
```

The caller learns "this was a dry run" via three out-of-band channels:

- **Log meta on the audit row:** `dry_run=1`, `dry_run_diff=<json>`, `dry_run_rolled_back=0|1`.
- **Response headers** (HTTP only): `X-AbilityGuard-Dry-Run: 1`, `X-AbilityGuard-Invocation-Id: <uuid>`.
- **REST endpoint:** `GET /abilityguard/v1/dry-run/<invocation_id>` returns `{ invocation_id, ability_name, executed_at, rolled_back, diff }`.
- **PHP helper:** `abilityguard_get_dry_run_result( $invocation_id )` returns the same payload.

If `safety.snapshot` is missing the dry-run still tags the row but `rolled_back` is false (nothing to revert). If rollback itself fails the audit row is set to `error`, `dry_run_failed=<error_code>` is recorded, and the caller gets a `WP_Error` so the inconsistency surfaces.

---

## `rate_limits` (1.3+)

Sliding-window-counter rate limiter, evaluated once per declared policy. The call is admitted only if every policy admits it. Algorithm and storage details follow the Cloudflare/Upstash variant; storage is pluggable (Redis, generic object cache, transients fallback) and selected automatically.

```php
'safety' => array(
    'rate_limits' => array(
        'policies' => array(
            array( 'id' => 'burst',     'limit' => 5,   'window' => 1   ),
            array( 'id' => 'sustained', 'limit' => 60,  'window' => 60  ),
        ),
    ),
),
```

`id` doubles as the wire identifier emitted in the IETF `RateLimit-Policy` and `RateLimit` response headers. On rejection the caller gets `WP_Error('abilityguard_rate_limited')` with HTTP `429` and a `Retry-After` header set to the seconds until the most-restrictive exhausted policy frees at least one slot.

Anonymous calls get an IP-hashed bucket (`sha1(REMOTE_ADDR)[0:12]`), authenticated calls get `u:<user_id>`, MCP/app-password clients with a `caller_id` get `c:<caller_id>`. On multisite the principal is suffixed with `@<blog_id>` so sub-sites are separate trust domains.

Storage replacement (e.g. for strict accounting via Lua-script-backed Redis):

```php
add_filter( 'abilityguard_rate_limiter_storage', static function ( $auto ) {
    return new MyStrictRedisStorage();
} );
```

Storage exceptions fail-open: a throwing storage MUST NOT block ability execution. The error is logged via `error_log` and the call admitted (Stripe pattern).

---

## `approval_roles` per stage (1.3+)

Inside a `requires_approval.stages[i]` entry, declare `approval_roles` as a list of WP role slugs. Any-of match: a user holding any listed role passes the role gate. The cap check (`cap`) still runs alongside it.

```php
'safety' => array(
    'requires_approval' => array(
        'stages' => array(
            array(
                'cap'            => 'manage_woocommerce',
                'approval_roles' => array( 'editor', 'shop_manager' ),
            ),
            array(
                'cap'            => 'manage_options',
                'approval_roles' => array( 'administrator' ),
            ),
        ),
    ),
),
```

Separation of duties is enforced across the chain: the same user can never decide two consecutive stages. In multi-role chains the same role can't decide two consecutive stages either. Single-role chains (`['administrator'] -> ['administrator']`) skip the role-SOD check, since otherwise the chain would be unsatisfiable; only the same-user rule fires there.

Rejection error codes: `abilityguard_approve_wrong_role`, `abilityguard_sod_same_user`, `abilityguard_sod_same_role`.

---

## What happens when your ability is invoked

1. **Registration filter.** On `wp_register_ability_args`, AbilityGuard checks for a `safety` key. If present, it wraps your `execute_callback` and strips `safety` before the core registry sees it.

2. **Approval gate.** If `requires_approval => true` and no human approval is in progress, execution stops here: snapshot captured, row logged as `pending`, `WP_Error(202)` returned.

3. **Pre-state snapshot.** The `snapshot` resolver is called with your ability's input. Each declared surface is collected and persisted to `wp_abilityguard_snapshots`. The `pre_hash` of all surfaces is computed.

4. **Execute.** Your original `execute_callback` runs. Exceptions are caught; the status is set to `error` and re-thrown after logging.

5. **Post-state snapshot.** If the callback succeeded, the same collectors run again and the post-state is stored on the same snapshot row. This is used as the drift baseline for future rollbacks.

6. **Audit log.** One row is written to `wp_abilityguard_log` with: ability name, caller type (`rest|mcp|cli|internal`), MCP server id (when applicable), user id, redacted+capped args, result, status, duration, `pre_hash`, `post_hash`, `snapshot_id`.

---

## Common mistakes

**Forgot to declare a touched surface.**
If your callback calls `update_post_meta( $product_id, '_sale_price', ... )` but `_sale_price` is not listed in the snapshot spec, AbilityGuard has no captured value to restore. Rollback will partially succeed (restoring only declared keys) without error.

**Used a closure that captures local state.**
Closures in `snapshot` that close over PHP variables evaluated at registration time will capture stale values. The callable form exists precisely to avoid this - declare it as `static function ( mixed $input )` and derive all ids from `$input`.

**Snapshoting a surface for a not-yet-existing resource.**
If your ability creates a new post, that post does not exist at snapshot time. Do not declare it in `post_meta`. See `examples/abilityguard-fluent-forms-pack/` for the acknowledged limitation: the new post id cannot be rolled back; only side-effects on pre-existing resources can.

**Redacting keys you need to roll back.**
Redacted keys are stored as `[redacted]` and skipped during rollback. If you redact `_price` in surfaces, the rollback for `_price` will fail (partial rollback error) unless you pass `force = true`.

**Large serialised blobs in options.**
Snapshotting a serialised option containing megabytes of data will hit the default 1 MB snapshot cap. Either raise `abilityguard_max_snapshot_bytes` or exclude the large option from the spec and snapshot only the scalar fields you actually need.

---

## Reference plugins

- `examples/abilityguard-woocommerce-pack/` - callable snapshot resolver, `post_meta`, `destructive`.
- `examples/abilityguard-fluent-forms-pack/` - static snapshot resolver, `options`, documented create-operation limitation.
