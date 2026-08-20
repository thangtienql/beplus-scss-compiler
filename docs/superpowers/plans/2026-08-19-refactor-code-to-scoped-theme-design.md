# Refactor Code to the Theme-Scoped Delivery Design

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the code match the updated `Plugin.md` contract — theme-relative settings paths resolved against the active theme, no rewrite endpoint, opt-in enqueue, and a compiled-file registry.

**Architecture:** The plugin keeps its layered architecture (`SettingsPage` / `Scanner` / `Detector` / `Compiler` / `Writer` / `Enqueue` + `Plugin` glue). The refactor removes the delivery endpoint and `web_root` plumbing from the glue, stores *relative* settings paths resolved against `get_stylesheet_directory()`, gates enqueueing behind the new `enqueue` setting (default `false`), and adds the `beplus_scss_compiled` registry as the source of truth for "what did the plugin compile".

**Tech Stack:** PHP 7.4, WordPress (Settings API, `wp_enqueue_style`, filters), scssphp `^1.11`, phpunit (unit + integration in wp-env), phpcs WPCS, PHPStan max.

**Spec:** `Plugin.md` (working tree) — §3 Settings, §6 Enqueue + Delivery, §8 Errors, §9 Bootstrap, §10 Tests, Appendix A.

## Global Constraints

- PHP >= 7.4 (no `str_starts_with`, no arrow-function shorthand beyond what 7.4 supports, typed properties OK).
- Only `SettingsPage` and `Plugin` may call `wp_*()`; pure layers stay WordPress-agnostic.
- WPCS (`composer lint`), PHPStan max (`composer analyse`), unit tests (`composer test`) must stay green.
- All new user-facing strings wrapped in `__()`/`esc_html_e()` with textdomain `beplus-scss`.
- Paths stored in `beplus_scss_settings` are **relative to the active theme**, normalized without leading/trailing `/`; `..` segments are rejected.
- No rewrite rules, no query var, no `readfile()` serving, no `flush_rewrite_rules()` anywhere.
- `enqueue` default is `false`; registry option is `beplus_scss_compiled`; `uninstall.php` deletes exactly 5 options.
- No code comments unless they explain *why*.

---

### Task 1: `SettingsPage` — relative theme paths, drop `web_root`, add `enqueue`

**Files:**
- Modify: `src/Settings/SettingsPage.php`
- Test: `tests/integration/SettingsPageTest.php`

**Interfaces:**
- Produces: `SettingsPage::absPath( string $relative ): string` (static, resolves a stored relative path against `get_stylesheet_directory()`); `ScssSettings` phpstan-type becomes `array{scss_dir:string, css_dir:string, compile_mode:'auto'|'manual', source_map:bool, minify:bool, enqueue:bool}`.

- [ ] **Step 1: Write the failing integration tests** — replace `web_root` with `enqueue`, drive validation with theme-relative paths, assert `..` is rejected.

```php
// tests/integration/SettingsPageTest.php — setUp
protected function setUp(): void {
	parent::setUp();
	$this->themeDir = get_stylesheet_directory();
	$this->rel      = 'beplus-settings-' . uniqid();
	mkdir( $this->themeDir . '/' . $this->rel . '/scss', 0777, true );
	mkdir( $this->themeDir . '/' . $this->rel . '/css', 0777, true );
	file_put_contents( $this->themeDir . '/' . $this->rel . '/scss/main.scss', '$c: #fff; .x { color: $c; }' );
}

protected function tearDown(): void {
	self::rrmdir( $this->themeDir . '/' . $this->rel );
	delete_option( SettingsPage::OPTION_NAME );
	parent::tearDown();
}

public function test_sanitize_stores_relative_paths_and_toggles_enqueue(): void {
	$page = new SettingsPage();
	$out  = $page->sanitize(
		[
			'scss_dir'     => $this->rel . '/scss',
			'css_dir'      => $this->rel . '/css',
			'compile_mode' => 'auto',
			'source_map'   => '1',
			'minify'       => '1',
			'enqueue'      => '1',
		]
	);

	self::assertSame( $this->rel . '/scss', $out['scss_dir'] );
	self::assertSame( $this->rel . '/css', $out['css_dir'] );
	self::assertTrue( $out['enqueue'] );
	self::assertArrayNotHasKey( 'web_root', $out );
}

public function test_sanitize_rejects_dotdot_segments(): void {
	update_option(
		SettingsPage::OPTION_NAME,
		[ 'scss_dir' => $this->rel . '/scss', 'css_dir' => $this->rel . '/css', 'compile_mode' => 'auto', 'source_map' => false, 'minify' => false, 'enqueue' => false ]
	);
	$page = new SettingsPage();
	$out  = $page->sanitize( [ 'scss_dir' => '../outside', 'css_dir' => $this->rel . '/css' ] );

	self::assertSame( $this->rel . '/scss', $out['scss_dir'] ); // previous kept
}
```

