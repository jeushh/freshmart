# FreshMart Independent Review Loop

Use this loop after implementation and before human staging.

## Required inputs

The Reviewer should receive:

- task
- acceptance criteria
- branch and base SHA
- approved scope
- forbidden scope
- changed-file list
- actual tracked patch
- untracked-file contents or an explicit patch artifact
- focused-test evidence

## Review order

Review in this order:

1. scope boundary
2. authorization and RBAC
3. business invariants
4. data and transaction integrity
5. compatibility
6. negative and error behavior
7. tests
8. frontend consistency
9. code quality

Security and correctness findings take priority over style suggestions.

## Required questions

Ask:

- Does the code satisfy the approved requirement?
- Can an unauthorized role reach the behavior directly through the API?
- Did any role gain information or mutation capability unintentionally?
- Could inventory change from a finance-only action?
- Could finance state leak into an operational read model?
- Are money and workflow transitions server authoritative?
- Are transaction and idempotency guarantees preserved where relevant?
- Is legacy behavior preserved where required?
- Do tests cover the important negative path?
- Are unrelated files, migrations, or dependency changes present?

## Finding format

Use:

```text
BLOCKING
- <finding>

NON-BLOCKING
- <finding>

VERDICT
PASS | HOLD
```

Do not create blocking findings solely for optional refactoring or personal
style.

## PASS condition

Return `PASS` only when no blocking defect remains.

A review PASS still requires the full verification suite before
`READY FOR HUMAN`.
