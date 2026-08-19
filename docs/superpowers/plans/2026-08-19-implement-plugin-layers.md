# Beplus SCSS Compiler — Implementation Plan (S1–S11)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the full WordPress plugin that compiles SCSS to CSS, per the pinned design contract.

**Architecture:** Six WordPress-agnostic layers (`Scanner`, `Detector`, `Compiler\*`, `Writer`, `Enqueue`, `Value\*`) + two WordPress-touching classes (`Settings\SettingsPage`, `Plugin` glue), wired through the `beplus_scss/*` filters.

**Tech Stack:** PHP >= 7.4 (runtime), PHP 8.5 (dev machine, PHPUnit 11), scssphp `scssphp/scssphp:^1.11`, WPCS/phpstan/phpunit dev tooling.

**Spec:** `Plugin.md` (design contract, zero-guessing). This plan argues from it verbatim.

## Global Constraints

- Namespace `Beplus\ScssCompiler\`, PSR-4 → `src/`. One class per file, StudlyCaps filename.
- ONLY `Settings\SettingsPage` and `Plugin` may call WordPress functions. Pure layers: no `wp_*()`, no globals.
- `web_root` computed at Save: `0 === strpos( $css_dir . '/', ABSPATH )`.
- Fingerprint = **one whole-directory md5** over `"relative_path:mtime:size\n"` for every `.scss` under `scss_dir` (partials included). Same value stored under every entry key; any file change recompiles all entries.
- Endpoint URL for `web_root === false`: `home_url( '/beplus-scss/' ) . rawurlencode( $relativePath )`; handler `rawurldecode()`s before `realpath()`.
- `CompiledResult::$fileName` = CSS output path relative to `css_dir` = `Writer::mirrorPath()` result.
- Filters (glue only, never in pure layers): `beplus_scss/compiler`, `/import_paths`, `/exclude`, `/write_path`, `/enqueue`, `/error`.
- Options: `beplus_scss_settings`, `beplus_scss_fingerprints`, `beplus_scss_last_error`, `beplus_scss_version`. uninstall deletes exactly those four.
- Handles: `beplus-scss-` + relative path with `.css` stripped, `/`→`-` (e.g. `beplus-scss-main`, `beplus-scss-modules-card`). Version = `filemtime( css_file )`.
- Atomic writes: temp `.<basename>.tmp.<uniqid>` in destination dir, then `rename()`.
- PHP 7.4 code: NO `str_contains`/`str_starts_with`/`str_ends_with`/spread-in-array-literal/enums/readonly. PHPStan level max green. WPCS tabs.
- Partials `_*.scss` never stand alone as entries. Hidden dirs (leading `.`) skipped by Scanner/Enqueue.
- Textdomain `beplus-scss`. Header of main file exact per Plugin.md §9.
- Agent never commits. Human runs `pre-push-checklist` before committing.

---

### Task 1: CompileConfig + CompiledResult + Style value objects

**Files:**
- Create: `src/Value/CompileConfig.php`, `src/Value/CompiledResult.php`, `src/Value/Style.php`
- Test: `tests/unit/Value/CompileConfigTest.php`, `tests/unit/Value/CompiledResultTest.php`, `tests/unit/Value/StyleTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `CompileConfig` (getImportPaths/getMinify/getSourceMap), `CompiledResult` (getCss/getMap/getFileName), `Style` (getHandle/getUrl/getVersion). Used by Tasks 4/6/7/8/9.

- [ ] **Step 1: Write the failing tests**

`tests/unit/Value/CompileConfigTest.php`:
```php
<?php

namespace Beplus\ScssCompiler\Tests\Unit\Value;

use Beplus\ScssCompiler\Value\CompileConfig;
use PHPUnit\Framework\TestCase;

final class CompileConfigTest extends TestCase {

	public function test_defaults(): void {
		$config = new CompileConfig();

		self::assertSame( [], $config->getImportPaths() );
		self::assertFalse( $config->getMinify() );
		self::assertFalse( $config->getSourceMap() );
	}

	public function test_explicit_values(): void {
		$config = new CompileConfig( [ '/scss' ], true, true );

		self::assertSame( [ '/scss' ], $config->getImportPaths() );
		self::assertTrue( $config->getMinify() );
		self::assertTrue( $config->getSourceMap() );
	}
}
```

`tests/unit/Value/CompiledResultTest.php`:
```php
<?php

namespace Beplus\ScssCompiler\Tests\Unit\Value;

use Beplus\ScssCompiler\Value\CompiledResult;
use PHPUnit\Framework\TestCase;

final class CompiledResultTest extends TestCase {

	public function test_getters(): void {
		$result = new CompiledResult( '.a{}', '{"version":3}', 'main.css' );

		self::assertSame( '.a{}', $result->getCss() );
		self::assertSame( '{"version":3}', $result->getMap() );
		self::assertSame( 'main.css', $result->getFileName() );
	}

	public function test_map_can_be_null(): void {
		$result = new CompiledResult( '.a{}', null, 'main.css' );

		self::assertNull( $result->getMap() );
	}
}
```

`tests/unit/Value/StyleTest.php`:
```php
<?php

namespace Beplus\ScssCompiler\Tests\Unit\Value;

use Beplus\ScssCompiler\Value\Style;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase {

	public function test_getters(): void {
		$style = new Style( 'beplus-scss-main', 'https://example.test/main.css', 123 );

		self::assertSame( 'beplus-scss-main', $style->getHandle() );
		self::assertSame( 'https://example.test/main.css', $style->getUrl() );
		self::assertSame( 123, $style->getVersion() );
	}
}
```

- [ ] **Step 2: Run tests, expect failure (class not found)**

Run: `vendor/bin/phpunit --testsuite unit --filter CompileConfigTest --no-coverage`
Expected: "Class ... not found" — they do not exist yet.

- [ ] **Step 3: Implement the value objects**

```php
<?php

namespace Beplus\ScssCompiler\Value;

final class CompileConfig {

	private $importPaths;
	private $minify;
	private $sourceMap;

	public function __construct( array $importPaths = [], bool $minify = false, bool $sourceMap = false ) {
		$this->importPaths = $importPaths;
		$this->minify      = $minify;
		$this->sourceMap   = $sourceMap;
	}

	public function getImportPaths(): array {
		return $this->importPaths;
	}

	public function getMinify(): bool {
		return $this->minify;
	}

	public function getSourceMap(): bool {
		return $this->sourceMap;
	}
}
```

```php
<?php

namespace Beplus\ScssCompiler\Value;

final class CompiledResult {

	private $css;
	private $map;
	private $fileName;

	public function __construct( string $css, ?string $map, string $fileName ) {
		$this->css      = $css;
		$this->map      = $map;
		$this->fileName = $fileName;
	}

	public function getCss(): string {
		return $this->css;
	}

	public function getMap(): ?string {
		return $this->map;
	}

	public function getFileName(): string {
		return $this->fileName;
	}
}
```

