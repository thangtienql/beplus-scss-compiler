<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Writer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure path-mapping part of Writer.
 *
 * Writer::write()/writeMap() go through the Filesystem helper (WP_Filesystem)
 * and are covered by tests/integration/WriterIntegrationTest.php instead.
 */
final class WriterTest extends TestCase {

	public function test_mirror_path_maps_scss_to_css(): void {
		$scssDir = '/site/assets/scss';
		$cssDir  = '/site/asset/css';

		self::assertSame( 'main.css', Writer::mirrorPath( $scssDir . '/main.scss', $scssDir, $cssDir ) );
		self::assertSame( 'modules/card.css', Writer::mirrorPath( $scssDir . '/modules/card.scss', $scssDir, $cssDir ) );
	}
}
