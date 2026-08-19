<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Enqueue;
use Beplus\ScssCompiler\Value\Style;
use PHPUnit\Framework\TestCase;

final class EnqueueTest extends TestCase {

	private string $cssDir;

	protected function setUp(): void {
		parent::setUp();
		$this->cssDir = dirname( __DIR__ ) . '/fixtures/css';
	}

	public function test_styles_list_skips_maps_hidden_and_produces_handles(): void {
		$styles = Enqueue::styles( $this->cssDir, true, 'https://example.test/wp-content/assets/css' );

		$handles = array_map(
			static function ( Style $style ): string {
				return $style->getHandle();
			},
			$styles
		);
		sort( $handles );

		self::assertSame( [ 'beplus-scss-main', 'beplus-scss-modules-card' ], $handles );
	}

	public function test_web_root_url_uses_plain_relative_path(): void {
		$styles = Enqueue::styles( $this->cssDir, true, 'https://example.test/assets' );

		$main = $this->byHandle( $styles, 'beplus-scss-main' );
		self::assertSame( 'https://example.test/assets/main.css', $main->getUrl() );
		self::assertSame( filemtime( $this->cssDir . '/main.css' ), $main->getVersion() );
	}

	public function test_non_web_root_url_rawurlencodes_path(): void {
		$styles = Enqueue::styles( $this->cssDir, false, 'https://example.test/beplus-scss' );

		$card = $this->byHandle( $styles, 'beplus-scss-modules-card' );
		self::assertSame( 'https://example.test/beplus-scss/modules%2Fcard.css', $card->getUrl() );
	}

	/** @param Style[] $styles */
	private function byHandle( array $styles, string $handle ): Style {
		foreach ( $styles as $style ) {
			if ( $style->getHandle() === $handle ) {
				return $style;
			}
		}
		self::fail( 'handle not found: ' . $handle );
	}
}