```php
<?php

namespace Beplus\ScssCompiler\Value;

final class Style {

	private $handle;
	private $url;
	private $version;

	public function __construct( string $handle, string $url, int $version ) {
		$this->handle  = $handle;
		$this->url     = $url;
		$this->version = $version;
	}

	public function getHandle(): string {
		return $this->handle;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getVersion(): int {
		return $this->version;
	}
}
```

- [ ] **Step 4: Run the three tests, expect pass**

Run: `vendor/bin/phpunit --testsuite unit --filter 'CompileConfigTest|CompiledResultTest|StyleTest' --no-coverage`
Expected: 3 tests green.

---

### Task 2: Scanner (scan + whole-directory fingerprint)

**Files:**
- Create: `src/Scanner.php`
- Test: `tests/unit/ScannerTest.php`, `tests/fixtures/scss/...`

**Interfaces:**
- Consumes: nothing.
- Produces: `Scanner::scan( string $scssDir ): array` (absolute non-partial `.scss` paths, recursive, hidden dirs skipped); `Scanner::fingerprint( string $scssDir ): string` (whole-dir md5 incl. partials). Used by Detector, Plugin, Enqueue tests.

- [ ] **Step 1: Write fixtures**

`tests/fixtures/scss/main.scss`:
```scss
@import 'variables';

.site-title {
    color: $brand;
}
```

`tests/fixtures/scss/_variables.scss`:
```scss
$brand: #336699;
```

`tests/fixtures/scss/modules/card.scss`:
```scss
.card {
    padding: 1rem;
}
```

`tests/fixtures/scss/_hidden.scss`:
```scss
.must-never-be-entry {
    display: none;
}
```

`tests/fixtures/scss/.hidden/skip.scss`:
```scss
.skip-me {
    display: none;
}
```

`tests/fixtures/scss/_base/_deep.scss`:
```scss
.base-deep {
    color: red;
}
```

- [ ] **Step 2: Write the failing tests**

`tests/unit/ScannerTest.php`:
```php
<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Scanner;
use PHPUnit\Framework\TestCase;

final class ScannerTest extends TestCase {

	private $scssDir;

	protected function setUp(): void {
		parent::setUp();
		$this->scssDir = dirname( __DIR__ ) . '/fixtures/scss';
	}

	public function test_scan_returns_absolute_non_partial_paths_recursively(): void {
		$entries = Scanner::scan( $this->scssDir );

		$relative = array_map(
			static function ( string $path ) {
				return str_replace( $this->scssDir . '/', '', $path );
			},
			$entries
		);
		sort( $relative );

		self::assertSame(
			[ 'main.scss', 'modules/card.scss' ],
			$relative
		);
		foreach ( $entries as $entry ) {
			self::assertStringStartsWith( $this->scssDir, $entry );
			self::assertTrue( is_file( $entry ) );
		}
	}

	public function test_fingerprint_changes_when_partial_touches(): void {
		$before = Scanner::fingerprint( $this->scssDir );

		$partial = $this->scssDir . '/_variables.scss';
		$original = file_get_contents( $partial );
		file_put_contents( $partial, $original . "\n" . '// changed' );

		$after = Scanner::fingerprint( $this->scssDir );

		file_put_contents( $partial, $original );

		self::assertNotSame( $before, $after );
	}

	public function test_fingerprint_is_stable_when_nothing_changes(): void {
		$first  = Scanner::fingerprint( $this->scssDir );
		$second = Scanner::fingerprint( $this->scssDir );

		self::assertSame( $first, $second );
	}
}
```

Note: `self::assertStringStartsWith` exists on PHPUnit 9+; `static function` with `$this` inside a closure inside a non-static method binds `$this` fine. PHP CSP: assigning `$this` inside a static closure is allowed for reading.

- [ ] **Step 3: Run, expect failure (Scanner not found)**

Run: `vendor/bin/phpunit --testsuite unit --filter ScannerTest --no-coverage`
Expected: "Class ...Scanner not found".

- [ ] **Step 4: Implement Scanner**

```php
<?php

namespace Beplus\ScssCompiler;

final class Scanner {

	public static function scan( string $scssDir ): array {
		$entries = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $scssDir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relPath = self::relativePath( $scssDir, $file->getPathname() );
			if ( self::isHiddenSegment( $relPath ) ) {
				continue;
			}
			if ( 'scss' !== $file->getExtension() ) {
				continue;
			}
			if ( 0 === strpos( $file->getBasename(), '_' ) ) {
				continue;
			}
			$entries[] = $file->getPathname();
		}

		sort( $entries );

		return $entries;
	}

	public static function fingerprint( string $scssDir ): string {
		$lines = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $scssDir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			if ( 'scss' !== $file->getExtension() ) {
				continue;
			}
			$relPath = self::relativePath( $scssDir, $file->getPathname() );
			if ( self::isHiddenSegment( $relPath ) ) {
				continue;
			}
			$lines[] = sprintf( "%s:%d:%d\n", $relPath, $file->getMTime(), $file->getSize() );
		}

		sort( $lines );

		return md5( implode( '', $lines ) );
	}

	private static function relativePath( string $scssDir, string $absPath ): string {
		$prefix = rtrim( $scssDir, '/' ) . '/';
		return ltrim( str_replace( $prefix, '', $absPath ), '/' );
	}

	private static function isHiddenSegment( string $relPath ): bool {
		foreach ( explode( '/', $relPath ) as $segment ) {
			if ( 0 === strpos( $segment, '.' ) ) {
				return true;
			}
		}
		return false;
	}
}
```

- [ ] **Step 5: Run, expect pass**

Run: `vendor/bin/phpunit --testsuite unit --filter ScannerTest --no-coverage`
Expected: 3 green.

---

### Task 3: Detector

**Files:**
- Create: `src/Detector.php`
- Test: `tests/unit/DetectorTest.php`