- [ ] **Step 2: Run the integration suite (wp-env) to verify they fail.**

Run: `composer test:integration` (needs `npm run wp-env start`)
Expected: FAIL — `web_root` in the old settings shape, relative paths fail `is_dir()` on the raw relative string.

- [ ] **Step 3: Implement the `SettingsPage` changes.**

```php
// phpstan-type becomes:
// @phpstan-type ScssSettings array{scss_dir:string, css_dir:string, compile_mode:'auto'|'manual', source_map:bool, minify:bool, enqueue:bool}

private static function defaults(): array {
	return [
		'scss_dir'     => '',
		'css_dir'      => '',
		'compile_mode' => 'auto',
		'source_map'   => false,
		'minify'       => false,
		'enqueue'      => false,
	];
}

public static function currentSettings(): array {
	$stored = get_option( self::OPTION_NAME, [] );
	$stored = is_array( $stored ) ? $stored : [];

	return [
		'scss_dir'     => isset( $stored['scss_dir'] ) && is_string( $stored['scss_dir'] ) ? $stored['scss_dir'] : '',
		'css_dir'      => isset( $stored['css_dir'] ) && is_string( $stored['css_dir'] ) ? $stored['css_dir'] : '',
		'compile_mode' => 'manual' === ( $stored['compile_mode'] ?? '' ) ? 'manual' : 'auto',
		'source_map'   => (bool) ( $stored['source_map'] ?? false ),
		'minify'       => (bool) ( $stored['minify'] ?? false ),
		'enqueue'      => (bool) ( $stored['enqueue'] ?? false ),
	];
}

/** Resolve a stored theme-relative path against the active theme directory. */
public static function absPath( string $relative ): string {
	return get_stylesheet_directory() . '/' . ltrim( $relative, '/' );
}
```

`sanitize()` per field (scss shown; css identical except validator):

```php
if ( isset( $input['scss_dir'] ) && is_string( $input['scss_dir'] ) ) {
	$rel = $this->normalizePath( $input['scss_dir'] );
	if ( '' === $rel ) {
		$output['scss_dir'] = '';
	} elseif ( $this->hasDotDotSegment( $rel ) ) {
		add_settings_error( self::OPTION_NAME, 'bad_scss_dir', __( 'SCSS directory must not contain "..".', 'beplus-scss' ) );
	} else {
		$validated = $this->validateScssDir( self::absPath( $rel ) );
		if ( true === $validated ) {
			$output['scss_dir'] = $rel;
		} else {
			add_settings_error( self::OPTION_NAME, 'bad_scss_dir', $validated );
		}
	}
}

private function normalizePath( string $path ): string {
	$path = trim( $path );
	if ( '' === $path ) {
		return '';
	}
	$path = wp_normalize_path( $path );
	$path = trim( $path, '/' );

	return $path;
}

private function hasDotDotSegment( string $path ): bool {
	foreach ( explode( '/', $path ) as $segment ) {
		if ( '..' === $segment ) {
			return true;
		}
	}

	return false;
}
```

Add `$output['enqueue'] = ! empty( $input['enqueue'] );` at the end of `sanitize()`; delete the `$output['web_root'] = ...` line. `validateScssDir()`/`validateCssDir()` keep their current bodies — they now receive the resolved absolute path.

- [ ] **Step 4: Add the enqueue checkbox row in `renderFields()`.**

```php
<tr>
	<th scope="row"><?php esc_html_e( 'Enqueue compiled CSS', 'beplus-scss' ); ?></th>
	<td><label><input type="checkbox" name="beplus_scss_settings[enqueue]" value="1" <?php checked( true, (bool) $values['enqueue'] ); ?> /> <?php esc_html_e( 'Load compiled styles on the frontend', 'beplus-scss' ); ?></label></td>
</tr>
```

