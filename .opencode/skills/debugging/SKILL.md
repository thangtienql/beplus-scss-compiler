---
name: debugging
description: Use when encountering a bug, test failure, or unexpected behavior in this project — compile errors, wrong CSS output, admin validation issues, or wp-env test failures. Applies the systematic-debugging process with WordPress-specific guidance.
---

# Debugging (WordPress context)

Follow `systematic-debugging` (superpowers) first — reproduce, isolate root
cause, fix, verify. This skill adds project-specific guidance.

## Layer isolation first

- Is the bug in a pure layer (`Compiler` / `Scanner` / `Detector` / `Writer` /
  `Enqueue`) or in the WP glue (`Settings\SettingsPage` / `Plugin` bootstrap /
  enqueue hook)?
- Pure layers: reproduce with a plain PHP unit test — no WordPress involved.
- WP glue: reproduce inside the `wp-env` environment; never debug WP code
  outside it.

## Common failure points in this project

- **Compile errors / wrong CSS** → isolate the exact SCSS input, confirm the
  expected CSS, and check import resolution and scssphp version behavior.
- **Auto-recompile not firing** → verify the fingerprint logic (entry + import
  paths), `filemtime`, and that `wp_enqueue_scripts` actually ran.
- **Admin save rejecting paths** → check normalization (trailing slash, relative
  path) and filesystem permissions.
- **CSS 404 / wrong URL** → check the `web_root` flag and URL resolution logic.
- **wp-env test failing** → confirm the plugin main file exists and mounts.

## Evidence

- Write a failing test that reproduces the bug BEFORE fixing (red), then fix it
  (green).
- Report the root cause plus the test, not just symptoms.
