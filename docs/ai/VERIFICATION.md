# FreshMart Verification Gates

Verification is evidence, not confidence.

An agent must not claim a gate passed merely because another agent said it passed.

## 1. Repository state

Before implementation and before handoff, capture:

- current branch
- current HEAD
- working-tree status
- changed, staged, and untracked files

Useful commands:

```bash
git branch --show-current
git rev-parse HEAD
git status --short
git diff --cached --name-only
git ls-files --others --exclude-standard
```

Unless the human explicitly authorized staging for the current gate,
`git diff --cached --name-only` must be empty.

If repository state differs from the approved baseline, return `HOLD`.

## 2. Focused verification

Run the narrowest meaningful tests for the changed behavior.

Backend example:

```bash
cd apps/api
php artisan test --filter=RelevantFeatureTest
```

A bug fix should preferably include a regression test that detects the original defect.

Focused tests never replace full validation.

## 3. Backend validation

When backend or PHP behavior changes, run the applicable checks:

```bash
cd apps/api
composer validate --strict --no-check-publish
vendor/bin/pint --test
php artisan migrate:status
php artisan freshmart:health
php artisan test
```

Do not manually run destructive migration or database reset commands.

## 4. Frontend validation

When Vue or frontend behavior changes:

```bash
cd apps/web
npm run lint
npm run build
```

## 5. Diff integrity

From repository root inspect:

```bash
git diff --check
git status --short
git diff --name-only
git diff --cached --name-only
git ls-files --others --exclude-standard
```

Review the actual tracked patch and every untracked file.

`git diff` and `git diff --check` do not include untracked file contents. Every
untracked file must therefore be opened and reviewed directly, or represented
in an explicit patch artifact, and receive equivalent whitespace/style checks.

Confirm:

- only approved files changed
- no secrets
- no debug leftovers
- no unrelated formatting sweep
- no dependency changes outside scope
- no migrations outside scope
- no RBAC widening outside scope
- no unexpected legacy changes

## 6. Independent review

The Reviewer must compare:

- approved task
- acceptance criteria
- actual changed files
- actual patch
- tests
- domain guards
- safety rules

Do not trust only the Implementer's summary.

Blocking findings return the task to implementation.

## 7. Repair rule

After a blocking repair:

FIX
→ FOCUSED TEST
→ REVIEW

Never skip re-review after a repair.

## 8. READY FOR HUMAN gate

`READY FOR HUMAN` requires all applicable verification and review gates to pass.

The final report should include:

- branch
- base SHA
- current HEAD
- changed files
- staged-file result
- untracked-file review result
- focused-test result
- full Laravel result when applicable
- Pint result when applicable
- ESLint result when applicable
- Vite build result when applicable
- diff-check result
- forbidden-scope result
- reviewer verdict
- working-tree status

## 9. Human Git boundary

`READY FOR HUMAN` does not authorize:

- staging
- committing
- pushing
- PR creation
- merging
- branch deletion

Those remain human-controlled gates.

## 10. GitHub Actions

After a human creates a pull request, required GitHub Actions checks become an additional gate.

Do not recommend merge while required CI is failing, cancelled, or pending.

Local success does not override failing CI.
