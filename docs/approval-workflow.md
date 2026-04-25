# Approval queue: when and how

When `safety.requires_approval` is set, AbilityGuard blocks execution and parks the invocation in a queue until a human decides. Two shapes:

```php
// Single-stage: any user with manage_abilityguard_approvals can decide.
'safety' => array( 'requires_approval' => true ),

// Multi-stage (v1.1+): each stage declares its own required capability.
// Sequential - stage N+1 only becomes "waiting" once stage N is approved.
// Any reject at any stage kills the chain (cancels remaining pending stages).
'safety' => array(
    'requires_approval' => array(
        'stages' => array(
            array( 'cap' => 'manage_abilityguard_approvals' ),  // stage 0
            array( 'cap' => 'edit_others_posts' ),              // stage 1
            array( 'cap' => 'manage_options' ),                 // stage 2 (final)
        ),
    ),
),
```

Single-stage and multi-stage approvals share the same code path internally - every approval has at least one stage row. The `true` form is sugar for `[['cap' => 'manage_abilityguard_approvals']]`.

Each stage advancement fires `abilityguard_approval_advanced( $approval_id, $new_stage_index, $required_cap, $approval_row )` so notification handlers (Slack, email, etc.) can re-emit messages targeted at the next stage's approver pool.

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

## Integrating with Slack, email, Discord, Teams, webhooks

See **[docs/notifications.md](notifications.md)** for full drop-in recipes covering Slack, `wp_mail()` email to all approvers, Discord, Microsoft Teams, and generic webhooks (Zapier / n8n / your own service), plus deferred-dispatch and testing patterns.

A minimal example:

`abilityguard_approval_requested` fires synchronously when a new approval row is recorded. Hook it to send Slack messages, emails, or webhooks to your approvers.

```php
add_action(
    'abilityguard_approval_requested',
    function ( int $approval_id, string $ability_name, int $log_id, mixed $input, string $invocation_id ): void {
        // Example: post to Slack
        wp_remote_post( 'https://hooks.slack.com/services/...', [
            'body' => wp_json_encode( [
                'text' => "Approval needed: {$ability_name} (#{$approval_id})",
                'blocks' => [
                    [ 'type' => 'section', 'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Ability:* `{$ability_name}`\n*Invocation:* `{$invocation_id}`\n<{$site_url}/wp-admin/tools.php?page=abilityguard&approval={$approval_id}|Review →>",
                    ] ],
                ],
            ] ),
        ] );
    },
    10,
    5
);
```

The action fires AFTER the approval row is persisted, so the `$approval_id` is guaranteed valid. The hook runs in the same request as the original ability invocation - keep handlers fast (queue heavy work via wp-cron if needed).
