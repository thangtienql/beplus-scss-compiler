<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Writer;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase {

	private string $destDir;

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
		$files = glob( $dir . '/*' );
		foreach ( $files ? $files : [] as $file ) {
			is_dir( $file ) ? self::rrmdir( $file ) : unlink( $file );
		}
		rmdir( $dir );
	}
}