- [ ] **Step 5: Run integration suite.** Expected: PASS.
- [ ] **Step 6: Commit** `src/Settings/SettingsPage.php` + `tests/integration/SettingsPageTest.php`.

---

### Task 2: `Enqueue` — registered-file filter, no `$webRoot`

**Files:**
- Modify: `src/Enqueue.php`
- Test: `tests/unit/EnqueueTest.php`

**Interfaces:**
- Consumes: `Value\Style` (unchanged).
- Produces: `Enqueue::styles( string $cssDir, string $baseUrl, array $registeredFiles ): array` — only `.css` files whose relative path (from `$cssDir`) is in `$registeredFiles`, URL always `rtrim( $baseUrl, '/' ) . '/' . $relPath`.

- [ ] **Step 1: Write the failing unit tests.**

```php
// tests/unit/EnqueueTest.php
public function test_styles_lists_only_registered_files(): void {
	$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ 'main.css', 'modules/card.css' ] );

	$handles = array_map(
		static function ( Style $style ): string {
			return $style->getHandle();
		},
		$styles
	);
	sort( $handles );

	self::assertSame( [ 'beplus-scss-main', 'beplus-scss-modules-card' ], $handles );
}

public function test_styles_skips_css_not_in_registry(): void {
	$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ 'main.css' ] );

	self::assertSame( [ 'beplus-scss-main' ], $this->handles( $styles ) );
}

public function test_url_is_plain_relative_path(): void {
	$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ 'main.css', 'modules/card.css' ] );

	$card = $this->byHandle( $styles, 'beplus-scss-modules-card' );
	self::assertSame( 'https://example.test/assets/modules/card.css', $card->getUrl() );
}
```

(Add a `handles()` helper next to the existing `byHandle()`; keep the map-skip assertion from the old first test via `registeredFiles = [ 'main.css', 'modules/card.css' ]`.)

- [ ] **Step 2: Run** `composer test`. Expected: FAIL (wrong arity / `web_root` removed).
- [ ] **Step 3: Implement.**

```php
public static function styles( string $cssDir, string $baseUrl, array $registeredFiles ): array {
	$styles = [];
	$files  = self::splFileInfoIterator( $cssDir );

	foreach ( $files as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$relPath = ltrim( str_replace( rtrim( $cssDir, '/' ) . '/', '', $file->getPathname() ), '/' );
		if ( self::isHiddenSegment( $relPath ) ) {
			continue;
		}
		if ( 'css' !== $file->getExtension() ) {
			continue;
		}
		if ( ! in_array( $relPath, $registeredFiles, true ) ) {
			continue;
		}
		$handle   = 'beplus-scss-' . str_replace( [ '/', '.' ], [ '-', '' ], substr( $relPath, 0, -4 ) );
		$url      = rtrim( $baseUrl, '/' ) . '/' . $relPath;
		$styles[] = new Style( $handle, $url, (int) $file->getMTime() );
	}

	usort(
		$styles,
		static function ( Style $a, Style $b ): int {
			return strcmp( $a->getHandle(), $b->getHandle() );
		}
	);

	return $styles;
}
```

- [ ] **Step 4: Run** `composer test`. Expected: PASS.
- [ ] **Step 5: Commit** `src/Enqueue.php` + `tests/unit/EnqueueTest.php`.

---

### Task 3: `Plugin` glue — remove endpoint, resolve theme paths, registry, enqueue gate

**Files:**
- Modify: `src/Plugin.php`
- Test: `tests/integration/PluginGlueTest.php`

**Interfaces:**
- Consumes: `SettingsPage::absPath()`, `Enqueue::styles( string, string, array )`.
- Produces: `Plugin::COMPILED_OPTION = 'beplus_scss_compiled'`; `compileEntries` maintains the registry; `onEnqueueScripts` gates enqueueing on `settings['enqueue']`.

- [ ] **Step 1: Write failing integration tests** (replace the rewrite-rule test).

