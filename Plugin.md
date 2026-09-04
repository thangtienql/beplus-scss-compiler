# Beplus SCSS Compiler — Design Document

## 1. Introduction

A public-ready WordPress plugin that compiles SCSS to CSS. Developers declare an **SCSS source directory** and a **CSS destination directory** as **relative paths inside the active theme** (e.g. `assets/scss`, `assets/css`); the plugin scans SCSS following a *mirror structure*, recompiles when files change (`auto` mode) or on manual trigger (`manual` mode), and — only when the `enqueue` option is enabled — enqueues the compiled CSS on the frontend. Out of the box the plugin's job is purely **SCSS → CSS inside the active theme**; delivery is opt-in.

Purpose: this is the **solid core** — the shared knowledge base that lets newcomers (both humans and AI agents) understand the design and implement code without drifting from the architecture.

**Zero-guessing rule**: every name, path, hook, default, and format below is pinned. Implementing code shall not invent alternatives to anything documented here or in Appendix A — if something is ambiguous, expand this document first.

## 2. Architecture Overview

The plugin is split into independent layers connected through interfaces, each with a single responsibility:

```
┌─────────────────────────────────────────────────┐
│  Admin UI (Settings)        SettingsPage         │  options + validate on Save
├─────────────────────────────────────────────────┤
│  Scanner + Detector         Scanner / Detector   │  scan .scss mirror, detect changes
├─────────────────────────────────────────────────┤
│  Compiler       CompilerInterface/ScssPhpCompiler│  scssphp backend, swappable
├─────────────────────────────────────────────────┤
│  Writer                     Writer               │  write .css into css_dir (atomic)
├─────────────────────────────────────────────────┤
│  Enqueue / Delivery         Enqueue              │  Style DTO list + glue enqueues
├─────────────────────────────────────────────────┤
│  Bootstrap                   Plugin (glue)      │  wires hooks/filters, WP-touch
└─────────────────────────────────────────────────┘
```

**Principle**: `Scanner`, `Detector`, and `Enqueue` are **WordPress-agnostic** — they receive paths/config as parameters and return results via value objects; only `Settings\SettingsPage`, `Plugin` (the bootstrap), and the `Filesystem` helper touch WordPress. `Compiler` and `Writer` keep the same contract shape (paths/config in, value objects out) but read/write files through the `Filesystem` helper (a thin `WP_Filesystem` wrapper) so the plugin satisfies the Plugin Check "direct filesystem" guidance.