**Interfaces:**
- Consumes: `Scanner::scan()`, `Scanner::fingerprint()`.
- Produces: `Detector::changedEntries( array $entries, array $storedFingerprints, string $scssDir ): array`. Used by Plugin glue (Task 9).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Detector;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase {

	private $scssDir;
	private $entries;

	protected function setUp(): void {
		parent::setUp();
		$this->scssDir = dirname( __DIR__ ) . '/fixtures/scss';
		sort( $this->entries = array_map(
			static function ( string $path ) {
				return str_replace( '/', '/', $path );
			},
			[ $this->scssDir . '/main.scss', $this->scssDir . '/modules/card.scss' ]
		) );
	}

	public function test_returns_entries_missing_in_stored(): void {
		$stored = [ 'modules/card.scss' => 'x' ];
		$current = Detector::changedEntries( $this->entries, $stored, $this->scssDir );

		self::assertSame( [ $this->scssDir . '/main.scss' ], $current );
	}

	public function test_returns_entries_with_stale_fingerprint(): void {
		$stored = [
			'main.scss'          => 'stale',
			'modules/card.scss'  => Scanner::fingerprint( $this->scssDir ),
		];
		$current = Detector::changedEntries( $this->entries, $stored, $this->scssDir );

		self::assertSame( [ $this->scssDir . '/main.scss' ], $current );
	}

	public function test_returns_empty_when_all_fingerprints_match(): void {
		$fp = Scanner::fingerprint( $this->scssDir );
		$stored = [ 'main.scss' => $fp, 'modules/card.scss' => $fp ];
		$current = Detector::changedEntries( $this->entries, $stored, $this->scssDir );

		self::assertSame( [], $current );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit --testsuite unit --filter DetectorTest --no-coverage`
Expected: "Class ...Detector not found".

- [ ] **Step 3: Implement Detector**

```php
<?php

namespace Beplus\ScssCompiler;

final class Detector {

	public static function changedEntries( array $entries, array $storedFingerprints, string $scssDir ): array {
		$current = Scanner::fingerprint( $scssDir );
		$changed = [];

		foreach ( $entries as $entry ) {
			$relPath = ltrim( str_replace( rtrim( $scssDir, '/' ) . '/', '', $entry ), '/' );
			if ( ! isset( $storedFingerprints[ $relPath ] ) || $storedFingerprints[ $relPath ] !== $current ) {
				$changed[] = $entry;
			}
		}

		return $changed;
	}
}
```

- [ ] **Step 4: Run, expect pass**

Run: `vendor/bin/phpunit --testsuite unit --filter DetectorTest --no-coverage`
Expected: 3 green.

---

### Task 4: CompilerInterface + ScssPhpCompiler

**Files:**
- Create: `src/Compiler/CompilerInterface.php`, `src/Compiler/ScssPhpCompiler.php`
- Test: `tests/unit/Compiler/ScssPhpCompilerTest.php`, fixtures `tests/fixtures/bad.scss`
- Modify: `composer.json` (require `scssphp/scssphp:^1.11`), then `composer update` (ask user is expected; use `composer update scssphp/scssphp --no-interaction` via permission "composer update*" (ask)).

**Interfaces:**
- Consumes: `CompileConfig`, `CompiledResult` (Task 1).
- Produces: `CompilerInterface::compile( string $entryFile, CompileConfig $config ): CompiledResult`; `ScssPhpCompiler` default implementation. Used by filter `beplus_scss/compiler` (Task 9) and integration tests.

- [ ] **Step 1: Add the dependency**

Run: `composer require scssphp/scssphp:^1.11 --no-interaction`
(permission gate "composer update*" applies when solver runs — answer accordingly.)

- [ ] **Step 2: Write the failing tests + fixture**

`tests/fixtures/bad.scss`:
```scss
.card {
    .broken {
```
(Unclosed block → scssphp throws.)

`tests/unit/Compiler/ScssPhpCompilerTest.php`:
```php
<?php

namespace Beplus\ScssCompiler\Tests\Unit\Compiler;

use Beplus\ScssCompiler\Compiler\ScssPhpCompiler;
use Beplus\ScssCompiler\Value\CompileConfig;
use PHPUnit\Framework\TestCase;
use ScssPhp\ScssPhp\Exception\SassException;

final class ScssPhpCompilerTest extends TestCase {

	private $scssDir;

	protected function setUp(): void {
		parent::setUp();
		$this->scssDir = dirname( __DIR__, 2 ) . '/fixtures/scss';
	}

	public function test_compiles_expanded_css(): void {
		$config = new CompileConfig( [ $this->scssDir ], false, false );
		$result = ( new ScssPhpCompiler() )->compile( $this->scssDir . '/main.scss', $config );

		self::assertStringContainsString( '.site-title', $result->getCss() );
		self::assertNull( $result->getMap() );
		self::assertSame( 'main.css', $result->getFileName() );
	}

	public function test_includes_imported_partial_variables(): void {
		$config = new CompileConfig( [ $this->scssDir ], false, false );
		$result = ( new ScssPhpCompiler() )->compile( $this->scssDir . '/main.scss', $config );

		self::assertMatchesRegularExpression( '/color:\s*#336699/', $result->getCss() );
	}

	public function test_minify_output_is_compressed(): void {
		$config = new CompileConfig( [ $this->scssDir ], true, false );
		$result = ( new ScssPhpCompiler() )->compile( $this->scssDir . '/main.scss', $config );

		self::assertSame( 1, substr_count( $result->getCss(), "\n" ) + (int) ( strpos( $result->getCss(), "\n" ) === false ) );
		self::assertStringContainsString( '.site-title', $result->getCss() );
	}

	public function test_source_map_enabled_returns_map_and_css_comment(): void {
		$config = new CompileConfig( [ $this->scssDir ], false, true );
		$result = ( new ScssPhpCompiler() )->compile( $this->scssDir . '/main.scss', $config );

		self::assertNotNull( $result->getMap() );
		self::assertStringContainsString( 'sourceMappingURL=main.css.map', $result->getCss() );
	}

	public function test_compile_error_propagates(): void {
		$config = new CompileConfig( [ $this->scssDir ] );

		$this->expectException( SassException::class );
		( new ScssPhpCompiler() )->compile( dirname( __DIR__, 2 ) . '/fixtures/bad.scss', $config );
	}
}
```

- [ ] **Step 3: Run, expect failure**

Run: `vendor/bin/phpunit --testsuite unit --filter ScssPhpCompilerTest --no-coverage`
Expected: "Class ...CompilerInterface not found" (or ScssPhpCompiler).

- [ ] **Step 4: Implement**

```php
<?php

namespace Beplus\ScssCompiler\Compiler;

use Beplus\ScssCompiler\Value\CompileConfig;
use Beplus\ScssCompiler\Value\CompiledResult;

interface CompilerInterface {

	public function compile( string $entryFile, CompileConfig $config ): CompiledResult;
}
```

```php
<?php

namespace Beplus\ScssCompiler\Compiler;

use Beplus\ScssCompiler\Value\CompileConfig;
use Beplus\ScssCompiler\Value\CompiledResult;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;

final class ScssPhpCompiler implements CompilerInterface {

	public function compile( string $entryFile, CompileConfig $config ): CompiledResult {
		$compiler = new Compiler();
		$compiler->setImportPaths( $config->getImportPaths() );
		$compiler->setOutputStyle( $config->getMinify() ? OutputStyle::COMPRESSED : OutputStyle::EXPANDED );

		$fileName = $this->mirroredFileName( $entryFile, $config->getImportPaths() );

		if ( $config->getSourceMap() ) {
			$scssDir = rtrim( $config->getImportPaths()[0] ?? '.', '/' );
			$compiler->setSourceMap( Compiler::SOURCE_MAP_FILE );
			$compiler->setSourceMapOptions( [
				'sourceMapBasepath' => $scssDir,
				'sourceMapURL'      => basename( $fileName ) . '.map',
			] );
		}

		$content = (string) file_get_contents( $entryFile );
		$result  = $compiler->compileString( $content, $entryFile );

		return new CompiledResult( $result->getCss(), $result->getSourceMap(), $fileName );
	}

	private function mirroredFileName( string $entryFile, array $importPaths ): string {
		$scssDir = rtrim( $importPaths[0] ?? '', '/' );
		$rel     = ltrim( str_replace( $scssDir . '/', '', $entryFile ), '/' );
		return preg_replace( '/\.scss$/', '.css', $rel );
	}
}
```

- [ ] **Step 5: Run, expect pass**

Run: `vendor/bin/phpunit --testsuite unit --filter ScssPhpCompilerTest --no-coverage`
Expected: 5 green.

---

### Task 5: Writer

**Files:**
- Create: `src/Writer.php`
- Test: `tests/unit/WriterTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Writer::mirrorPath( string $entry, string $scssDir, string $cssDir ): string`; `Writer::write( string $content, string $absPath ): bool` (atomic); `Writer::writeMap( string $content, string $absPath ): bool` (atomic, writes `$absPath . '.map'`). Used by Plugin (Task 9) and integration tests.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Writer;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase {

	private $destDir;

	protected function setUp(): void {
		parent::setUp();
		$this->destDir = sys_get_temp_dir() . '/beplus-writer-' . uniqid();
		mkdir( $this->destDir, 0777, true );
	}

	protected function tearDown(): void {
		self::rrmdir( $this->destDir );
		parent::tearDown();
	}

	public function test_mirror_path_maps_scss_to_css(): void {
		$scssDir = '/site/assets/scss';
		$cssDir  = '/site/asset/css';

		self::assertSame( 'main.css', Writer::mirrorPath( $scssDir . '/main.scss', $scssDir, $cssDir ) );
		self::assertSame( 'modules/card.css', Writer::mirrorPath( $scssDir . '/modules/card.scss', $scssDir, $cssDir ) );
	}

	public function test_write_creates_subdirectories_and_writes_content(): void {
		$abs = $this->destDir . '/nested/deep/app.css';

		$ok = Writer::write( '.app { color: blue; }', $abs );

		self::assertTrue( $ok );
		self::assertFileExists( $abs );
		self::assertSame( '.app { color: blue; }', file_get_contents( $abs ) );
	}

	public function test_write_is_atomic_no_temp_leftovers(): void {
		$abs = $this->destDir . '/app.css';
		Writer::write( 'a{}', $abs );

		$leftovers = glob( $this->destDir . '/.*.tmp.*' );
		self::assertSame( [], $leftovers ? $leftovers : [] );
	}

	public function test_write_map_appends_map_extension(): void {
		$css = $this->destDir . '/app.css';
		$map = $this->destDir . '/app.css.map';

		$ok = Writer::writeMap( '{"map":true}', $css );

		self::assertTrue( $ok );
		self::assertFileExists( $map );
		self::assertSame( '{"map":true}', file_get_contents( $map ) );
	}

	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( glob( $dir . '/*' ) ?: [] as $file ) {
			is_dir( $file ) ? self::rrmdir( $file ) : unlink( $file );
		}
		rmdir( $dir );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit --testsuite unit --filter WriterTest --no-coverage`
Expected: "Class ...Writer not found".

- [ ] **Step 3: Implement Writer**

```php
<?php

namespace Beplus\ScssCompiler;

final class Writer {

	public static function mirrorPath( string $entry, string $scssDir, string $cssDir ): string {
		$prefix = rtrim( $scssDir, '/' ) . '/';
		$rel    = ltrim( str_replace( $prefix, '', $entry ), '/' );
		return preg_replace( '/\.scss$/', '.css', $rel );
	}

	public static function write( string $content, string $absPath ): bool {
		return self::atomicWrite( $content, $absPath );
	}

	public static function writeMap( string $content, string $absPath ): bool {
		return self::atomicWrite( $content, $absPath . '.map' );
	}

	private static function atomicWrite( string $content, string $absPath ): bool {
		$dir = dirname( $absPath );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p_if_not_wp( ... ) ) {
			if ( ! @mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
				return false;
			}
		}

		$tmp = $dir . '/.' . basename( $absPath ) . '.tmp.' . uniqid();
		if ( false === file_put_contents( $tmp, $content ) ) {
			return false;
		}

		if ( ! rename( $tmp, $absPath ) ) {
			@unlink( $tmp );
			return false;
		}

		return true;
	}
}
```

**Correction (WP-agnostic):** replace the `wp_mkdir_p_if_not_wp( ... )` placeholder in the final code with the plain `@mkdir(...)` branch shown after it. No WP call. The final method:

```php
	private static function atomicWrite( string $content, string $absPath ): bool {
		$dir = dirname( $absPath );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0777, true );
			if ( ! is_dir( $dir ) ) {
				return false;
			}
		}

		$tmp = $dir . '/.' . basename( $absPath ) . '.tmp.' . uniqid();
		if ( false === file_put_contents( $tmp, $content ) ) {
			return false;
		}

		if ( ! @rename( $tmp, $absPath ) ) {
			@unlink( $tmp );
			return false;
		}

		return true;
	}
```

- [ ] **Step 4: Run, expect pass**

Run: `vendor/bin/phpunit --testsuite unit --filter WriterTest --no-coverage`
Expected: 4 green.

---

### Task 6: Enqueue (Styles DTO list)

**Files:**
- Create: `src/Enqueue.php`
- Test: `tests/unit/EnqueueTest.php`, fixtures `tests/fixtures/css/...`

**Interfaces:**
- Consumes: `Style` (Task 1).
- Produces: `Enqueue::styles( string $cssDir, bool $webRoot, string $baseUrl ): array` (`Style[]`). Used by Plugin (Task 9) and integration tests.

- [ ] **Step 1: Write CSS fixtures**

`tests/fixtures/css/main.css`:
```css
.site-title { color: #336699; }
```
`tests/fixtures/css/modules/card.css`:
```css
.card { padding: 1rem; }
```
`tests/fixtures/css/.hidden/ignored.css`:
```css
.ignored {}
```
`tests/fixtures/css/deep/.skip.scss` (non-css → ignored).
`tests/fixtures/css/main.css.map`:
```json
{"version":3}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Enqueue;
use Beplus\ScssCompiler\Value\Style;
use PHPUnit\Framework\TestCase;

final class EnqueueTest extends TestCase {

	private $cssDir;

	protected function setUp(): void {
		parent::setUp();
		$this->cssDir = dirname( __DIR__ ) . '/fixtures/css';
	}

	public function test_styles_list_skips_maps_hidden_and_produces_handles(): void {
		$styles = Enqueue::styles( $this->cssDir, true, 'https://example.test/wp-content/assets/css' );

		$handles = array_map(
			static function ( Style $style ): string {
				return $style->getHandle();
			},
			$styles
		);
		sort( $handles );

		self::assertSame( [ 'beplus-scss-main', 'beplus-scss-modules-card' ], $handles );
		foreach ( $styles as $style ) {
			self::assertInstanceOf( Style::class, $style );
		}
	}

	public function test_web_root_url_uses_plain_relative_path(): void {
		$styles = Enqueue::styles( $this->cssDir, true, 'https://example.test/assets' );

		$main = $this->byHandle( $styles, 'beplus-scss-main' );
		self::assertSame( 'https://example.test/assets/main.css', $main->getUrl() );
		self::assertSame( filemtime( $this->cssDir . '/main.css' ), $main->getVersion() );
	}

	public function test_non_web_root_url_rawurlencodes_path(): void {
		$styles = Enqueue::styles( $this->cssDir, false, 'https://example.test/beplus-scss' );

		$card = $this->byHandle( $styles, 'beplus-scss-modules-card' );
		self::assertSame( 'https://example.test/beplus-scss/modules%2Fcard.css', $card->getUrl() );
	}

	private function byHandle( array $styles, string $handle ): Style {
		foreach ( $styles as $style ) {
			if ( $style->getHandle() === $handle ) {
				return $style;
			}
		}
		self::fail( 'handle not found: ' . $handle );
	}
}
```

- [ ] **Step 3: Run, expect failure**

Run: `vendor/bin/phpunit --testsuite unit --filter EnqueueTest --no-coverage`
Expected: "Class ...Enqueue not found".

- [ ] **Step 4: Implement Enqueue**

```php
<?php

namespace Beplus\ScssCompiler;

use Beplus\ScssCompiler\Value\Style;

final class Enqueue {

	public static function styles( string $cssDir, bool $webRoot, string $baseUrl ): array {
		$styles = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $cssDir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relPath = ltrim( str_replace( rtrim( $cssDir, '/' ) . '/', '', $file->getPathname() ), '/' );
			if ( $this->isHiddenSegment( $relPath ) ) {
				continue;
			}
			if ( 'css' !== $file->getExtension() || 'map' === $file->getExtension() ) {
				continue;
			}
			$handle = 'beplus-scss-' . str_replace( [ '/', '.' ], [ '-', '' ], substr( $relPath, 0, -4 ) );
			$url    = $webRoot ? ( rtrim( $baseUrl, '/' ) . '/' . $relPath )
				: ( rtrim( $baseUrl, '/' ) . '/' . rawurlencode( $relPath ) );
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

	private static function isHiddenSegment( string $relPath ): bool {
		foreach ( explode( '/', $relPath ) as $segment ) {
			if ( 0 === strpos( $segment, '.' ) ) {
				return true;
			}
		}
		return false;
	}
}
```

**Correction (static context):** the body references `$this->isHiddenSegment(...)` inside a `static` method. In the final code the call must be `self::isHiddenSegment( $relPath )`. Same for the call site inside `styles()`.

- [ ] **Step 5: Run, expect pass**

Run: `vendor/bin/phpunit --testsuite unit --filter EnqueueTest --no-coverage`
Expected: 3 green.

---

### Task 7: Settings\SettingsPage (WP-touch)

**Files:**
- Create: `src/Settings/SettingsPage.php`
- Modify: `beplus-scss-compiler.php` (main file — Task 9 does the glue; here only the class)
- Test (integration, runs in wp-env): `tests/integration/SettingsPageTest.php`

**Interfaces:**
- Consumes: nothing (WordPress functions only — this layer is allowed to call WP).
- Produces: `SettingsPage::register()` hooks menu + settings; `settingsFields()`, `renderPage()`, `sanitize( array $input ): array` (public, testable), `renderCompileNow()`.

- [ ] **Step 1: Write the integration test** (gated to wp-env run)

`tests/integration/SettingsPageTest.php`:
```php
<?php

namespace Beplus\ScssCompiler\Tests\Integration;

use Beplus\ScssCompiler\Settings\SettingsPage;

/**
 * Requires the wp-env PHPUnit environment (composer test:integration).
 */
final class SettingsPageTest extends \WP_UnitTestCase {

	public function test_sanitize_keeps_previous_value_on_invalid_scss_dir(): void {
		$page  = new SettingsPage();
		$old   = [
			'scss_dir'     => '/prev',
			'css_dir'      => '/css',
			'compile_mode' => 'auto',
			'source_map'   => false,
			'minify'       => false,
			'web_root'     => true,
		];
		update_option( 'beplus_scss_settings', $old );

		$input = [ 'scss_dir' => '/non/existent', 'css_dir' => '/css', 'compile_mode' => 'auto', 'source_map' => '', 'minify' => '' ];
		$out   = $page->sanitize( $input );

		self::assertSame( '/prev', $out['scss_dir'] );
		self::assertSame( '/css', $out['css_dir'] );
	}

	/**
	 * @requires PHPUNIT_INTEGRATION
	 */
	public function test_menu_registers(): void {
		( new SettingsPage() )->register();

		self::assertGreaterThanOrEqual( 1, did_action( 'admin_menu' ) );
		self::assertSame( 'Beplus_SCSS_PLUGIN', 'Beplus_SCSS_PLUGIN' == 'Beplus_SCSS_PLUGIN' ? 'Beplus_SCSS_PLUGIN' : '' );
	}
}
```

- [ ] **Step 2: Implement SettingsPage** — allowed to use `wp_*()`:

```php
<?php

namespace Beplus\ScssCompiler\Settings;

final class SettingsPage {

	const OPTION_NAME    = 'beplus_scss_settings';
	const MENU_SLUG      = 'beplus-scss';
	const COMPILE_ACTION = 'beplus_scss_compile';
	const NONCE_FIELD    = 'beplus_scss_compile_nonce';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'registerMenu' ] );
		add_action( 'admin_init', [ $this, 'registerSettings' ] );
	}

	public function registerMenu(): void {
		add_menu_page(
			__( 'Beplus SCSS', 'beplus-scss' ),
			__( 'Beplus SCSS', 'beplus-scss' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'renderPage' ]
		);
	}

	public function registerSettings(): void {
		register_setting(
			'beplus_scss_settings_group',
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
			]
		);
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['msg'] ) && 'compiled' === $_GET['msg'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'SCSS compiled successfully.', 'beplus-scss' ) . '</p></div>';
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Beplus SCSS Compiler', 'beplus-scss' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( 'beplus_scss_settings_group' );
		$this->renderFields( get_option( self::OPTION_NAME, [] ) );
		submit_button();
		echo '</form>';
		echo '<hr>';
		$this->renderCompileNow();
		echo '</div>';
	}

	public function renderFields( array $settings ): void {
		$values = wp_parse_args( $settings, [
			'scss_dir'     => '',
			'css_dir'      => '',
			'compile_mode' => 'auto',
			'source_map'   => false,
			'minify'       => false,
			'web_root'     => false,
		] );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="beplus_scss_scss_dir"><?php esc_html_e( 'SCSS source directory', 'beplus-scss' ); ?></label></th>
				<td><input type="text" class="regular-text code" id="beplus_scss_scss_dir" name="beplus_scss_settings[scss_dir]" value="<?php echo esc_attr( $values['scss_dir'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="beplus_scss_css_dir"><?php esc_html_e( 'CSS destination directory', 'beplus-scss' ); ?></label></th>
				<td><input type="text" class="regular-text code" id="beplus_scss_css_dir" name="beplus_scss_settings[css_dir]" value="<?php echo esc_attr( $values['css_dir'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Compile mode', 'beplus-scss' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="beplus_scss_settings[compile_mode]" value="auto" <?php checked( 'auto', $values['compile_mode'] ); ?> /> <?php esc_html_e( 'Auto (recompile on change)', 'beplus-scss' ); ?></label><br>
						<label><input type="radio" name="beplus_scss_settings[compile_mode]" value="manual" <?php checked( 'manual', $values['compile_mode'] ); ?> /> <?php esc_html_e( 'Manual (compile via button)', 'beplus-scss' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Source maps', 'beplus-scss' ); ?></th>
				<td><label><input type="checkbox" name="beplus_scss_settings[source_map]" value="1" <?php checked( true, (bool) $values['source_map'] ); ?> /> <?php esc_html_e( 'Emit .css.map files', 'beplus-scss' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Minify', 'beplus-scss' ); ?></th>
				<td><label><input type="checkbox" name="beplus_scss_settings[minify]" value="1" <?php checked( true, (bool) $values['minify'] ); ?> /> <?php esc_html_e( 'Compact output', 'beplus-scss' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	public function renderCompileNow(): void {
		$url = admin_url( 'admin-post.php' );
		?>
		<form method="get" action="<?php echo esc_url( $url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::COMPILE_ACTION ); ?>" />
			<?php wp_nonce_field( self::NONCE_FIELD ); ?>
			<input type="hidden" name="msg_base" value="admin" />
			<?php submit_button( __( 'Compile SCSS now', 'beplus-scss' ), 'secondary', 'compile-now', false ); ?>
		</form>
		<?php
	}

	public function sanitize( array $input ): array {
		$old    = get_option( self::OPTION_NAME, [] );
		$output = wp_parse_args( $old, [
			'scss_dir'     => '',
			'css_dir'      => '',
			'compile_mode' => 'auto',
			'source_map'   => false,
			'minify'       => false,
			'web_root'     => false,
		] );

		if ( isset( $input['scss_dir'] ) ) {
			$scss      = $this->normalizePath( $input['scss_dir'] );
			$validated = $this->validateScssDir( $scss );
			if ( true === $validated ) {
				$output['scss_dir'] = $scss;
			} else {
				add_settings_error( self::OPTION_NAME, 'bad_scss_dir', $validated );
			}
		}

		if ( isset( $input['css_dir'] ) ) {
			$css       = $this->normalizePath( $input['css_dir'] );
			$validated = $this->validateCssDir( $css );
			if ( true === $validated ) {
				$output['css_dir']     = $css;
				$output['web_root']    = 0 === strpos( $css . '/', ABSPATH );
			} else {
				add_settings_error( self::OPTION_NAME, 'bad_css_dir', $validated );
			}
		}

		if ( isset( $input['compile_mode'] ) && in_array( $input['compile_mode'], [ 'auto', 'manual' ], true ) ) {
			$output['compile_mode'] = $input['compile_mode'];
		}

		$output['source_map'] = ! empty( $input['source_map'] );
		$output['minify']     = ! empty( $input['minify'] );

		return $output;
	}

	private function normalizePath( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}
		$path = wp_normalize_path( $path );
		$path = rtrim( $path, '/' );
		return $path;
	}

	private function validateScssDir( string $path ): bool/string /* bool | string */ {
		if ( '' === $path ) {
			return true;
		}
		if ( ! is_dir( $path ) ) {
			return __( 'SCSS directory does not exist.', 'beplus-scss' );
		}
		if ( ! is_readable( $path ) ) {
			return __( 'SCSS directory is not readable.', 'beplus-scss' );
		}
		if ( 0 < count( Scanner::scan( $path ) ) ) {
			return true;
		}
		return __( 'SCSS directory contains no entries (non-partial .scss files).', 'beplus-scss' );
	}

	private function validateCssDir( string $path ): bool/string /* bool | string */ {
		if ( '' === $path ) {
			return true;
		}
		if ( ! is_dir( $path ) ) {
			return __( 'CSS directory does not exist.', 'beplus-scss' );
		}
		if ( ! is_writable( $path ) ) {
			return __( 'CSS directory is not writable.', 'beplus-scss' );
		}
		return true;
	}
}
```

**Correction (typing):** PHP 7.4 forbids `bool/string` union return types. Use `/** @return bool|string */` docblock + return type `:` removed (or `$validated` typed loosely). Final signatures:

```php
	private function validateScssDir( string $path ) { /* @return bool|string */ }
	private function validateCssDir( string $path ) { /* @return bool|string */ }
```

And `register_menu`/`render_page` etc. keep the snake_case WP-style names used in hooks (`registerMenu → hook callers match`). Keep hook callback names consistent: `add_action( 'admin_menu', [ $this, 'registerMenu' ] )`; method named `registerMenu()`. Fine.

- [ ] **Step 3: Lint + analyse + unit suite still green**

Run: `composer lint && composer analyse` (analyse: PHPStan skips integration-only files? see Task 10 tweak).

---

### Task 8: Plugin glue (bootstrap)

**Files:**
- Create: `src/Plugin.php`, `beplus-scss-compiler.php`, `uninstall.php`
- Test (integration, wp-env): `tests/integration/PluginGlueTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `Plugin` with `register()`, `onEnqueueScripts()` (auto-compile + enqueue), `handleCompileNow()`, `registerEndpoint()`, `serveFile()`.

- [ ] **Step 1: Integration test**

`tests/integration/PluginGlueTest.php`:
```php
<?php

namespace Beplus\ScssCompiler\Tests\Integration;

use Beplus\ScssCompiler\Plugin;

final class PluginGlueTest extends \WP_UnitTestCase {

	/**
	 * @requires PHPUNIT_INTEGRATION
	 */
	public function test_rewrite_rule_registers(): void {
		$plugin = new Plugin();
		$plugin->register();

		$rules = get_option( 'rewrite_rules' );
		self::assertIsArray( $rules );
		self::assertArrayHasKey( '^beplus-scss/([^/]+)$', $rules );
	}

	public function test_compile_now_action_exists(): void {
		$plugin = new Plugin();
		$plugin->register();

		self::assertGreaterThan( 0, has_action( 'admin_post_beplus_scss_compile' ) );
	}
}
```

- [ ] **Step 2: Implement Plugin.php**

```php
<?php

namespace Beplus\ScssCompiler;

use Beplus\ScssCompiler\Compiler\CompilerInterface;
use Beplus\ScssCompiler\Compiler\ScssPhpCompiler;
use Beplus\ScssCompiler\Settings\SettingsPage;
use Beplus\ScssCompiler\Value\CompileConfig;
use Beplus\ScssCompiler\Value\CompiledResult;
use Beplus\ScssCompiler\Value\Style;

final class Plugin {

	const VERSION   = '0.1.0';
	const FINGERPRINTS_OPTION = 'beplus_scss_fingerprints';
	const LAST_ERROR_OPTION   = 'beplus_scss_last_error';
	const VERSION_OPTION      = 'beplus_scss_version';

	private $settingsPage;
	private static $autoCompiled = false;

	public function __construct() {
		$this->settingsPage = new SettingsPage();
	}

	public function register(): void {
		$this->settingsPage->register();

		add_action( 'wp_enqueue_scripts', [ $this, 'onEnqueueScripts' ] );
		add_action( 'admin_post_' . SettingsPage::COMPILE_ACTION, [ $this, 'handleCompileNow' ] );
		add_action( 'init', [ $this, 'registerEndpoint' ] );
		add_action( 'template_redirect', [ $this, 'serveFile' ] );
		add_action( 'update_option_beplus_scss_settings', [ $this, 'flushRewriteRules' ] );

		register_activation_hook( BE_PLUS_SCSS_COMPILER_MAIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( BE_PLUS_SCSS_COMPILER_MAIN_FILE, [ $this, 'deactivate' ] );
	}

	public function activate(): void {
		$this->registerEndpoint();
		update_option( self::VERSION_OPTION, self::VERSION );
		flush_rewrite_rules();
	}

	public function deactivate(): void {
		flush_rewrite_rules();
	}

	public function flushRewriteRules(): void {
		$this->registerEndpoint();
		flush_rewrite_rules();
	}

	public function registerEndpoint(): void {
		add_rewrite_rule( '^beplus-scss/([^/]+)$', 'index.php?beplus_scss_file=$matches[1]', 'top' );
		add_rewrite_tag( '%beplus_scss_file%', '([^&]+)' );
	}

	public function onEnqueueScripts(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		$settings   = get_option( SettingsPage::OPTION_NAME, [] );
		$scssDir    = isset( $settings['scss_dir'] ) ? $settings['scss_dir'] : '';
		$cssDir     = isset( $settings['css_dir'] ) ? $settings['css_dir'] : '';
		if ( '' === $scssDir || '' === $cssDir || ! is_dir( $scssDir ) || ! is_dir( $cssDir ) ) {
			return;
		}

		if ( ( $settings['compile_mode'] ?? 'auto' ) === 'auto' && ! self::$autoCompiled ) {
			self::$autoCompiled = true;
			$this->compileChangedEntries( $settings );
		}

		$styles = $this->buildStyles( $settings, $cssDir );
		$styles = apply_filters( 'beplus_scss/enqueue', $styles );
		foreach ( $styles as $style ) {
			/** @var Style $style */
			wp_enqueue_style( $style->getHandle(), $style->getUrl(), [], $style->getVersion() );
		}
	}

	public function handleCompileNow(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'beplus-scss' ) );
		}
		check_admin_referer( SettingsPage::NONCE_FIELD );

		$settings = get_option( SettingsPage::OPTION_NAME, [] );
		$compiled = $this->compileAllEntries( $settings );
		$msg      = $compiled ? 'compiled' : 'error';

		wp_safe_redirect( add_query_arg( 'msg', $msg, admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) );
		exit;
	}

	public function serveFile(): void {
		$file = get_query_var( 'beplus_scss_file' );
		if ( '' === $file ) {
			return;
		}
		$file     = rawurldecode( $file );
		$settings = get_option( SettingsPage::OPTION_NAME, [] );
		$cssDir   = isset( $settings['css_dir'] ) ? $settings['css_dir'] : '';
		if ( '' === $cssDir ) {
			return;
		}

		if ( ! $this->isServeable( $file ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$realDir = realpath( $cssDir );
		$real    = realpath( $cssDir . '/' . $file );
		if ( false === $real || 0 !== strpos( $real, $realDir . '/' ) ) {
			status_header( 404 );
			exit;
		}

		$mime = 'map' === pathinfo( $real, PATHINFO_EXTENSION ) ? 'application/json' : 'text/css';
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real );
		exit;
	}

	private function isServeable( string $file ): bool {
		$ext = pathinfo( $file, PATHINFO_EXTENSION );
		return in_array( $ext, [ 'css', 'map' ], true ) && 0 === strpos( $file, '.' ) !== true;
	}

	private function buildStyles( array $settings, string $cssDir ): array {
		$webRoot = ! empty( $settings['web_root'] );
		$baseUrl = $webRoot
			? home_url( '/' ) . ltrim( substr( $cssDir, strlen( ABSPATH ) ), '/' )
			: home_url( '/beplus-scss' );
		return Enqueue::styles( $cssDir, $webRoot, $baseUrl );
	}

	private function compileChangedEntries( array $settings ): void {
		$entries = Scanner::scan( $settings['scss_dir'] );
		$stored  = get_option( self::FINGERPRINTS_OPTION, [] );
		$changed = Detector::changedEntries( $entries, $stored, $settings['scss_dir'] );
		if ( [] === $changed ) {
			return;
		}
		$this->compileEntries( $settings, $entries, $changed );
	}

	private function compileAllEntries( array $settings ): bool {
		$entries = Scanner::scan( $settings['scss_dir'] );
		$ok      = true;
		$this->compileEntries( $settings, $entries, $entries );
		return ! $this->hasCompileError();
	}

	private function compileEntries( array $settings, array $entries, array $toCompile ): void {
		/** @var CompilerInterface $compiler */
		$compiler = apply_filters( 'beplus_scss/compiler', new ScssPhpCompiler() );
		$importPaths = apply_filters( 'beplus_scss/import_paths', [ $settings['scss_dir'] ] );
		$config = new CompileConfig(
			is_array( $importPaths ) ? $importPaths : [ $settings['scss_dir'] ],
			! empty( $settings['minify'] ),
			! empty( $settings['source_map'] )
		);

		$fingerprints = get_option( self::FINGERPRINTS_OPTION, [] );
		$toWrite      = [ basename( $settings['scss_dir'] ) /* ignored */ ];
		foreach ( $entries as $entry ) {
			$relPath = ltrim( str_replace( rtrim( $settings['scss_dir'], '/' ) . '/', '', $entry ), '/' );

			if ( false !== apply_filters( 'beplus_scss/exclude', false, $entry, $relPath ) ) {
				continue;
			}

			if ( in_array( $entry, $toCompile, true ) ) {
				try {
					/** @var CompiledResult $result */
					$result = $compiler->compile( $entry, $config );
					$dest   = apply_filters( 'beplus_scss/write_path', Writer::mirrorPath( $entry, $settings['scss_dir'], $settings['css_dir'] ), $entry, $settings['scss_dir'], $settings['css_dir'] );
					Writer::write( $result->getCss(), $settings['css_dir'] . '/' . $dest );
					if ( null !== $result->getMap() ) {
						Writer::writeMap( $result->getMap(), $settings['css_dir'] . '/' . $dest );
					}
					$this->clearError( $relPath );
				} catch ( \Throwable $e ) {
					$this->setError( $relPath, $e->getMessage() );
					$wpError = new \WP_Error( 'beplus_scss_compile', $e->getMessage(), [ 'entry' => $relPath ] );
					apply_filters( 'beplus_scss/error', $wpError );
				}
			}
			$fingerprints[ $relPath ] = Scanner::fingerprint( $settings['scss_dir'] );
		}
		update_option( self::FINGERPRINTS_OPTION, $fingerprints );
	}

	private function clearError( string $entry ): void {
		$error = get_option( self::LAST_ERROR_OPTION, [] );
		if ( isset( $error['entry'] ) && $error['entry'] === $entry ) {
			delete_option( self::LAST_ERROR_OPTION );
		}
	}

	private function setError( string $entry, string $message ): void {
		update_option( self::LAST_ERROR_OPTION, [
			'time'    => time(),
			'entry'   => $entry,
			'message' => $message,
		] );
	}

	private function hasCompileError(): bool {
		return false !== get_option( self::LAST_ERROR_OPTION, false );
	}
}
```

**Correction (compile flow):** Simplify — `compileEntries` is called once per request from either auto or compile-now; `$toWrite` unused; remove it. Fix `isServeable` hidden-dot guard: the real check is `0 === strpos( $file, '.' )` → reject hidden. Clean final serve guard:

```php
	private function isServeable( string $file ): bool {
		$ext = pathinfo( $file, PATHINFO_EXTENSION );
		if ( ! in_array( $ext, [ 'css', 'map' ], true ) ) {
			return false;
		}
		return 0 !== strpos( $file, '.' );
	}
```

Also drop `add_rewrite_tag( '%beplus_scss_file%', '([^&]+)' )` — the query var must exist for `get_query_var` to work. Register it instead:

```php
	public function registerEndpoint(): void {
		add_rewrite_rule( '^beplus-scss/([^/]+)$', 'index.php?beplus_scss_file=$matches[1]', 'top' );
		add_filter( 'query_vars', static function ( array $vars ): array {
			$vars[] = 'beplus_scss_file';
			return $vars;
		} );
	}
```

- [ ] **Step 3: Implement main plugin file**

`beplus-scss-compiler.php`:
```php
<?php
/**
 * Plugin Name: Beplus SCSS Compiler
 * Description: Compiles SCSS to CSS. Declare an SCSS source directory and a CSS destination directory in the admin; the plugin recompiles on change (auto) or on demand (manual) and enqueues the result.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Beplus
 * Author URI: https://profiles.wordpress.org/bearsthemes/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: beplus-scss
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BE_PLUS_SCSS_COMPILER_MAIN_FILE', __FILE__ );

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Beplus SCSS Compiler: run `composer install` in the plugin directory.', 'beplus-scss' ) . '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

( new \Beplus\ScssCompiler\Plugin() )->register();
```

- [ ] **Step 4: Implement uninstall.php**

```php
<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'beplus_scss_settings' );
delete_option( 'beplus_scss_fingerprints' );
delete_option( 'beplus_scss_last_error' );
delete_option( 'beplus_scss_version' );
```

- [ ] **Step 5: Lint + analyse + unit suite green**

Run: `composer lint && composer analyse && composer test`

---

### Task 9: Integration scaffolding + readme + pot + final verification

**Files:**
- Create: `readme.txt`, `languages/beplus-scss.pot`, `docs/worklogs/2026-08-19.md` (no file on days without changes — today HAS changes, so create/extend)
- Modify: `tests/bootstrap.php` (skip-loading WP fw for unit; the wp-env integration bootstrap loads it), `composer.json` (scripts stay as-is), remove `.gitkeep` from `src/`, `tests/integration/`

- [ ] **Step 1: readme.txt**

```
=== Beplus SCSS Compiler ===
Contributors: beplus
Tags: scss, css, compiler, sass
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compiles SCSS to CSS and enqueues the result on the frontend.

== Description ==

Declare an SCSS source directory and a CSS destination directory in the
admin. The plugin scans SCSS (mirror structure), recompiles when files change
(auto mode) or on demand (manual mode), and enqueues the compiled CSS.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or zip via Plugins > Add New.
2. Activate.
3. Go to Beplus SCSS in the admin, set both directories, and Save.

== Changelog ==

= 0.1.0 =
Initial release.
```

- [ ] **Step 2: languages/beplus-scss.pot** (minimal gettext pot covering strings from src/)

```
msgid ""
msgstr ""
"Project-Id-Version: Beplus SCSS Compiler 0.1.0\n"
"Language-Team: Beplus\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"POT-Creation-Date: 2026-08-19T00:00:00+00:00\n"
"X-Domain-Loader: 1\n"
```

Navigate code; every `__(...)`/`esc_html__()`/`esc_html_e()` string with the `beplus-scss` textdomain gets a `msgid`. Placeholders may be added during task 9 finalization.

- [ ] **Step 3: tests/bootstrap.php update**

```php
<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit suite: loads Composer autoloader only (no WordPress).
 * Integration suite (inside wp-env): additionally loads the WP test
 * framework when the environment provides it.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( getenv( 'WP_TESTS_DIR' ) ) {
	$wp_tests = getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';
	if ( is_file( $wp_tests ) ) {
		require_once $wp_tests;
	}
}
```

- [ ] **Step 4: remove placeholders**

```bash
git rm src/.gitkeep tests/integration/.gitkeep
```

- [ ] **Step 5: full verification**

Run: `composer lint && composer analyse && composer test`
Then: `npm run build` (must produce `build/beplus-scss-compiler.zip`).
Update `docs/worklogs/2026-08-19.md` with today's Summary/Changes/Files/Verification/Next.

---

## Self-Review notes

- Every Plugin.md §3–§10 requirement maps to a task (settings validation → Task 7; auto/manual + fingerprint → Task 8; endpoint + traversal → Task 8; six filters → Tasks 7/8; Pot/readme → Task 9; build → Task 9).
- PHP 7.4 rules honored: strpos not str_starts_with; no union types (docblock `@return bool|string` instead); typed props only where 7.4 allows.
- scssphp API verified against v1.11.0 source: `Compiler::SOURCE_MAP_FILE` + `sourceMapBasepath/sourceMapURL`, `setOutputStyle( OutputStyle::COMPRESSED )`, `compileString()` → `CompilationResult`.
- `WP_Error`/`apply_filters`/`add_rewrite_*` only inside `Plugin`/`SettingsPage` (allowed).
- Integration tests are gated to the wp-env PHPUnit environment (`composer test:integration`); they cannot run on this machine (no Docker) — noted in the worklog.