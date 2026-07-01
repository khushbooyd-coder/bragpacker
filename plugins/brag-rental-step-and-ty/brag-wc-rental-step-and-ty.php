<?php
/**
 * Plugin Name: Brag – Rental 2-Step + Thank-You Routing (MU)
 * Description: For rental orders: keep status Pending and send only "Booking Confirmation Pending" email on creation; admin later sends invoice manually. Paid flow remains default; TY routes unpaid→Inquiry, paid→Renting.
 * Version: 1.0.0
 * Author: Brag
 */

/* -----------------------
 * Helpers (with fallback)
 * ----------------------- */
if ( ! function_exists( 'bpr_is_rental_product_fallback' ) ) {
	function bpr_is_rental_product_fallback( $product_or_id ) {
		$p = is_numeric( $product_or_id ) ? wc_get_product( (int) $product_or_id ) : $product_or_id;
		if ( ! $p || ! is_a( $p, 'WC_Product' ) ) return false;
		$id = (int) $p->get_id();

		// BKAP flags
		if ( 'on' === get_post_meta( $id, '_bkap_enable_booking', true ) ) return true;
		if ( '' !== get_post_meta( $id, '_bkap_has_price', true ) ) return true;

		// Soft signals
		if ( has_term( array( 'rental','rent','rent-gear','rent-a-gopro' ), 'product_cat', $id ) ) return true;
		if ( has_term( 'rental', 'product_tag', $id ) ) return true;
		if ( 'yes' === get_post_meta( $id, 'bp_has_end_date', true ) ) return true;

		return false;
	}
}

if ( ! function_exists( 'bpr_is_rental_order' ) ) {
	function bpr_is_rental_order( WC_Order $order ) : bool {
		if ( function_exists( 'bp_is_rental_order' ) ) return bp_is_rental_order( $order );

		$has_items = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$has_items = true;
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				$pid = (int) $item->get_product_id();
				$vid = (int) $item->get_variation_id();
				$product = $vid ? wc_get_product( $vid ) : ( $pid ? wc_get_product( $pid ) : null );
			}
			if ( ! $product instanceof WC_Product ) return false;

			if ( function_exists( 'bp_is_rental_product' ) ) {
				if ( ! bp_is_rental_product( $product ) ) return false;
			} else {
				if ( ! bpr_is_rental_product_fallback( $product ) ) return false;
			}
		}
		return $has_items;
	}
}

/* ==========================================================
 * A) Step 1: On rental order creation → keep Pending
 *    and send ONLY "Booking Confirmation Pending" email.
 *    (No invoice/payment link here — admin will send later.)
 * ========================================================== */
add_action( 'woocommerce_new_order', 'bpr_rental_mark_pending_and_send_booking_mail', 25, 2 );
add_action( 'woocommerce_thankyou',  'bpr_rental_mark_pending_and_send_booking_mail', 10, 1 ); // safety

