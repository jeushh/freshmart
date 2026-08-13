# FreshMart Bugfix Loop

Use this loop for incorrect existing behavior.

## 1. Reproduce

Identify:

- observed behavior
- expected behavior
- affected role or workflow
- exact code path
- nearest existing test

Do not change production code before the defect is understood.

## 2. Regression test

When practical, add a focused regression test that demonstrates the defect.

The test should protect the business rule rather than implementation details.

## 3. Minimal fix

Change only what is necessary to correct the defect.

Do not perform unrelated cleanup or redesign.

## 4. Focused verification

Run:

- the new regression test
- neighboring workflow tests where risk is meaningful

Check specifically for:

- authorization widening
- unintended inventory mutation
- unintended finance mutation
- compatibility changes

## 5. Independent review

The Reviewer determines:

- whether the root cause was fixed
- whether the regression test would catch recurrence
- whether scope stayed minimal
- whether compatibility changed
- whether unrelated behavior changed

## 6. Repair loop

When review returns `HOLD`:

FIX
→ FOCUSED TEST
→ REVIEW

Stop with `HOLD` after three recurrences of the same unresolved failure.

## 7. Full validation

Run all applicable gates in `../VERIFICATION.md`.

## 8. Stop

Report `READY FOR HUMAN` only after review and required validation pass.

Do not stage or publish automatically.
