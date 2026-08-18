# Beplus SCSS Compiler

A public-ready WordPress plugin that compiles SCSS to CSS. Developers declare a
SCSS source directory and a CSS destination directory in the admin; the plugin
scans SCSS (mirror structure), recompiles when files change (`auto` mode) or on
demand (`manual` mode), and enqueues the compiled CSS on the frontend.

> **Design contract:** see [`Plugin.md`](Plugin.md) — read it before working on
> the code.

## Requirements

- WordPress 6.0+
- PHP >= 7.4 (enforced via phpcs `PHPCompatibility`, `testVersion 7.4-`)
- Composer (dev), Node.js/npm (dev), Docker (optional, integration tests)

## Setup (developers)

```bash
composer install     # PHP tooling (wpcs, phpunit)
npm install          # npm tooling (codegraph, wp-env)
codegraph init       # build the local code graph index (machine-local)
```

## Commands

| Command | Purpose |
|---|---|
| `composer lint` | phpcs — WordPress Coding Standards (`WordPress-Extra`) + PHP 7.4 compat |
| `composer lint:fix` | phpcbf — auto-fix style issues |
| `composer analyse` | phpstan — static analysis at the highest level |
| `composer test` | phpunit — unit tests for the pure layers (run integration via `composer test:integration` inside wp-env) |
| `composer i18n:pot` | regenerate `languages/beplus-scss.pot` (`wp i18n make-pot`) |
| `npm run build` | build the distributable zip → `build/beplus-scss-compiler.zip` |
| `npm run wp-env start` | start the WordPress integration environment |

## Repository structure

```
├── Plugin.md              # design contract (architecture source of truth)
├── AGENTS.md              # conventions + workflow for AI agents
├── .opencode/skills/      # repo-level skills (workflow, conventions, review)
├── scripts/build-package.mjs # release build → build/beplus-scss-compiler.zip
├── src/                   # PSR-4: Beplus\ScssCompiler\
└── tests/                 # unit (pure layers) + integration (wp-env)
```

## Contributing

Read `AGENTS.md` and `Plugin.md` first. Every task follows the documented
workflow: brainstorm → design → plan → TDD → verify → review.
