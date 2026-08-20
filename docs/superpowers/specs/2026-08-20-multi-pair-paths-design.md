# Multi-Pair SCSS → CSS Paths — Design Spec

## 1. Overview

Today the plugin stores a single `scss_dir` / `css_dir` pair inside the
`beplus_scss_settings` option. This spec upgrades the plugin to support **multiple
pairs**: each row declares its own SCSS source directory and CSS destination
directory. The four shared flags (`compile_mode`, `source_map`, `minify`,
`enqueue`) stay plugin-global — they are not per-row.

Design decisions (approved):
- Flags shared, only paths become multiple.
- Enqueue delivers CSS from **all** valid pairs.
- Storage keeps the old `scss_dir`/`css_dir` keys and adds `pairs` (backward
  compatible; old values migrate to `pairs[0]`).
- Add/Remove rows with minimal inline JS.
- Blank rows are skipped on save.
- Pair identity = the index inside the `pairs` array.
- `beplus_scss/enqueue` is applied once over the merged style list from all pairs.
- `/exclude`, `/write_path`, `/error` filters gain a trailing `$pairId` argument.

## 2. Data structure

`beplus_scss_settings` (single option array):

```php
[
    'pairs'       => [
        [ 'scss_dir' => 'assets/scss', 'css_dir' => 'assets/css' ],
        [ 'scss_dir' => 'blocks/scss', 'css_dir' => 'blocks/css' ],
    ],
    'compile_mode' => 'auto',
    'source_map'   => false,
    'minify'       => false,
    'enqueue'      => false,
]
```

- `pairs`: list of `{scss_dir, css_dir}`. Both keys are theme-relative strings,
  normalized without leading/trailing `/`.
- Backward compatibility / migration: when `pairs` is missing or empty but the
  legacy `scss_dir`/`css_dir` keys exist, `pairs` is derived as
  `[ [ 'scss_dir' => legacy, 'css_dir' => legacy ] ]`. `currentSettings()`
  always exposes `pairs`; the legacy keys are still written on save (mirroring
  the first pair) so older consumers keep working.
- `currentSettings()` filters out fully-blank rows before returning.

## 3. Sanitize & validation (SettingsPage)

`sanitize($input)`:
- Reads `$input['pairs']` as a list of `{scss_dir, css_dir}`.
- For each row, validates both paths exactly as today (reject `..` segments,
  `is_dir`, `is_readable`, `is_writable`, at least one non-partial `.scss`).
- A row where **both** paths are blank is skipped (not stored).
- A row with a validation error keeps its **previous** value (per row) and
  registers `add_settings_error` with codes `bad_scss_dir_<i>` /
  `bad_css_dir_<i>` so the toast names the failing row.
- If every row is blank, `pairs = []`.
- The legacy `scss_dir` / `css_dir` keys mirror the first stored pair (or are
  emptied when `pairs` is empty).

Pure helper (no WP calls) so it is unit-testable without wp-env:

```
sanitizePairs(array $inputPairs, array $previousPairs): array
    returns [ 'validated' => list<pair>, 'errors' => list<{index, code, message}> ]
```

## 4. Pipeline (Plugin) — pair-namespaced

- Pair identity = index in `pairs` (`0`, `1`, …).
- `beplus_scss_fingerprints`: keys become `"<pairId>:<relPath>"`. Pair `0` also
  reads legacy unprefixed keys.
- `beplus_scss_compiled`: entries become `"<pairId>:<relPath>"`. Pair `0` also
  reads legacy unprefixed entries.
- `beplus_scss_last_error.entry`: `"<pairId>:<relPath>"` (cleared per pair+entry).
- Enqueue handle: `beplus-scss-<pairId>-<slug>` (e.g. `beplus-scss-0-main`,
  `beplus-scss-1-main`).

`onEnqueueScripts()`:
- Loop over every valid pair (dirs exist). For each:
  - `auto` mode → `compileChangedEntries(...)` once per request (guarded by the
    existing static `$autoCompiled` flag, shared across pairs).
  - `enqueue` → collect `Style[]` for the pair into `$allStyles`.
- Apply `beplus_scss/enqueue` **once** over the merged `$allStyles`, then
  `wp_enqueue_style` each.

`handleCompileNow()`:
- Loop over every valid pair and compile all entries; if any pair fails,
  `msg=error`, else `msg=compiled`.

`compileEntries()`:
- Gains a `$pairId` parameter; prefixes fingerprints / compiled registry /
  error entry with `"$pairId:"`.

## 5. Enqueue (pure layer)

`Enqueue::styles(string $cssDir, string $baseUrl, array $registeredFiles, int $pairId)`:
- Handle = `beplus-scss-<pairId>-<slug>`.
- `$registeredFiles` entries are `"<pairId>:<relPath>"`; `Enqueue` matches by
  prefix. For `pairId === 0`, unprefixed entries (legacy) are also accepted.
- URL/version logic unchanged.

## 6. UI

`renderFields()`:
- Renders one row per pair: two text inputs
  (`beplus_scss_settings[pairs][<i>][scss_dir]`,
  `beplus_scss_settings[pairs][<i>][css_dir]`), class `beplus-pair-row`.
- Renders one empty row by default when there are no pairs.
- "Add pair" button + per-row "Remove" button.
- Minimal inline JS: clones a row template (hidden `<script type="text/template">`
  or an invisible template row), renumbers `name` attributes.

## 7. Filters

| Filter | Signature change |
|---|---|
| `beplus_scss/compiler` | unchanged |
| `beplus_scss/import_paths` | unchanged |
| `beplus_scss/exclude` | `apply_filters( 'beplus_scss/exclude', false, $entry, $relPath, $pairId )` |
| `beplus_scss/write_path` | `apply_filters( 'beplus_scss/write_path', $default, $entry, $scssDir, $cssDir, $pairId )` |
| `beplus_scss/enqueue` | unchanged signature, called once over merged styles |
| `beplus_scss/error` | `apply_filters( 'beplus_scss/error', $wpError, $pairId )` |

Trailing arguments preserve backward compatibility with existing callbacks.

## 8. Tests

Unit (no wp-env):
- `EnqueueTest`: pair-id handle, prefix matching, pair-0 legacy acceptance.
- New pure `sanitizePairs` tests: valid, invalid keeps previous, blank skipped,
  normalize, `..` rejected.

Integration (wp-env):
- `SettingsPageTest`: multi-pair sanitize, migration from legacy, per-row error
  codes, render with multiple rows + Add/Remove JS.
- `PluginGlueTest`: two pairs compile + enqueue (distinct handles), namespaced
  fingerprints/compiled, `handleCompileNow` over all pairs.

## 9. Non-goals

- No per-pair flags (flags stay global).
- No version bump (plugin unreleased).
- No endpoint / rewrite changes.
