# FreshMart Feature Loop

Use this loop for new FreshMart behavior.

## 1. Discover

Inspect current:

- implementation
- routes and permissions
- schema where relevant
- nearest feature tests
- canonical documentation

Write down what already exists before proposing changes.

## 2. Plan

Define:

- goal
- acceptance criteria
- approved scope
- forbidden scope
- business invariants
- RBAC constraints
- focused tests
- expected changed files

If the feature unexpectedly requires migrations, RBAC expansion, dependency
changes, destructive data work, or unrelated API changes, stop with `HOLD`.

## 3. Implement

Make the smallest coherent patch.

Do not opportunistically refactor neighboring modules.

Add or update focused tests in the same iteration.

## 4. Focused test

Run the narrowest relevant tests.

If they fail, repair the implementation rather than weakening expectations.

## 5. Independent review

The Reviewer inspects the actual patch and verifies:

- requirement correctness
- authorization boundaries
- domain invariants
- negative paths
- compatibility
- unintended mutations
- changed-file scope

Blocking findings return to implementation.

## 6. Repair loop

For each blocking finding:

FIX
→ FOCUSED TEST
→ REVIEW

Default maximum review/fix cycles: 3.

## 7. Full validation

Run every applicable gate in `../VERIFICATION.md`.

A failure returns to implementation and then review before validation is
repeated.

## 8. Stop

When all required evidence passes, return:

`READY FOR HUMAN`

Do not stage, commit, push, create a PR, merge, or delete branches.