```php
// tests/integration/PluginGlueTest.php
public function test_activation_stores_version(): void {
	$plugin = new Plugin();
	$plugin->activate();

	self::assertSame( Plugin::VERSION, get_option( Plugin::VERSION_OPTION ) );
}

public function test_compile_now_action_exists(): void {
	$plugin = new Plugin();
	$plugin->register();

	self::assertGreaterThan( 0, has_action( 'admin_post_' . SettingsPage::COMPILE_ACTION ) );
}

public function test_compile_registers_compiled_files(): void {
	$rel = 'beplus-glue-' . uniqid();
	$theme = get_stylesheet_directory();
	mkdir( $theme . '/' . $rel . '/scss', 0777, true );
	mkdir( $theme . '/' . $rel . '/css', 0777, true );
	file_put_contents( $theme . '/' . $rel . '/scss/main.scss', '$c: #fff; .x { color: $c; }' );

	update_option(
		SettingsPage::OPTION_NAME,
		[ 'scss_dir' => $rel . '/scss', 'css_dir' => $rel . '/css', 'compile_mode' => 'manual', 'source_map' => false, 'minify' => false, 'enqueue' => false ]
	);

	$plugin = new Plugin();
	$ref    = new \ReflectionMethod( $plugin, 'compileAllEntries' );
	$ref->setAccessible( true );
	$settings = SettingsPage::currentSettings();
	$ref->invoke( $plugin, $settings, SettingsPage::absPath( $settings['scss_dir'] ), SettingsPage::absPath( $settings['css_dir'] ) );

	self::assertFileExists( $theme . '/' . $rel . '/css/main.css' );
	self::assertSame( [ 'main.css' ], get_option( Plugin::COMPILED_OPTION ) );

	self::rrmdir( $theme . '/' . $rel );
}

public function test_enqueue_gated_on_setting(): void {
	if ( is_admin() ) {
		$this->markTestSkipped( 'Frontend context required.' );
	}
	$rel = 'beplus-glue-' . uniqid();
	$theme = get_stylesheet_directory();
	mkdir( $theme . '/' . $rel . '/scss', 0777, true );
	mkdir( $theme . '/' . $rel . '/css', 0777, true );
	file_put_contents( $theme . '/' . $rel . '/scss/main.scss', '$c: #fff; .x { color: $c; }' );

	update_option(
		SettingsPage::OPTION_NAME,
		[ 'scss_dir' => $rel . '/scss', 'css_dir' => $rel . '/css', 'compile_mode' => 'manual', 'source_map' => false, 'minify' => false, 'enqueue' => true ]
	);

	$plugin = new Plugin();
	$ref    = new \ReflectionMethod( $plugin, 'compileAllEntries' );
	$ref->setAccessible( true );
	$settings = SettingsPage::currentSettings();
	$ref->invoke( $plugin, $settings, SettingsPage::absPath( $settings['scss_dir'] ), SettingsPage::absPath( $settings['css_dir'] ) );

	$plugin->onEnqueueScripts();

	self::assertArrayHasKey( 'beplus-scss-main', wp_styles()->registered );

	self::rrmdir( $theme . '/' . $rel );
}
```

(Keep the existing `rrmdir` helper or add it to the test class.)

- [ ] **Step 2: Run** `composer test:integration` (wp-env). Expected: FAIL.
- [ ] **Step 3: Implement `Plugin` changes.**

```php
const COMPILED_OPTION = 'beplus_scss_compiled';
```

`register()` — drop the `init`, `template_redirect`, and `update_option` hooks and the deactivation hook:

```php
public function register(): void {
	$this->settingsPage->register();

	add_action( 'wp_enqueue_scripts', [ $this, 'onEnqueueScripts' ] );
	add_action( 'admin_post_' . SettingsPage::COMPILE_ACTION, [ $this, 'handleCompileNow' ] );

	register_activation_hook( BE_PLUS_SCSS_COMPILER_MAIN_FILE, [ $this, 'activate' ] );
}

public function activate(): void {
	update_option( self::VERSION_OPTION, self::VERSION );
}
```

Delete `deactivate()`, `flushRewriteRules()`, `registerEndpoint()`, `serveFile()`, `isServeable()`.

`onEnqueueScripts()`:

