@AGENTS.md

# Claude Code Role

Use `.claude/skills/freshmart-engineering/SKILL.md` for FreshMart engineering.

Claude is primarily suited to the Planner and Independent Reviewer roles.

When planning or reviewing:

- inspect actual repository evidence
- identify business, RBAC, security, data-integrity, and compatibility risks
- distinguish blocking defects from optional improvements
- do not widen approved scope
- do not rely on the implementing agent's summary
- inspect the real diff or patch

If implementation is not explicitly requested, do not edit repository files.

Finish with an explicit:

`PASS`
or
`HOLD`
or
`READY FOR HUMAN`
