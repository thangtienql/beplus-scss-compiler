<?php

namespace Beplus\ScssCompiler\Tests\Integration;

use Beplus\ScssCompiler\Plugin;
use Beplus\ScssCompiler\Settings\SettingsPage;

/**
 * Requires the wp-env PHPUnit environment (composer test:integration).
 */
final class PluginGlueTest extends \WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( Plugin::VERSION_OPTION );
		delete_option( Plugin::COMPILED_OPTION );
		delete_option( Plugin::FINGERPRINTS_OPTION );
		delete_option( SettingsPage::OPTION_NAME );
		parent::tearDown();
	}

	public function test_compile_now_action_exists(): void {
		$plugin = new Plugin();
		$plugin->register();

		self::assertGreaterThan( 0, has_action( 'admin_post_' . SettingsPage::COMPILE_ACTION ) );
	}

	public function test_activation_stores_version(): void {
		$plugin = new Plugin();
		$plugin->activate();

		self::assertSame( Plugin::VERSION, get_option( Plugin::VERSION_OPTION ) );
	}

	public function test_compile_registers_compiled_files(): void {
		$rel   = $this->prepareThemeDirs();
		$theme = get_stylesheet_directory();

		$plugin = new Plugin();
		$ref    = new \ReflectionMethod( $plugin, 'compileAllEntries' );
		$ref->setAccessible( true );
		$settings = SettingsPage::currentSettings();
		$ref->invoke( $plugin, $settings, SettingsPage::absPath( $settings['scss_dir'] ), SettingsPage::absPath( $settings['css_dir'] ) );

		self::assertFileExists( $theme . '/' . $rel . '/css/main.css' );
		self::assertSame( [ 'main.css' ], get_option( Plugin::COMPILED_OPTION ) );

		self::rrmdir( $theme . '/' . $rel );
	}

	public function test_enqueue_gated_on_setting(): void {
		if ( is_admin() ) {
			$this->markTestSkipped( 'Frontend context required.' );
		}
		$theme = get_stylesheet_directory();
		$rel   = $this->prepareThemeDirs();

		$plugin = new Plugin();
		$ref    = new \ReflectionMethod( $plugin, 'compileAllEntries' );
		$ref->setAccessible( true );
		$settings = SettingsPage::currentSettings();
		$ref->invoke( $plugin, $settings, SettingsPage::absPath( $settings['scss_dir'] ), SettingsPage::absPath( $settings['css_dir'] ) );

		$plugin->onEnqueueScripts();

		self::assertArrayHasKey( 'beplus-scss-main', wp_styles()->registered );

		self::rrmdir( $theme . '/' . $rel );
	}

	public function test_auto_compile_with_stored_fingerprints_does_not_fatal(): void {
		if ( is_admin() ) {
			$this->markTestSkipped( 'Frontend context required.' );
		}
		$theme = get_stylesheet_directory();
		$rel   = $this->prepareThemeDirs();
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'scss_dir'     => $rel . '/scss',
				'css_dir'      => $rel . '/css',
				'compile_mode' => 'auto',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => true,
			]
		);
		update_option( Plugin::FINGERPRINTS_OPTION, [ 'main.scss' => 'stored-fingerprint' ] );

		$plugin = new Plugin();
		$plugin->onEnqueueScripts();

		self::assertArrayHasKey( 'beplus-scss-main', wp_styles()->registered );

		self::rrmdir( $theme . '/' . $rel );
	}

	private function prepareThemeDirs(): string {
		$theme = get_stylesheet_directory();
		$rel   = 'beplus-glue-' . uniqid();
		mkdir( $theme . '/' . $rel . '/scss', 0777, true );
		mkdir( $theme . '/' . $rel . '/css', 0777, true );
		file_put_contents( $theme . '/' . $rel . '/scss/main.scss', '$c: #fff; .x { color: $c; }' );

		update_option(
			SettingsPage::OPTION_NAME,
			[
				'scss_dir'     => $rel . '/scss',
				'css_dir'      => $rel . '/css',
				'compile_mode' => 'manual',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => true,
			]
		);

		return $rel;
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
