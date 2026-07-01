<?php
/**
 * Plugin Name: Bragpacker Cart UX (Unified & Optimized)
 * Description: Split checkout (Buy vs Rental), block mixed carts, correct checkout routing, reliable Buy Now (AJAX + non-AJAX), side-cart routing via Woo fragments (with AJAX fallback), qty steppers (checkout + side cart), rental checkout UX, and refundable deposit line.
 * Author: Khushboo Dahat
 * Version: 1.4.0
 */

if (!defined('ABSPATH')) exit;

/* =============================================================================
 * CONSTANTS
 * ========================================================================== */
if (!defined('BP_BUY_ROOT_SLUG'))       define('BP_BUY_ROOT_SLUG',      'bragpacker-buy-store');
if (!defined('BP_BUY_CHECKOUT_SLUG'))   define('BP_BUY_CHECKOUT_SLUG',  'buy-checkout');
if (!defined('BP_RENT_CHECKOUT_SLUG'))  define('BP_RENT_CHECKOUT_SLUG', 'rental-checkout');

/* =============================================================================
 * RUNTIME GUARDS
 * ========================================================================== */
function bp_cartux_env_ready_for_cart(){
	if (is_admin() && !wp_doing_ajax()) return false;
	if (!did_action('wp_loaded')) return false;
	if (!did_action('woocommerce_init')) return false;
	if (!function_exists('WC') || !WC()->cart) return false;
	return true;
}

/* =============================================================================
 * HELPERS
 * ========================================================================== */
function bp_cartux_base_product_id($product_id){
	$parent = wp_get_post_parent_id($product_id);
	return $parent ?: $product_id;
}

/**
 * BUY detector:
 * A) True if product is under the root BUY category (BP_BUY_ROOT_SLUG)
 * B) Else, if it's NOT a BKAP booking product, treat as BUY
 */
function bp_cartux_is_buy_product($product_id){
	$pid  = bp_cartux_base_product_id($product_id);
	$root = get_term_by('slug', BP_BUY_ROOT_SLUG, 'product_cat');

	// A) Category tree rule
	if ($root && !is_wp_error($root)){
		$terms = wp_get_post_terms($pid, 'product_cat', ['fields' => 'ids']);
		if (!is_wp_error($terms) && $terms){
			$root_id = (int) $root->term_id;
			foreach ($terms as $tid){
				$tid = (int) $tid;
				if ($tid === $root_id) return true;
				$anc = get_ancestors($tid, 'product_cat');
				if (in_array($root_id, array_map('intval', $anc), true)) return true;
			}
		}
	}

	// B) BKAP fallback: non-booking → BUY
	$bkap_type    = get_post_meta($pid, '_bkap_booking_type', true);
	$rental_types = ['multiple_days','date_time'];
	if (empty($bkap_type) || !in_array($bkap_type, $rental_types, true)){
		return true;
	}
	return false;
}

/** 'buy' | 'rental' | 'mixed' | 'empty' */
function bp_cartux_cart_type(){
	if (!bp_cartux_env_ready_for_cart()) return 'empty';

	WC()->cart->get_cart();
	if (WC()->cart->is_empty()) return 'empty';

	$has_buy = false; $has_rental = false;
	foreach (WC()->cart->get_cart() as $ci){
		if (empty($ci['data'])) { $has_rental = true; continue; }
		$p   = $ci['data'];
		$pid = $p->is_type('variation') ? $p->get_parent_id() : $p->get_id();
		if (bp_cartux_is_buy_product($pid)) $has_buy = true; else $has_rental = true;
		if ($has_buy && $has_rental) return 'mixed';
	}
	return $has_buy ? 'buy' : 'rental';
}

function bp_cartux_checkout_url_for_cart(){
	if (!bp_cartux_env_ready_for_cart()){
		$checkout_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('checkout') : 0;
		return $checkout_page_id ? get_permalink($checkout_page_id) : home_url('/checkout/');
	}
	$type = bp_cartux_cart_type();
	if ($type === 'buy')    return home_url('/'.BP_BUY_CHECKOUT_SLUG.'/');
	if ($type === 'rental') return home_url('/'.BP_RENT_CHECKOUT_SLUG.'/');
	return wc_get_checkout_url();
}

/* =============================================================================
 * 1) BLOCK MIXED CARTS — GLOBAL
 *    Also stash a one-shot message in Woo session.
 * ========================================================================== */
