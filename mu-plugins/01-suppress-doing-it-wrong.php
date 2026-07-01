<?php
/**
 * Plugin Name: Bragpacker – Quiet Debug Helpers
 * Description: Silences specific doing_it_wrong notices and logs clean caller info for wp_add_inline_script misuse.
 */

/**
 * 1) Suppress doing_it_wrong PHP notice for:
 *    - wp_add_inline_script (people passing <script> tags)
 *    - wp_enqueue_script with deprecated 'accounting' handle (now 'wc-accounting')
 *    We only silence the NOTICE; nothing else changes.
 */
add_filter( 'doing_it_wrong_trigger_error', function( $trigger, $function, $message, $version ) {
    if ( $function === 'wp_add_inline_script' ) {
        return false; // hush the notice; we'll log our own details below
    }
    if ( $function === 'wp_enqueue_script' && strpos( $message ?? '', 'wc-accounting' ) !== false ) {
        return false; // hush the deprecation about 'accounting' handle
    }
    return $trigger;
}, 10, 4 );

add_action( 'doing_it_wrong_run', function( $function, $message, $version ) {
    if ( $function !== 'wp_add_inline_script' ) {
        return;
    }

    $req = ( $_SERVER['REQUEST_METHOD'] ?? '' ) . ' ' . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' );
    error_log( "wp_add_inline_script misuse on: {$req}" );

    if ( function_exists( 'wp_debug_backtrace_summary' ) ) {
        $summary = wp_debug_backtrace_summary( null, 0, false );
        if ( is_array( $summary ) ) {
            // Be robust across WP versions/hosts
            $summary = implode( ' | ', $summary );
        }
        error_log( "wp_add_inline_script misuse backtrace summary: {$summary}" );
    }

    $trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
    $caller = null;
    foreach ( $trace as $i => $frame ) {
        if ( isset( $frame['function'] ) && $frame['function'] === 'wp_add_inline_script' ) {
            $caller = $trace[ $i + 1 ] ?? null;
            break;
        }
    }

    $file = $caller['file'] ?? 'unknown';
    $line = $caller['line'] ?? 'unknown';

    // Try to surface the first non-core frame if the immediate caller is core
    $first_non_core = null;
    if ( is_array( $trace ) ) {
        foreach ( $trace as $frame ) {
            $f = $frame['file'] ?? '';
            if ( $f && strpos( $f, '/wp-includes/' ) === false && strpos( $f, '/wp-admin/' ) === false ) {
                $first_non_core = $frame;
                break;
            }
        }
    }

    if ( $first_non_core ) {
        error_log( sprintf(
            "Likely caller (first non-core): %s:%s in %s()",
            $first_non_core['file'] ?? 'unknown',
            $first_non_core['line'] ?? 'unknown',
            $first_non_core['function'] ?? 'unknown'
        ) );
    } else {
        error_log( "Likely caller of wp_add_inline_script(): {$file}:{$line}" );
    }
}, 10, 3 );