function bpr_rental_mark_pending_and_send_booking_mail( $order_id, $maybe_order = null ) {
	$order = ( $maybe_order instanceof WC_Order ) ? $maybe_order : wc_get_order( $order_id );
	if ( ! $order ) return;

	// Only handle rental-only
	if ( ! bpr_is_rental_order( $order ) ) return;

	// If already paid (rare at creation), do nothing here
	if ( $order->is_paid() ) return;

	// Ensure Pending status (enquiry step)
	if ( $order->get_status() !== 'pending' ) {
		$order->update_status( 'pending', 'Rental enquiry – set to Pending payment.' );
	}

	// Send BKAP "Booking Confirmation Pending" once
	if ( ! $order->get_meta( '_bpr_booking_pending_mail_sent' ) ) {
		$sent   = false;
		$mailer = WC()->mailer();
		if ( $mailer ) {
			$emails = method_exists( $mailer, 'get_emails' ) ? $mailer->get_emails() : array();

			// 1) Try BKAP built-in (id: bkap_customer_pending_booking)
			if ( isset( $emails['bkap_customer_pending_booking'] ) && is_object( $emails['bkap_customer_pending_booking'] ) ) {
				$bk = $emails['bkap_customer_pending_booking'];
				if ( method_exists( $bk, 'trigger' ) ) {
					try { $bk->trigger( $order->get_id() ); $sent = true; } catch ( \Throwable $e ) {}
				}
			}

			// 2) Fallback to your custom template
			if ( ! $sent ) {
				$to = $order->get_billing_email();
				if ( $to ) {
					$subject = sprintf( 'Bragpacker.com: Your Order Inquiry (Order %s)', $order->get_order_number() );
					$heading = 'Booking Confirmation Pending';
					$body = wc_get_template_html(
						'emails/customer-pending-confirmation.php',
						array(
							'order'         => $order,
							'email_heading' => $heading,
							'sent_to_admin' => false,
							'plain_text'    => false,
							'email'         => null,
						)
					);
					$sent = $mailer->send( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
				}
			}
		}

		if ( $sent ) {
			$order->update_meta_data( '_bpr_booking_pending_mail_sent', current_time( 'mysql' ) );
			$order->add_order_note( 'Booking Confirmation Pending email sent to customer.' );
			$order->save();
		}
	}

	
	// IMPORTANT: Do NOT send invoice here.
	// Admin will send "Customer invoice / Order details" later from order actions.
	
// ----------------------
// Admin notification for rental enquiry (single-shot) to info@bragpacker.com
// Includes billing city and attempts to show rental start/end dates per item.
// Sends raw HTML (no Woo wrap/footer).
// ----------------------
if ( ! $order->get_meta( '_bpr_admin_enquiry_mail_sent' ) ) {

    $raw_recip = 'info@bragpacker.com';
    $admin_recipient = is_email( trim( $raw_recip ) ) ? trim( $raw_recip ) : '';

    if ( $admin_recipient ) {

        // Helper: try to extract start/end from an order item (best-effort)
        $extract_dates = function( $item ) {
            $start = $end = '';

            // 1) If item has structured booking meta arrays (BKAP often stores this)
            $possible_array_keys = array( 'booking', 'bkap_booking', '_bkap_booking' );
            foreach ( $possible_array_keys as $k ) {
                $val = $item->get_meta( $k, true );
                if ( is_array( $val ) ) {
                    // common naming variants inside the array
                    $candidates = array( 'start', 'Start Date', 'Start', 'start_date', 'date', 'from', 'pickup_date' );
                    $candidates_end = array( 'end', 'End Date', 'End', 'end_date', 'to', 'dropoff_date' );
                    foreach ( $candidates as $ck ) {
                        if ( isset( $val[$ck] ) && $val[$ck] !== '' ) { $start = (string) $val[$ck]; break; }
                    }
                    foreach ( $candidates_end as $ck ) {
                        if ( isset( $val[$ck] ) && $val[$ck] !== '' ) { $end = (string) $val[$ck]; break; }
                    }
                    if ( $start || $end ) return array( $start, $end );
                }
            }

            // 2) Look for simple meta keys on the item itself
            $meta_keys = array( 'Start Date','End Date','start_date','end_date','rent_from','rent_to','pickup_date','dropoff_date','date' );
            foreach ( $meta_keys as $mk ) {
                if ( ! $start ) {
                    $v = $item->get_meta( $mk, true );
                    if ( $v ) $start = (string) $v;
                }
                if ( ! $end ) {
                    $v = $item->get_meta( $mk, true );
                    if ( $v && stripos($mk,'end') !== false || stripos($mk,'to') !== false || stripos($mk,'dropoff') !== false ) {
                        $end = (string) $v;
                    }
                }
            }

            // 3) As a last attempt, scan all item meta for date-like strings (YYYY or / or - or digits)
            if ( ! $start && ! $end ) {
                foreach ( $item->get_meta_data() as $md ) {
                    $k = (string) $md->key;
                    $v = (string) $md->value;
                    if ( ! $start && preg_match('/\d{4}|\d{1,2}\/\d{1,2}\/\d{2,4}|\d{1,2}-\d{1,2}-\d{2,4}/', $v) ) {
                        $start = $v;
                    } elseif ( $start && ! $end && preg_match('/\d{4}|\d{1,2}\/\d{1,2}\/\d{2,4}|\d{1,2}-\d{1,2}-\d{2,4}/', $v) ) {
                        $end = $v;
                    }
                }
            }

            return array( $start, $end );
        };

        // City: billing -> shipping fallback
        $city = $order->get_billing_city();
        if ( empty( $city ) ) $city = $order->get_shipping_city();

        $mailer  = WC()->mailer();
        $subject = sprintf( 'New Rental Enquiry — Order %s', $order->get_order_number() );
        $heading = 'New Rental Enquiry';

        $admin_link = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );

        // Build HTML body (no wrap_message so no Woo footer/header)
        $body  = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.4;color:#111;">';
        $body .= '<h2 style="margin:0 0 8px;">' . esc_html( $heading ) . '</h2>';
        $body .= '<p style="margin:0 0 10px;">A new rental enquiry has been created on your store.</p>';
        $body .= '<p style="margin:0 0 6px;"><strong>Order:</strong> #' . $order->get_order_number() . ' — <a href="' . esc_url( $admin_link ) . '">Open in admin</a></p>';
        $body .= '<p style="margin:0 0 6px;"><strong>Customer:</strong> ' . esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ) . '</p>';
        $body .= '<p style="margin:0 0 6px;"><strong>Email:</strong> ' . esc_html( $order->get_billing_email() ) . '</p>';
        $body .= '<p style="margin:0 0 10px;"><strong>Phone:</strong> ' . esc_html( $order->get_billing_phone() ) . '</p>';

        if ( $city ) {
            $body .= '<p style="margin:0 0 10px;"><strong>City:</strong> ' . esc_html( $city ) . '</p>';
        }

        $body .= '<p style="margin:0 0 8px;"><strong>Order total:</strong> ' . wc_price( $order->get_total() ) . '</p>';

        // Items + rental dates
        $body .= '<h4 style="margin:10px 0 6px;">Items & Rental Dates</h4>';
        $body .= '<ul style="margin:0 0 12px 18px;padding:0;">';
        foreach ( $order->get_items() as $item ) {
            $prod_name = $item->get_name();
            $qty       = $item->get_quantity();

            list( $s, $e ) = $extract_dates( $item );

            $dates = '';
            if ( $s && $e ) {
                $dates = ' — ' . esc_html( $s ) . ' to ' . esc_html( $e );
            } elseif ( $s && ! $e ) {
                $dates = ' — Start: ' . esc_html( $s );
            } elseif ( ! $s && $e ) {
                $dates = ' — End: ' . esc_html( $e );
            }

            $body .= '<li>' . esc_html( $prod_name ) . ' × ' . intval( $qty ) . esc_html( $dates ) . '</li>';
        }
        $body .= '</ul>';

        // Additional order notes / customer note if present
        $cust_note = $order->get_customer_note();
        if ( $cust_note ) {
            $body .= '<h4 style="margin:8px 0 6px;">Customer note</h4>';
            $body .= '<p style="margin:0 0 10px;">' . nl2br( esc_html( $cust_note ) ) . '</p>';
        }

        $body .= '</div>';

        // Send raw HTML via WC mailer if possible, else wp_mail as fallback
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        try {
            $mail_ok = false;
            if ( is_object( $mailer ) && method_exists( $mailer, 'send' ) ) {
                // send raw HTML so we don't get Woo's footer/header
                $mail_ok = $mailer->send( $admin_recipient, $subject, $body, $headers );
            }

            if ( ! $mail_ok ) {
                // fallback to wp_mail (plain text fallback)
                $plain = wp_strip_all_tags( preg_replace('/\s+/', ' ', $body) );
                $mail_ok = wp_mail( $admin_recipient, $subject, $plain );
            }

            if ( $mail_ok ) {
                $order->update_meta_data( '_bpr_admin_enquiry_mail_sent', current_time( 'mysql' ) );
                $order->add_order_note( 'Admin rental-enquiry notification sent to ' . $admin_recipient . '.' );
                $order->save();
            } else {
                error_log( '[BKP ADMIN ENQUIRY] Email send failed for order ' . $order->get_order_number() );
            }
        } catch ( Throwable $e ) {
            error_log( '[BKP ADMIN ENQUIRY] Exception sending admin mail: ' . $e->getMessage() );
        }
    } else {
        error_log( '[BKP ADMIN ENQUIRY] Invalid admin recipient: ' . var_export( $raw_recip, true ) );
    }
}


}

