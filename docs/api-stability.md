# API stability

AbilityGuard follows **Semantic Versioning** (MAJOR.MINOR.PATCH) for its public API.

The public API is defined here. Anything not listed is internal and may change without notice.

## SemVer policy

- **PATCH** (0.x.Y) - Bug fixes only. No public-API change.
- **MINOR** (0.X.0) - Backwards-compatible additions: new public hooks, new public methods, new REST routes, new CLI subcommands, new safety-config keys. Existing public API keeps its contract.
- **MAJOR** (X.0.0) - Removals or breaking changes to anything in the public API. Always paired with a migration note in the changelog.

Pre-1.0 (where we are now), the public API is provisional but we still bump MINOR for additions and call out breaking changes explicitly in the release notes. We intend to freeze the public API at 1.0.

## Public API surface

### PHP functions (procedural helpers)

| Function | Since | Behaviour |
|---|---|---|
| `abilityguard_rollback( int\|string $ref, bool $force = false )` | 0.1 | Roll back an invocation. |
| `abilityguard_snapshot_meta( int $post_id, string[] $keys )` | 0.1 | Read current post_meta values matching the spec shape. |
| `abilityguard_snapshot_options( string[] $keys )` | 0.1 | Read current option values matching the spec shape. |

### PHP classes (public, namespaced)

| Class | Since | Purpose |
|---|---|---|
| `AbilityGuard\Audit\LogMeta` | 0.6 | `LogMeta::set( $log_id, $key, $value )` and `LogMeta::get_all( $log_id, $key )` for reading/writing the `log_meta` extensible store |
| `AbilityGuard\Snapshot\Collector\CriticalFileRegistry` | 0.7 | `add( $suffix )`, `remove( $suffix )`, `matches( $path )`, `all()` for managing the critical-file allowlist used by `FilesCollector::STRATEGY_CRITICAL_HASH` |
| `AbilityGuard\Snapshot\Collector\CollectorRegistry` | 0.8 | `register( $surface, $collector )`, `has( $surface )`, `defaults()` - process-wide registry of custom collectors layered over the built-ins. `safety.collectors` writes to this. |
| `AbilityGuard\Snapshot\Collector\CollectorInterface` | 0.1 | The contract custom collectors must implement: `collect( $spec ): array` and `restore( array $captured ): void`. |
| `AbilityGuard\Snapshot\FileBlobStore` | 0.9 | Sidecar staging-dir blob store powering `full_content`: `put($bytes)`, `get($hash)`, `has($hash)`, `delete($hash)`, `prune_except($keep_hashes)`, `staging_dir()`. |

### Safety config keys (passed to `wp_register_ability` under `safety`)

| Key | Since | Type |
|---|---|---|
| `destructive` | 0.1 | bool |
| `snapshot` | 0.1 | array \| callable returning the spec |
| `requires_approval` | 0.2 | `bool \| array{stages: array<int, array{cap: string}>}` - `true` is single-stage with the default `manage_abilityguard_approvals` cap. The array form opts into a multi-stage chain; each stage entry sets the capability required to act on that stage. Sequential semantics: any reject kills the chain. (1.1+) |
| `redact` | 0.3 | array - sub-keys: `input`, `result`, `surfaces` (each: string[] of dot-paths) |
| `scrub` | 0.3 | callable - receives `(mixed $value, string $kind)`, returns redacted value |
| `max_payload_bytes` | 0.3 | int - 0 disables truncation |
| `skip_drift_check` | 0.6 | bool - when true the wrapper auto-writes a `log_meta` row that RollbackService reads to bypass drift |
| `lock_timeout` | 0.4 | int - seconds; 0 = fail fast; -1 = lock disabled |
| `collectors` | 0.8 | `array<string, CollectorInterface>` - register custom collectors for non-built-in surfaces. Keys matching built-in surfaces (`post_meta`, `options`, `taxonomy`, `user_role`, `files`) are silently ignored. |

### Snapshot surfaces (keys returned by the `snapshot` resolver)

