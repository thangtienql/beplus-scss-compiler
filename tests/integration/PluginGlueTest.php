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
		$pair     = $settings['pairs'][0];
		$ref->invoke( $plugin, $settings, 0, SettingsPage::absPath( $pair['scss_dir'] ), SettingsPage::absPath( $pair['css_dir'] ) );

		self::assertFileExists( $theme . '/' . $rel . '/css/main.css' );
		self::assertSame( [ '0:main.css' ], get_option( Plugin::COMPILED_OPTION ) );

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
		$pair     = $settings['pairs'][0];
		$ref->invoke( $plugin, $settings, 0, SettingsPage::absPath( $pair['scss_dir'] ), SettingsPage::absPath( $pair['css_dir'] ) );

		$plugin->onEnqueueScripts();

		self::assertArrayHasKey( 'beplus-scss-0-main', wp_styles()->registered );

		self::rrmdir( $theme . '/' . $rel );
	}

	public function test_auto_compile_with_stored_fingerprints_does_not_fatal(): void {
		if ( is_admin() ) {
			$this->markTestSkipped( 'Frontend context required.' );
		}
		wp_set_current_user( 1 );
		$theme = get_stylesheet_directory();
		$rel   = $this->prepareThemeDirs();
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs'        => [
					[
						'scss_dir' => $rel . '/scss',
						'css_dir'  => $rel . '/css',
					],
				],
				'compile_mode' => 'auto',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => true,
			]
		);
		update_option( Plugin::FINGERPRINTS_OPTION, [ '0:main.scss' => 'stored-fingerprint' ] );

		$plugin = new Plugin();
		$plugin->onEnqueueScripts();

		self::assertArrayHasKey( 'beplus-scss-0-main', wp_styles()->registered );

		self::rrmdir( $theme . '/' . $rel );
	}

	public function test_two_pairs_compile_and_enqueue_distinct_handles(): void {
		if ( is_admin() ) {
			$this->markTestSkipped( 'Frontend context required.' );
		}
		$theme = get_stylesheet_directory();
		$rel   = 'beplus-glue-' . uniqid();
		mkdir( $theme . '/' . $rel . '/a/scss', 0777, true );
		mkdir( $theme . '/' . $rel . '/a/css', 0777, true );
		mkdir( $theme . '/' . $rel . '/b/scss', 0777, true );
		mkdir( $theme . '/' . $rel . '/b/css', 0777, true );
		file_put_contents( $theme . '/' . $rel . '/a/scss/main.scss', '$c: #fff; .x { color: $c; }' );
		file_put_contents( $theme . '/' . $rel . '/b/scss/main.scss', '$c: #000; .y { color: $c; }' );

		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs'        => [
					[
						'scss_dir' => $rel . '/a/scss',
						'css_dir'  => $rel . '/a/css',
					],
					[
						'scss_dir' => $rel . '/b/scss',
						'css_dir'  => $rel . '/b/css',
					],
				],
				'compile_mode' => 'manual',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => true,
			]
		);

		$plugin = new Plugin();
		$ref    = new \ReflectionMethod( $plugin, 'compileAllEntries' );
		$ref->setAccessible( true );
		$settings = SettingsPage::currentSettings();
		$pairA    = $settings['pairs'][0];
		$pairB    = $settings['pairs'][1];
		$ref->invoke( $plugin, $settings, 0, SettingsPage::absPath( $pairA['scss_dir'] ), SettingsPage::absPath( $pairA['css_dir'] ) );
		$ref->invoke( $plugin, $settings, 1, SettingsPage::absPath( $pairB['scss_dir'] ), SettingsPage::absPath( $pairB['css_dir'] ) );

		self::assertFileExists( $theme . '/' . $rel . '/a/css/main.css' );
		self::assertFileExists( $theme . '/' . $rel . '/b/css/main.css' );

		$plugin->onEnqueueScripts();
		self::assertArrayHasKey( 'beplus-scss-0-main', wp_styles()->registered );
		self::assertArrayHasKey( 'beplus-scss-1-main', wp_styles()->registered );

		self::rrmdir( $theme . '/' . $rel );
	}

	public function test_auto_compile_is_skipped_for_anonymous_visitors(): void {
		if ( is_admin() ) {
			$this->markTestSkipped( 'Frontend context required.' );
		}
		$theme = get_stylesheet_directory();
		$rel   = 'beplus-glue-' . uniqid();
		mkdir( $theme . '/' . $rel . '/scss', 0777, true );
		mkdir( $theme . '/' . $rel . '/css', 0777, true );
		file_put_contents( $theme . '/' . $rel . '/scss/main.scss', '$c: #fff; .x { color: $c; }' );
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs'        => [
					[
						'scss_dir' => $rel . '/scss',
						'css_dir'  => $rel . '/css',
					],
				],
				'compile_mode' => 'auto',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => true,
			]
		);
		update_option( Plugin::FINGERPRINTS_OPTION, [ '0:main.scss' => 'stored-fingerprint' ] );
		wp_set_current_user( 0 );

		$plugin = new Plugin();
		$plugin->onEnqueueScripts();

		self::assertFileDoesNotExist( $theme . '/' . $rel . '/css/main.css' );
		self::assertSame( [ '0:main.scss' => 'stored-fingerprint' ], get_option( Plugin::FINGERPRINTS_OPTION ) );
		self::assertArrayNotHasKey( 'beplus-scss-0-main', wp_styles()->registered );

		self::rrmdir( $theme . '/' . $rel );
	}

	public function test_auto_compile_runs_for_logged_in_admin(): void {
		if ( is_admin() ) {
			$this->markTestSkipped( 'Frontend context required.' );
		}
		$theme = get_stylesheet_directory();
		$rel   = 'beplus-glue-' . uniqid();
		mkdir( $theme . '/' . $rel . '/scss', 0777, true );
		mkdir( $theme . '/' . $rel . '/css', 0777, true );
		file_put_contents( $theme . '/' . $rel . '/scss/main.scss', '$c: #fff; .x { color: $c; }' );
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'pairs'        => [
					[
						'scss_dir' => $rel . '/scss',
						'css_dir'  => $rel . '/css',
					],
				],
				'compile_mode' => 'auto',
				'source_map'   => false,
				'minify'       => false,
				'enqueue'      => true,
			]
		);
		update_option( Plugin::FINGERPRINTS_OPTION, [ '0:main.scss' => 'stored-fingerprint' ] );
		wp_set_current_user( 1 );

		$plugin = new Plugin();
		$plugin->onEnqueueScripts();

		self::assertFileExists( $theme . '/' . $rel . '/css/main.css' );
		self::assertArrayHasKey( 'beplus-scss-0-main', wp_styles()->registered );

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
				'pairs'        => [
					[
						'scss_dir' => $rel . '/scss',
						'css_dir'  => $rel . '/css',
					],
				],
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
