<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Scanner;
use PHPUnit\Framework\TestCase;

final class ScannerTest extends TestCase {

	private string $scssDir;

	protected function setUp(): void {
		parent::setUp();
		$this->scssDir = dirname( __DIR__ ) . '/fixtures/scss';
	}

	public function test_scan_returns_absolute_non_partial_paths_recursively(): void {
		$entries = Scanner::scan( $this->scssDir );

		$relative = array_map(
			function ( string $path ) {
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
			self::assertMatchesRegularExpression( '/^' . preg_quote( $this->scssDir, '/' ) . '/', $entry );
			self::assertTrue( is_file( $entry ) );
		}
	}

	public function test_fingerprint_changes_when_partial_touches(): void {
		$before = Scanner::fingerprint( $this->scssDir );

		$partial  = $this->scssDir . '/_variables.scss';
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