```php
public function onEnqueueScripts(): void {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}
	$settings = SettingsPage::currentSettings();
	if ( '' === $settings['scss_dir'] || '' === $settings['css_dir'] ) {
		return;
	}
	$scssDir = SettingsPage::absPath( $settings['scss_dir'] );
	$cssDir  = SettingsPage::absPath( $settings['css_dir'] );
	if ( ! is_dir( $scssDir ) || ! is_dir( $cssDir ) ) {
		return;
	}

	if ( 'auto' === $settings['compile_mode'] && ! self::$autoCompiled ) {
		self::$autoCompiled = true;
		$this->compileChangedEntries( $settings, $scssDir, $cssDir );
	}

	if ( ! $settings['enqueue'] ) {
		return;
	}

	/** @var Style[] $styles */
	$styles = $this->buildStyles( $cssDir );
	$styles = apply_filters( 'beplus_scss/enqueue', $styles );
	if ( ! is_array( $styles ) ) {
		return;
	}
	foreach ( $styles as $style ) {
		if ( $style instanceof Style ) {
			wp_enqueue_style( $style->getHandle(), $style->getUrl(), [], (string) $style->getVersion() );
		}
	}
}
```

`buildStyles()`:

```php
/**
 * @return Style[]
 */
private function buildStyles( string $cssDir ): array {
	$baseUrl = home_url( '/' ) . ltrim( substr( $cssDir, strlen( ABSPATH ) ), '/' );

	return Enqueue::styles( $cssDir, $baseUrl, $this->compiledFiles() );
}
```

`handleCompileNow()` — resolve paths and guard empty settings:

```php
public function handleCompileNow(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'beplus-scss' ) );
	}
	check_admin_referer( SettingsPage::NONCE_FIELD );

	$settings = SettingsPage::currentSettings();
	if ( '' === $settings['scss_dir'] || '' === $settings['css_dir'] ) {
		$msg = 'error';
	} else {
		$scssDir = SettingsPage::absPath( $settings['scss_dir'] );
		$cssDir  = SettingsPage::absPath( $settings['css_dir'] );
		$msg     = $this->compileAllEntries( $settings, $scssDir, $cssDir ) ? 'compiled' : 'error';
	}

	wp_safe_redirect( add_query_arg( 'msg', $msg, admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) );
	exit;
}
```

Compile pipeline — resolve dirs once, pass them down, maintain the registry:

```php
/**
 * @param ScssSettings $settings
 */
private function compileChangedEntries( array $settings, string $scssDir, string $cssDir ): void {
	$entries = Scanner::scan( $scssDir );
	/** @var array<array-key, mixed> $storedOption */
	$storedOption = get_option( self::FINGERPRINTS_OPTION, [] );
	$stored       = array_filter( $storedOption, 'is_string', ARRAY_FILTER_USE_BOTH );
	/** @var array<string, string> $stored */
	$changed = Detector::changedEntries( $entries, $stored, $scssDir );
	if ( [] === $changed ) {
		return;
	}
	$this->compileEntries( $settings, $entries, $changed, $scssDir, $cssDir );
}

/**
 * @param ScssSettings $settings
 */
private function compileAllEntries( array $settings, string $scssDir, string $cssDir ): bool {
	$entries = Scanner::scan( $scssDir );
	$this->compileEntries( $settings, $entries, $entries, $scssDir, $cssDir );

	return ! $this->hasCompileError();
}

/**
 * @param ScssSettings $settings
 * @param string[]                   $entries
 * @param string[]                   $toCompile
 */
private function compileEntries( array $settings, array $entries, array $toCompile, string $scssDir, string $cssDir ): void {
	/** @var CompilerInterface $compiler */
	$compiler = apply_filters( 'beplus_scss/compiler', new ScssPhpCompiler() );
	/** @var array<mixed> $rawImportPaths */
	$rawImportPaths = apply_filters( 'beplus_scss/import_paths', [ $scssDir ] );
	$importPaths    = array_values( array_filter( $rawImportPaths, 'is_string' ) );
	if ( [] === $importPaths ) {
		$importPaths = [ $scssDir ];
	}
	$config       = new CompileConfig(
		$importPaths,
		! empty( $settings['minify'] ),
		! empty( $settings['source_map'] )
	);
	$fingerprints = get_option( self::FINGERPRINTS_OPTION, [] );
	$fingerprints = is_array( $fingerprints ) ? $fingerprints : [];

	foreach ( $entries as $entry ) {
		$relPath = ltrim( str_replace( rtrim( $scssDir, '/' ) . '/', '', $entry ), '/' );

		if ( false !== apply_filters( 'beplus_scss/exclude', false, $entry, $relPath ) ) {
			continue;
		}

		if ( in_array( $entry, $toCompile, true ) ) {
			try {
				/** @var CompiledResult $result */
				$result = $compiler->compile( $entry, $config );
				$dest   = apply_filters(
					'beplus_scss/write_path',
					Writer::mirrorPath( $entry, $scssDir, $cssDir ),
					$entry,
					$scssDir,
					$cssDir
				);
				$dest   = is_string( $dest ) && '' !== $dest ? $dest : Writer::mirrorPath( $entry, $scssDir, $cssDir );
				Writer::write( $result->getCss(), $cssDir . '/' . $dest );
				if ( null !== $result->getMap() ) {
					Writer::writeMap( $result->getMap(), $cssDir . '/' . $dest );
				}
				$this->registerCompiledPath( ltrim( $dest, '/' ) );
				$this->clearError( $relPath );
			} catch ( \Throwable $e ) {
				$this->setError( $relPath, $e->getMessage() );
				$wpError = new \WP_Error( 'beplus_scss_compile', $e->getMessage(), [ 'entry' => $relPath ] );
				apply_filters( 'beplus_scss/error', $wpError );
			}
		}
		$fingerprints[ $relPath ] = Scanner::fingerprint( $scssDir );
	}
	update_option( self::FINGERPRINTS_OPTION, $fingerprints );
	$this->pruneCompiledPaths( $cssDir );
}
```