| Surface | Since | Spec shape |
|---|---|---|
| `post_meta` | 0.1 | `array<int $post_id, string[] $meta_keys>` |
| `options` | 0.1 | `string[]` |
| `taxonomy` | 0.2 | `array<int $post_id, string[] $taxonomy_names>` |
| `user_role` | 0.2 | `int[]` |
| `files` | 0.2 | `string[]` OR `{ paths: string[]\|Traversable, strategy?: 'mtime'\|'mtime_size'\|'critical_hash'\|'full_hash'\|'full_content', exclude_dirs?: string[] }`. Drift-only restore for the first four strategies (fires `abilityguard_files_changed_since_snapshot` / `abilityguard_files_deleted_since_snapshot`). `full_content` (0.9+) actually restores bytes via FileBlobStore and fires `abilityguard_files_restored` on success. |

### WordPress actions

| Action | Since | Args |
|---|---|---|
| `abilityguard_booted` | 0.1 | `()` - fires once on `plugins_loaded` after services wire |
| `abilityguard_rollback` | 0.1 | `( $log, $snapshot, $drifted_surfaces = [] )` |
| `abilityguard_rollback_drift` | 0.3 | `( $log, $snapshot, $drifted_surfaces )` |
| `abilityguard_files_changed_since_snapshot` | 0.2 | `( string[] $changed_paths )` |
| `abilityguard_files_deleted_since_snapshot` | 0.7 | `( string[] $deleted_paths )` - strict subset of changed paths: existed at snapshot, gone now |
| `abilityguard_files_restored` | 0.9 | `( string[] $restored_paths )` - fired after FilesCollector successfully rewrote one or more files from a `full_content` capture |
| `abilityguard_approval_advanced` | 1.1 | `( int $approval_id, int $new_stage_index, string $required_cap, array $approval_row )` - fired when a multi-stage approval advances to its next waiting stage |
| `abilityguard_retention_prune` | 0.2 | `()` - daily WP cron hook running `RetentionService::prune()` |
| `abilityguard_bulk_rollback_complete` | 0.4 | `( array $summary )` |
| `abilityguard_approval_requested` | 0.5 | `( int $approval_id, string $ability_name, int $log_id, mixed $input, string $invocation_id )` |
| `abilityguard_invocation_started` | 0.5 | `( string $invocation_id, string $ability_name, mixed $input, array $context )` |
| `abilityguard_invocation_completed` | 0.5 | `( string $invocation_id, string $ability_name, string $status, int $duration_ms, array $context )` |
| `abilityguard_invocation_error` | 0.5 | `( string $invocation_id, string $ability_name, ?Throwable $thrown, mixed $result, int $duration_ms )` |

### WordPress filters

| Filter | Since | Default | Used for |
|---|---|---|---|
| `abilityguard_retention_days_normal` | 0.2 | `30` | Days to keep non-destructive log rows |
| `abilityguard_retention_days_destructive` | 0.2 | `180` | Days to keep destructive log rows |
| `abilityguard_redact_keys` | 0.3 | `Redactor::default_keys()` | Global key list to redact |
| `abilityguard_redaction_placeholder` | 0.3 | `'[redacted]'` | Sentinel string when strategy=`'placeholder'` |
| `abilityguard_redaction_strategy` | 0.4 | `'encrypt'` | `'encrypt'` (default) or `'placeholder'` |
| `abilityguard_max_args_bytes` | 0.3 | `65_536` | Max bytes for `args_json` |
| `abilityguard_max_result_bytes` | 0.3 | `131_072` | Max bytes for `result_json` |
| `abilityguard_max_snapshot_bytes` | 0.3 | `1_048_576` | Max bytes per snapshot surface |
| `abilityguard_lock_timeout` | 0.4 | `5` | Default seconds to wait on advisory lock |
| `abilityguard_lock_reentrant` | 0.5 | `false` | When true, re-entrant per-process lock acquires succeed (MySQL-only behavior) |
| `abilityguard_can_approve` | 0.4 | `true` | Veto an approval decision: `( bool $can, array $approval_row, int $user_id ): bool` |
| `abilityguard_files_default_strategy` | 0.7 | `'full_hash'` | Site-wide default detection strategy for `FilesCollector` |
| `abilityguard_files_default_exclude_dirs` | 0.7 | backup-plugin paths | Substring-match excludes applied to every `FilesCollector::collect()` call |
| `abilityguard_files_critical_suffixes` | 0.7 | `CriticalFileRegistry::all()` | Final say over which path suffixes are "critical" under `critical_hash` |
| `abilityguard_max_file_bytes` | 0.9 | `262144` (256 KB) | Per-file cap for `full_content` capture. Files over this are fingerprinted-only with a doing-it-wrong notice. |
| `abilityguard_file_blob_dir` | 0.9 | `wp-content/abilityguard-staging` | Override the staging directory used by FileBlobStore (tests / unusual hosts). |

