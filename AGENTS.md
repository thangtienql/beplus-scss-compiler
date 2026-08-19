# AGENTS.md — Beplus SCSS Compiler

## Project Overview

A public-ready WordPress plugin that compiles SCSS to CSS. Developers declare a
SCSS source directory and a CSS destination directory in the admin; the plugin
scans SCSS (mirror structure), recompiles when files change (`auto` mode) or on
demand (`manual` mode), and enqueues the compiled CSS on the frontend.

**Design contract:** read `Plugin.md` FIRST before any design or code work. It is
the source of truth for the architecture. If a change affects the design, the
`Plugin.md` edit comes first (approved by the user), then the code.

## Repository Rules

- All project files and documentation are written in English.
- Do not add comments unless they explain *why*, not *what*.
- Never commit secrets, build artifacts, or machine-local data.
- Every working day with changes ships a `docs/worklogs/YYYY-MM-DD.md`
  summarizing them; no file is created on days without changes.

## Tech Stack & Requirements

- PHP >= 7.4, public-ready WordPress plugin (WordPress Coding Standards).
- PSR-4 autoload: `Beplus\ScssCompiler\` mapped to `src/`.
- Compiler backend: scssphp (`scssphp/scssphp:^1.11`) — added in the implementation phase, not yet.
- Dev tooling: Composer (`wpcs`, `phpunit`, `phpstan`, `phpcompatibility`), npm
  (`@colbymchenry/codegraph`, `@wordpress/env`).

## Architecture (from Plugin.md)

Layers: `Settings\SettingsPage` / `Scanner` / `Detector` / `Compiler` / `Writer` /
`Enqueue`, wired together by the `Plugin` bootstrap (glue).

- ONLY `Settings\SettingsPage` and `Plugin` may call WordPress functions.
- The other layers are WordPress-agnostic: paths/config in, value objects out.
  No `wp_*()` calls, no global state.
- The Compiler is chosen through the `beplus_scss/compiler` filter — swappable.
- The Writer uses atomic writes (temp file + rename).
- Extensibility happens through `beplus_scss/*` filters with sensible defaults.
- Every name, hook, default, and format is pinned in `Plugin.md` Appendix A —
  do not invent alternatives.

## Workflow

Every task follows this order (see the `project-workflow` skill):

1. Brainstorm the design (`brainstorming` skill).
2. If the design changes, update `Plugin.md` first.
3. Write an implementation plan (`writing-plans` skill) for multi-step work.
4. Build with TDD (`test-driven-development` skill), following `wp-plugin-dev`.
5. Verify with `verify-code`; review with `code-review` before handing off.
6. Debug via `debugging` + `systematic-debugging`.
7. Before the human commits or pushes, run the `pre-push-checklist` skill. A
   husky `pre-commit` hook enforces `composer lint` + `composer analyse` +
   `composer test` locally.

**The agent never commits, pushes, or merges — the human does.** Preparing the
change, running the checks, and reporting the diff is the agent's job; the commit
command itself is always issued by the human.

## Code Conventions

- WordPress Coding Standards (phpcs, `WordPress-Extra`). Tabs for PHP.
- PHP compatibility enforced via phpcs (`PHPCompatibility`, `testVersion 7.4-`):
  code must run on PHP 7.4.
- PHPStan runs at the highest level (`composer analyse`) — keep it green.
- Escape all output; sanitize all input; nonces + capability checks in admin.
- i18n: every user-facing string wrapped with textdomain `beplus-scss`.
- One class per file, StudlyCaps filename (PSR-4).

## Setup (one-time, per developer)

```bash
composer install     # PHP tooling (wpcs, phpunit)
npm install          # npm tooling (codegraph, wp-env)
codegraph init       # build the local code graph index (machine-local, not committed)
```

## Commands

| Command | Purpose |
|---|---|
| `composer lint` | phpcs — WordPress-Extra standards + PHP 7.4 compatibility |
| `composer lint:fix` | phpcbf — auto-fix style issues |
| `composer analyse` | phpstan — static analysis at the highest level |
| `composer test` | phpunit — unit tests (pure layers) |
| `composer test:integration` | phpunit — integration suite (run inside wp-env) |
| `composer i18n:pot` | regenerate `languages/beplus-scss.pot` (`wp i18n make-pot`) |
| `npm run build` | compile frontend assets (no-op today); never packages the zip |
| `npm run build:package` | build the distributable zip → `build/beplus-scss-compiler-<version>.zip` |
| `npm run wp-env start` | start the WordPress integration environment |
| `npm run wp-env run phpunit` | run the integration suite inside wp-env |
| `codegraph explore "<query>"` | understand code via the local index |

## CodeGraph

When a `.codegraph/` index exists, use it BEFORE grep/glob/read to understand or
locate code:

- **MCP tool** (when available): `codegraph_explore` answers most code questions
  in one call — relevant symbols' source plus call paths.
- **Shell** (always works): `codegraph explore "<symbol names or question>"`

If there is no `.codegraph/` index, skip CodeGraph and use grep/glob/read.
