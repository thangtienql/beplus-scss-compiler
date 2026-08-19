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