add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $qty, $variation_id = 0){
	if (!$passed) return false;

	$type = bp_cartux_cart_type();
	if ($type === 'empty') return true;

	$incoming_id     = bp_cartux_base_product_id($variation_id ?: $product_id);
	$incoming_is_buy = bp_cartux_is_buy_product($incoming_id);

	if ($type === 'buy' && !$incoming_is_buy){
		$msg = 'You already have Buy items in your cart. Rental items can\'t be mixed. Please clear the cart or finish your Buy checkout first.';
		if (isset(WC()->session)) WC()->session->set('bp_cartux_block_msg', $msg);
		wc_add_notice($msg, 'error');
		return false;
	}
	if ($type === 'rental' && $incoming_is_buy){
		$msg = 'You already have Rental items in your cart. Buy items can\'t be mixed. Please clear the cart or finish your Rental checkout first.';
		if (isset(WC()->session)) WC()->session->set('bp_cartux_block_msg', $msg);
		wc_add_notice($msg, 'error');
		return false;
	}
	return true;
}, 10, 4);

/* =============================================================================
 * FIX: Push block message into WC AJAX fragments so JS can read it immediately
 *      This runs at priority 5 — before the main fragment hook below.
 * ========================================================================== */
add_filter('woocommerce_add_to_cart_fragments', function($fragments){
	$msg = '';
	if (isset(WC()->session)){
		$msg = (string) WC()->session->get('bp_cartux_block_msg', '');
		// Do NOT unset here — the main fragment hook below will unset it
	}

	// Also check live error notices queued this request
	if (!$msg){
		$notices = wc_get_notices('error');
		if (!empty($notices)){
			foreach ($notices as $notice){
				$text = is_array($notice) ? ($notice['notice'] ?? '') : (string)$notice;
				$text = wp_strip_all_tags($text);
				if (strpos($text, "can't be mixed") !== false || strpos($text, "can\'t be mixed") !== false){
					$msg = $text;
					break;
				}
			}
		}
	}

	$fragments['div#bp-mixed-cart-error'] =
		'<div id="bp-mixed-cart-error" data-msg="' . esc_attr($msg) . '"></div>';

	return $fragments;
}, 5);

/* =============================================================================
 * 2) STOP PDP REDIRECT ON AJAX ERROR
 * ========================================================================== */
add_filter('woocommerce_cart_redirect_after_error', function ($url) {
	$has_our_block = ( isset(WC()->session) && WC()->session->get('bp_cartux_block_msg') );
	if ( ! $has_our_block ) {
		return $url;
	}

	$back = wc_get_raw_referer();
	if ( ! $back ) {
		$back = remove_query_arg( array('add-to-cart','kh_buy_now') );
	}
	$back = add_query_arg( 'bp_cart_block', '1', $back );

	return $back ?: home_url('/');
}, 99);

/* =============================================================================
 * 3) CART FRAGMENTS — publish cart type + URL and a one-shot blocked message
 * ========================================================================== */
add_filter( 'woocommerce_add_to_cart_fragments', function( $fragments ) {
	$type = bp_cartux_cart_type();
	$url  = bp_cartux_checkout_url_for_cart();

	$blocked_msg = '';
	if ( isset( WC()->session ) ) {
		$blocked_msg = (string) WC()->session->get( 'bp_cartux_block_msg', '' );
		WC()->session->__unset( 'bp_cartux_block_msg' ); // one-shot — consume here
	}

	// meta fragment
	$fragments['div#bp-cart-meta'] =
		'<div id="bp-cart-meta"
              data-type="' . esc_attr( $type ) . '"
              data-url="'  . esc_url( $url )   . '"
              data-msg="Your cart contains both Buy and Rental items. Please keep only one type to proceed."></div>';

	// one-shot blocked message fragment
	$fragments['div#bp-cart-blocked'] =
		'<div id="bp-cart-blocked" data-msg="' . esc_attr( $blocked_msg ) . '"></div>';

	return $fragments;
}, 10 ); // priority 10 — runs after priority 5 above (which already checked session)

/* =============================================================================
 * 4) FRONTEND HOOKS (under `wp`)
 * ========================================================================== */
