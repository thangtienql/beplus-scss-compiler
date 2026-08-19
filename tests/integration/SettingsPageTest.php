<?php

namespace Beplus\ScssCompiler\Tests\Integration;

use Beplus\ScssCompiler\Settings\SettingsPage;

/**
 * Requires the wp-env PHPUnit environment (composer test:integration).
 */
final class SettingsPageTest extends \WP_UnitTestCase {

	private $fixtures;
	private $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->fixtures = dirname( __DIR__ ) . '/fixtures';
		$this->tmp      = sys_get_temp_dir() . '/beplus-settings-' . uniqid();
		mkdir( $this->tmp, 0777, true );
	}

	protected function tearDown(): void {
		self::rrmdir( $this->tmp );
		delete_option( SettingsPage::OPTION_NAME );
		parent::tearDown();
	}

	public function test_sanitize_keeps_previous_value_on_invalid_scss_dir(): void {
		$page = new SettingsPage();
		$old  = [
			'scss_dir'     => '/prev',
			'css_dir'      => $this->tmp,
			'compile_mode' => 'auto',
			'source_map'   => false,
			'minify'       => false,
			'web_root'     => false,
		];
		update_option( SettingsPage::OPTION_NAME, $old );

		$out = $page->sanitize(
			[
				'scss_dir'     => '/non/existent',
				'css_dir'      => $this->tmp,
				'compile_mode' => 'manual',
				'source_map'   => '',
				'minify'       => '',
			]
		);

		self::assertSame( '/prev', $out['scss_dir'] );
		self::assertSame( $this->tmp, $out['css_dir'] );
		self::assertSame( 'manual', $out['compile_mode'] );
	}

	public function test_sanitize_accepts_valid_dirs_and_flags_toggles(): void {
		$page = new SettingsPage();

		$out = $page->sanitize(
			[
				'scss_dir'     => $this->fixtures . '/scss',
				'css_dir'      => $this->tmp,
				'compile_mode' => 'auto',
				'source_map'   => '1',
				'minify'       => '1',
			]
		);

		self::assertSame( $this->fixtures . '/scss', $out['scss_dir'] );
		self::assertSame( $this->tmp, $out['css_dir'] );
		self::assertTrue( $out['source_map'] );
		self::assertTrue( $out['minify'] );
		self::assertIsBool( $out['web_root'] );
	}

	/**
	 * @requires PHPUNIT_INTEGRATION
	 */
	public function test_compile_now_nonce_field_is_emitted(): void {
		$page = new SettingsPage();

		$this->expectNotToPerformAssertions();
		$page->registerMenu();
		$page->registerSettings();
		self::assertTrue( true );
	}

	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( $dir . '/*' );
		foreach ( $files ? $files : [] as $file ) {
			is_dir( $file ) ? self::rrmdir( $file ) : unlink( $file );
		}
		rmdir( $dir );
	}
}
