<?php
/**
 * Plugin Name: Beplus SCSS Compiler
 * Description: Compiles SCSS to CSS. Declare an SCSS source directory and a CSS destination directory in the admin; the plugin recompiles on change (auto) or on demand (manual), and can enqueue the compiled CSS.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Beplus
 * Author URI: https://profiles.wordpress.org/bearsthemes/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: beplus-scss
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BE_PLUS_SCSS_COMPILER_MAIN_FILE', __FILE__ );

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Beplus SCSS Compiler: run `composer install` in the plugin directory.', 'beplus-scss' ) . '</p></div>';
		}
	);

	return;
}

require_once __DIR__ . '/vendor/autoload.php';

( new \Beplus\ScssCompiler\Plugin() )->register();
