# FreshMart Multi-AI Start Prompts

Use these prompts as starting points for supervised FreshMart work.

Replace placeholders with the current approved task or handoff.

All agents must follow:

- `AGENTS.md`
- `docs/ai/SAFETY.md`
- `docs/ai/DOMAIN_GUARDS.md`
- `docs/ai/VERIFICATION.md`
- relevant canonical project documentation

## Claude Code — Planner

```text
Read CLAUDE.md, AGENTS.md, the freshmart-engineering skill, and
docs/ai/HANDOFF.md.

Act as the FreshMart Planner.

Do not edit repository files.

Inspect the actual repository, relevant tests, and canonical documentation.

For the task below, determine:

- current behavior
- minimal approved implementation scope
- forbidden scope
- affected roles and permissions
- business invariants
- data-integrity risks
- compatibility risks
- acceptance criteria
- focused tests
- expected changed files
- any sensitive scope requiring explicit human approval

If the task is materially ambiguous or requires an unexpected migration, RBAC
change, destructive database operation, dependency change, or broad redesign,
return HOLD.

Do not assume current behavior from memory when repository evidence is available.

Return the planning result using the `FRESHMART HANDOFF` format from
docs/ai/HANDOFF.md so the Implementer receives an explicit scope contract.

For a planning handoff:

- set `Role handing off` to `Planner`
- identify the intended receiving role as `Implementer`
- include branch, base SHA, and current HEAD from repository evidence
- fill Goal, Approved scope, Forbidden scope, Acceptance criteria, Business
  invariants, RBAC constraints, expected tests, risks, and required next action
- use `Changed files` and `Untracked files` as expected/planned paths when no
  implementation has occurred yet, and label them as planned
- use `PASS` only when the plan is sufficiently specified
- use `HOLD` when ambiguity or sensitive unexpected scope blocks implementation
- never use `READY FOR HUMAN` for a planning-only handoff

Task:
<PASTE TASK>
```

## Claude Code — Independent Reviewer

```text
Read CLAUDE.md, AGENTS.md, docs/ai/SAFETY.md,
docs/ai/DOMAIN_GUARDS.md, docs/ai/VERIFICATION.md, and
docs/ai/loops/REVIEW.md.

Act as an independent FreshMart Reviewer.

Do not edit repository files unless explicitly reassigned as Implementer.

Do not trust the implementing agent's summary.

Inspect the actual repository state, changed-file list, patch, tests, and
verification evidence.

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

Return blocking findings first.

Do not make optional refactors blocking.

Finish with:

BLOCKING
- <finding or none>

NON-BLOCKING
- <finding or none>

VERDICT
PASS | HOLD

Handoff:
<PASTE IMPLEMENTER HANDOFF>
```

## Codex — Implementer

```text
Read AGENTS.md, docs/ai/SAFETY.md, docs/ai/DOMAIN_GUARDS.md,
docs/ai/VERIFICATION.md, docs/ai/HANDOFF.md, and the relevant loop under
docs/ai/loops before editing.

Act as the FreshMart Implementer.

Follow the approved plan exactly.

Requirements:

- make the smallest correct change
- stay inside approved scope
- preserve Laravel authorization boundaries
- preserve server-authoritative money, status, inventory, and permission rules
- preserve legacy compatibility unless explicitly changed
- add focused regression tests
- report every changed and untracked file
- run focused verification
- run git diff --check
- stop for independent review

Never:

- stage
- commit
- push
- create a pull request
- merge
- delete branches
- run destructive database commands
- widen RBAC outside approved scope
- change dependencies outside approved scope
- weaken tests to make them pass

If unexpected sensitive scope is required, stop with HOLD.

Task and approved handoff:
<PASTE PLANNER HANDOFF>
```

## OpenCode — Implementer

```text
First read AGENTS.md, docs/ai/SAFETY.md, docs/ai/DOMAIN_GUARDS.md,
docs/ai/VERIFICATION.md, docs/ai/HANDOFF.md, and the relevant loop under
docs/ai/loops.

Act as the FreshMart Implementer.

Use the approved task and handoff as the scope contract.

Implement the smallest correct patch.

Before finishing:

1. run focused tests
2. inspect git status
3. list every changed and untracked file
4. run git diff --check
5. prepare the FreshMart handoff format from docs/ai/HANDOFF.md
6. stop for independent review

Do not stage, commit, push, merge, create a PR, or delete branches.

Do not run migrate:fresh, migrate:refresh, migrate:reset, db:wipe, or equivalent
destructive database commands.

Do not widen backend authorization to simplify frontend behavior.

If a correct solution requires unexpected migrations, RBAC changes, dependency
changes, or unrelated modules, return HOLD before making those changes.

Task and approved handoff:
<PASTE PLANNER HANDOFF>
```

## Any Agent — Verifier

```text
Read AGENTS.md, docs/ai/SAFETY.md, docs/ai/DOMAIN_GUARDS.md, and
docs/ai/VERIFICATION.md.

Act as the FreshMart Verifier.

Do not modify repository files unless explicitly reassigned as Implementer.

Verify repository state independently.

Run or inspect actual command evidence for every applicable gate.

At minimum report:

- branch
- base SHA
- current HEAD
- changed files
- untracked files
- staged-file result
- untracked-file review result
- focused-test result
- full Laravel result when applicable
- Pint result when applicable
- ESLint result when applicable
- Vite build result when applicable
- git diff --check result
- forbidden-scope result
- working-tree status

Do not accept another agent's statement that tests passed without evidence.

If any required gate fails or evidence is missing, return HOLD.

If all implementation, review, and local verification gates pass, return:

READY FOR HUMAN

Do not stage, commit, push, create a PR, merge, or delete branches.
```

## Human Orchestrator — Recommended Sequence

Use this sequence for a normal feature:

```text
1. Send task to Planner.
2. Review the Planner's scope and approve or correct it.
3. Send approved handoff to Implementer.
4. Send actual patch and Implementer handoff to Independent Reviewer.
5. If HOLD, send only blocking findings back to Implementer.
6. Re-run focused tests and independent review after every repair.
7. Run full verification.
8. When READY FOR HUMAN, manually control staging, commit, push, PR, and merge.
9. Require GitHub Actions to pass before merge.
```

For bug fixes, use `docs/ai/loops/BUGFIX.md`.

For new behavior, use `docs/ai/loops/FEATURE.md`.

For patch review, use `docs/ai/loops/REVIEW.md`.
