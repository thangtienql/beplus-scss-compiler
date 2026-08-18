---
name: wp-plugin-dev
description: Use when writing or editing PHP for this project. Enforces the Beplus SCSS Compiler implementation conventions: layered architecture with WordPress-agnostic classes, PSR-4, filters, security (escaping/sanitization/nonces), i18n, atomic writes, and WPCS compliance.
---

# Beplus SCSS Compiler — Implementation Conventions

Read `Plugin.md` before implementing anything.

## Architecture rules

- Layers: `Settings\SettingsPage` / `Scanner` / `Detector` / `Compiler` / `Writer` /
  `Enqueue`, wired by the `Plugin` bootstrap (glue).
- ONLY `Settings\SettingsPage` and `Plugin` may call WordPress functions.
- `Scanner`, `Detector`, `Compiler`, `Writer`, and `Enqueue` are
  WordPress-agnostic: they receive paths/config as parameters and return value
  objects. No `wp_*()` calls, no global state. Inject dependencies.
- The Compiler is selected through the `beplus_scss/compiler` filter; never
  hardcode a backend bypass.
- The Writer writes atomically (temp file + rename).
- New extensibility points go through `beplus_scss/*` filters with sensible
  defaults — never force consumers to rewrite internals.

## Structure

- Namespace `Beplus\ScssCompiler\`, PSR-4 autoload mapped to `src/`.
- One class per file, StudlyCaps filename (PSR-4).

## Security (WordPress)

- Escape ALL output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`.
- Sanitize ALL input; validate admin paths with
  `is_dir()` / `is_readable()` / `is_writable()`.
- Admin forms need a nonce and a `current_user_can()` capability check.
- Never trust a path — guard the serve endpoint against traversal.
- No `eval()`, no unguarded remote URL fetching.

## i18n

- Every user-facing string is wrapped in `__()` / `esc_html__()` /
  `esc_html_e()` with textdomain `beplus-scss`.
- Never hardcode English in admin UI.

## Code style

- WordPress Coding Standards (phpcs, `WordPress-Extra`). Tabs for indentation.
- No comments that restate the code ("what" comments). Explain "why" only.
- `composer lint` must pass before completion.
