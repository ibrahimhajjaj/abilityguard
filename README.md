# AbilityGuard

Snapshot + audit + rollback + approval middleware for the WordPress Abilities API.

**Status:** v0.8-dev. Not production ready, but feature-complete for the snapshot/audit/rollback/approval core, with extensibility for custom collectors via `safety.collectors`.

## What it is

A developer library for plugin authors who register abilities via `wp_register_ability()` and want snapshot capture, audit logging, approval workflows, and one-click rollback for every invocation - across REST, MCP, internal PHP, and wp-cli - without building it themselves.

Declare what state your ability touches; AbilityGuard handles the safety wrapper.

> **Heads up:** the Abilities API requires the ability's `category` to already be registered on `wp_abilities_api_categories_init`, otherwise the registration silently no-ops. See [docs/safety-config.md > Prerequisite](docs/safety-config.md#prerequisite-register-a-category-before-your-abilities) for the one-liner.

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
- **Audit log.** One row per invocation with ability name, caller (`rest|mcp|cli|internal|...`), `caller_id` (e.g. MCP server name when invoked through mcp-adapter), user, args, result, status, duration, pre/post hashes, and `parent_invocation_id` for nested calls.
- **One-click rollback.** Restore captured state from `post_meta`, `options`, taxonomy term assignments, user roles + caps. File contents are not rewritten - instead, `FilesCollector` does tiered drift detection (`mtime` / `mtime_size` / `critical_hash` / `full_hash`), fires `abilityguard_files_changed_since_snapshot` and `abilityguard_files_deleted_since_snapshot`, and pins the changed/deleted paths onto the log row as `files_changed_on_rollback` / `files_deleted_on_rollback` meta.
- **Drift check on rollback.** Live state is hashed and compared to the snapshot's post-state before restoring; if they differ the rollback returns `abilityguard_rollback_drift` unless you pass `force=true` or set `safety.skip_drift_check = true` on the registration.
- **Concurrency lock.** Capture + execute is serialised per surface set via a MySQL advisory lock so two simultaneous invocations don't capture each other's mid-states.
- **Encrypted redaction.** `safety.redact` / `safety.scrub` and a global `abilityguard_redact_keys` filter scrub secrets out of `args_json` / `result_json` / snapshot surfaces. v0.4+ stores redacted values as AES-256-GCM envelopes so rollback can still restore them when the key is intact.
- **Payload caps.** `args_json` and `result_json` are capped (defaults 64 KB / 128 KB, filterable per-ability) with an explicit truncation marker so a runaway ability can't blow up the audit table.
- **Approval queue.** When `safety.requires_approval => true`, the wrapper blocks execution, logs the row as `pending`, persists the input, and returns `WP_Error('abilityguard_pending_approval', 202)`. A human approves or rejects via wp-admin, `wp abilityguard approval`, or REST.
- **Invocation correlation.** Nested ability calls record a `parent_invocation_id`, so the admin UI shows a click-through "Invocation chain" linking parents and children.
- **Retention.** Daily WP cron prunes old log rows (defaults: 30 days normal, 180 days destructive) and orphaned snapshots. Both windows are filterable.
- **MCP client attribution.** When an ability is invoked via the WordPress MCP adapter, the audit row records which MCP server made the call.

## Surfaces (interfaces to your plugin)

- **PHP API** - `wp_register_ability( $name, [ ..., 'safety' => [...] ] )` and helpers `abilityguard_rollback`, `abilityguard_snapshot_meta`, `abilityguard_snapshot_options`.
- **REST** - `GET /abilityguard/v1/log`, `GET /log/<id>` (returns log row + decoded snapshot + parent + children + log_meta), `GET /log/export` (CSV/JSON with the same filter args), `POST /rollback/<id>`, `POST /rollback/bulk`, `GET /approval`, `POST /approval/<id>/approve`, `POST /approval/<id>/reject`, `POST /approval/bulk`, `GET /approval/export`, `GET /retention`, `POST /retention/prune`.
- **WP-CLI** - `wp abilityguard log list/show`, `wp abilityguard rollback <id>`, `wp abilityguard approval list/approve/reject <id>`, `wp abilityguard prune`.
- **wp-admin** - Tools → AbilityGuard. Hybrid timeline + command-palette search + per-day pagination, snapshot drawer, JSON-highlighted Input/Result tabs, "Invocation chain" navigation between parent and child invocations, "Rollback signals" card surfacing changed/deleted file paths, and real rollback against the captured snapshot.

