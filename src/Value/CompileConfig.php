<?php

namespace Beplus\ScssCompiler\Value;

final class CompileConfig {

	/** @var string[] */
	private array $importPaths;
	private bool $minify;
	private bool $sourceMap;

	/**
	 * @param string[] $importPaths
	 */
	public function __construct( array $importPaths = [], bool $minify = false, bool $sourceMap = false ) {
		$this->importPaths = $importPaths;
		$this->minify      = $minify;
		$this->sourceMap   = $sourceMap;
	}

	/**
	 * @return string[]
	 */
	public function getImportPaths(): array {
		return $this->importPaths;
	}

	public function getMinify(): bool {
		return $this->minify;
	}

	public function getSourceMap(): bool {
		return $this->sourceMap;
	}
}
