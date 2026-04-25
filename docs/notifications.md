# Approval notifications: Slack, email, Discord, Teams, webhooks

When an ability declared `requires_approval => true` is invoked, AbilityGuard fires the `abilityguard_approval_requested` action synchronously after the approval row is persisted. Hook it to send the approver a notification.

```php
// Action signature:
do_action(
    'abilityguard_approval_requested',
    int    $approval_id,    // Newly inserted approval row id
    string $ability_name,   // e.g. "my-plugin/update-product-price"
    int    $log_id,         // Audit log row id
    mixed  $input,          // Original input passed to the ability
    string $invocation_id   // UUID
);
```

The hook runs in the same request as the original ability invocation. Keep handlers fast - for any non-trivial work (HTTP, DB writes against another store), schedule a single-shot `wp_schedule_single_event()` and do the work there.

A small helper that we'll reuse below:

```php
function abilityguard_approval_url( int $approval_id ): string {
    return add_query_arg(
        array( 'page' => 'abilityguard', 'approval' => $approval_id ),
        admin_url( 'tools.php' )
    );
}
```

---

## Slack

Replace `SLACK_WEBHOOK_URL` with your incoming webhook from <https://api.slack.com/messaging/webhooks>.

```php
add_action(
    'abilityguard_approval_requested',
    function ( int $approval_id, string $ability_name, int $log_id, mixed $input, string $invocation_id ): void {
        $url = abilityguard_approval_url( $approval_id );

        wp_remote_post( SLACK_WEBHOOK_URL, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => sprintf(
                                "*Approval needed*\n*Ability:* `%s`\n*Invocation:* `%s`",
                                $ability_name,
                                $invocation_id
                            ),
                        ],
                    ],
                    [
                        'type'     => 'actions',
                        'elements' => [
                            [
                                'type' => 'button',
                                'text' => [ 'type' => 'plain_text', 'text' => 'Review' ],
                                'url'  => $url,
                            ],
                        ],
                    ],
                ],
            ] ),
            'timeout' => 5,
        ] );
    },
    10,
    5
);
```

---

## Email

Uses WordPress's built-in `wp_mail()`. Configure SMTP via WP Mail SMTP or your platform's mail relay; on a fresh install `wp_mail()` falls through to PHP's `mail()` which is unreliable in production.

```php
add_action(
    'abilityguard_approval_requested',
    function ( int $approval_id, string $ability_name, int $log_id, mixed $input, string $invocation_id ): void {
        $approvers = get_users( array( 'capability' => 'manage_abilityguard_approvals' ) );
        $emails    = wp_list_pluck( $approvers, 'user_email' );
        if ( empty( $emails ) ) {
            return;
        }

        $url     = abilityguard_approval_url( $approval_id );
        $subject = sprintf( '[AbilityGuard] Approval needed: %s', $ability_name );
        $body    = sprintf(
            "An ability is awaiting your approval.\n\nAbility: %s\nInvocation: %s\n\nReview: %s\n",
            $ability_name,
            $invocation_id,
            $url
        );

        wp_mail( $emails, $subject, $body );
    },
    10,
    5
);
```

---

## Discord

Discord webhooks accept Slack-compatible JSON if you append `/slack` to the webhook URL - but the native Discord shape is cleaner:

```php
add_action(
    'abilityguard_approval_requested',
    function ( int $approval_id, string $ability_name, int $log_id, mixed $input, string $invocation_id ): void {
        wp_remote_post( DISCORD_WEBHOOK_URL, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'username' => 'AbilityGuard',
                'embeds'   => [ [
                    'title'       => 'Approval needed',
                    'description' => sprintf( "**Ability:** `%s`\n**Invocation:** `%s`", $ability_name, $invocation_id ),
                    'url'         => abilityguard_approval_url( $approval_id ),
                    'color'       => 0xc08400, // amber for "pending"
                ] ],
            ] ),
            'timeout' => 5,
        ] );
    },
    10,
    5
);
```

---

## Microsoft Teams

Teams expects an Adaptive Card payload:

