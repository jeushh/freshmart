# AI Safety Rules

These rules apply to every AI agent working in FreshMart.

They are intentionally more conservative than ordinary local-development
instructions.

## Git safety

Without explicit human authorization for the exact action, an agent must not:

- run `git add`
- run `git commit`
- push
- force-push
- run `git merge`
- run `git cherry-pick`
- run `git rebase`
- create a pull request
- merge a pull request
- enable auto-merge
- delete local branches
- delete remote branches
- reset shared history
- run broad cleanup such as `git clean`
- discard working-tree changes with `git restore`, `git checkout --`, or destructive `git reset`
- hide or relocate unreviewed work with `git stash`
- overwrite files from another ref merely to make a diff disappear

Any command whose purpose is to discard, hide, or overwrite unreviewed local work
requires explicit human authorization for that exact action.

If an agent runtime requires automatic staging, committing, pushing, or PR
publication to complete a task, do not use that mode for the pre-human loop;
return `HOLD`.

Read-only Git inspection is allowed.

Even after staging is authorized, prefer exact reviewed paths.

Do not use:

- `git add .`
- `git add -A`

in the supervised workflow.

## Database safety

AI agents must never manually run:

- `php artisan migrate:fresh`
- `php artisan migrate:refresh`
- `php artisan migrate:reset`
- `php artisan db:wipe`
- destructive SQLite reset commands
- destructive SQL used to recreate the application database

This rule applies even when local-development documentation explains how a
human may intentionally reset a disposable local database.

Only an explicit human instruction authorizing the exact destructive operation
may override this rule.

Safe verification normally includes:

- `php artisan migrate:status`
- `php artisan freshmart:health`
- `php artisan test`

The test framework may build isolated test databases. That does not authorize an
agent to reset the developer's application database manually.

## Authorization safety

Do not:

- widen permissions silently
- move security enforcement from Laravel to Vue
- rely on hidden buttons as authorization
- add access merely because another role can see a related record
- expose finance settlement data to operational roles as a UI shortcut

Backend authorization remains authoritative.

## Business-data safety

Do not silently:

- mutate inventory from unrelated finance workflows
- change server-authoritative money calculations
- change financial classifications
- weaken transaction boundaries
- weaken idempotency
- remove audit behavior
- rewrite finalized historical records
- fabricate structured records for legacy data
- replace compatibility behavior without approval

## Dependency safety

Do not modify dependency manifests or lockfiles unless dependency work is
explicitly approved.

Examples include:

- `composer.json`
- `composer.lock`
- `apps/web/package.json`
- `apps/web/package-lock.json`

Do not perform opportunistic upgrades.

## Test safety

Never:

- delete a failing regression test merely to pass
- weaken an assertion to hide a defect
- skip a failing test without approval
- change expected behavior solely because implementation is failing
- report PASS based only on another agent's summary

Tests are evidence, not obstacles to remove.

## Scope safety

Before implementation, define:

- approved scope
- forbidden scope
- expected behavior
- invariants that must remain unchanged

If a correct solution requires crossing a forbidden boundary, return `HOLD`.

## Secret safety

Never print, commit, or paste into an AI handoff:

- passwords
- application keys
- API tokens
- session cookies
- production credentials
- sensitive private database contents

If a secret appears in output or a diff, stop and report the exposure without
repeating the secret.
