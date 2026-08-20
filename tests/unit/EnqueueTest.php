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

	public function test_styles_lists_only_registered_files(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ 'main.css', 'modules/card.css' ] );

		self::assertSame( [ 'beplus-scss-main', 'beplus-scss-modules-card' ], $this->handles( $styles ) );
	}

	public function test_styles_skips_css_not_in_registry(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ 'main.css' ] );

		self::assertSame( [ 'beplus-scss-main' ], $this->handles( $styles ) );
	}

	public function test_styles_skips_maps_hidden_and_directories(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ 'main.css', 'modules/card.css' ] );

		self::assertSame( [ 'beplus-scss-main', 'beplus-scss-modules-card' ], $this->handles( $styles ) );
	}

	public function test_url_is_plain_relative_path(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ 'main.css', 'modules/card.css' ] );

		$card = $this->byHandle( $styles, 'beplus-scss-modules-card' );
		self::assertSame( 'https://example.test/assets/modules/card.css', $card->getUrl() );
		self::assertSame( filemtime( $this->cssDir . '/main.css' ), $this->byHandle( $styles, 'beplus-scss-main' )->getVersion() );
	}

	/**
	 * @param Style[] $styles
	 * @return string[]
	 */
	private function handles( array $styles ): array {
		$handles = array_map(
			static function ( Style $style ): string {
				return $style->getHandle();
			},
			$styles
		);
		sort( $handles );

		return $handles;
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
