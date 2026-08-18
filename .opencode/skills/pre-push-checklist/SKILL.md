---
name: pre-push-checklist
description: Use before committing to git or pushing to a shared branch. Runs the final safety checks: green lint/tests, no secrets, Plugin.md still consistent, a clean diff, and updated readme.txt/pot when user-facing strings changed.
---

# Pre-Push Checklist

Run through this checklist before ANY commit or push. Stop and fix anything red.

## Commit-time

- `composer lint`, `composer analyse`, and `composer test` are green.
- A local gate runs these three via husky `pre-commit` (`.husky/pre-commit`);
  it is bypassable with `--no-verify`, so the human re-runs the checks before
  committing.
- `git status` shows only intended files.
- No secrets/keys/credentials in the diff (scan for tokens, `.env`, private keys).
- No build artifacts committed: `vendor/`, `node_modules/`, `.codegraph/` data.
- Today's `docs/worklogs/YYYY-MM-DD.md` exists and is part of the diff when
  the commit includes work done today.
- Diff matches the task; no unrelated changes.

## Push-time

- The last commit was reviewed; the message is concise and describes intent.
- `Plugin.md` still matches the shipped code; if behavior changed, `Plugin.md`
  was updated first (with approval).
- `readme.txt` changelog and version bumped for user-facing changes
  (public-ready plugin).
- Pot file (i18n) regenerated when strings changed (`wp i18n make-pot`).

## Before merging to a shared branch

- Request a review, or self-review with the `code-review` skill checklist.
- No debugging leftovers (`error_log`, `var_dump`, `print_r`).

Never force-push to shared branches.
