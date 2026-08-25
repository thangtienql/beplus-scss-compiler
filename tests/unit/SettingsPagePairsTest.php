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

	public function test_duplicate_css_dir_is_rejected_on_subsequent_pair(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'admin/scss',
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

		self::assertSame(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
			],
			$result['pairs']
		);
		self::assertCount( 1, $result['errors'] );
		self::assertSame( 1, $result['errors'][0]['index'] );
		self::assertSame( 'duplicate_css_dir_1', $result['errors'][0]['code'] );
	}

	public function test_duplicate_css_dir_reverts_to_previous_pair_if_available(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'assets/css',
				],
			],
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'admin/css',
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
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'admin/css',
				],
			],
			$result['pairs']
		);
		self::assertCount( 1, $result['errors'] );
		self::assertSame( 'duplicate_css_dir_1', $result['errors'][0]['code'] );
	}

	public function test_unique_css_dirs_across_pairs_are_accepted(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'theme/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'admin/css',
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

		self::assertCount( 2, $result['pairs'] );
		self::assertSame( [], $result['errors'] );
	}

	public function test_duplicate_revert_that_reintroduces_duplicate_drops_row(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'assets/css',
				],
			],
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'theme/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'assets/css',
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
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
			],
			$result['pairs']
		);
		self::assertSame( 'duplicate_css_dir_1', $result['errors'][0]['code'] );
	}

	public function test_invalid_row_revert_that_reintroduces_duplicate_drops_row(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'bad/css',
				],
			],
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'assets/css',
				],
			],
			static function (): bool {
				return true;
			},
			static function ( string $path ): bool {
				return 'bad/css' !== $path;
			}
		);

		self::assertSame(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
			],
			$result['pairs']
		);
		self::assertSame( 'bad_css_dir_1', $result['errors'][0]['code'] );
	}

	public function test_duplicate_css_dir_comparison_is_case_insensitive(): void {
		$result = SettingsPage::sanitizePairs(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'admin/scss',
					'css_dir'  => 'assets/CSS',
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

		self::assertCount( 1, $result['pairs'] );
		self::assertSame( 'duplicate_css_dir_1', $result['errors'][0]['code'] );
	}

	public function test_stored_pairs_dedupe_duplicate_css_dirs_keeping_first(): void {
		$method = new \ReflectionMethod( SettingsPage::class, 'storedPairs' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$result = $method->invoke(
			null,
			[
				'pairs' => [
					[
						'scss_dir' => 'theme/scss',
						'css_dir'  => 'assets/css',
					],
					[
						'scss_dir' => 'admin/scss',
						'css_dir'  => 'assets/CSS',
					],
					[
						'scss_dir' => 'other/scss',
						'css_dir'  => 'other/css',
					],
				],
			]
		);

		self::assertSame(
			[
				[
					'scss_dir' => 'theme/scss',
					'css_dir'  => 'assets/css',
				],
				[
					'scss_dir' => 'other/scss',
					'css_dir'  => 'other/css',
				],
			],
			$result
		);
	}
}
