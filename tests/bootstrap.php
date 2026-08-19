<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit suite: loads the Composer autoloader only (no WordPress).
 * Integration suite (inside wp-env): additionally loads the WP test
 * framework when the environment provides it.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( getenv( 'WP_TESTS_DIR' ) ) {
	$wp_tests = getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';
	if ( is_file( $wp_tests ) ) {
		require_once $wp_tests;
	}
}
