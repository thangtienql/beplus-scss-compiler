<?php

namespace Beplus\ScssCompiler\Value;

final class CompiledResult {

	private string $css;
	private ?string $map;
	private string $fileName;

	public function __construct( string $css, ?string $map, string $fileName ) {
		$this->css      = $css;
		$this->map      = $map;
		$this->fileName = $fileName;
	}

	public function getCss(): string {
		return $this->css;
	}

	public function getMap(): ?string {
		return $this->map;
	}

	public function getFileName(): string {
		return $this->fileName;
	}
}
