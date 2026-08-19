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