**Namespace layout** (PSR-4, `Beplus\ScssCompiler\` → `src/`):

```
src/
├── Plugin.php                        # bootstrap/glue — the only other WP-touch class
├── Filesystem.php                    # WP_Filesystem wrapper (lazy init) — FS access helper
├── Settings/
│   └── SettingsPage.php              # settings UI + sanitize + compile-now handler
├── Scanner.php
├── Detector.php
├── Writer.php
├── Compiler/
│   ├── CompilerInterface.php
│   └── ScssPhpCompiler.php
├── Enqueue.php
└── Value/
    ├── CompileConfig.php
    ├── CompiledResult.php
    └── Style.php
```

## 3. Settings Layer

Submenu under the **Settings** menu (`options-general.php`): title "Beplus SCSS Compiler", slug `beplus-scss-compiler`, capability `manage_options`. Uses the Settings API.

**Scope contract**: the plugin operates **only inside the active theme** (`get_stylesheet_directory()`, i.e. the theme currently rendering the frontend, parent or child). Users enter **relative paths** from that theme root (e.g. `assets/scss`, `assets/css`); the plugin resolves them against the theme directory. This keeps the blast radius bounded to the theme and makes paths portable across environments.

Single option, an array under key `beplus_scss_settings`:

| Key            | Type   | Meaning                                                |
|----------------|--------|--------------------------------------------------------|
| `pairs`        | array  | list of `{scss_dir, css_dir}` rows. Each pair is an independent SCSS→CSS mapping; the plugin compiles and (when enabled) enqueues every valid pair. |
| `compile_mode` | enum   | `auto` (recompile when files change) / `manual` (compile via button) |
| `source_map`   | bool   | emit `.css.map` next to the CSS file when enabled       |
| `minify`       | bool   | compact output                                          |
| `enqueue`      | bool   | when `true`, auto-enqueue the compiled CSS on the frontend; when `false` (default), the plugin only compiles and the developer enqueues manually |

Each `pairs` row is `{ 'scss_dir' => string, 'css_dir' => string }` — both
**relative to the active theme** (e.g. `assets/scss` / `assets/css`), stored
normalized without leading/trailing `/`. Fully blank rows are dropped on save;
a row whose path fails validation keeps its previous value and registers a
row-specific error (`bad_scss_dir_<i>` / `bad_css_dir_<i>`). The legacy
top-level `scss_dir` / `css_dir` keys mirror the first pair (and are emptied
when there are no pairs) so older consumers keep working; a fresh read migrates
legacy values into `pairs[0]` when `pairs` is absent.

**Defaults** (fresh install / empty option): `pairs=[]`, `compile_mode='auto'`, `source_map=false`, `minify=false`, `enqueue=false`. Until at least one valid pair is saved, the plugin is inactive: nothing is compiled and nothing is enqueued.

**Validate on Save** (in the `sanitize_callback` of `register_setting`), per pair row:
- Normalize each relative path: trim whitespace, strip leading/trailing `/`, collapse to a clean relative form. Reject `..` segments (path traversal) — the resolved absolute path must stay inside the theme directory.
- Resolve `abs = get_stylesheet_directory() . '/' . $relative` and validate (via `Filesystem`):
  - `scss_dir`: `is_dir()` → `is_readable()` → contains ≥ 1 `.scss` file that is not a `_*.scss` partial.
  - `css_dir`: `is_dir()` → `is_writable()`.
- A row blank in both paths is skipped. On failure → `add_settings_error`, **previous value kept** for the invalid row.
- `css_dir` must be **unique across pairs**, compared case-insensitively after normalization. A later row reusing an already-accepted `css_dir` registers `duplicate_css_dir_<i>`. The rejected row falls back to its previous value — unless that previous value's `css_dir` was already accepted by an earlier row, in which case the row is **dropped** (a revert must never re-introduce the duplication being rejected). The same drop rule applies to rows failing `scss_dir`/`css_dir` validation whose previous value would duplicate an accepted `css_dir`.
- String uniqueness alone cannot see overlaps between **different** `css_dir` values that still map two pairs onto the same output file (e.g. `assets/css` vs the nested `assets/css/modules`, or `//`/`.`/symlink variants of one directory). A pair is therefore additionally rejected when its **compiled output set** intersects an already-accepted pair's: the candidate `scss_dir` is scanned and each entry mapped through `Writer::mirrorPath()` against `realpath(css_dir)` (canonical, so path variants compare equal); any intersection registers `overlapping_css_dir_<i>`. The revert-to-previous fallback re-runs the full acceptance checks (string uniqueness **and** output overlap) and **drops** the row when its previous value would collide — a revert never re-introduces the conflict being rejected. Enforced at save time only; files added to a tree after saving are not re-checked (the enqueue dedupe in §6 is the runtime safety net).
- On read (`storedPairs()`), pairs saved by older versions are deduped the same way — `css_dir` compared case-insensitively, first occurrence wins — so legacy duplicates are neutralized without a migration.
- Validation/save errors are rendered by the plugin itself as `beplus-toast beplus-toast-error` boxes **inside** the settings page layout (after the hero, before the status bar). The core `settings_errors()` renderer — which WordPress runs inline via `wp-admin/options-head.php` on every `options-general.php` child — is neutralized on this screen (`settings_page_beplus-scss`): on `admin_notices` (max priority) the plugin captures the `settings_errors` transient into memory, deletes it, and empties the global `$wp_settings_errors`, so the inline `settings_errors()` prints nothing and the default markup never breaks the custom layout. The captured errors are what `renderPage()` renders (falling back to `get_settings_errors()` when nothing was captured). A successful save with no errors shows a green `Settings saved.` toast.
- Store the **relative** paths (theme-scoped) in the option.

**URL for the frontend** (derived, not stored): because the theme always lives under `ABSPATH`, the URL is always `home_url( '/' ) . ltrim( substr( abs_css_dir, strlen( ABSPATH ) ), '/' )`. Files are served directly by the web server; there is no rewrite endpoint.

**"Compile now" button** (works in both modes; this is how `manual` recompiles):
- Submit to `admin-post.php?action=beplus_scss_compile` (GET), nonce field `beplus_scss_compile_nonce`, capability `manage_options`, nonce check via `check_admin_referer`.
- Runs the same compile pipeline as the Detector but over **all** entries of **every** saved pair regardless of fingerprint; if any pair fails, `msg=error`, then `wp_safe_redirect` back with `msg=compiled|error` (read by the settings page to show a toast).
- The `msg` query arg is stripped from the URL client-side (`history.replaceState`) after the toast renders, so a later Save does not re-show a stale compile toast. Compile toasts are only shown when no validation/save notice is pending — errors always win.

## 4. Compiler Layer

```
CompilerInterface
  compile(string $entryFile, CompileConfig $config): CompiledResult
ScssPhpCompiler   // default backend (scssphp library)
```

- `Value\CompileConfig` — immutable value object: `array $importPaths = []`, `bool $minify = false`, `bool $sourceMap = false`; getters only.
- `Value\CompiledResult` — immutable value object: `string $css`, `?string $map`, `string $fileName`; getters only. `$map` is `null` when source maps are disabled. `$fileName` is the CSS output path **relative to `css_dir`** (e.g. `main.css`, `modules/card.css`) — it is the `Writer::mirrorPath()` result, kept so callers can address the output without re-deriving it.
- `ScssPhpCompiler` wraps `scssphp/scssphp` (`^1.11`, PHP 7.4 compatible):
  - `setImportPaths( $config->getImportPaths() )`.
  - Formatter: `Compressed` when `minify`, else `Expanded`.
  - Source maps: `SourceMap::ENABLED` with `sourceMapBasepath` = `scss_dir`. scssphp appends the `/*# sourceMappingURL=... */` comment to the CSS itself; the Writer is not responsible for appending it.
  - Reads the entry file through the `Filesystem` helper (`$wp_filesystem->get_contents()`) instead of `file_get_contents()`, satisfying the Plugin Check direct-filesystem guidance.
- **Partials `_*.scss`**: never stand alone as entries; they are only used for imports.
- **Exceptions**: scssphp parse/compile exceptions propagate out of `compile()`; the bootstrap glue catches them (see Section 8). The compiler itself must not print anything.

## 5. Scanner + Detector + Writer

**Scanner** — `Scanner::scan(string $scssDir): array` returns a list of absolute paths for every `.scss` file that does not start with `_`, retrieved recursively. Skips hidden directories (those beginning with `.`).

**Fingerprint** — `Scanner::fingerprint(string $scssDir): string` = `md5` over the concatenation of `"relative_path:mtime:size\n"` for **every** `.scss` file under `scss_dir` (including partials). Catching all `.scss` files makes partial changes reliable — no import-graph tracking needed. The result is **one whole-directory md5**: a change to any `.scss` file (partial or not) changes the fingerprint for **every** entry, so the Detector reports all entries as changed and auto mode recompiles them all. Stored in option `beplus_scss_fingerprints` as `array key => fingerprint` where `key = "<pairId>:<relative_path>"` (the pair id is the index in `pairs`) — the same whole-directory value under every entry key of that pair. Pair `0` also reads legacy unprefixed keys so existing installs keep working.

**Detector** — pure: `Detector::changedEntries(array $entries, array $storedFingerprints, string $scssDir): array` compares the current whole-directory fingerprint against each entry's stored value and returns the subset of `$entries` that differ or are missing. A missing key marks a new/deleted-but-still-present entry as changed; a differing value recompiles that entry.

**Auto-mode glue** (in `Plugin`): registered on `wp_enqueue_scripts`, default priority. Guards before doing anything: `! is_admin()`, `! wp_doing_ajax()`, at least one valid saved pair, `compile_mode === 'auto'`, **and `current_user_can( 'manage_options' )`** — only a logged-in administrator (the capability that configures this plugin) triggers compilation on a frontend page load. Anonymous visitors and non-admin roles **never** compile; they only receive the already-compiled CSS through enqueue (§6). This keeps filesystem writes off anonymous traffic: after an SCSS change the CSS updates on the next frontend load by an administrator (or via the "Compile now" button), and anonymous visitors keep the previous CSS until then. Enqueue itself stays ungated — every visitor loads the compiled styles when `enqueue` is on. Then, for **each** saved pair: for each changed entry → compile → write → update the fingerprint option by storing the **current whole-directory fingerprint** under every entry key of that pair. Runs once per request for the whole plugin (static `$done` flag shared across pairs).

**Manual mode**: compiles only via the "Compile now" button (Section 3).

**Writer** — mirrors paths: `scss/main.scss` → `css_dir/main.css`; `scss/modules/card.scss` → `css_dir/modules/card.css`; creates missing subdirectories recursively.
- `Writer::mirrorPath(string $entry, string $scssDir, string $cssDir): string` computes the destination (`relative` from `scssDir`, `.scss` → `.css`).
- Writes **atomically when the transport supports it**: a temp file `.<basename>.tmp.<uniqid>` is written in the destination directory, then moved over the target via `Filesystem::move()` with overwrite enabled (an atomic-rename attempt where supported; on transports that lack atomic `rename` the destination is deleted before the move, so a mid-write failure can lose the previous CSS). A separate write stores the `.map` file at the destination path plus `.map` when source maps are enabled.
- All filesystem access goes through the `Filesystem` helper (a thin `WP_Filesystem` wrapper) rather than direct PHP calls (`mkdir`, `rename`, `unlink`, `get_contents`, `put_contents`, `is_writable`), so the plugin passes the Plugin Check "direct filesystem" guidance. The previous deviation note no longer applies.

**Filesystem helper** — `Filesystem` is the single WordPress-touching access point for file operations. It lazily initializes the transport: it tries `WP_Filesystem()` first (admin path, where the class files are loaded), and when that is unavailable — notably on the **frontend** (`wp-admin/includes/file.php` is not loaded there, so `WP_Filesystem()` does not exist, and auto-compile runs in `wp_enqueue_scripts`) — it falls back to loading `class-wp-filesystem-base.php` + `class-wp-filesystem-direct.php` from `ABSPATH` and instantiating `WP_Filesystem_Direct` directly. This guarantees file operations work in every context while staying inside the WP_Filesystem abstraction (PCP-clean). It forwards the calls the plugin uses (`is_dir`, `is_readable`, `is_writable`, `mkdir`, `get_contents`, `put_contents`, `move`, `delete`). `move()` passes `overwrite=true` so an existing compiled CSS file is replaced on recompile; on transports that lack atomic rename this costs a delete-before-write (no longer atomic there) — accepted trade-off for PCP compliance and correct overwrite behaviour. Writer, `ScssPhpCompiler`, and `SettingsPage` validation route their FS work through it; the pure layers (`Scanner`, `Detector`, `Enqueue`) stay untouched.

## 6. Enqueue + Delivery

- `Enqueue` is pure: `Enqueue::styles(string $cssDir, string $baseUrl, array $registeredFiles, int $pairId): array` returns `Value\Style` DTOs `{ handle, url, version }`, one per `.css` file in `css_dir` **that is registered as compiled by the plugin**, recursing like the scanner (`.map` files ignored). `$registeredFiles` is the list of `"<pairId>:<relative_path>"` entries (from `css_dir`) the plugin actually wrote for that pair — passing it in keeps `Enqueue` WordPress-agnostic and guarantees pre-existing CSS in `css_dir` (e.g. a theme's own `editor-style.css`) is **never** enqueued. For pair `0` an unprefixed legacy `relative_path` entry is also accepted.
- Handle: `beplus-scss-<pairId>-` + relative path slugified (`/` → `-`, `.` stripped). Examples: `beplus-scss-0-main`, `beplus-scss-1-modules-card`. The pair id keeps handles distinct when two pairs contain the same file name.
- **Cache-bust**: `version = filemtime( css_file )`.
- `Enqueue::uniqueByUrl( array $styles ): array` — pure; drops later `Style` entries whose `url` was already seen, keeping the first occurrence and preserving order. Non-`Style` entries pass through untouched (the glue's `instanceof` guard still applies). Called by the glue after the `beplus_scss/enqueue` filter.
- Glue: `Plugin` collects `Style[]` from **every** valid pair, applies `beplus_scss/enqueue` once over the merged list, then maps each `Style` to `wp_enqueue_style( $style->handle, $style->url, [], $style->version )` — gated on `enqueue === true`. After the filter the list is **deduped by URL** (first occurrence wins, pair order), so the frontend never prints two `<link>` tags pointing at the same file — the runtime safety net for overlaps the save-time check cannot see (legacy configs, `beplus_scss/write_path` overrides, entries added after save).
- **URL resolution**: always `home_url( '/' ) . ltrim( substr( abs_css_dir, strlen( ABSPATH ) ), '/' )` because the theme lives under `ABSPATH`. No rewrite endpoint exists.

**Compiled-file registry** — option `beplus_scss_compiled` (`array`, entries `"<pairId>:<relative_path>"` from `css_dir`, e.g. `0:main.css`, `1:modules/card.css`):
- Maintained by the `Plugin` glue in `compileEntries`: after writing a CSS file, its pair-scoped relative path is added to the registry; stale entries whose file no longer exists are pruned. The map file `.css.map` is not registered (only `.css`).
- Consumed by `Enqueue::styles()` as `$registeredFiles`. This is the single source of truth for "what did the plugin compile" used by both auto- and manual-mode enqueueing. Pair `0` also reads legacy unprefixed entries.

## 7. Hooks / Filter API (extensibility)

Every filter has a sensible default, applied in the `Plugin` glue (never inside a pure layer):

| Filter | Signature / default |
|---|---|
| `beplus_scss/compiler` | `$compiler = apply_filters( 'beplus_scss/compiler', new ScssPhpCompiler() )` — must return a `CompilerInterface` |
| `beplus_scss/import_paths` | `$paths = apply_filters( 'beplus_scss/import_paths', [ $scss_dir ] )` — array of absolute directories |
| `beplus_scss/exclude` | `$exclude = apply_filters( 'beplus_scss/exclude', false, $entry, $relativePath, $pairId )` — `true` skips the entry |
| `beplus_scss/write_path` | `$path = apply_filters( 'beplus_scss/write_path', $defaultPath, $entry, $scss_dir, $css_dir, $pairId )` — override destination |
| `beplus_scss/enqueue` | `$styles = apply_filters( 'beplus_scss/enqueue', $styles )` — reshape the merged `Style[]` list (applied once over all pairs) |
| `beplus_scss/error` | `apply_filters( 'beplus_scss/error', $wpError, $pairId )` — notify developers of a compile failure |

Trailing arguments preserve backward compatibility with existing callbacks.

## 8. Error Handling

- Compile error at runtime (auto mode): **keep the previous CSS intact** (guaranteed by atomic writes), catch the exception in the glue, store `beplus_scss_last_error`, never break the page.
- Option `beplus_scss_last_error` = array `{ time: int timestamp, entry: string, message: string }`, where `entry` is `"<pairId>:<relative_path>"`. Cleared on the next successful compile of that entry (or any successful Compile-now run) and on a successful settings save.
- The settings page shows a dismissible admin notice when `beplus_scss_last_error` is set (dismiss clears the option).
- Path errors: blocked at Save; if `css_dir` is deleted mid-flight → re-detect and show an admin notice.

## 9. Bootstrapping & Code Standards

**Main plugin file**: `beplus-scss-compiler.php` at the repository root, with this exact header:

```
Plugin Name: Beplus SCSS Compiler
Description: Compiles SCSS to CSS. Declare an SCSS source directory and a CSS destination directory in the admin; the plugin recompiles on change (auto) or on demand (manual), and can enqueue the compiled CSS.
Version: 1.0.0
Requires at least: 6.0
Requires PHP: 7.4
Author: Beplus
Author URI: https://profiles.wordpress.org/bearsthemes/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: beplus-scss-compiler
Domain Path: /languages
```

- Bootstrap guard: if `vendor/autoload.php` is missing → admin notice ("run `composer install` in the plugin directory") + `return` — never crash.
- The main file instantiates `Beplus\ScssCompiler\Plugin` and calls `Plugin::register()`, which hooks everything: menu, settings, `wp_enqueue_scripts`, `admin_post`, activation, deactivation, and the `beplus_scss/*` glue filters.
- Activation: store `beplus_scss_version` (plugin version). Deactivation: no cleanup. (No rewrite rules exist, so nothing to flush.)
- `uninstall.php`: deletes exactly `beplus_scss_settings`, `beplus_scss_fingerprints`, `beplus_scss_compiled`, `beplus_scss_last_error`, `beplus_scss_version`.
- PSR-4 autoload: namespace `Beplus\ScssCompiler\`, directory `src/`.
- Textdomain `beplus-scss-compiler`, pot at `languages/beplus-scss-compiler.pot`, `readme.txt` present (public-ready).
- WP Coding Standards (phpcs) wired into pre-commit; PHPStan at the highest level; PHP 7.4 compatibility enforced (phpcs `PHPCompatibility`, `testVersion 7.4-`).
- i18n: every user-facing string wrapped with textdomain `beplus-scss-compiler`; **no hardcoded English months/URLs** in output.
- Security: escape all output, sanitize all input, nonces + capability checks in every admin handler.

## 10. Release & Tooling

- `scripts/build-package.mjs` builds the distributable zip:
  1. `composer install --no-dev --optimize-autoloader` into a fresh build tree.
  2. Copy the publishable files (main plugin file, `src/`, `vendor/`, `languages/`, `readme.txt`, `uninstall.php`, `composer.json`, `composer.lock`) into `build/beplus-scss-compiler/`.
  3. `archiver` (`zlib` level 9) packages `build/beplus-scss-compiler/` into `build/beplus-scss-compiler-<version>.zip` with the plugin folder as the archive root; `<version>` comes from `package.json`, and the build prints the final zip size.
  - Excluded: `tests/`, `.github/`, `.codegraph/`, `.opencode/`, `.husky/`, `Plugin.md`, `AGENTS.md`, Composer dev tooling configs, npm/Node files.
  - npm script `build:package` wraps the Node script; `vendor/` stays gitignored and is resolved at build time.
- Repo asset build: `npm run build` compiles frontend assets (JS/CSS) — a no-op placeholder today; it never touches the release zip. Packaging is a separate step: `npm run build:package`.
- i18n pot: `composer i18n:pot` → `wp i18n make-pot . languages/beplus-scss-compiler.pot`.
- **Tests**:
  - Unit (`composer test` → `phpunit --testsuite unit`): pure layers — `Scanner`, `Detector`, `Enqueue`, `Value\CompileConfig`/`CompiledResult`, `Writer::mirrorPath` (pure path mapping), and the `mirroredFileName` path logic of `ScssPhpCompiler` (no FS).
  - Integration (`composer test:integration` → `phpunit --testsuite integration`, run inside `wp-env`): settings save/validate, compile-now, registry maintenance, enqueue handles/URLs (gated on `enqueue=true`), the six filters, plus the FS-backed behaviour of `Writer` (real writes in temp dirs) and `ScssPhpCompiler` (real scssphp on `tests/fixtures/`) — these need WordPress because they now go through the `Filesystem`/`WP_Filesystem` helper.

---

## Appendix A — Conventions & Naming (single source of truth)

| What | Value |
|---|---|
| Main plugin file | `beplus-scss-compiler.php` (repo root) |
| Namespace root | `Beplus\ScssCompiler\` |
| Bootstrap class | `Beplus\ScssCompiler\Plugin` (`register()` wire-up) |
| Settings class | `Beplus\ScssCompiler\Settings\SettingsPage` |
| Options register | `beplus_scss_settings` (array, keys per Section 3) |
| Fingerprints option | `beplus_scss_fingerprints` |
| Compiled registry | `beplus_scss_compiled` (array of `"<pairId>:<relative_path>"` from `css_dir`) |
| Last error option | `beplus_scss_last_error` |
| Version option | `beplus_scss_version` |
| uninstall.php deletes | exactly the 5 options above |
| Menu | Submenu of Settings (`options-general.php`), slug `beplus-scss-compiler`, `manage_options` |
| Nonce (compile-now) | `beplus_scss_compile_nonce` |
| Admin-post action | `beplus_scss_compile` |
| Handle prefix | `beplus-scss-<pairId>-` (slugified relative path; pair id = index in `pairs`) |
| Filters | `beplus_scss/compiler`, `/import_paths`, `/exclude`, `/write_path`, `/enqueue`, `/error` (see §7 for signatures) |
| Defaults | `pairs=[]`, `auto`, `source_map=false`, `minify=false`, `enqueue=false` |
| Textdomain / domain path | `beplus-scss-compiler` / `/languages` |
| POT | `languages/beplus-scss-compiler.pot` |
| WP min / PHP min | 6.0 / 7.4 |
| Compiler backend | `scssphp/scssphp:^1.11` |
| Release zip | `build/beplus-scss-compiler-<version>.zip` via `scripts/build-package.mjs` (`npm run build:package`, uses `archiver`; version read from `package.json`) |
| Version | `1.0.0` |
| Author | Beplus — `https://profiles.wordpress.org/bearsthemes/` |