/* ======================================================================================
 * B) DO NOT block paid rentals later: when payment completes via gateway,
 *    let Woo set processing/completed. If no gateway, keep Pending.
 * ====================================================================================== */
add_filter( 'woocommerce_payment_complete_order_status', function( $status, $order_id, $order ) {
	if ( ! $order instanceof WC_Order ) $order = wc_get_order( $order_id );
	if ( ! $order ) return $status;
	if ( ! bpr_is_rental_order( $order ) ) return $status;

	$has_gateway = (string) $order->get_payment_method() !== '';
	if ( ! $has_gateway && ! $order->is_paid() ) {
		// Still the enquiry path (no gateway) → stay Pending
		return 'pending';
	}

	// Paid path → use Woo default paid status
	return $order->needs_processing() ? 'processing' : 'completed';
}, 999, 3 );

/* ======================================================================================
 * C) COD fixer: if rental order hits order-received with COD and still pending,
 *    bump to 'on-hold' so it counts as "paid enough" for the Renting TY page.
 *    (Runs BEFORE the main router.)
 * ====================================================================================== */
add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() ) return;
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) return;

	// Resolve order
	$order     = null;
	$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
	if ( $order_key ) {
		$oid   = wc_get_order_id_by_order_key( $order_key );
		$order = $oid ? wc_get_order( $oid ) : null;
	}
	if ( ! $order && get_query_var( 'order-received' ) ) {
		$oid   = absint( get_query_var( 'order-received' ) );
		$order = $oid ? wc_get_order( $oid ) : null;
	}
	if ( ! $order ) return;

	// Only rental-only orders
	if ( ! bpr_is_rental_order( $order ) ) return;

	// If gateway is COD and status is still pending, upgrade to on-hold
	$pm = (string) $order->get_payment_method();
	if ( $pm === 'cod' && $order->has_status( 'pending' ) ) {
		$order->update_status( 'on-hold', 'COD placed – set to On hold for fulfillment.' );
		$order->save();
	}
}, -2100 );