Registry helpers:

```php
/**
 * @return string[]
 */
private function compiledFiles(): array {
	$stored = get_option( self::COMPILED_OPTION, [] );
	if ( ! is_array( $stored ) ) {
		return [];
	}

	return array_values( array_filter( $stored, 'is_string' ) );
}

private function registerCompiledPath( string $relPath ): void {
	$compiled = $this->compiledFiles();
	if ( ! in_array( $relPath, $compiled, true ) ) {
		$compiled[] = $relPath;
		update_option( self::COMPILED_OPTION, array_values( $compiled ) );
	}
}

private function pruneCompiledPaths( string $cssDir ): void {
	$compiled = $this->compiledFiles();
	$kept     = array_values(
		array_filter(
			$compiled,
			static function ( string $relPath ) use ( $cssDir ): bool {
				return is_file( $cssDir . '/' . $relPath );
			}
		)
	);
	if ( $kept !== $compiled ) {
		update_option( self::COMPILED_OPTION, $kept );
	}
}
```

- [ ] **Step 4: Run** `composer test` (unit still green) + `composer lint` + `composer analyse`.
- [ ] **Step 5: Run** `composer test:integration` (wp-env). Expected: PASS.
- [ ] **Step 6: Commit** `src/Plugin.php` + `tests/integration/PluginGlueTest.php`.

---

### Task 4: `uninstall.php`, main-file description, `readme.txt`, `Plugin.md` §9, pot

**Files:**
- Modify: `uninstall.php`, `beplus-scss-compiler.php`, `readme.txt`, `Plugin.md`, `languages/beplus-scss.pot`

**Interfaces:** None.

- [ ] **Step 1: `uninstall.php`** — delete `beplus_scss_compiled` (5 options total).
- [ ] **Step 2: Main file description + readme** — reflect opt-in enqueue:
  - `beplus-scss-compiler.php` header Description: `...; the plugin recompiles on change (auto) or on demand (manual), and can enqueue the compiled CSS.`
  - `readme.txt` line 11 and line 17: "can enqueue" wording.
- [ ] **Step 3: `Plugin.md` §9** — align the pinned header Description with the opt-in wording (same string as the main file).
- [ ] **Step 4: pot** — add the two new strings (`Enqueue compiled CSS`, `Load compiled styles on the frontend`) and the `..` rejection message to `languages/beplus-scss.pot` (regenerate with `composer i18n:pot` when `wp` CLI is available; otherwise insert matching entries manually).
- [ ] **Step 5: Commit.**

---

### Task 5: Verification + worklog

**Files:**
- Create: `docs/worklogs/2026-08-19.md` (append the refactor entry)

- [ ] **Step 1: Run** `composer lint`, `composer analyse`, `composer test`. All green.
- [ ] **Step 2: Run** `composer test:integration` in wp-env if available; otherwise note it as pending.
- [ ] **Step 3: Review** the diff against `Plugin.md` (§3/§6/§8/§9/§10/Appendix A) — every pinned name matches.
- [ ] **Step 4: Append** the refactor summary to today's worklog.
- [ ] **Step 5: Report** the diff to the human (no commit/push — human commits).
