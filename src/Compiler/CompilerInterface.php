<?php

namespace Beplus\ScssCompiler\Compiler;

use Beplus\ScssCompiler\Value\CompileConfig;
use Beplus\ScssCompiler\Value\CompiledResult;

interface CompilerInterface {

	public function compile( string $entryFile, CompileConfig $config ): CompiledResult;
}
