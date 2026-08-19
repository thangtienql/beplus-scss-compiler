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
		parent::tearDown();
	}

	/**
	 * @requires PHPUNIT_INTEGRATION
	 */
	public function test_rewrite_rule_registers(): void {
		$plugin = new Plugin();
		$plugin->register();

		$rules = get_option( 'rewrite_rules' );
		self::assertIsArray( $rules );
		self::assertArrayHasKey( '^beplus-scss/([^/]+)$', $rules );
	}

	public function test_compile_now_action_exists(): void {
		$plugin = new Plugin();
		$plugin->register();

		self::assertGreaterThan( 0, has_action( 'admin_post_' . SettingsPage::COMPILE_ACTION ) );
	}

	public function test_activation_stores_version_and_flushes_rules(): void {
		$plugin = new Plugin();
		$plugin->activate();

		self::assertSame( Plugin::VERSION, get_option( Plugin::VERSION_OPTION ) );
	}
}
