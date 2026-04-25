# Running AbilityGuard on a multisite network

AbilityGuard supports WordPress multisite. Each subsite gets its own set of `wp_<N>_abilityguard_*` tables; audit data is physically isolated per subsite, not multiplexed through a shared schema with a `blog_id` column. Below is what you need to know to deploy correctly.

## Activation

Two paths, both supported:

1. **Network-activate** from `Network Admin → Plugins`. AbilityGuard's activation hook walks every subsite in the current network (filtered by network id, so multinetwork installs don't accidentally touch sister networks), installs the four tables on each via `dbDelta`, schedules the daily retention cron, and grants the `manage_abilityguard_approvals` capability to the administrator role on each subsite.
2. **Per-site activate** from a single subsite's `Plugins` screen. AbilityGuard installs only on that subsite - useful if you only want audit on a subset of the network.

Subsites created **after** AbilityGuard is network-activated are auto-installed via the `wp_initialize_site` action - no manual step required.

Subsites that are deleted (`Network Admin → Sites → Delete`) have their AbilityGuard tables dropped automatically via `wpmu_drop_tables`. No orphan tables.

## Capabilities

`manage_abilityguard_approvals` is granted **per-subsite** to the administrator role. The implication:

- A site administrator on subsite B can approve/reject pending requests scoped to subsite B's audit log only - not subsite A's. This matches the data-isolation principle.
- Network admins (Super Admins) implicitly have all capabilities on all subsites - they can approve anywhere.
- An MCP server invoking an ability registered on subsite B writes its audit row to subsite B's tables. Subsite B's admins are the approvers. If you need "approve everywhere" semantics for a given user, give them Super Admin.

## Cron - IMPORTANT

WP-Cron triggers from front-end visits. **On a multisite network, cron events scheduled on a low-traffic subsite may not fire reliably** - this is a WordPress core behavior, not an AbilityGuard limitation, but it directly affects retention pruning.

The recommended fix is a real cronjob that loops subsites and runs prune via WP-CLI:

```bash
# /etc/cron.d/abilityguard-prune
0 3 * * * www-data /usr/local/bin/wp abilityguard prune --all-sites --path=/var/www/wordpress
```

`--all-sites` walks every subsite in the network and runs `RetentionService::prune()` against each. It's safe to run repeatedly - prune is idempotent.

For very-low-traffic subsites you may also want a generic WP-Cron driver that runs *all* due events across subsites, not just AbilityGuard's:

```bash
# Run every WP-Cron event on every subsite, every two minutes.
*/2 * * * * www-data for url in $(wp site list --field=url --path=/var/www/wordpress); do wp cron event run --due-now --url="$url" --path=/var/www/wordpress; done
```

This is a general multisite operations pattern, not specific to AbilityGuard.

## File-content rollback (`STRATEGY_FULL_CONTENT`)

The staging directory at `wp-content/abilityguard-staging/` is **shared across the install**, not per-subsite. Blobs are content-addressed by sha256 - identical bytes captured by abilities on different subsites dedupe to the same staging file.

Retention runs on each subsite independently (`prune` walks that subsite's snapshots table for referenced blob hashes). The orphan sweep is conservative: a blob is only deleted when **no** surviving snapshot on the current subsite references it. This means a blob captured by subsite A AND subsite B survives until both subsites have pruned away their references. Acceptable trade-off - better to keep one extra blob on disk than risk deleting a blob still needed for an active rollback path.

If you need stricter cross-network garbage collection, run `wp abilityguard prune --all-sites` periodically - that closes the window because every subsite's references are evaluated in one pass.

## REST endpoints

REST URLs are scoped per subsite. `wp_json/abilityguard/v1/log` on subsite B returns subsite B's log, not the network. There's no network-wide REST aggregator endpoint in v1.0; if you need cross-subsite reporting, query each subsite's REST endpoint and merge client-side, or use `wp abilityguard log list --url=<each>` from the CLI.

## Multinetwork

AbilityGuard's activation iteration filters by `get_current_network_id()`, so on a multinetwork WordPress install, network-activating on network A does **not** install on subsites belonging to network B. Each network is treated independently. This is by design - multinetwork is rare enough that we don't try to coordinate across networks, but we don't break anyone using it either.

## Uninstall

Plugin **deletion** (not just deactivation) walks every subsite in every network and drops AbilityGuard's four tables, removes the approval capability, and unschedules the retention cron on each. The shared staging directory is purged once. After uninstall there is nothing left of AbilityGuard in the database or filesystem.

If you want to deactivate without losing audit data, use `Plugins → Deactivate`, not `Plugins → Delete`.