/* =======================================================================
 * D) Thank-You routing (single pass on order-received):
 *    unpaid → /thank-you-for-your-order-inquiry/?order_id=ID
 *    paid   → /thank-you-for-renting/?order_id=ID
 * ======================================================================= */
add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() ) return;
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) return;

	// Loop guard
	if ( isset( $_GET['bp_routed'] ) ) return;

	// Resolve order
	$order     = null;
	$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
	if ( $order_key ) {
		$oid   = wc_get_order_id_by_order_key( $order_key );
		$order = $oid ? wc_get_order( $oid ) : null;
	}
	if ( ! $order && get_query_var( 'order-received' ) ) {
		$oid   = absint( get_query_var( 'order-received' ) );
		$order = $oid ? wc_get_order( $oid ) : null;
	}
	if ( ! $order ) return;

	if ( ! bpr_is_rental_order( $order ) ) return;

	$status  = $order->get_status();

	// Treat these as "paid enough" for routing to Renting TY:
	// - is_paid() true (online success)
	// - processing (standard paid)
	// - completed (fully done)
	// - on-hold (common for COD gateways)
	$is_paid = $order->is_paid() || in_array( $status, array( 'processing', 'completed', 'on-hold' ), true );

	$dest = $is_paid
		? home_url( '/thank-you-for-renting/?order_id=' . $order->get_id() )
		: home_url( '/thank-you-for-your-order-inquiry/?order_id=' . $order->get_id() );

	// Clear cart only if this TY belongs to this session order
	if ( function_exists( 'WC' ) && WC()->cart && $order_key && $order_key === $order->get_order_key() ) {
		WC()->cart->empty_cart();
		if ( WC()->session ) {
			WC()->session->__unset( 'cart_totals' );
			WC()->session->__unset( 'applied_coupons' );
		}
	}

	wp_safe_redirect( add_query_arg( 'bp_routed', '1', $dest ), 302 );
	exit;
}, -2000 );

