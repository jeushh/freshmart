# FreshMart Agent Guide

FreshMart uses supervised loop engineering.

The objective is the smallest correct change with evidence, independent review,
and a human-controlled Git boundary.

## Mandatory reading

Before editing, read:

1. `docs/ai/README.md`
2. `docs/ai/SAFETY.md`
3. `docs/ai/DOMAIN_GUARDS.md`
4. `docs/ai/VERIFICATION.md`
5. `docs/ai/HANDOFF.md`
6. the relevant workflow under `docs/ai/loops/`
7. relevant canonical project documentation

Important canonical project documentation:

- `docs/ARCHITECTURE.md`
- `docs/SECURITY.md`
- `docs/DATABASE_SCHEMA.md`
- `docs/LOCAL_DEVELOPMENT.md`
- `docs/REPORTING.md`
- `docs/OPERATIONS.md`

Current code and tests are authoritative evidence of actual behavior.

## Engineering loop

DISCOVER
→ PLAN
→ IMPLEMENT
→ FOCUSED TEST
→ REVIEW
→ FIX
→ FULL VALIDATION
→ HUMAN GATE

Do not skip from implementation directly to completion.

## Core rules

- Make the smallest correct change.
- Preserve existing architecture unless the approved task explicitly changes it.
- Laravel authorization is the security boundary.
- Frontend visibility never substitutes for backend authorization.
- Server-authoritative money, permissions, state transitions, and inventory
  behavior must remain server authoritative.
- Preserve legacy compatibility unless removal is explicitly approved.
- Never claim a check passed without evidence.
- Review the actual tracked patch and every untracked file, not only an agent summary.

## Human-controlled Git boundary

Unless the human explicitly authorizes the exact action, agents must not:

- stage files
- commit
- push or force-push
- run `git merge`, `git cherry-pick`, `git rebase`, or equivalent integration
- create or merge pull requests
- enable auto-merge
- delete branches
- rewrite Git history
- discard, stash, or overwrite unreviewed working-tree changes

Read-only Git inspection is allowed.

## Verdicts

Use:

- `PASS` — current gate satisfied
- `HOLD` — blocker, uncertainty, failed check, or unexpected scope
- `READY FOR HUMAN` — implementation, review, and validation passed

Never report `READY FOR HUMAN` when required evidence is missing.

## Mandatory escalation

Stop with `HOLD` before an unexpected change involving:

- migrations
- RBAC or permissions
- authentication
- supplier-payment visibility
- inventory mutation
- accounting semantics
- dependencies
- destructive database work
- broad refactoring
- public API changes outside approved scope
