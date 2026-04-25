# AbilityGuard Fluent Forms Pack (demo)

A reference plugin showing how to declare a `safety` config for a **create** operation - and, crucially, what rollback cannot do automatically in that case. It uses a plain WordPress CPT (`acme_form`) so it runs in any install without Fluent Forms.

## What this demonstrates

- Registering a custom category (`acme-forms`) on `wp_abilities_api_categories_init`.
- Declaring a `safety` config for an ability that creates a new post (`wp_insert_post`).
- Using the `snapshot` callable to capture side-effect state (an options timestamp) that can be reverted by rollback.

## Known rollback limitation

The snapshot is taken **before** execution, at which point the duplicated post does not yet exist. AbilityGuard's built-in rollback restores options and post meta captured in the pre-snapshot - it does **not** auto-delete posts created during execution.

Concretely: if you roll back an invocation of `acme-forms/duplicate-form`, the `acme_forms_last_duplicated_at` option is restored to its pre-run value, but the cloned post remains in the database as a draft.

**Design takeaway for plugin authors:** for create operations, pair AbilityGuard with your own post-rollback hook or a custom `safety.on_rollback` handler (a future AbilityGuard extension point) that deletes the created resource by id - perhaps stored in the audit log's `result_json`.

## Install

1. Activate **AbilityGuard** (must load first).
2. Drop this directory into `wp-content/plugins/` and activate it from the Plugins screen.

## wp-cli example

```bash
# Create a source form post first
wp post create --post_type=acme_form --post_title="Contact Form" --post_status=publish

# Duplicate it (replace 123 with the real post id)
wp ability execute acme-forms/duplicate-form --input='{"form_id":123}'

# Inspect the audit log
wp abilityguard log list --ability=acme-forms/duplicate-form

# Roll back - restores the timestamp option but does NOT delete the clone
wp abilityguard rollback <invocation-id>
```