//cancelled order
// Send the proper Customer "cancelled" email and log it.
add_action( 'woocommerce_order_status_cancelled', function( $order_id, $order = null ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order ) { error_log("[BKP CANCELLED EMAIL] No order for ID {$order_id}"); return; }

	// Prevent duplicates if something else also tries.
	if ( $order->get_meta( '_bkp_cancelled_email_sent' ) ) {
		error_log("[BKP CANCELLED EMAIL] Already sent for " . $order->get_order_number());
		return;
	}

	// Make sure Woo's mailer is ready
	$mailer = WC()->mailer();
	if ( ! $mailer ) { error_log('[BKP CANCELLED EMAIL] WC()->mailer() missing'); return; }

	// Force-enable this email type & ensure recipient is the billing email
	add_filter( 'woocommerce_email_enabled_customer_cancelled_order', '__return_true', 99 );
	add_filter( 'woocommerce_email_recipient_customer_cancelled_order', function( $recipient, $email_obj ) use ( $order ) {
		return $order->get_billing_email() ?: $recipient;
	}, 99, 2 );

	// Find the class safely across versions
	$emails = $mailer->get_emails();
	$customer_cancelled = null;
	foreach ( (array) $emails as $e ) {
		if ( $e instanceof WC_Email && is_a( $e, 'WC_Email_Customer_Cancelled_Order' ) ) {
			$customer_cancelled = $e;
			break;
		}
	}
	if ( ! $customer_cancelled ) { error_log('[BKP CANCELLED EMAIL] Class not found'); return; }

	error_log('[BKP CANCELLED EMAIL] Triggering for order ' . $order->get_order_number());
	try {
		$customer_cancelled->trigger( $order_id, $order );
		$order->update_meta_data( '_bkp_cancelled_email_sent', time() );
		$order->save();
	} catch ( Throwable $t ) {
		error_log('[BKP CANCELLED EMAIL] Exception: ' . $t->getMessage());
	}
}, 20, 2 );

// Never send Customer Invoice for cancelled orders.
add_filter( 'woocommerce_email_enabled_customer_invoice', function( $enabled, $email, $order ) {
	if ( $order instanceof WC_Order && $order->has_status( 'cancelled' ) ) {
		error_log('[BKP EMAIL] Blocked customer_invoice for CANCELLED order ' . $order->get_order_number());
		return false;
	}
	return $enabled;
}, 99, 3 );

//email debug
add_filter( 'woocommerce_email_send', function( $send, $email_object, $email_content ) {
	$id   = property_exists( $email_object, 'id' ) ? $email_object->id : get_class( $email_object );
	$oid  = $email_object->object instanceof WC_Order ? $email_object->object->get_order_number() : 'n/a';
	error_log("[BKP EMAIL] Sending id={$id} for order={$oid}");
	return $send;
}, 10, 3 );

