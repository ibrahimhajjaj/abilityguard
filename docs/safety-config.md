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
    'category'    => 'my-plugin',
    // ... other core ability keys ...
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
| `skip_drift_check` | `bool` | `false` | **Note:** this key is documented as a planned per-registration override but is not yet wired from `safety` config into the audit row. In v0.3 the drift bypass is set via a `log_meta` row (`meta_key = skip_drift_check`, `meta_value = 1`). A `safety.skip_drift_check` shorthand is scheduled for v0.4. |

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

**Spec shape:** `string[]` (absolute filesystem paths)

```php
'files' => array(
    WP_CONTENT_DIR . '/uploads/my-plugin/export.csv',
),
```

**Captures:** for each path: `exists`, `sha256`, `size`, `mtime`.

**Does NOT restore file contents.** `FilesCollector::restore()` is a no-op. On rollback it fires `do_action( 'abilityguard_files_changed_since_snapshot', $changed_paths )` with paths whose sha256 differs from the captured state, so you can hook in your own restore logic.

If you need real file rollback, hook that action:

```php
add_action( 'abilityguard_files_changed_since_snapshot', function ( array $paths ): void {
    foreach ( $paths as $path ) {
        // restore from your own backup store
    }
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

For idempotent or append-only operations where overwriting drifted state is safe, you can bypass the check:

**Current mechanism (v0.3):** write a `log_meta` row after the invocation completes:

```php
global $wpdb;
$wpdb->insert(
    $wpdb->prefix . 'abilityguard_log_meta',
    array(
        'log_id'     => $log_id,
        'meta_key'   => 'skip_drift_check',
        'meta_value' => '1',
    ),
    array( '%d', '%s', '%s' )
);
```

**Note:** The `RollbackService` docblock mentions `safety.skip_drift_check = true` as a per-registration shorthand, but that wiring is not implemented in v0.3. The `log_meta` approach above is the only working path. A `safety.skip_drift_check` shorthand is planned for v0.4.

When the drift check is skipped, the `abilityguard_rollback_drift` action still fires for observability if drift is present.

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
