<?php

namespace Beplus\ScssCompiler\Tests\Integration;

use Beplus\ScssCompiler\Writer;

/**
 * Requires the wp-env PHPUnit environment (composer test:integration).
 *
 * Writer::write()/writeMap() go through the Filesystem helper (WP_Filesystem),
 * so their real-write behaviour needs WordPress.
 */
final class WriterIntegrationTest extends \WP_UnitTestCase {

	private string $destDir;

	protected function setUp(): void {
		parent::setUp();
		$this->destDir = sys_get_temp_dir() . '/beplus-writer-int-' . uniqid();
		wp_mkdir_p( $this->destDir );
	}

	protected function tearDown(): void {
		self::rrmdir( $this->destDir );
		parent::tearDown();
	}

	public function test_write_creates_subdirectories_and_writes_content(): void {
		$abs = $this->destDir . '/nested/deep/app.css';

		$ok = Writer::write( '.app { color: blue; }', $abs );

		self::assertTrue( $ok );
		self::assertFileExists( $abs );
		self::assertSame( '.app { color: blue; }', file_get_contents( $abs ) );
	}

	public function test_write_has_no_temp_leftovers(): void {
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