## Documentation

Plugin-author guides live in `docs/`:

- [docs/safety-config.md](docs/safety-config.md) - Adding the safety config to your ability: full schema reference, snapshot resolver forms, all five surfaces, redaction, payload caps, and common mistakes.
- [docs/approval-workflow.md](docs/approval-workflow.md) - Approval queue: when to use `requires_approval`, the approve/reject lifecycle, CLI commands, and integration recipes.
- [docs/custom-collectors.md](docs/custom-collectors.md) - Writing your own collector: the `CollectorInterface` contract, a worked example, current extensibility limits, and testing patterns.
- [docs/api-stability.md](docs/api-stability.md) - Public API surface (every supported PHP function, action, filter, REST route, CLI command, and capability) plus the SemVer policy.
- [docs/notifications.md](docs/notifications.md) - Wiring approval requests to Slack, email, Discord, Microsoft Teams, or generic webhooks. Drop-in `add_action` recipes.

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

### Canonical slug

The plugin's canonical slug is **`abilityguard`** (lowercase) - that's what the release zip ships, what wp.org expects, and what dependent plugins should declare in `Requires Plugins: abilityguard`.

Your local clone folder may be named `AbilityGuard/` (capital A) or `abilityguard/`; `.wp-env.json` mounts the working tree under `wp-content/plugins/abilityguard` regardless via `mappings`, so dependents that declare `Requires Plugins: abilityguard` activate cleanly in dev. The plugin is auto-activated on `wp-env start` via `lifecycleScripts.afterStart`.

The integration suite is the primary correctness source - 140 tests covering installer schema, snapshot/audit/rollback round-trip, drift detection, encrypted redaction, the approval queue, all five collectors (including the FilesCollector tiered detection strategies), retention pruning, MCP identity, the post-state diff path, parent/child invocation correlation, and both example plugins.

If you use Claude Code worktrees, drop a local `phpcs.xml` (not committed) so PHPCS doesn't scan `.claude/`:

```xml
<?xml version="1.0"?>
<ruleset name="AbilityGuard (local)">
    <rule ref="./phpcs.xml.dist"/>
    <exclude-pattern>*/.claude/*</exclude-pattern>
</ruleset>
```

## Known limitations

- **File-content rollback is intentionally out of scope.** `FilesCollector` captures fingerprints under one of four detection strategies and fires `abilityguard_files_changed_since_snapshot` / `abilityguard_files_deleted_since_snapshot` on restore, but doesn't write file contents back. RollbackService records the changed/deleted paths as `log_meta` so the audit trail and admin UI surface the drift, but if you need real file rollback hook the actions and implement your own restore.
- **Multisite story is unverified.** Tables are `$wpdb->prefix`-scoped so per-site installs work, but network-level approval delegation and cross-site `caller_id` semantics haven't been designed yet.
- **Approval queue is single-step.** Multi-stage approval (e.g. requester → reviewer → final approver) is not modeled. Each `requires_approval` ability has exactly one approve/reject decision.

## Schema

Four tables, all prefixed `wp_abilityguard_`:

- `log` - one row per invocation. `id, invocation_id, parent_invocation_id, ability_name, caller_type, caller_id, user_id, args_json, result_json, status, destructive, duration_ms, pre_hash, post_hash, snapshot_id, created_at`.
- `log_meta` - extensible key/value attached to a log row. Currently used for `skip_drift_check`, `files_changed_on_rollback`, `files_deleted_on_rollback`. Read/write via `AbilityGuard\Audit\LogMeta`.
- `snapshots` - gzipped pre-state and (when callback succeeds) post-state per invocation.
- `approvals` - pending approval requests when `safety.requires_approval` is set.

## License

GPL-2.0-or-later.
