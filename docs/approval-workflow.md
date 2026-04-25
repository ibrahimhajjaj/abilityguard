# Approval queue: when and how

When `safety.requires_approval => true`, AbilityGuard blocks execution and parks the invocation in a queue until a human decides.

## When to use `requires_approval`

Good candidates:

- **Irreversible but not rollback-eligible operations.** Sending an email, triggering a payment charge, or deleting a row that AbilityGuard cannot restore - where a human checkpoint is safer than relying on rollback after the fact.
- **Multi-stakeholder writes.** Operations that affect multiple system owners (e.g. a user-role change that crosses team boundaries).
- **Regulated data.** Bulk exports, GDPR-adjacent deletions, or anything your compliance posture requires a human sign-off on.

Avoid it for high-frequency, low-risk, or fully rollback-eligible operations - the queue adds latency and requires someone to actively process it.

## Lifecycle

```
Caller invokes ability
        |
        v
requires_approval = true?
        |
       YES
        |
        v
  [pre-snapshot captured]
  [log row: status=pending]
  [approval row: status=pending]
        |
        v
  WP_Error(202) returned to caller
  { approval_id: N, log_id: M }
        |
   (human decides)
        |
       / \
    YES   NO
     |     |
     v     v
 approve  reject
     |     |
     v     v
 ability   log row
 callback  status=rejected
 runs      (no execution)
     |
     v
  log row status=ok
  approval row status=approved
```

When rejected, the ability's callback never runs. The invocation is permanently parked.

## What the caller receives

The wrapper returns a `WP_Error` with code `abilityguard_pending_approval` and HTTP status hint `202`:

```php
$result = $ability->execute( $input );

if ( is_wp_error( $result ) && 'abilityguard_pending_approval' === $result->get_error_code() ) {
    $data        = $result->get_error_data();
    $approval_id = $data['approval_id']; // int
    $log_id      = $data['log_id'];      // int
    // store $approval_id and surface it to the user
}
```

REST callers get a `202` response body when the core Abilities API converts the `WP_Error` to a REST response.

## CLI commands

```bash
# List pending approvals
wp abilityguard approval list
wp abilityguard approval list --status=pending --format=json

# Approve
wp abilityguard approval approve 42

# Reject
wp abilityguard approval reject 42
```

`--format` accepts `table` (default), `csv`, `json`, `yaml`.

## Capability: `manage_abilityguard_approvals`

**Coming in v0.4.** The plan is to gate approve/reject actions on a dedicated capability `manage_abilityguard_approvals`. In v0.3 the CLI approval commands run under whatever user is authenticated to WP-CLI; there is no capability check in `ApprovalService::approve()` or `ApprovalService::reject()` beyond what WP-CLI's own session enforces. The REST endpoint defers to the `manage_options` check on the `RestController`.

## Self-approval guard

The `approvals` table stores `requested_by` (user id of the invoker) and `decided_by` (user id of the approver). AbilityGuard does not currently enforce `requested_by !== decided_by` at the service layer. Enforce this in your own capability logic or via the `abilityguard_can_approve` filter below.

## `abilityguard_can_approve` filter

**Coming in v0.4.** A filter to layer custom approval logic (e.g. block self-approval, require a specific role) is planned but not yet wired. Proposed signature:

```php
// NOT YET AVAILABLE - v0.4 planned
add_filter(
    'abilityguard_can_approve',
    function ( bool $allowed, int $approval_id, int $user_id ): bool {
        $row = /* fetch approval row */ ;
        if ( (int) $row['requested_by'] === $user_id ) {
            return false; // block self-approval
        }
        return $allowed;
    },
    10,
    3
);
```

Until that filter exists, add the guard inside your own plugin that calls `ApprovalService::approve()`.

## Integrating with Slack or email

**The `abilityguard_approval_requested` action does not exist yet.** A hook fired when an approval is queued is planned for v0.5.

In the interim, hook `wp_insert_post` if you store approvals as posts (not applicable here), or poll `wp abilityguard approval list --status=pending --format=json` via a cron and dispatch notifications yourself:

```bash
# Example: run every 5 minutes via server cron
wp abilityguard approval list --status=pending --format=json \
  | jq '.[] | select(.created_at > "2026-01-01")' \
  | your-notification-script
```

When v0.5 ships `abilityguard_approval_requested`, the intended recipe will look like:

```php
// FUTURE - v0.5
add_action(
    'abilityguard_approval_requested',
    function ( int $approval_id, string $ability_name, int $requested_by ): void {
        // send Slack message, email, etc.
    },
    10,
    3
);
```
