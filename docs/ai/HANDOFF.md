# FreshMart Agent Handoff Contract

Use this contract when transferring a task between AI agents or between an AI agent and the human orchestrator.

A handoff is an evidence package, not merely a prose summary.

## Required format

```text
FRESHMART HANDOFF

Task:
Role handing off:
Role receiving:

Repository:
Branch:
Base SHA:
Current HEAD:

Goal:

Approved scope:

Forbidden scope:

Acceptance criteria:

Business invariants:

RBAC constraints:

Changed files:

Untracked files:

Staged-file result:

Untracked-file review result:

Tests added or changed:

Focused verification:

Full verification:

Diff-check result:

Forbidden-scope result:

Known risks:

Blocking findings:

Non-blocking findings:

Iteration count:

Verdict:
PASS | HOLD | READY FOR HUMAN

Required next action:
```

## Receiving-agent obligations

The receiving agent must independently verify:

- branch
- current SHA
- working-tree state
- changed-file list

Do not assume the handoff is correct merely because it is formatted correctly.

## Planner handoff

The Planner should provide:

- current behavior
- minimal implementation scope
- forbidden scope
- acceptance criteria
- security boundaries
- business invariants
- expected tests
- sensitive areas that require human approval

A Planner should return `HOLD` when the task is not sufficiently specified.

## Implementer handoff

An Implementer must provide:

- exact changed files
- exact untracked files
- staged-file result
- untracked-file review result
- focused-test evidence
- `git diff --check` result
- unexpected issues encountered
- actual patch or a reliable way for the Reviewer to inspect it

Do not stage, commit, or push as part of the handoff.

## Reviewer handoff

A Reviewer must distinguish:

### Blocking

Issues that prevent approval because of:

- incorrect behavior
- security or RBAC regression
- business-rule violation
- unintended mutation
- compatibility break
- missing critical negative test
- unexpected scope

### Non-blocking

Optional improvements such as:

- naming preference
- refactoring opportunity
- future enhancement
- cosmetic cleanup

Do not turn optional refactors into blockers.

## Verifier handoff

A Verifier must report actual results for every applicable gate.

Do not say:

`tests look good`

when the required information is concrete command evidence.

## HOLD behavior

When returning `HOLD`, state:

1. the exact blocker
2. the evidence
3. the smallest action needed to resolve it

Avoid broad redesign proposals unless the blocker truly requires them.

## READY FOR HUMAN behavior

`READY FOR HUMAN` means:

- implementation is complete
- independent review passed
- required validation passed
- scope is clean
- Git publication has not been authorized automatically

The next action must explicitly return control to the human.
