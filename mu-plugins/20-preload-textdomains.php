<?php
/**
 * Preload plugin translations early so JIT loader doesn't run before 'init'.
 * Fixes "_load_textdomain_just_in_time called too early" notices.
 */
add_action( 'muplugins_loaded', function () {
	$domains = array(
		'woocommerce-jetpack',   // Booster
		'woocommerce',           // WooCommerce (rarely needed but harmless)
		'health-check',          // Health Check & Troubleshooting
		'api-manager-wct_text',  // WooCommerce Custom Tabs Pro (its updater textdomain)
	);

	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

	foreach ( $domains as $domain ) {
		// Typical WP location for plugin .mo files:
		$mofile = WP_LANG_DIR . "/plugins/{$domain}-{$locale}.mo";
		if ( file_exists( $mofile ) ) {
			load_textdomain( $domain, $mofile );
		}
	}
}, 0 );
