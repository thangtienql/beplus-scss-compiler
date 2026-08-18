---
name: verify-code
description: Use when finishing an implementation or before claiming a task is done. Runs the project's verification commands (composer lint, composer test), checks the change respects the architecture, and reports evidence before declaring success.
---

# Verify Code

Never claim "done" without evidence. Run these before closing any task.

## 1. Static checks

- `composer lint` (phpcs, `WordPress-Extra` + PHP 7.4 compatibility) must be clean.
- `composer analyse` (phpstan, level `max`) must be clean.
- Fix with `composer lint:fix` only after reviewing what it changed.

## 2. Tests

- `composer test` (phpunit) — all green.
- The integration suite runs via `wp-env` once the plugin main file exists.
- New behavior ships with tests (TDD); existing suites must stay green.

## 3. Daily work log

- `docs/worklogs/YYYY-MM-DD.md` (today) exists and reflects the changes made.
- No log is needed only when the session produced no repo changes.

## 4. Architecture audit

- No `wp_*()` calls leaked into the WordPress-agnostic layers.
- New extension points go through `beplus_scss/*` filters.
- `Plugin.md` still matches the code; if not, flag it (see `project-workflow`).

## 5. Report evidence

- Report the exact command output, not "it should work".
- If a check fails, fix it or report the failure honestly — do not mark the
  task done.