```php
add_action(
    'abilityguard_approval_requested',
    function ( int $approval_id, string $ability_name, int $log_id, mixed $input, string $invocation_id ): void {
        wp_remote_post( TEAMS_WEBHOOK_URL, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                '@type'    => 'MessageCard',
                '@context' => 'http://schema.org/extensions',
                'summary'  => 'AbilityGuard approval needed',
                'title'    => 'Approval needed',
                'text'     => sprintf( '**Ability:** `%s` &nbsp;&nbsp; **Invocation:** `%s`', $ability_name, $invocation_id ),
                'potentialAction' => [ [
                    '@type'   => 'OpenUri',
                    'name'    => 'Review',
                    'targets' => [ [ 'os' => 'default', 'uri' => abilityguard_approval_url( $approval_id ) ] ],
                ] ],
            ] ),
            'timeout' => 5,
        ] );
    },
    10,
    5
);
```

---

## Generic webhook (Zapier, n8n, Make, your own service)

POST a small JSON envelope and let downstream tools fan out:

```php
add_action(
    'abilityguard_approval_requested',
    function ( int $approval_id, string $ability_name, int $log_id, mixed $input, string $invocation_id ): void {
        wp_remote_post( YOUR_WEBHOOK_URL, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . YOUR_WEBHOOK_TOKEN, // optional, recommended
            ],
            'body' => wp_json_encode( [
                'event'         => 'abilityguard.approval_requested',
                'approval_id'   => $approval_id,
                'ability_name'  => $ability_name,
                'log_id'        => $log_id,
                'invocation_id' => $invocation_id,
                'input'         => $input, // CAUTION: may contain secrets if not redacted at safety config
                'review_url'    => abilityguard_approval_url( $approval_id ),
                'site_url'      => home_url(),
                'timestamp'     => time(),
            ] ),
            'timeout' => 5,
        ] );
    },
    10,
    5
);
```

> **Security note.** The `$input` array is whatever the caller passed to the ability. If your ability accepts secrets in input, declare them in `safety.redact.input` so they're encrypted in storage AND don't leak to your webhook. The action receives the un-redacted value because it fires before the audit log write - the un-redacted access is intentional for use cases where the notification needs to mention the actual values, but it means YOU are now responsible for redacting before forwarding.

---

## Multiple channels

Just add multiple `add_action` calls. They fire in priority order; failures in one don't affect the others (each is wrapped in WordPress's normal action-callback error isolation).

```php
add_action( 'abilityguard_approval_requested', 'my_slack_notify',   10, 5 );
add_action( 'abilityguard_approval_requested', 'my_email_notify',   10, 5 );
add_action( 'abilityguard_approval_requested', 'my_pagerduty_warn', 20, 5 );
```

---

## Deferred / async dispatch

If your ability runs many times per minute and notifications are slow, defer the HTTP call:

```php
add_action(
    'abilityguard_approval_requested',
    function ( int $approval_id, string $ability_name ): void {
        wp_schedule_single_event(
            time() + 1,
            'my_plugin_send_approval_notification',
            array( $approval_id, $ability_name )
        );
    },
    10,
    5
);

add_action( 'my_plugin_send_approval_notification', 'my_actual_dispatch_function', 10, 2 );
```

The cron event picks up the job in the next request and runs your real notification code there. Failures in the cron job don't block the original ability invocation.

---

## Testing your notification handler

Use the wp-cli recipe from `docs/safety-config.md` (set the current user, register a test ability with `requires_approval => true`, invoke it). The action fires synchronously when `ApprovalService::request()` writes the row, so a single `wp eval-file` run exercises the whole path.

For a passive sanity check during development, add a trace log:

```php
add_action(
    'abilityguard_approval_requested',
    function ( ...$args ): void {
        error_log( '[abilityguard] approval_requested: ' . wp_json_encode( $args ) );
    },
    1, // run before your real handlers
    5
);
```

Tail `wp-content/debug.log` and confirm the args land in the order you expect before wiring up the real channel.
