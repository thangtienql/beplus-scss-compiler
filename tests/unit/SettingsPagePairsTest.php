<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Settings\SettingsPage;
use PHPUnit\Framework\TestCase;

final class SettingsPagePairsTest extends TestCase {

	public function test_valid_pairs_are_normalized_and_kept(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => '/assets/scss/',
					'css_dir'  => '/assets/css/',
				],
				[
					'scss_dir' => 'blocks/scss',
					'css_dir'  => 'blocks/css',
				],
			],
			[],
			static function (): bool {
				return true;
			},
			static function (): bool {
				return true;
			}
		);

		self::assertSame(
			[
				[
					'scss_dir' => 'assets/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'blocks/scss',
					'css_dir'  => 'blocks/css',
				],
			],
			$result['pairs']
		);
		self::assertSame( [], $result['errors'] );
	}

	public function test_blank_rows_are_skipped(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => 'assets/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => '',
					'css_dir'  => '',
				],
				[],
			],
			[],
			static function (): bool {
				return true;
			},
			static function (): bool {
				return true;
			}
		);

		self::assertSame(
			[
				[
					'scss_dir' => 'assets/scss',
					'css_dir'  => 'assets/css',
				],
			],
			$result['pairs']
		);
	}

	public function test_dotdot_segments_are_rejected_per_row(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => '../outside',
					'css_dir'  => 'assets/css',
				],
			],
			[
				[
					'scss_dir' => 'old/scss',
					'css_dir'  => 'old/css',
				],
			],
			static function (): bool {
				return true;
			},
			static function (): bool {
				return true;
			}
		);

		self::assertSame(
			[
				[
					'scss_dir' => 'old/scss',
					'css_dir'  => 'old/css',
				],
			],
			$result['pairs']
		);
		self::assertSame( 'bad_scss_dir_0', $result['errors'][0]['code'] );
	}

	public function test_invalid_row_keeps_previous_value_but_valid_rows_saved(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => 'bad/scss',
					'css_dir'  => 'bad/css',
				],
				[
					'scss_dir' => 'good/scss',
					'css_dir'  => 'good/css',
				],
			],
			[
				[
					'scss_dir' => 'old/scss',
					'css_dir'  => 'old/css',
				],
				[
					'scss_dir' => 'good/scss',
					'css_dir'  => 'good/css',
				],
			],
			static function ( string $path ): bool {
				return 'bad/scss' !== $path;
			},
			static function ( string $path ): bool {
				return 'bad/css' !== $path;
			}
		);

		self::assertSame(
			[
				[
					'scss_dir' => 'old/scss',
					'css_dir'  => 'old/css',
				],
				[
					'scss_dir' => 'good/scss',
					'css_dir'  => 'good/css',
				],
			],
			$result['pairs']
		);
		self::assertSame( 'bad_scss_dir_0', $result['errors'][0]['code'] );
		self::assertSame( 'bad_css_dir_0', $result['errors'][1]['code'] );
	}

	public function test_row_with_only_blank_scss_dir_is_dropped(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => '',
					'css_dir'  => 'assets/css',
				],
			],
			[],
			static function (): bool {
				return true;
			},
			static function (): bool {
				return true;
			}
		);

		self::assertSame( [], $result['pairs'] );
	}

	public function test_empty_input_yields_empty_pairs(): void {
		$result = SettingsPage::sanitizePairs(
			[],
			[],
			static function (): bool {
				return true;
			},
			static function (): bool {
				return true;
			}
		);

		self::assertSame( [], $result['pairs'] );
		self::assertSame( [], $result['errors'] );
	}
}
