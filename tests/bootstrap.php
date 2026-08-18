<?php
/**
 * PHPUnit bootstrap: loads the Composer autoloader for unit tests.
 *
 * Pure-layer unit tests never load WordPress. When the integration suite runs
 * inside `wp-env`, the WordPress test-framework bootstrap is loaded here too —
 * that wiring is added during the integration phase.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
