<?php
/**
 * Ensure public REST routes have a permission_callback to satisfy WP >= 5.5.
 * Adjust $targets to the exact routes reported by Query Monitor.
 */
add_filter( 'rest_endpoints', function( $endpoints ) {

	$targets = array(
		'/intrkt/v1/oauth',           // Abandoned checkout plugin's route (adjust if different)
		// '/mpplugin/v1/rent-details',  // Example – add any other route QM shows as missing
	);

	foreach ( $targets as $route ) {
		if ( isset( $endpoints[ $route ] ) ) {
			foreach ( $endpoints[ $route ] as &$handler ) {
				if ( empty( $handler['permission_callback'] ) ) {
					$handler['permission_callback'] = '__return_true'; // public route
				}
			}
		}
	}

	return $endpoints;
} );
