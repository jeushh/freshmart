# FreshMart Loop Engineering

This directory defines the tool-neutral AI engineering protocol for FreshMart.

It is designed for supervised collaboration among human developers and coding
agents such as Claude Code, Codex, OpenCode, and other repository-capable AI
tools.

The process deliberately separates planning, implementation, review,
verification, and Git publication.

## Goal

FreshMart contains workflows where a visually correct implementation can still
be wrong if it:

- widens permissions
- changes inventory unexpectedly
- alters financial semantics
- breaks historical compatibility
- bypasses server-side authority
- weakens transaction or idempotency rules
- passes only because a test was weakened

Loop engineering therefore optimizes for verified correctness rather than
single-pass code generation.

## Evidence precedence

For an active task, use this order:

1. explicit human-approved task and acceptance criteria
2. current application code and tests
3. canonical project documentation
4. AI workflow documentation in this directory
5. agent assumptions

If these materially conflict, stop with `HOLD`.

Do not silently reconcile conflicting requirements.

## Canonical project documentation

The AI layer does not replace FreshMart's main documentation.

Read the relevant files:

- `../ARCHITECTURE.md`
- `../SECURITY.md`
- `../DATABASE_SCHEMA.md`
- `../LOCAL_DEVELOPMENT.md`
- `../REPORTING.md`
- `../OPERATIONS.md`
- `../BACKUP_AND_RESTORE.md`

## Standard collaboration

The preferred sequence is:

Human
→ Planner
→ Implementer
→ Independent Reviewer
→ Verifier
→ Human Git Gate
→ GitHub Actions
→ Human Merge Gate

One AI may perform more than one role, but self-review must never be presented
as independent review.

## Loop types

The repository defines separate workflows for:

- feature implementation
- bug fixes
- independent review

Supporting contracts define:

- safety boundaries
- FreshMart domain guards
- verification gates
- agent handoffs
- reusable start prompts

## Completion language

Use only:

- `PASS`
- `HOLD`
- `READY FOR HUMAN`

`READY FOR HUMAN` means the implementation is ready for the next human-owned
Git action. It does not authorize staging, committing, pushing, or merging.
