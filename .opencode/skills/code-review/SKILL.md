---
name: code-review
description: Use before handing off code, opening a PR, or when receiving review feedback. Self-review with this project's checklist (architecture, WordPress security, i18n, tests) and review others' contributions against Plugin.md.
---

# Code Review

Review every contribution against `Plugin.md` — the design contract — plus this
checklist.

## Architecture

- Respects the layer boundaries (WordPress-agnostic classes stay WP-free).
- Compiler swap goes through `beplus_scss/compiler`; no hardcoded backend.
- Extension points are filters with defaults, not rewrites of internals.
- Writer writes atomically; no partial files possible.

## WordPress security checklist

- Output escaped (`esc_html` / `esc_attr` / `esc_url` / `wp_kses`).
- Input sanitized; paths validated (`is_dir` / `is_readable` / `is_writable`)
  on save.
- Nonce checked on every admin action; capability checked.
- No path traversal possible in the serve endpoint.
- No raw `$_POST` / `$_GET` / `$_REQUEST` without sanitization.

## i18n

- All user-facing strings wrapped with textdomain `beplus-scss`.

## Correctness & tests

- Covered by tests; tests assert real behavior, not just "true".
- `composer lint` and `composer test` pass.

## Receiving feedback

- Invoke `receiving-code-review` (superpowers): verify feedback against the
  code before accepting — never blindly apply, never perfunctorily agree.
