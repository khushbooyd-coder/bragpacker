<?php
/**
 * Prevent double-updates of Booster/Jetpack XML cron time in the same request.
 * It stops the 2nd write and keeps the first one.
 */

add_filter( 'pre_update_option_wcj_create_products_xml_cron_time_1', function( $new, $old ) {
	static $already_wrote = false;
	if ( $already_wrote ) {
		// Returning $old short-circuits and skips the UPDATE.
		return $old;
	}
	$already_wrote = true;
	return $new;
}, 10, 2 );

// If you have other feeds (_2, _3, …) copy & adjust the filter:
foreach ( range( 2, 5 ) as $i ) {
	add_filter( "pre_update_option_wcj_create_products_xml_cron_time_{$i}", function( $new, $old ) {
		static $w = array();
		if ( isset( $w[ current_filter() ] ) ) {
			return $old;
		}
		$w[ current_filter() ] = true;
		return $new;
	}, 10, 2 );
}
