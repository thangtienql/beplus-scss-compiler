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
			$compiler->setSourceMapOptions(
				[
					'sourceMapBasepath' => $scssDir,
					'sourceMapURL'      => basename( $fileName ) . '.map',
				]
			);
		}

		$content = (string) file_get_contents( $entryFile );
		$result  = $compiler->compileString( $content, $entryFile );

		return new CompiledResult( $result->getCss(), $result->getSourceMap(), $fileName );
	}

	/**
	 * @param string[] $importPaths
	 */
	private function mirroredFileName( string $entryFile, array $importPaths ): string {
		$scssDir = rtrim( $importPaths[0] ?? '', '/' );
		$rel     = ltrim( str_replace( $scssDir . '/', '', $entryFile ), '/' );
		$mapped  = preg_replace( '/\.scss$/', '.css', $rel );

		return $mapped ?? $rel;
	}
}
