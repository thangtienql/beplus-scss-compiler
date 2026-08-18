---
name: project-workflow
description: Use when starting ANY task in this repo — feature, bugfix, refactor, or chore. Establishes the mandatory order of operations: brainstorm, update Plugin.md if the design changes, write an implementation plan, then build with TDD. Prevents skipping design and jumping straight into code.
---

# Project Workflow

The design contract lives in `Plugin.md`. Everything we build must stay true to
it. Follow this order for every task — do not skip steps.

## 1. Understand the task

- Read `Plugin.md` first (the architecture is the contract).
- Read `AGENTS.md` for conventions.
- Explore the relevant code (prefer `codegraph explore` when an index exists).

## 2. Brainstorm the design

- Invoke the `brainstorming` skill (superpowers) to clarify intent.
- If the change affects the architecture or the contract, propose the
  `Plugin.md` edit inside the design discussion and get approval BEFORE
  touching it.

## 3. Update Plugin.md (only if the design changed)

- Any change to architecture, layers, setting keys, or filter names must be
  reflected in `Plugin.md` first.
- Editing `Plugin.md` requires explicit user approval in chat (it is **not**
  enforced by the `opencode.json` permission system — the rule lives here).
  Never edit it silently.

## 4. Write an implementation plan

- For multi-step work invoke the `writing-plans` skill (superpowers).
- For small, one-file changes a short plan in chat is enough — still get
  approval before implementing.

## 5. Build with TDD

- Invoke `test-driven-development` (superpowers): red → green → refactor.
- Follow `wp-plugin-dev` for implementation conventions.

## 6. Write the daily work log

- Create `docs/worklogs/YYYY-MM-DD.md` (English) before verifying. Template:
  `# YYYY-MM-DD`, then `## Summary`, `## Changes`, `## Files`,
  `## Verification`, `## Next`.
- A log exists only on days with actual changes to the repo — no file on days
  without changes.

## 7. Verify

- Run `verify-code` before declaring the task done.
- Run `code-review` before handing off.

## Never

- Jump straight into code without design approval.
- Bypass `Plugin.md` to "just make it work".
- Split a task across several messages without a todo list.