add_action('wp', function () {

	/* Guard wrong checkout URL visits with a notice */
	add_action('woocommerce_before_checkout_form', function(){
		$type = bp_cartux_cart_type();
		if ($type === 'mixed'){
			wc_add_notice('Your cart contains both Buy and Rental items. Please keep only one type to proceed.', 'error');
			return;
		}
		$is_buy_page    = function_exists('is_page') && is_page(BP_BUY_CHECKOUT_SLUG);
		$is_rental_page = function_exists('is_page') && is_page(BP_RENT_CHECKOUT_SLUG);

		if ($is_buy_page && $type === 'rental'){
			wc_add_notice(sprintf('These are Rental items. Please use the <a href="%s">Rental Checkout</a>.', esc_url(home_url('/'.BP_RENT_CHECKOUT_SLUG.'/'))), 'error');
		}
		if ($is_rental_page && $type === 'buy'){
			wc_add_notice(sprintf('These are Buy items. Please use the <a href="%s">Buy Checkout</a>.', esc_url(home_url('/'.BP_BUY_CHECKOUT_SLUG.'/'))), 'error');
		}
	}, 1);

	/* Force all checkout buttons/links to the correct slug */
	add_filter('woocommerce_get_checkout_url', function($url){
		if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
			return $url;
		}
		$type = bp_cartux_cart_type();
		if ($type === 'buy')    return home_url('/'.BP_BUY_CHECKOUT_SLUG.'/');
		if ($type === 'rental') return home_url('/'.BP_RENT_CHECKOUT_SLUG.'/');
		return $url;
	}, 999);

	/* Redirect generic /checkout/ to split checkout */
	add_action('template_redirect', function(){
		if (is_admin() || wp_doing_ajax()) return;
		if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) return;

		if (function_exists('is_checkout') && is_checkout()){
			$type = bp_cartux_cart_type();
			if ($type === 'empty' || $type === 'mixed') return;

			$need = ($type === 'buy') ? BP_BUY_CHECKOUT_SLUG : BP_RENT_CHECKOUT_SLUG;
			$current_path = trim(parse_url(add_query_arg([]), PHP_URL_PATH), '/');
			if ($need && strpos($current_path, $need) === false){
				wp_safe_redirect(home_url('/'.$need.'/'), 302);
				exit;
			}
		}
	});

	/* BUY NOW — non-AJAX: redirect only on success */
	add_filter('woocommerce_add_to_cart_redirect', function($url){
		if (isset($_REQUEST['kh_buy_now']) && '1' === (string) $_REQUEST['kh_buy_now']){
			if (function_exists('wc_notice_count') && wc_notice_count('error') > 0){
				$back = wc_get_raw_referer();
				$back = $back ? $back : remove_query_arg(array('add-to-cart','kh_buy_now'));
				$back = add_query_arg('bp_cart_block', '1', $back);
				return $back;
			}
			return bp_cartux_checkout_url_for_cart();
		}
		return $url;
	}, 9999);

	/* FRONT-END JS */
	add_action('wp_footer', function(){
		if (is_admin()) return; ?>

<script>
(function($){
  if (typeof jQuery==='undefined') return;

  var AJAX_URL = (window.ajaxurl || "<?php echo esc_url( admin_url('admin-ajax.php') ); ?>");

  /* =====================================================
   * Helpers
   * ===================================================== */
  function sc_getBtns(){
    return $('.xoo-wsc-container .xoo-wsc-footer a.xoo-wsc-ft-btn-checkout, .xoo-wsc-container a.xoo-wsc-chkt, .xoo-wsc-container button.xoo-wsc-chkt, .xoo-wsc-container .xoo-wsc-footer button');
  }

  function sc_meta(){
    var $m = $('#bp-cart-meta');
    if (!$m.length) return null;
    return { type: $m.data('type') || 'empty', url: $m.data('url') || '', msg: $m.data('msg') || '' };
  }

  function isGenericCheckout(url){
    if (!url) return true;
    try { url = url.toString(); } catch(e){ return true; }
    return /\/checkout\/?(\?|$)/i.test(url);
  }

  function sc_setCheckoutTarget($b, url){
    if (!url) return;
    $b.filter('a').attr('href', url).attr('target','_self');
    $b.not('a').off('click.bpGo').on('click.bpGo', function(e){
      e.preventDefault();
      window.location.href = url;
    });
    $b.attr('data-href', url).attr('data-url', url);
  }

  /* =====================================================
   * Centralised notice display — tries XootiX, then Woo, then alert
   * ===================================================== */
  function sc_showNotice(msg){
    if (!msg) return;
    if (window.xoo_wsc_open) { try { window.xoo_wsc_open(); } catch(e){} }
    if (window.xoo_wsc_notice && typeof window.xoo_wsc_notice === 'function'){
      try { window.xoo_wsc_notice(msg, 'error'); return; } catch(e){}
    }
    try { $(document.body).trigger('wc_add_notice', [msg, 'error']); return; } catch(e){}
    alert(msg);
  }

  /* =====================================================
   * sc_apply — wire checkout button URL + block mixed clicks
   * ===================================================== */
  function sc_apply(){
    var d  = sc_meta();
    var $b = sc_getBtns();
    if (!$b.length) return;

    if (d && d.url && !isGenericCheckout(d.url)){
      sc_setCheckoutTarget($b, d.url);
    } else {
      sc_fetchAndApply();
    }

    // Block proceed if cart is mixed
    $b.off('click.bpSplit');
    if (d && d.type === 'mixed'){
      $b.on('click.bpSplit', function(e){
        e.preventDefault();
        sc_showNotice(d.msg || 'Your cart contains both Buy and Rental items. Please keep only one type to proceed.');
      });
    }
  }

  /* =====================================================
   * sc_checkFlash — reads one-shot messages from fragments
   *   Checks BOTH #bp-cart-blocked (session-based)
   *   AND #bp-mixed-cart-error (AJAX validation-based) ← NEW
   * ===================================================== */
  function sc_checkFlash(){
    // 1) Session-based one-shot (non-AJAX add-to-cart / Buy Now)
    var $flash = $('#bp-cart-blocked');
    if ($flash.length){
      var fmsg = ($flash.data('msg') || '').toString().trim();
      if (fmsg){
        sc_showNotice(fmsg);
        $flash.attr('data-msg', ''); // consume
      }
    }

    // 2) AJAX validation-based (shop/archive AJAX add-to-cart) ← NEW FIX
    var $aerr = $('#bp-mixed-cart-error');
    if ($aerr.length){
      var amsg = ($aerr.data('msg') || '').toString().trim();
      if (amsg){
        sc_showNotice(amsg);
        $aerr.attr('data-msg', ''); // consume
      }
    }
  }

  /* =====================================================
   * sc_fetchAndApply — AJAX fallback to get correct checkout URL
   * ===================================================== */
  function sc_fetchAndApply(){
    var $b = sc_getBtns();
    if (!$b.length) return;
    $.post(AJAX_URL, { action: 'bp_cartux_checkout_meta' }, function(resp){
      if (!resp || !resp.success || !resp.data) return;
      var url = resp.data.url || '';
      if (url) sc_setCheckoutTarget($b, url);
    }, 'json');
  }

  /* =====================================================
   * Buy Now (AJAX on archives) → go to split checkout
   * ===================================================== */
  var bpBuyNowBtn = null;
  $(document).on('click', 'a.ajax_add_to_cart[href*="kh_buy_now=1"]', function(){
    bpBuyNowBtn = this;
  });

  $(document.body).on('added_to_cart', function(e, fragments, cart_hash, $button){
    var isBuyNow = false;
    if ($button && $button.length){
      var href = ($button.attr('href') || '') + ($button.data('product_url') || '');
      isBuyNow = href.indexOf('kh_buy_now=1') !== -1;
    } else if (bpBuyNowBtn){
      isBuyNow = true;
    }
    if (isBuyNow){
      var d   = sc_meta();
      var url = d && d.url && !isGenericCheckout(d.url) ? d.url : null;
      if (url){
        window.location.href = url;
      } else {
        $.post(AJAX_URL, { action: 'bp_cartux_checkout_meta' }, function(meta){
          var u = (meta && meta.success && meta.data && meta.data.url)
                  ? meta.data.url
                  : "<?php echo esc_url( wc_get_checkout_url() ); ?>";
          window.location.href = u;
        }, 'json');
      }
    }
    bpBuyNowBtn = null;
  });

  /* =====================================================
   * Force side-cart checkout button to use split URL
   * ===================================================== */
  var checkoutBtnSel =
    '.xoo-wsc-container .xoo-wsc-footer a.xoo-wsc-ft-btn-checkout, ' +
    '.xoo-wsc-container a.xoo-wsc-chkt, ' +
    '.xoo-wsc-container button.xoo-wsc-chkt, ' +
    '.xoo-wsc-container .xoo-wsc-footer button';

  $(document).off('click.bpCartUX', checkoutBtnSel).on('click.bpCartUX', checkoutBtnSel, function(e){
    e.preventDefault(); e.stopImmediatePropagation();
    $.post(AJAX_URL, { action: 'bp_cartux_checkout_meta' }, function(resp){
      var data = (resp && resp.success && resp.data) ? resp.data : {};
      var url  = data.url || "<?php echo esc_url( wc_get_checkout_url() ); ?>";
      if (data.type === 'mixed'){
        sc_showNotice(data.msg || 'Your cart contains both Buy and Rental items. Please keep only one type to proceed.');
        return;
      }
      window.location.href = url;
    }, 'json');
  });

  /* =====================================================
   * bp_cart_block query param — non-AJAX redirect path
   * ===================================================== */
  $(function(){
    var p = new URLSearchParams(window.location.search);
    if (p.get('bp_cart_block') === '1'){
      $.post(AJAX_URL, { action: 'bp_cartux_checkout_meta' }, function(resp){
        var msg = (resp && resp.success && resp.data && resp.data.msg)
          ? resp.data.msg
          : 'Your cart contains both Buy and Rental items. Please keep only one type to proceed.';
        sc_showNotice(msg);
      }, 'json');
      // Clean URL so it doesn't re-fire on refresh
      var u = new URL(window.location.href);
      u.searchParams.delete('bp_cart_block');
      window.history.replaceState({}, '', u);
    }
  });

  /* =====================================================
   * Bind — init + every cart update event
   * ===================================================== */
  $(function(){
    sc_apply();
    sc_checkFlash(); // catch any flash msg already in DOM on page load
  });

  $(document.body).on('wc_fragments_refreshed added_to_cart removed_from_cart', function(){
    sc_apply();
    sc_fetchAndApply();
    sc_checkFlash(); // catch flash msg pushed via fragment on every cart update ← KEY FIX
  });

  $(document).on('xoo_wsc_open xoo_wsc_opened xoo_wsc_cart_updated xoo_wsc_updated', function(){
    sc_apply();
    sc_fetchAndApply();
    sc_checkFlash();
  });

  /* =====================================================
   * Qty steppers — BUY checkout only
   * ===================================================== */
  function enhanceCheckoutQty(){
    var d = sc_meta();
    if (!d || d.type !== 'buy') return;
    $('.woocommerce-checkout-review-order input.qty').each(function(){
      var $q = $(this);
      if ($q.data('bpEnhanced')) return;
      var $wrap = $q.closest('.quantity, .woocommerce-quantity, li, td, div');
      if ($wrap.find('button.plus, button.minus, a.plus, a.minus').length) return;
      $q.siblings('.bp-qm, .bp-qp').remove();
      $q.data('bpEnhanced', true);
      $('<button type="button" class="bp-qm">−</button>').insertBefore($q);
      $('<button type="button" class="bp-qp">+</button>').insertAfter($q);
    });
  }
  $(document).on('click', '.woocommerce-checkout-review-order .bp-qm, .woocommerce-checkout-review-order .bp-qp', function(e){
    e.preventDefault();
    var $q   = $(this).siblings('input.qty');
    if (!$q.length) return;
    var step = parseFloat($q.attr('step')) || 1;
    var min  = isNaN(parseFloat($q.attr('min')))  ? 0    : parseFloat($q.attr('min'));
    var max  = isNaN(parseFloat($q.attr('max')))  ? 9999 : parseFloat($q.attr('max'));
    var val  = parseFloat($q.val()) || 0;
    val += $(this).hasClass('bp-qp') ? step : -step;
    val  = Math.max(min, Math.min(max, val));
    $q.val(val).trigger('change');
  });
  $(document).on('change input', '.woocommerce-checkout-review-order input.qty', function(){
    $('body').trigger('update_checkout');
  });
  $(enhanceCheckoutQty);
  $(document.body).on('updated_checkout', enhanceCheckoutQty);

})(jQuery);
</script>

<style>
/* neutral steppers */
.woocommerce-checkout-review-order input.qty{ width:52px; text-align:center }
.woocommerce-checkout-review-order .bp-qm,
.woocommerce-checkout-review-order .bp-qp{
  display:inline-flex; align-items:center; justify-content:center;
  width:28px;height:28px;line-height:26px;border:1px solid #ddd;background:#f6f6f6;border-radius:4px;padding:0;margin:0 6px;cursor:pointer
}
.xoo-wsc-container input.qty{ width:50px; text-align:center }
.xoo-wsc-container .bp-sc-minus,.xoo-wsc-container .bp-sc-plus{
  width:26px;height:26px;line-height:24px;border:1px solid #ddd;background:#f6f6f6;border-radius:4px;padding:0;cursor:pointer
}
</style>
<?php });

	/* CHECKOUT: qty editable ONLY for BUY items */
	add_filter('woocommerce_checkout_cart_item_quantity', function($qty_html, $cart_item, $cart_item_key){
		if (!is_checkout()) return $qty_html;
		$cart_type = bp_cartux_cart_type();
		if ($cart_type !== 'buy') return $qty_html;

		$product = isset($cart_item['data']) ? $cart_item['data'] : null;
		if (!$product) return $qty_html;
		$pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
		if (!bp_cartux_is_buy_product($pid)) return $qty_html;

		if ($product->is_sold_individually()){
			return '1 <input type="hidden" name="cart['.esc_attr($cart_item_key).'][qty]" value="1" />';
		}
		return woocommerce_quantity_input(array(
			'input_name'  => "cart[{$cart_item_key}][qty]",
			'input_value' => $cart_item['quantity'],
			'max_value'   => $product->get_max_purchase_quantity(),
			'min_value'   => 0,
			'class'       => 'input-text qty text'
		), $product, false);
	}, 10, 3);

	add_action('woocommerce_checkout_update_order_review', function($posted_data){
		parse_str($posted_data, $data);
		if (empty($data['cart']) || !WC()->cart) return;
		foreach ($data['cart'] as $key => $vals){
			if (!isset($vals['qty'])) continue;
			$qty = wc_stock_amount($vals['qty']);
			WC()->cart->set_quantity($key, $qty, false);
		}
		WC()->cart->calculate_totals();
	});

	/* RENTAL: show Start/End/Days on checkout line items */
	add_filter('woocommerce_cart_item_name', function($name, $cart_item){
		if (!is_checkout()) return $name;
		if (bp_cartux_cart_type() !== 'rental') return $name;

		$start = $end = $days = '';
		foreach (['booking','bkap_booking'] as $k){
			if (!empty($cart_item[$k]) && is_array($cart_item[$k])){
				$bk = $cart_item[$k];
				$start = $start ?: ($bk['start'] ?? $bk['Start Date'] ?? $bk['date'] ?? '');
				$end   = $end   ?: ($bk['end']   ?? $bk['End Date']   ?? '');
				$days  = $days  ?: ($bk['days']  ?? $bk['Duration']   ?? '');
			}
		}
		$start = $start ?: ($cart_item['start_date'] ?? $cart_item['pickup_date'] ?? $cart_item['rent_from'] ?? '');
		$end   = $end   ?: ($cart_item['end_date']   ?? $cart_item['dropoff_date'] ?? $cart_item['rent_to'] ?? '');
		$days  = $days  ?: ($cart_item['rental_days'] ?? $cart_item['days'] ?? $cart_item['duration'] ?? '');
		if ($days !== '' && !is_numeric($days) && preg_match('~\d+~', (string)$days, $m)) $days = $m[0];

		$bits = [];
		if ($start) $bits[] = 'Start Date: ' . esc_html($start);
		if ($end)   $bits[] = 'End Date: '   . esc_html($end);
		if ($days !== '') $bits[] = 'No. of Days: ' . esc_html($days);

		if ($bits){
			$name .= '<div class="bp-rental-meta" style="margin-top:6px;font-size:12px;opacity:.9;">'
				   . wp_kses_post(implode('<br>', $bits))
				   . '</div>';
		}
		return $name;
	}, 10, 2);

	/* RENTAL checkout: enquiry-first */
	add_filter('woocommerce_available_payment_gateways', function($gateways){
		if (!is_checkout() || bp_cartux_cart_type() !== 'rental') return $gateways;

		$allowed_ids = array('rental_enquiry','cod','cheque');
		foreach ($gateways as $id => $gw){
			if (!in_array($id, $allowed_ids, true)) unset($gateways[$id]);
		}
		if (isset($gateways['rental_enquiry'])){
			$gateways['rental_enquiry']->title       = __('Rental Enquiry (No Payment Now)', 'bragpacker');
			$gateways['rental_enquiry']->description = __('Submit your rental request. We will confirm availability by email before payment.', 'bragpacker');
		} elseif (isset($gateways['cod'])){
			$gateways['cod']->title       = __('Rental Enquiry (No Payment Now)', 'bragpacker');
			$gateways['cod']->description = __('Submit your rental request. We will confirm availability by email before payment.', 'bragpacker');
		} elseif (isset($gateways['cheque'])){
			$gateways['cheque']->title       = __('Rental Enquiry (No Payment Now)', 'bragpacker');
			$gateways['cheque']->description = __('Submit your rental request. We will confirm availability by email before payment.', 'bragpacker');
		}
		return $gateways;
	}, 20);

	add_filter('woocommerce_order_button_text', function($text){
		return (bp_cartux_cart_type() === 'rental') ? __('Submit Booking Request','bragpacker') : $text;
	}, 10);

	add_action('woocommerce_review_order_before_payment', function(){
		if (bp_cartux_cart_type() === 'rental') {
			echo '<div class="woocommerce-info" style="margin-bottom:10px">'
			   . esc_html__('This is an enquiry request. We\'ll email confirmation of availability and a payment link.', 'bragpacker')
			   . '</div>';
		}
	});

	/* RENTAL: Refundable Security Deposit — info-only (no total impact) */
	if ( ! function_exists('bp_cartux_calc_refundable_deposit') ) {
		function bp_cartux_calc_refundable_deposit( $cart ) : float {
			if ( ! $cart ) return 0.0;

			$to_float  = function($v){
				if ($v === '' || $v === null) return 0.0;
				if (is_numeric($v)) return (float)$v;
				$v = preg_replace('~[^\d\.\-]~', '', (string)$v);
				return is_numeric($v) ? (float)$v : 0.0;
			};
			$meta_keys = ['deposite_fee_amount','deposit_fee_amount','deposit_fee','refundable_security_deposit'];

			$total = 0.0;
			foreach ( $cart->get_cart() as $item ) {
				if ( empty($item['data']) || ! is_a($item['data'], 'WC_Product') ) continue;

				$prod   = $item['data'];
				$var_id = $prod->is_type('variation') ? $prod->get_id()        : 0;
				$par_id = $prod->is_type('variation') ? $prod->get_parent_id() : $prod->get_id();

				$deposit = 0.0;
				if ( $var_id ) {
					foreach ( $meta_keys as $k ) {
						$raw = get_post_meta( $var_id, $k, true );
						if ( $raw !== '' ) { $deposit = $to_float($raw); if ( $deposit > 0 ) break; }
					}
				}
				if ( $deposit <= 0 ) {
					foreach ( $meta_keys as $k ) {
						$raw = get_post_meta( $par_id, $k, true );
						if ( $raw !== '' ) { $deposit = $to_float($raw); if ( $deposit > 0 ) break; }
					}
				}
				if ( $deposit > 0 ) {
					$qty    = isset($item['quantity']) ? max(1,(int)$item['quantity']) : 1;
					$total += ( $deposit * $qty );
				}
			}
			return $total;
		}
	}

	if ( ! function_exists('bp_cartux_render_deposit_info_popup') ) {
		function bp_cartux_render_deposit_info_popup() {
			if ( ! function_exists('bp_cartux_cart_type') || bp_cartux_cart_type() !== 'rental' ) return;
			if ( ! function_exists('WC') || ! WC()->cart ) return;

			$deposit_total = bp_cartux_calc_refundable_deposit( WC()->cart );
			if ( $deposit_total <= 0 ) return;

			$popup_id      = 'bp-refund-deposit-pop-' . uniqid();
			$waiver_id     = 'bp-deposit-waiver-' . uniqid();
			$eligibility_link = esc_url( home_url('/deposit-waiver#eligibility') );
			?>
			<tr class="bp-deposit-info">
				<th style="font-weight:600; vertical-align:middle;">
					<span style="display:inline-flex;align-items:baseline;gap:8px;">
						<span>Refundable Security Deposit</span>
						<a href="#<?php echo esc_attr($popup_id); ?>" class="bp-deposit-info-link" aria-label="More info" title="More info" style="text-decoration:none;margin-left:6px;">
							<svg class="bp-deposit-info-icon" viewBox="0 0 24 24" aria-hidden="true" style="width:18px;height:18px;vertical-align:middle">
								<circle cx="12" cy="12" r="10" stroke="currentColor" fill="none" stroke-width="1.6"/>
								<circle cx="12" cy="7.5" r="1.2" fill="currentColor"/>
								<rect x="11.15" y="10.5" width="1.7" height="7" rx="0.85" fill="currentColor"/>
							</svg>
						</a>
					</span>
					<div style="font-size:12px;opacity:.85;margin-top:4px;">
						<a href="#<?php echo esc_attr($waiver_id); ?>"
						   class="bp-deposit-waiver-link"
						   style="color:#ff6c2f;text-decoration:underline;font-weight:600;">
							See how to get it waived
						</a>
					</div>
				</th>
				<td data-title="Refundable Security Deposit" style="vertical-align:middle;">
					<?php echo wc_price( $deposit_total ); ?>
				</td>
			</tr>

			<div id="<?php echo esc_attr($popup_id); ?>" class="bp-deposit-overlay" style="display:none;">
				<div class="bp-deposit-card" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($popup_id); ?>-title">
					<button type="button" class="bp-deposit-close" aria-label="Close">×</button>
					<h3 id="<?php echo esc_attr($popup_id); ?>-title" style="margin:0 0 8px;">Refundable Security Deposit</h3>
					<p style="margin:0 0 8px;">
						The deposit amount will be added to the invoice sent to you on order confirmation. It is held as a refundable security and returned after the product is returned in acceptable condition.
					</p>
					<p style="margin:0;font-size:13px;opacity:.95;">
						If eligible, we may waive the deposit — see the short guidance below.
					</p>
				</div>
			</div>

			<div id="<?php echo esc_attr($waiver_id); ?>" class="bp-deposit-waiver-overlay" style="display:none;">
				<div class="bp-deposit-waiver-card" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($waiver_id); ?>-title">
					<button type="button" class="bp-deposit-waiver-close" aria-label="Close">×</button>
					<h4 id="<?php echo esc_attr($waiver_id); ?>-title" style="margin:0 0 8px;font-size:16px;">Deposit Waiver — Quick Guide</h4>
					<ul style="margin:0 0 8px 18px;padding:0;font-size:13px;line-height:1.35;">
						<li>Deposit is fully refundable once product is returned in the agreed condition.</li>
						<li>For some customers/orders we can waive it — <a href="<?php echo $eligibility_link; ?>" target="_blank" rel="noopener" style="color:#ff6c2f;">Check your eligibility here</a>.</li>
					</ul>
				</div>
			</div>

			<style>
				.woocommerce-checkout-review-order-table .bp-deposit-info th,
				.woocommerce-checkout-review-order-table .bp-deposit-info td,
				.cart_totals .bp-deposit-info th,
				.cart_totals .bp-deposit-info td { border-top: 1px solid #eee; }
				.bp-deposit-overlay, .bp-deposit-waiver-overlay{
					position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px
				}
				.bp-deposit-card, .bp-deposit-waiver-card{
					background:#fff;border-radius:10px;max-width:520px;width:92%;padding:18px 16px;box-shadow:0 18px 42px rgba(0,0,0,.25);position:relative
				}
				.bp-deposit-close, .bp-deposit-waiver-close{
					position:absolute;top:8px;right:10px;border:0;background:transparent;font-size:22px;cursor:pointer;color:#6b7280
				}
				.bp-deposit-close:hover, .bp-deposit-waiver-close:hover{color:#111}
				.bp-deposit-waiver-link{ font-size:13px; display:inline-block; margin-top:4px; }
			</style>
			<script>
			(function(){
				var link    = document.querySelector('.bp-deposit-info-link');
				var overlay = document.getElementById('<?php echo esc_js($popup_id); ?>');
				if (link && overlay){
					function openInfo(e){ e && e.preventDefault(); overlay.style.display='flex'; document.body.style.overflow='hidden'; }
					function closeInfo(){ overlay.style.display='none'; document.body.style.overflow=''; }
					link.addEventListener('click', openInfo);
					overlay.addEventListener('click', function(e){ if (e.target === overlay) closeInfo(); });
					var closeBtn = overlay.querySelector('.bp-deposit-close');
					if (closeBtn) closeBtn.addEventListener('click', closeInfo);
					document.addEventListener('keyup', function(e){ if (e.key === 'Escape') closeInfo(); });
				}
				var wlink    = document.querySelector('.bp-deposit-waiver-link');
				var woverlay = document.getElementById('<?php echo esc_js($waiver_id); ?>');
				if (wlink && woverlay){
					function openWaiver(e){ e && e.preventDefault(); woverlay.style.display='flex'; document.body.style.overflow='hidden'; }
					function closeWaiver(){ woverlay.style.display='none'; document.body.style.overflow=''; }
					wlink.addEventListener('click', openWaiver);
					woverlay.addEventListener('click', function(e){ if (e.target === woverlay) closeWaiver(); });
					var closeW = woverlay.querySelector('.bp-deposit-waiver-close');
					if (closeW) closeW.addEventListener('click', closeWaiver);
					document.addEventListener('keyup', function(e){ if (e.key === 'Escape') closeWaiver(); });
				}
			})();
			</script>
			<?php
		}
	}

	add_action('woocommerce_cart_totals_after_order_total',  'bp_cartux_render_deposit_info_popup', 20);
	add_action('woocommerce_review_order_after_order_total', 'bp_cartux_render_deposit_info_popup', 20);

	/* PERFORMANCE (frontend) */
	add_action('wp_enqueue_scripts', function () {
		$needs_fragments =
			is_cart() || is_checkout()
			|| (function_exists('is_woocommerce') && is_woocommerce())
			|| !empty($_GET['add-to-cart']);

		if (!$needs_fragments){
			wp_dequeue_script('wc-cart-fragments');
			wp_deregister_script('wc-cart-fragments');
			add_filter('woocommerce_cart_fragment_refresh_rate', '__return_false');
		}
	}, 99);

	add_action('wp_enqueue_scripts', function () {
		if (!is_product()){
			wp_dequeue_script('wc-single-product');
			wp_dequeue_script('flexslider');
			wp_dequeue_style('woocommerce_prettyPhoto_css');
		}
	}, 100);

}); // end add_action('wp', ...)

