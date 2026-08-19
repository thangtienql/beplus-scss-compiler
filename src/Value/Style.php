<?php

namespace Beplus\ScssCompiler\Value;

final class Style {

	private string $handle;
	private string $url;
	private int $version;

	public function __construct( string $handle, string $url, int $version ) {
		$this->handle  = $handle;
		$this->url     = $url;
		$this->version = $version;
	}

	public function getHandle(): string {
		return $this->handle;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getVersion(): int {
		return $this->version;
	}
}
