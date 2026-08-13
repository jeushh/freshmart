---
name: freshmart-engineering
description: Use for FreshMart feature work, bug fixes, independent reviews, and verification when changes must preserve RBAC, finance/inventory boundaries, legacy compatibility, and human-controlled Git publication.
---

# FreshMart Engineering Skill

## Mission

Produce the smallest correct change while preserving FreshMart architecture,
RBAC, business invariants, compatibility, and test evidence.

## Roles

### Planner

The Planner:

- discovers current behavior
- identifies authoritative code and tests
- defines approved scope
- defines forbidden scope
- identifies RBAC and business invariants
- proposes acceptance criteria
- flags sensitive changes before implementation
- does not edit repository files while acting only as Planner

### Implementer

The Implementer:

- stays inside approved scope
- changes the minimum necessary files
- adds focused regression tests
- runs focused verification
- reports every changed file
- stops if unexpected scope is required

The Implementer does not publish Git changes.

### Reviewer

The Reviewer:

- inspects the actual diff or patch
- checks requirements against code
- checks RBAC and business boundaries
- checks negative cases
- checks unintended mutation
- checks compatibility
- returns blocking findings first
- does not edit repository files while acting only as Reviewer

Self-review must be labeled `SELF-REVIEW`.

Only another agent or separately delegated reviewer may be called
`INDEPENDENT REVIEW`.

### Verifier

The Verifier:

- checks focused tests
- runs the required full validation
- checks changed-file scope, staged state, and untracked-file review
- checks `git diff --check`
- reports actual command evidence
- does not edit repository files while acting only as Verifier

A failed verification returns to implementation.

### Human

The human owns:

- sensitive-scope approval
- staging
- committing
- pushing
- PR creation
- merging
- branch deletion
- destructive database authorization

## Loop

DISCOVER
→ PLAN
→ IMPLEMENT
→ FOCUSED TEST
→ REVIEW
→ FIX
→ FULL VALIDATION
→ READY FOR HUMAN

If review returns `HOLD`:

REVIEW
→ FIX
→ FOCUSED TEST
→ REVIEW

If full validation fails:

VALIDATION
→ FIX
→ FOCUSED TEST
→ REVIEW
→ VALIDATION

Never skip review after a repair.

## Default iteration limits

- implementation iterations: 5
- review/fix cycles: 3
- same repeated failure: 3

When a limit is reached, stop with `HOLD`.

## Immediate HOLD conditions

Stop if:

- the requirement is materially ambiguous
- an unexpected migration is required
- an unexpected RBAC change is required
- destructive database work appears necessary
- tests would need weakening or deletion
- unrelated files change
- secrets appear
- dependency changes are unexpectedly required
- authorization would be widened
- the same failure occurs three times
- repository state differs from the approved baseline

## Completion rule

Do not report `READY FOR HUMAN` until all applicable focused tests, full tests,
formatting/static checks, build checks, diff checks, scope checks, and review
gates have passed.

Never stage, commit, push, merge, or delete branches without explicit human
authorization.
