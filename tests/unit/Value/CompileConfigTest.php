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
