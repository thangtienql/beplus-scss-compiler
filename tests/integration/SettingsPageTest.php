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
				'pairs'        => [
					[
						'scss_dir' => $this->rel . '/scss',
						'css_dir'  => $this->rel . '/css',
					],
				],
				'compile_mode' => 'auto',
				'source_map'   => '1',
				'minify'       => '1',
				'enqueue'      => '1',
			]
		);

		self::assertSame( $this->rel . '/scss', $out['pairs'][0]['scss_dir'] );
		self::assertSame( $this->rel . '/css', $out['pairs'][0]['css_dir'] );
		self::assertTrue( $out['enqueue'] );
		self::assertArrayNotHasKey( 'web_root', $out );
	}

	public function test_sanitize_rejects_dotdot_segments(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs'        => [
					[
						'scss_dir' => $this->rel . '/scss',
						'css_dir'  => $this->rel . '/css',
					],
				],
				'compile_mode' => 'auto',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => false,
			]
		);
		$page = new SettingsPage();
		$out  = $page->sanitize(
			[
				'pairs' => [
					[
						'scss_dir' => '../outside',
						'css_dir'  => $this->rel . '/css',
					],
				],
			]
		);

		self::assertSame( $this->rel . '/scss', $out['pairs'][0]['scss_dir'] );
	}

	public function test_sanitize_keeps_previous_value_on_invalid_scss_dir(): void {
		$old = [
			'pairs'        => [
				[
					'scss_dir' => $this->rel . '/scss',
					'css_dir'  => $this->rel . '/css',
				],
			],
			'compile_mode' => 'auto',
			'source_map'   => false,
			'minify'       => false,
			'enqueue'      => false,
		];
		update_option( SettingsPage::OPTION_NAME, $old );

		$page = new SettingsPage();
		$out  = $page->sanitize(
			[
				'pairs'        => [
					[
						'scss_dir' => $this->rel . '/nonexistent',
						'css_dir'  => $this->rel . '/css',
					],
				],
				'compile_mode' => 'manual',
			]
		);

		self::assertSame( $this->rel . '/scss', $out['pairs'][0]['scss_dir'] );
		self::assertSame( $this->rel . '/css', $out['pairs'][0]['css_dir'] );
		self::assertSame( 'manual', $out['compile_mode'] );
		self::assertFalse( $out['enqueue'] );
	}

	public function test_sanitize_multiple_pairs_and_skips_blank_rows(): void {
		$page = new SettingsPage();
		$out  = $page->sanitize(
			[
				'pairs' => [
					[
						'scss_dir' => $this->rel . '/scss',
						'css_dir'  => $this->rel . '/css',
					],
					[
						'scss_dir' => '',
						'css_dir'  => '',
					],
				],
			]
		);

		self::assertCount( 1, $out['pairs'] );
		self::assertSame( $this->rel . '/scss', $out['pairs'][0]['scss_dir'] );
	}

	public function test_current_settings_migrates_legacy_scss_css_keys(): void {
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

		$settings = SettingsPage::currentSettings();

		self::assertSame( $this->rel . '/scss', $settings['pairs'][0]['scss_dir'] );
		self::assertSame( $this->rel . '/css', $settings['pairs'][0]['css_dir'] );
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

	public function test_render_fields_renders_multiple_pair_rows_and_controls(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs'        => [
					[
						'scss_dir' => 'assets/scss',
						'css_dir'  => 'assets/css',
					],
					[
						'scss_dir' => 'blocks/scss',
						'css_dir'  => 'blocks/css',
					],
				],
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

		self::assertSame( 2, substr_count( $html, 'class="beplus-pair-row"' ) );
		self::assertStringContainsString( '>Pair 1<', $html );
		self::assertStringContainsString( '>Pair 2<', $html );
		self::assertStringContainsString( 'class="beplus-btn-remove"', $html );
		self::assertStringContainsString( 'name="beplus_scss_settings[pairs][0][scss_dir]"', $html );
		self::assertStringContainsString( 'name="beplus_scss_settings[pairs][0][css_dir]"', $html );
		self::assertStringContainsString( 'name="beplus_scss_settings[pairs][1][scss_dir]"', $html );
		self::assertStringContainsString( 'name="beplus_scss_settings[pairs][1][css_dir]"', $html );
		self::assertStringContainsString( 'id="beplus-add-pair"', $html );
		self::assertStringContainsString( 'data-remove', $html );
		self::assertStringContainsString( '__INDEX__', $html );
		self::assertStringContainsString( 'beplus-pair-template', $html );
	}

	public function test_stats_output_shows_single_css_dir(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs' => [
					[
						'scss_dir' => 'assets/scss',
						'css_dir'  => 'assets/css',
					],
				],
			]
		);
		wp_set_current_user( 1 );

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();

		self::assertMatchesRegularExpression( '/>assets\/css</', $html );
	}

	public function test_stats_output_shows_pair_count_when_multiple_pairs(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs' => [
					[
						'scss_dir' => 'assets/scss',
						'css_dir'  => 'assets/css',
					],
					[
						'scss_dir' => 'pages/scss',
						'css_dir'  => 'assets/css/pages',
					],
				],
			]
		);
		wp_set_current_user( 1 );

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();

		self::assertMatchesRegularExpression( '/>2 outputs</', $html );
		self::assertDoesNotMatchRegularExpression( '/>assets\/css</', $html );
	}

	public function test_render_page_renders_validation_errors_as_toasts(): void {
		wp_set_current_user( 1 );
		$_GET['settings-updated'] = 'true';
		set_transient(
			'settings_errors',
			[
				[
					'setting' => SettingsPage::OPTION_NAME,
					'code'    => 'bad_scss_dir',
					'message' => 'SCSS directory does not exist.',
					'type'    => 'error',
				],
			]
		);

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();
		unset( $_GET['settings-updated'] );

		self::assertStringContainsString( 'beplus-toast beplus-toast-error', $html );
		self::assertStringContainsString( 'SCSS directory does not exist.', $html );
		self::assertStringNotContainsString( 'notice-error', $html );
	}

	public function test_render_page_clears_settings_errors_transient(): void {
		wp_set_current_user( 1 );
		$_GET['settings-updated'] = 'true';
		set_transient(
			'settings_errors',
			[
				[
					'setting' => SettingsPage::OPTION_NAME,
					'code'    => 'bad_css_dir',
					'message' => 'CSS directory is not writable.',
					'type'    => 'error',
				],
			]
		);

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		ob_end_clean();
		unset( $_GET['settings-updated'] );

		self::assertFalse( get_transient( 'settings_errors' ) );
	}

	public function test_render_page_shows_settings_saved_toast(): void {
		wp_set_current_user( 1 );
		$_GET['settings-updated'] = 'true';
		set_transient(
			'settings_errors',
			[
				[
					'setting' => 'general',
					'code'    => 'settings_updated',
					'message' => 'Settings saved.',
					'type'    => 'success',
				],
			]
		);

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();
		unset( $_GET['settings-updated'] );

		self::assertStringContainsString( 'beplus-toast-success', $html );
		self::assertStringContainsString( 'Settings saved.', $html );
	}

	public function test_compile_toast_suppressed_when_validation_error_present(): void {
		wp_set_current_user( 1 );
		$_GET['msg']              = 'compiled';
		$_GET['settings-updated'] = 'true';
		set_transient(
			'settings_errors',
			[
				[
					'setting' => SettingsPage::OPTION_NAME,
					'code'    => 'bad_scss_dir',
					'message' => 'SCSS directory does not exist.',
					'type'    => 'error',
				],
			]
		);

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();
		unset( $_GET['msg'], $_GET['settings-updated'] );

		self::assertStringContainsString( 'beplus-toast beplus-toast-error', $html );
		self::assertStringContainsString( 'SCSS directory does not exist.', $html );
		self::assertStringNotContainsString( 'SCSS compiled successfully.', $html );
	}

	public function test_render_page_emits_msg_strip_script(): void {
		wp_set_current_user( 1 );

		$page = new SettingsPage();
		ob_start();
		$page->renderPage();
		$html = ob_get_clean();

		self::assertStringContainsString( 'searchParams.delete', $html );
		self::assertStringContainsString( 'history.replaceState', $html );
	}

	public function test_suppress_core_notices_only_on_this_screen(): void {
		add_action( 'admin_notices', 'settings_errors' );
		$page      = new SettingsPage();
		$other     = new \WP_Screen();
		$other->id = 'dashboard';
		$page->suppressCoreNotices( $other );
		self::assertGreaterThan( 0, has_action( 'admin_notices', 'settings_errors' ) );

		$mine     = new \WP_Screen();
		$mine->id = 'settings_page_' . SettingsPage::MENU_SLUG;
		$page->suppressCoreNotices( $mine );
		self::assertFalse( has_action( 'admin_notices', 'settings_errors' ) );
	}

	public function test_capture_settings_errors_clears_core_state(): void {
		set_current_screen( 'settings_page_' . SettingsPage::MENU_SLUG );
		set_transient(
			'settings_errors',
			[
				[
					'setting' => SettingsPage::OPTION_NAME,
					'code'    => 'bad_scss_dir',
					'message' => 'SCSS directory does not exist.',
					'type'    => 'error',
				],
			]
		);
		$GLOBALS['wp_settings_errors'] = [
			[
				'setting' => SettingsPage::OPTION_NAME,
				'code'    => 'bad_css_dir',
				'message' => 'CSS directory is not writable.',
				'type'    => 'error',
			],
		];

		$page = new SettingsPage();
		$page->captureSettingsErrors();

		self::assertFalse( get_transient( 'settings_errors' ) );
		self::assertSame( [], $GLOBALS['wp_settings_errors'] );
		$captured = $page->capturedErrors();
		self::assertSame( 'SCSS directory does not exist.', $captured[0]['message'] );
	}

	public function test_capture_does_not_run_on_other_screens(): void {
		set_current_screen( 'dashboard' );
		set_transient(
			'settings_errors',
			[
				[
					'setting' => 'x',
					'code'    => 'c',
					'message' => 'm',
					'type'    => 'error',
				],
			]
		);
		$GLOBALS['wp_settings_errors'] = [
			[
				'setting' => 'x',
				'code'    => 'c',
				'message' => 'm',
				'type'    => 'error',
			],
		];

		$page = new SettingsPage();
		$page->captureSettingsErrors();

		self::assertNotFalse( get_transient( 'settings_errors' ) );
		self::assertNotEmpty( $GLOBALS['wp_settings_errors'] );
	}

	public function test_render_page_uses_captured_errors(): void {
		wp_set_current_user( 1 );
		set_current_screen( 'settings_page_' . SettingsPage::MENU_SLUG );
		set_transient(
			'settings_errors',
			[
				[
					'setting' => SettingsPage::OPTION_NAME,
					'code'    => 'bad_scss_dir',
					'message' => 'SCSS directory does not exist.',
					'type'    => 'error',
				],
			]
		);

		$page = new SettingsPage();
		$page->captureSettingsErrors();

		ob_start();
		$page->renderPage();
		$html = ob_get_clean();

		self::assertStringContainsString( 'beplus-toast beplus-toast-error', $html );
		self::assertStringContainsString( 'SCSS directory does not exist.', $html );
		self::assertStringNotContainsString( 'notice-error', $html );
	}

	public function test_sanitize_rejects_duplicate_css_dir_and_adds_settings_error(): void {
		$page = new SettingsPage();
		mkdir( $this->themeDir . '/' . $this->rel . '/scss2', 0777, true );
		file_put_contents( $this->themeDir . '/' . $this->rel . '/scss2/main.scss', '$c: #000;' );

		$out = $page->sanitize(
			[
				'pairs' => [
					[
						'scss_dir' => $this->rel . '/scss',
						'css_dir'  => $this->rel . '/css',
					],
					[
						'scss_dir' => $this->rel . '/scss2',
						'css_dir'  => $this->rel . '/css',
					],
				],
			]
		);

		self::assertCount( 1, $out['pairs'] );
		self::assertSame( $this->rel . '/css', $out['pairs'][0]['css_dir'] );

		$errors = get_settings_errors( SettingsPage::OPTION_NAME );
		$codes  = array_column( $errors, 'code' );
		self::assertContains( 'duplicate_css_dir_1', $codes );
	}

	public function test_current_settings_dedupes_legacy_duplicate_pairs(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs' => [
					[
						'scss_dir' => $this->rel . '/scss',
						'css_dir'  => $this->rel . '/css',
					],
					[
						'scss_dir' => $this->rel . '/scss2',
						'css_dir'  => $this->rel . '/css',
					],
				],
			]
		);

		$settings = SettingsPage::currentSettings();

		self::assertCount( 1, $settings['pairs'] );
		self::assertSame( $this->rel . '/scss', $settings['pairs'][0]['scss_dir'] );
		self::assertSame( $this->rel . '/css', $settings['pairs'][0]['css_dir'] );
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
