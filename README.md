# AbilityGuard

Snapshot + audit + rollback + approval middleware for the WordPress Abilities API.

**Status:** v0.2-dev. Not production ready.

## What it is

A developer library for plugin authors who register abilities via `wp_register_ability()` and want snapshot capture, audit logging, approval workflows, and one-click rollback for every invocation - across REST, MCP, internal PHP, and wp-cli - without building it themselves.

Declare what state your ability touches; AbilityGuard handles the safety wrapper.

```php
wp_register_ability( 'my-plugin/update-product-price', array(
    'label'               => 'Update product price',
    'description'         => 'Updates the price on a WooCommerce product.',
    'category'            => 'woocommerce',
    'input_schema'        => array( /* ... */ ),
    'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
    'execute_callback'    => fn( $args ) => update_post_meta( $args['product_id'], '_price', $args['price'] ),

    // AbilityGuard extension (stripped before core validation):
    'safety' => array(
        'destructive'       => true,
        'requires_approval' => false,                // optional; true blocks until human approves
        'snapshot'          => fn( $input ) => array(
            'post_meta' => array( $input['product_id'] => array( '_price', '_regular_price' ) ),
            'options'   => array( 'woocommerce_last_price_change' ),
            // Other supported surfaces: 'taxonomy', 'user_role', 'files'
        ),
    ),
) );
```

## What you get out of the box

- **Pre + post snapshots.** Every safety-enabled invocation captures declared state before the callback and (on success) after - so the audit log can show a real diff, not just hashes.
- **Audit log.** One row per invocation with ability name, caller (`rest|mcp|cli|internal|...`), `caller_id` (e.g. MCP server name when invoked through mcp-adapter), user, args, result, status, duration, pre/post hashes.
- **One-click rollback.** Restore captured state from `post_meta`, `options`, taxonomy term assignments, user roles + caps. File-content rollback is intentionally out of scope (v0.2 fingerprints files and emits a `abilityguard_files_changed_since_snapshot` action; v0.3 may add real file restore).
- **Approval queue.** When `safety.requires_approval => true`, the wrapper blocks execution, logs the row as `pending`, persists the input, and returns `WP_Error('abilityguard_pending_approval', 202)`. A human approves or rejects via wp-admin or `wp abilityguard approval`.
- **Retention.** Daily WP cron prunes old log rows (defaults: 30 days normal, 180 days destructive) and orphaned snapshots. Both windows are filterable.
- **MCP client attribution.** When an ability is invoked via the WordPress MCP adapter, the audit row records which MCP server made the call.

## Surfaces (interfaces to your plugin)

- **PHP API** - `wp_register_ability( $name, [ ..., 'safety' => [...] ] )` and helpers `abilityguard_rollback`, `abilityguard_snapshot_meta`, `abilityguard_snapshot_options`.
- **REST** - `GET /abilityguard/v1/log`, `GET /log/<id>` (returns log row + decoded snapshot), `POST /rollback/<id>`.
- **WP-CLI** - `wp abilityguard log list/show`, `wp abilityguard rollback <id>`, `wp abilityguard approval list/approve/reject <id>`, `wp abilityguard prune`.
- **wp-admin** - Tools → AbilityGuard. Hybrid timeline + command-palette search + per-day pagination, snapshot drawer, JSON-highlighted Input/Result tabs, real rollback against the captured snapshot.

## Documentation

Plugin-author guides live in `docs/`:

- [docs/safety-config.md](docs/safety-config.md) - Adding the safety config to your ability: full schema reference, snapshot resolver forms, all five surfaces, redaction, payload caps, and common mistakes.
- [docs/approval-workflow.md](docs/approval-workflow.md) - Approval queue: when to use `requires_approval`, the approve/reject lifecycle, CLI commands, and integration recipes.
- [docs/custom-collectors.md](docs/custom-collectors.md) - Writing your own collector: the `CollectorInterface` contract, a worked example, current extensibility limits, and testing patterns.

## Reference plugins

`examples/abilityguard-woocommerce-pack/` and `examples/abilityguard-fluent-forms-pack/` - minimal third-party plugins that depend on AbilityGuard and demonstrate the `safety` config pattern. Each ships with its own integration test.

## Development

Requires PHP 8.1+, Composer, Node 20+, Docker.

```bash
composer install
npm install && npm run build      # bundles assets/admin.jsx → assets/admin.js

# Pure unit tests (Hash + Json - fast, no DB).
composer test

# Lint + static analysis.
composer lint
composer stan

# Real integration tests against WordPress + MySQL via wp-env.
composer env:start
composer test:integration
composer env:stop
```

`wp-env` is pinned to **dev: 18888 / tests: 18889** so this project doesn't collide with other wp-env projects you might run on 8888.

The integration suite is the primary correctness source - 54 tests covering installer schema, snapshot/audit/rollback round-trip, the approval queue, all five collectors, retention pruning, MCP identity, the post-state diff path, and both example plugins.

If you use Claude Code worktrees, drop a local `phpcs.xml` (not committed) so PHPCS doesn't scan `.claude/`:

```xml
<?xml version="1.0"?>
<ruleset name="AbilityGuard (local)">
    <rule ref="./phpcs.xml.dist"/>
    <exclude-pattern>*/.claude/*</exclude-pattern>
</ruleset>
```

## Known limitations (v0.2)

- **No concurrency lock around capture+execute.** Two simultaneous invocations of an ability touching the same surface (e.g. the same `post_meta` key) capture each other's mid-states; rollback restores to "the other invocation's output," not "before either ran." If your ability is expected to run concurrently against shared state, consider serialising at the application level. v0.4 may add advisory locking via `get_option`-based mutexes or the `wp_locks` API.
- **No drift check on rollback (in v0.2).** Rollback blindly overwrites live state with the captured snapshot. If another plugin or user mutated the same surface after the snapshot was taken, those changes are clobbered. v0.3 adds a `pre_hash` drift check with a `--force` opt-out.
- **No payload size or secret redaction (in v0.2).** `args_json`, `result_json`, and snapshot surfaces are stored verbatim. Don't pass secrets through ability inputs, and don't snapshot options containing large serialised blobs. v0.3 adds configurable size caps and a `safety.redact` / `safety.scrub` redaction layer.
- **File-content rollback is intentionally out of scope.** `FilesCollector` captures sha256 fingerprints and emits `abilityguard_files_changed_since_snapshot` on restore, but doesn't write file contents back. If you need real file rollback, hook the action and implement your own restore.

## Schema

Four tables, all prefixed `wp_abilityguard_`:

- `log` - one row per invocation. `id, invocation_id, ability_name, caller_type, caller_id, user_id, args_json, result_json, status, destructive, duration_ms, pre_hash, post_hash, snapshot_id, created_at`.
- `log_meta` - extensible key/value attached to a log row (reserved for v0.3+).
- `snapshots` - gzipped pre-state and (when callback succeeds) post-state per invocation.
- `approvals` - pending approval requests when `safety.requires_approval` is set.

## License

GPL-2.0-or-later.
