=== AbilityGuard ===
Contributors: ibrahimhajjaj
Tags: abilities-api, mcp, audit, rollback, safety
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Snapshot, audit, and rollback middleware for the WordPress Abilities API.

== Description ==

AbilityGuard is a developer library for plugin authors who register abilities via `wp_register_ability()` and want snapshot capture, audit logging, approval workflows, and one-click rollback for every invocation across REST, MCP, internal PHP, and WP-CLI without building it themselves.

Declare what state your ability touches; AbilityGuard handles the safety wrapper.

= What you get out of the box =

* **Pre + post snapshots.** Every safety-enabled invocation captures declared state before the callback and (on success) after, so the audit log can show a real diff.
* **Audit log.** One row per invocation with ability name, caller (REST/MCP/CLI/internal), user, args, result, status, duration, pre/post hashes, and parent_invocation_id for nested calls.
* **One-click rollback.** Restore captured state from post_meta, options, taxonomy term assignments, user roles + caps. File contents support tiered drift detection (mtime / mtime_size / critical_hash / full_hash) plus opt-in real byte-level rollback via `full_content` strategy.
* **Drift check on rollback.** Live state is hashed and compared to the snapshot's post-state before restoring; if they differ the rollback returns an error unless forced.
* **Concurrency lock.** Capture + execute is serialised per surface set via a MySQL advisory lock so two simultaneous invocations do not capture each other's mid-states.
* **Encrypted redaction.** Scrub secrets out of args, results, and snapshots. Stores redacted values as AES-256-GCM envelopes so rollback can still restore them.
* **Approval queue.** When `safety.requires_approval` is set, the wrapper blocks execution and returns a 202 pending response. A human approves or rejects via wp-admin, WP-CLI, or REST. Multi-stage sequential or parallel approval chains are supported.
* **Multisite support.** Each subsite gets its own set of `wp_<N>_abilityguard_*` tables, with auto-install on `wp_initialize_site` and auto-drop on `wpmu_drop_tables`.
* **Retention.** Daily WP-Cron prunes old log rows (defaults: 30 days normal, 180 days destructive) and orphaned snapshots.

= Surfaces =

* **PHP API** with `wp_register_ability( $name, [ ..., 'safety' => [...] ] )` and helpers `abilityguard_rollback`, `abilityguard_snapshot_meta`, `abilityguard_snapshot_options`.
* **REST**: `/abilityguard/v1/log`, `/log/<id>`, `/log/export`, `/rollback/<id>`, `/rollback/bulk`, `/approval`, `/approval/<id>/approve`, `/approval/<id>/reject`, `/approval/bulk`, `/approval/export`, `/retention`, `/retention/prune`, `/health`.
* **WP-CLI**: `wp abilityguard log list/show`, `wp abilityguard rollback <id>`, `wp abilityguard approval list/approve/reject <id>`, `wp abilityguard prune`.
* **wp-admin**: Tools > AbilityGuard. Hybrid timeline + command-palette search, snapshot drawer, JSON-highlighted Input/Result tabs, invocation chain navigation, and real rollback against the captured snapshot.

= Example =

`
wp_register_ability( 'my-plugin/update-product-price', array(
    'label'               => 'Update product price',
    'description'         => 'Updates the price on a WooCommerce product.',
    'category'            => 'woocommerce',
    'input_schema'        => array( /* ... */ ),
    'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
    'execute_callback'    => fn( $args ) => update_post_meta( $args['product_id'], '_price', $args['price'] ),
    'safety' => array(
        'destructive'       => true,
        'requires_approval' => false,
        'snapshot'          => fn( $input ) => array(
            'post_meta' => array( $input['product_id'] => array( '_price', '_regular_price' ) ),
            'options'   => array( 'woocommerce_last_price_change' ),
        ),
    ),
) );
`

= Documentation =

Full plugin-author documentation lives at the GitHub repo: https://github.com/ibrahimhajjaj/abilityguard

== Installation ==

1. Upload the `abilityguard` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress (or network-activate on multisite).
3. Visit Tools > AbilityGuard to view the audit log.
4. In your own plugin, register abilities via `wp_register_ability()` with a `safety` config.

Requires WordPress 6.9 or later (for the Abilities API) and PHP 8.1 or later.

== Frequently Asked Questions ==

= Does this work without other plugins? =

It will activate without registered abilities, but it only does work when other plugins register abilities with a `safety` config via `wp_register_ability()`.

= What state surfaces are supported for snapshots? =

post_meta, options, taxonomy term assignments, user roles + caps, and files (with five tiered strategies from mtime to full content rollback).

= Does it support multisite? =

Yes. Each subsite gets its own set of `wp_<N>_abilityguard_*` tables. New subsites are auto-installed via `wp_initialize_site`; deleted subsites have their tables dropped via `wpmu_drop_tables`.

= How does it handle concurrent invocations? =

Per-surface MySQL advisory locks (GET_LOCK) serialise capture + execute so simultaneous invocations do not capture each other's mid-states.

= Are secrets encrypted in the log? =

Yes. Redaction uses AES-256-GCM envelopes so rollback can still restore the original value when the encryption key is intact.

== Changelog ==

= 1.2.0 =
* Parallel multi-stage approval chains with optional per-stage user pinning.
* `/health` REST endpoint and a pending-approvals badge in the admin bar.
* WP-CLI: `log show --diff`, `approval show`, `prune --all-sites`.
* JSONL export option for audit log.
* Real byte-level file rollback via `safety.snapshot.files.strategy = 'full_content'` (AES-256-GCM, content-addressed sidecar staging dir, atomic writes, 256 KB per-file cap).
* Full multisite support with auto-install on subsite creation and auto-drop on subsite deletion.
* Sequential and parallel multi-stage approval chains.

= 1.1.0 =
* Multi-stage approval queues.
* Invocation correlation via `parent_invocation_id` and an admin-side invocation chain navigator.
* `log_meta` table for extensible per-row metadata.

= 1.0.0 =
* Initial public release.
* Snapshot, audit, rollback, and approval middleware for the WordPress Abilities API.
* Five collectors: post_meta, options, taxonomy, user_role, files.
* REST + WP-CLI + wp-admin surfaces.
* Encrypted redaction, payload caps, retention pruning.
