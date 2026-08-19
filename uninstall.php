<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'beplus_scss_settings' );
delete_option( 'beplus_scss_fingerprints' );
delete_option( 'beplus_scss_last_error' );
delete_option( 'beplus_scss_version' );
