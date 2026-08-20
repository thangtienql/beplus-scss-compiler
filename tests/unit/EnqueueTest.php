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

	public function test_styles_lists_only_registered_files_for_pair(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ '0:main.css', '0:modules/card.css' ], 0 );

		self::assertSame( [ 'beplus-scss-0-main', 'beplus-scss-0-modules-card' ], $this->handles( $styles ) );
	}

	public function test_styles_skips_css_not_in_registry(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ '0:main.css' ], 0 );

		self::assertSame( [ 'beplus-scss-0-main' ], $this->handles( $styles ) );
	}

	public function test_styles_skips_maps_hidden_and_directories(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ '0:main.css', '0:modules/card.css' ], 0 );

		self::assertSame( [ 'beplus-scss-0-main', 'beplus-scss-0-modules-card' ], $this->handles( $styles ) );
	}

	public function test_url_is_plain_relative_path(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ '0:main.css', '0:modules/card.css' ], 0 );

		$card = $this->byHandle( $styles, 'beplus-scss-0-modules-card' );
		self::assertSame( 'https://example.test/assets/modules/card.css', $card->getUrl() );
		self::assertSame( filemtime( $this->cssDir . '/main.css' ), $this->byHandle( $styles, 'beplus-scss-0-main' )->getVersion() );
	}

	public function test_pair_id_prefixes_handles_and_filters_registry(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ '1:main.css' ], 1 );

		self::assertSame( [ 'beplus-scss-1-main' ], $this->handles( $styles ) );
	}

	public function test_pair_1_ignores_other_pairs_registered_files(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ '0:main.css', '1:modules/card.css' ], 1 );

		self::assertSame( [ 'beplus-scss-1-modules-card' ], $this->handles( $styles ) );
	}

	public function test_pair_0_accepts_legacy_unprefixed_entries(): void {
		$styles = Enqueue::styles( $this->cssDir, 'https://example.test/assets', [ 'main.css' ], 0 );

		self::assertSame( [ 'beplus-scss-0-main' ], $this->handles( $styles ) );
	}

	public function test_distinct_pairs_with_same_file_do_not_collide(): void {
		$stylesA = Enqueue::styles( $this->cssDir, 'https://example.test/a', [ '0:main.css' ], 0 );
		$stylesB = Enqueue::styles( $this->cssDir, 'https://example.test/b', [ '1:main.css' ], 1 );

		self::assertNotSame( $this->byHandle( $stylesA, 'beplus-scss-0-main' )->getHandle(), $this->byHandle( $stylesB, 'beplus-scss-1-main' )->getHandle() );
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