/* =============================================================================
 * 5) AJAX — returns type, url, and last block message
 * ========================================================================== */
add_action('wp_ajax_bp_cartux_checkout_meta', function(){
	$msg = '';
	if (isset(WC()->session)){
		$msg = (string) WC()->session->get('bp_cartux_block_msg', '');
		WC()->session->__unset('bp_cartux_block_msg');
	}
	wp_send_json_success(['type' => bp_cartux_cart_type(), 'url' => bp_cartux_checkout_url_for_cart(), 'msg' => $msg]);
});
add_action('wp_ajax_nopriv_bp_cartux_checkout_meta', function(){
	$msg = '';
	if (isset(WC()->session)){
		$msg = (string) WC()->session->get('bp_cartux_block_msg', '');
		WC()->session->__unset('bp_cartux_block_msg');
	}
	wp_send_json_success(['type' => bp_cartux_cart_type(), 'url' => bp_cartux_checkout_url_for_cart(), 'msg' => $msg]);
});

/* =============================================================================
 * Small global trims
 * ========================================================================== */
add_action('init', function () {
	remove_action('wp_head', 'print_emoji_detection_script', 7);
	remove_action('wp_print_styles', 'print_emoji_styles');
});
add_action('wp_enqueue_scripts', function () {
	if (!is_user_logged_in()) wp_dequeue_style('dashicons');
}, 100);

/* Treat COD/Cheque as "enquiry" for rental carts: leave order in 'pending' */
function bp_cartux_order_is_rental( $order ) {
	if ( ! $order || is_wp_error( $order ) ) return false;
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$pid = $item->get_product_id();
		if ( ! bp_cartux_is_buy_product( $pid ) ) return true;
	}
	return false;
}

add_filter( 'woocommerce_cod_process_payment_order_status', function( $status, $order ) {
	return bp_cartux_order_is_rental( $order ) ? 'pending' : $status;
}, 10, 2 );

add_filter( 'woocommerce_cheque_process_payment_order_status', function( $status, $order ) {
	return bp_cartux_order_is_rental( $order ) ? 'pending' : $status;
}, 10, 2 );
