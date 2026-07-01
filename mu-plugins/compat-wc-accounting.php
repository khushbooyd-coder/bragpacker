<?php
/*
Plugin Name: WC Accounting Compatibility + Checkout Button Guard
Description: Compatibility shim: alias old 'accounting' <-> 'wc-accounting' and ensure order button text exists.
Version: 1.0
Author: quick-fix
*/

add_action( 'wp_default_scripts', function( $wp_scripts ) {
    // If wc-accounting exists but accounting doesn't, copy it
    if ( isset( $wp_scripts->registered['wc-accounting'] ) && ! isset( $wp_scripts->registered['accounting'] ) ) {
        $old = $wp_scripts->registered['wc-accounting'];
        $wp_scripts->add( 'accounting', $old->src, $old->deps, $old->ver, $old->args );
    }
    // If accounting exists but wc-accounting doesn't, copy it
    if ( isset( $wp_scripts->registered['accounting'] ) && ! isset( $wp_scripts->registered['wc-accounting'] ) ) {
        $old = $wp_scripts->registered['accounting'];
        $wp_scripts->add( 'wc-accounting', $old->src, $old->deps, $old->ver, $old->args );
    }
}, 5 );

// Provide a guaranteed order button text so old templates don't fail
add_filter( 'woocommerce_order_button_text', function( $text ) {
    return $text ?: __( 'Place order', 'woocommerce' );
} );