### REST endpoints (namespace `abilityguard/v1`)

| Method | Path | Since | Capability |
|---|---|---|---|
| `GET` | `/log` | 0.1 | `manage_options` |
| `GET` | `/log/<id>` | 0.1 | `manage_options` |
| `POST` | `/rollback/<id>` | 0.1 | `manage_options` |
| `POST` | `/rollback/bulk` | 0.4 | `manage_options` |
| `GET` | `/log/export` | 0.7 | `manage_options` |
| `GET` | `/approval` | 0.5 | `manage_abilityguard_approvals` |
| `POST` | `/approval/<id>/approve` | 0.4 | `manage_abilityguard_approvals` |
| `POST` | `/approval/<id>/reject` | 0.4 | `manage_abilityguard_approvals` |
| `POST` | `/approval/bulk` | 0.7 | `manage_abilityguard_approvals` |
| `GET` | `/approval/export` | 0.8 | `manage_abilityguard_approvals` |
| `GET` | `/retention` | 0.5 | `manage_options` |
| `POST` | `/retention/prune` | 0.7 | `manage_options` |

### WP-CLI subcommands

| Command | Since |
|---|---|
| `wp abilityguard log list` | 0.1 |
| `wp abilityguard log show <ref>` | 0.1 |
| `wp abilityguard rollback <ref>` | 0.1 |
| `wp abilityguard rollback --batch=<filter>` | 0.4 |
| `wp abilityguard rollback --force` | 0.3 |
| `wp abilityguard rollback --dry-run` | 0.4 |
| `wp abilityguard prune` | 0.2 |
| `wp abilityguard approval list` | 0.2 |
| `wp abilityguard approval approve <id>` | 0.2 |
| `wp abilityguard approval reject <id>` | 0.2 |

### Capabilities

| Capability | Since | Default role assignment |
|---|---|---|
| `manage_abilityguard_approvals` | 0.4 | `administrator` (granted on plugin activation) |

## What's NOT public

- Any `private`/`protected` method on any service class.
- The schema of `wp_abilityguard_*` tables - read via `LogRepository` / `ApprovalRepository` / `SnapshotStore`. Direct SQL is not supported and may break.
- Internal namespaces like `AbilityGuard\Support\Cipher` (the encryption envelope shape is internal - use `safety.scrub` if you need to influence redaction output).
- `AbilityGuard\Registry\InvocationStack` is internal - read `parent_invocation_id` off the audit row instead.
- The React app structure (`assets/admin.jsx`) - extend via REST + actions, not by patching the bundle.
- `Lock::reset_for_tests()` and any other `*_for_tests` methods.

## Multisite

Supported. The plugin file declares `Network: true`. Both network-activate and per-site activate work. Each subsite gets its own set of `wp_<N>_abilityguard_*` tables (per-site isolation, not a shared schema with a `blog_id` column). New subsites are auto-installed via `wp_initialize_site`; deleted subsites have their AbilityGuard tables dropped via `wpmu_drop_tables`.

`manage_abilityguard_approvals` is granted per-subsite to administrators; approvers on subsite B can only act on subsite B's audit log. Network admins (Super Admins) implicitly have the cap everywhere.

Multinetwork installs (rare) are filtered by `get_current_network_id()` during activation - sister networks aren't touched.

WP-Cron's visit-driven model is unreliable on low-traffic subsites. Production deployments should run `wp abilityguard prune --all-sites` from a real system cronjob. See [`docs/multisite.md`](multisite.md) for the full story.

## Deprecation policy

Deprecations land in a MINOR release with a `_deprecated_function` / `_deprecated_hook` notice. Removal happens no sooner than the next MAJOR. The CHANGELOG always lists deprecations explicitly.
