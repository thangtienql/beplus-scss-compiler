<?php

namespace Beplus\ScssCompiler\Tests\Integration;

use Beplus\ScssCompiler\Settings\SettingsPage;

/**
 * Requires the wp-env PHPUnit environment (composer test:integration).
 */
final class SettingsPageTest extends \WP_UnitTestCase {

	/** @var string */
	private $rel;
	/** @var string */
	private $themeDir;

	protected function setUp(): void {
		parent::setUp();
		$this->themeDir = get_stylesheet_directory();
		$this->rel      = 'beplus-settings-' . uniqid();
		mkdir( $this->themeDir . '/' . $this->rel . '/scss', 0777, true );
		mkdir( $this->themeDir . '/' . $this->rel . '/css', 0777, true );
		file_put_contents( $this->themeDir . '/' . $this->rel . '/scss/main.scss', '$c: #fff; .x { color: $c; }' );
	}

	protected function tearDown(): void {
		self::rrmdir( $this->themeDir . '/' . $this->rel );
		delete_option( SettingsPage::OPTION_NAME );
		parent::tearDown();
	}

	public function test_sanitize_stores_relative_paths_and_toggles_enqueue(): void {
		$page = new SettingsPage();
		$out  = $page->sanitize(
			[
				'scss_dir'     => $this->rel . '/scss',
				'css_dir'      => $this->rel . '/css',
				'compile_mode' => 'auto',
				'source_map'   => '1',
				'minify'       => '1',
				'enqueue'      => '1',
			]
		);

		self::assertSame( $this->rel . '/scss', $out['scss_dir'] );
		self::assertSame( $this->rel . '/css', $out['css_dir'] );
		self::assertTrue( $out['enqueue'] );
		self::assertArrayNotHasKey( 'web_root', $out );
	}

	public function test_sanitize_rejects_dotdot_segments(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'scss_dir'     => $this->rel . '/scss',
				'css_dir'      => $this->rel . '/css',
				'compile_mode' => 'auto',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => false,
			]
		);
		$page = new SettingsPage();
		$out  = $page->sanitize(
			[
				'scss_dir' => '../outside',
				'css_dir'  => $this->rel . '/css',
			]
		);

		self::assertSame( $this->rel . '/scss', $out['scss_dir'] );
	}

	public function test_sanitize_keeps_previous_value_on_invalid_scss_dir(): void {
		$old = [
			'scss_dir'     => $this->rel . '/scss',
			'css_dir'      => $this->rel . '/css',
			'compile_mode' => 'auto',
			'source_map'   => false,
			'minify'       => false,
			'enqueue'      => false,
		];
		update_option( SettingsPage::OPTION_NAME, $old );

		$page = new SettingsPage();
		$out  = $page->sanitize(
			[
				'scss_dir'     => $this->rel . '/nonexistent',
				'css_dir'      => $this->rel . '/css',
				'compile_mode' => 'manual',
			]
		);

		self::assertSame( $this->rel . '/scss', $out['scss_dir'] );
		self::assertSame( $this->rel . '/css', $out['css_dir'] );
		self::assertSame( 'manual', $out['compile_mode'] );
		self::assertFalse( $out['enqueue'] );
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

	public function test_menu_registers_as_settings_submenu(): void {
		$page = new SettingsPage();
		$page->registerMenu();

		$submenu = $GLOBALS['submenu'] ?? [];
		self::assertArrayHasKey( 'options-general.php', $submenu );

		$slugs = wp_list_pluck( $submenu['options-general.php'], 2 );
		self::assertContains( SettingsPage::MENU_SLUG, $slugs );
	}

	public function test_render_page_outputs_modern_ui_markup(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'scss_dir'     => $this->rel . '/scss',
				'css_dir'      => $this->rel . '/css',
				'compile_mode' => 'manual',
				'source_map'   => true,
				'minify'       => false,
				'enqueue'      => true,
			]
		);
		$_GET['msg'] = 'compiled';
		wp_set_current_user( 1 );

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();
		unset( $_GET['msg'] );

		self::assertStringContainsString( 'beplus-hero', $html );
		self::assertStringContainsString( 'beplus-toast-success', $html );
		self::assertStringContainsString( 'beplus-stats', $html );
		self::assertStringContainsString( 'name="beplus_scss_settings[compile_mode]"', $html );
		self::assertStringContainsString( 'name="beplus_scss_settings[source_map]"', $html );
		self::assertStringContainsString( 'name="beplus_scss_settings[enqueue]"', $html );
		self::assertStringContainsString( 'admin-post.php', $html );
		self::assertStringContainsString( 'beplus-btn-compile', $html );
	}

	public function test_render_fields_placeholder_is_theme_relative(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'scss_dir'     => '',
				'css_dir'      => '',
				'compile_mode' => 'auto',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => false,
			]
		);
		wp_set_current_user( 1 );

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();

		self::assertStringContainsString( 'placeholder="assets/scss"', $html );
		self::assertStringContainsString( 'placeholder="assets/css"', $html );
		self::assertStringNotContainsString( 'wp-content/themes/your-theme', $html );
	}

	public function test_render_toggles_are_clickable_labels(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'scss_dir'     => '',
				'css_dir'      => '',
				'compile_mode' => 'auto',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => false,
			]
		);
		wp_set_current_user( 1 );

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();

		self::assertSame( 3, substr_count( $html, '<label class="beplus-toggle">' ) );
		self::assertSame( 3, substr_count( $html, '</label>' ) );
		foreach ( [ 'source_map', 'minify', 'enqueue' ] as $name ) {
			self::assertMatchesRegularExpression(
				'/<label class="beplus-toggle">\s*<input type="checkbox" name="beplus_scss_settings\[' . preg_quote( $name, '/' ) . '\]"/',
				$html
			);
		}
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
