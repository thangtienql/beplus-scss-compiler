<?php

namespace Beplus\ScssCompiler\Tests\Integration;

use Beplus\ScssCompiler\Compiler\ScssPhpCompiler;
use Beplus\ScssCompiler\Value\CompileConfig;
use ScssPhp\ScssPhp\Exception\ParserException;

/**
 * Requires the wp-env PHPUnit environment (composer test:integration).
 *
 * ScssPhpCompiler::compile() reads the entry file through the Filesystem
 * helper (WP_Filesystem), so compiling real fixtures needs WordPress.
 */
final class ScssPhpCompilerIntegrationTest extends \WP_UnitTestCase {

	private string $scssDir;

	protected function setUp(): void {
		parent::setUp();
		$this->scssDir = dirname( __DIR__ ) . '/fixtures/scss';
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

		$this->expectException( ParserException::class );
		( new ScssPhpCompiler() )->compile( dirname( __DIR__ ) . '/fixtures/bad.scss', $config );
	}
}
