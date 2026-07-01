<?php
/**
 * Plugin Name: KH Buy Now — Bragpacker (Final Stable)
 * Description: Adds working "Buy Now" button for Bragpacker Buy Store (single + archive) that redirects to checkout, keeps Add to Cart visible, and fixes hover + fatal errors.
 * Version: 1.1.0
 */

/* =========================
   Helpers
========================= */
function bp_buy_root_term() {
	if ( ! function_exists( 'get_term_by' ) ) return null;
	$t = get_term_by( 'slug', 'bragpacker-buy-store', 'product_cat' );
	if ( ! $t ) $t = get_term_by( 'name', 'Bragpacker Buy Store', 'product_cat' );
	return ( $t && ! is_wp_error( $t ) ) ? $t : null;
}

function bp_in_buy_tree( $post_id ) {
	if ( ! function_exists( 'get_the_terms' ) ) return false;
	$root = bp_buy_root_term(); if ( ! $root ) return false;
	$terms = get_the_terms( $post_id, 'product_cat' ); if ( empty( $terms ) || is_wp_error( $terms ) ) return false;
	foreach ( $terms as $term ) {
		if ( (int) $term->term_id === (int) $root->term_id ) return true;
		$anc = get_ancestors( $term->term_id, 'product_cat' );
		if ( in_array( (int) $root->term_id, array_map( 'intval', $anc ), true ) ) return true;
	}
	return false;
}

function bp_is_buy_archive() {
	if ( ! function_exists( 'is_product_category' ) || ! function_exists( 'get_queried_object' ) ) return false;
	if ( ! is_product_category() ) return false;
	$root = bp_buy_root_term(); if ( ! $root ) return false;
	$term = get_queried_object(); if ( ! $term || is_wp_error( $term ) ) return false;
	if ( (int) $term->term_id === (int) $root->term_id ) return true;
	$anc = get_ancestors( $term->term_id, 'product_cat' );
	return in_array( (int) $root->term_id, array_map( 'intval', $anc ), true );
}

/* =========================
   Body class
========================= */
add_filter( 'body_class', function ( $classes ) {
	if ( function_exists('is_product') && is_product() && bp_in_buy_tree( get_queried_object_id() ) ) {
		$classes[] = 'bp-buy-single';
	}
	if ( bp_is_buy_archive() ) $classes[] = 'bp-buy-archive';
	return $classes;
} );

/* =========================
   CSS (unchanged from original)
========================= */
add_action( 'wp_head', function () { ?>
<style>
/* ---------- PRICE ROW: put icons to the right of "Buy for …" ---------- */
.bp-buy-archive .wrap-price{
  display:flex !important;
  align-items:center;
  gap:10px;
}
.bp-buy-archive .wrap-price .price{
  flex:1 1 auto;
  margin:0;                  /* no extra line-breaks */
}
.bp-buy-archive .wrap-price .wd-action-buttons{
  flex:0 0 auto;
  display:flex !important;
  align-items:center;
  gap:10px;
  margin:0;
}


/* ---------- CTA row (Add to cart / Buy Now / Select options) ---------- */
.bp-buy-archive .product .bp-cta{
  display:flex; flex-direction:row; gap:12px; width:100%; margin-top:8px;
}
.bp-buy-archive .product .bp-cta .button,
.bp-buy-archive .product .bp-cta a.button{
  flex:1 1 48%; max-width:80%;
  height:44px; line-height:44px;
  display:inline-flex !important; align-items:center; justify-content:center;
  border-radius:6px; margin:0 !important; font-size:14px; font-weight:600; white-space:nowrap;
  text-decoration:none !important;
}
  a.button.kh-select-options
  {
    background:#1253A0 !important;
  border-color:#1253A0 !important;
  color:#fff !important;
  height:44px; line-height:44px;
  padding:0 14px;
  }

  .bp-buy-archive .wrap-price .wd-action-buttons
  {display: inline-flex!important;
  }
  .wrap-price
  {
  max-width: 270px!important;
  }
/* Orange CTA */
.bp-buy-archive .product .bp-cta a.add_to_cart_button{
  background:#ff6c2f !important; border-color:#ff6c2f !important; color:#fff !important;
}
/* Blue Buy Now */
.bp-buy-archive .product .bp-cta .kh-buy-now-archive{
  background:#1253A0 !important; border-color:#1253A0 !important; color:#fff !important;
}

/* ---------- Make "SELECT OPTIONS" look like the orange CTA everywhere ---------- */
.bp-buy-archive .product a.product_type_variable,
.bp-buy-archive .product a.product_type_grouped,
.bp-buy-archive .product a.product_type_external,
.bp-buy-archive .wd-product-footer a.product_type_variable,
.bp-buy-archive .wd-product-footer a.product_type_grouped,
.bp-buy-archive .wd-product-footer a.product_type_external{
  background:#ff6c2f !important;
  border-color:#ff6c2f !important;
  color:#fff !important;
  height:44px; line-height:44px;
  padding:0 18px;
  border-radius:6px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.2px;
  display:inline-flex !important;
  align-items:center; justify-content:center;
  text-decoration:none !important;
  box-shadow:none !important;
}
.bp-buy-archive .product a.product_type_variable:hover,
.bp-buy-archive .product a.product_type_grouped:hover,
.bp-buy-archive .product a.product_type_external:hover{
  filter:brightness(.95);
}

/* Misc look/feel */
.bp-buy-archive .wd-product .price{ font-size:16px !important; display:inline-block; }
.bp-buy-archive .products .product{ min-width:260px; }
.wd-product.wd-hover-buttons-on-hover .wd-product-footer{ gap:0 !important; }
a.button.product_type_simple.add_to_cart_button.ajax_add_to_cart.add-to-cart-loop{ padding:4px 0; }

/* Single product Buy Now */
button#kh-buy-now{
  background:#1253A0 !important; border-color:#1253A0 !important; color:#fff !important;
  font-size:13px !important; font-weight:600; height:44px; line-height:44px; border-radius:6px;
}

/* Phones */
@media (max-width:768px){
  .bp-buy-archive .wd-products-holder,
  .bp-buy-archive .products{ display:block !important; height:auto !important; overflow:visible !important; }
  .bp-buy-archive .wd-products-holder .product,
  .bp-buy-archive .products .product,
  .bp-buy-archive .wd-products-holder .product-grid-item,
  .bp-buy-archive .wd-products-holder .grid-item{
    width:100% !important; float:none !important; margin:0 0 16px 0 !important;
    position:static !important; top:auto !important; left:auto !important; transform:none !important;
  }
  .bp-buy-archive .product .wd-product-footer{ display:flex !important; flex-direction:column; gap:10px; }
  .bp-buy-archive .product .bp-cta{
    display:-webkit-inline-box !important; flex-direction:column; gap:10px; width:50%; margin-top:12px;
  }
  .bp-buy-archive .product .bp-cta .button,
  .bp-buy-archive .product .bp-cta a.button{
    flex:1 1 100% !important; max-width:100% !important;
    display:inline-flex !important; align-items:center; justify-content:center;
    height:43px; line-height:44px;
  }
  img.attachment-woocommerce_thumbnail.size-woocommerce_thumbnail{ width:100% }
}

/* Hide that raw icon list block */
.wpb_raw_code.wpb_raw_html.wpb_content_element.vc_custom_1731073982423{ display:none; }

/*to show buttons*/
/* Always show product footer buttons (override Woodmart hover behavior) */
.wd-product .wd-product-footer,
.wd-hover-buttons-on-hover .wd-product-footer {
  opacity: 1 !important;
  visibility: visible !important;
  transform: none !important;
  pointer-events: auto !important;
  display: flex !important;
}
.wd-product .wd-product-footer .button,
.wd-product .wd-product-footer a.button,
.wd-product .wd-product-footer .bp-cta {
  opacity: 1 !important;
  visibility: visible !important;
  transform: none !important;
  pointer-events: auto !important;
}

/* -------------------------------------------------------
   FIX ADDED: Top-of-page notice bar for mixed cart errors
   on archive pages where side cart may not be available
------------------------------------------------------- */
.bp-mixed-cart-notice {
  display: none;
  position: fixed;
  top: 20px; left: 50%; transform: translateX(-50%);
  z-index: 99999;
  background: #f8d7da; color: #721c24;
  border: 1px solid #f5c6cb;
  padding: 14px 24px;
  border-radius: 6px;
  font-size: 14px; font-weight: 600;
  max-width: 90vw;
  box-shadow: 0 4px 16px rgba(0,0,0,.15);
  text-align: center;
  cursor: pointer;
}
.bp-mixed-cart-notice.bp-visible { display: block; }
</style>
<?php });

/* =========================
   Add Buy Now button to PDP
========================= */
add_action( 'woocommerce_after_add_to_cart_button', function () {
	if ( ! function_exists('is_product') || ! is_product() ) return;
	$pid = get_queried_object_id();
	if ( ! $pid || ! bp_in_buy_tree( $pid ) ) return;
	echo '<button type="submit" name="kh_buy_now" value="1" id="kh-buy-now" class="button alt">Buy Now</button>';
} );

/* =========================
   Redirect Buy Now → Checkout
========================= */
add_filter( 'woocommerce_add_to_cart_redirect', function ( $url ) {
	if ( isset($_REQUEST['kh_buy_now']) && '1' === (string) $_REQUEST['kh_buy_now'] ) {

		if ( function_exists('wc_notice_count') && wc_notice_count('error') > 0 ) {
			$back = wc_get_raw_referer();
			return $back ?: remove_query_arg( array('add-to-cart','kh_buy_now') );
		}

		return function_exists('bp_cartux_checkout_url_for_cart')
			? bp_cartux_checkout_url_for_cart()
			: ( function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url() );
	}
	return $url;
}, 9999 );

/* =========================
   Archive Enhancements
   UNCHANGED from original — only added mixed cart
   notice check after scan events
========================= */
add_action( 'wp_footer', function () {
	if ( ! bp_is_buy_archive() ) return;
	$ajax_url = esc_url( admin_url('admin-ajax.php') );
	?>

<!-- FIX ADDED: fixed notice bar for mixed cart messages on archive pages -->
<div class="bp-mixed-cart-notice" id="bp-mixed-cart-notice" role="alert" aria-live="assertive"></div>

<script>
(function(){
  /* -------------------------------------------------------
     FIX ADDED: Show mixed-cart notice on archive pages
     Called after AJAX cart events AND on ?bp_cart_block=1
  ------------------------------------------------------- */
  var AJAX_URL = "<?php echo $ajax_url; ?>";

  function showMixedCartNotice(msg) {
    if (!msg) return;

    // 1) Try XootiX side cart notice (if available)
    if (window.xoo_wsc_open) { try { window.xoo_wsc_open(); } catch(e){} }
    if (window.xoo_wsc_notice && typeof window.xoo_wsc_notice === 'function') {
      try { window.xoo_wsc_notice(msg, 'error'); return; } catch(e){}
    }

    // 2) Fallback: fixed top notice bar
    var n = document.getElementById('bp-mixed-cart-notice');
    if (n) {
      n.textContent = msg;
      n.classList.add('bp-visible');
      // Click to dismiss
      n.onclick = function(){ n.classList.remove('bp-visible'); };
      // Auto-dismiss after 5s
      clearTimeout(window._bpNoticeTimer);
      window._bpNoticeTimer = setTimeout(function(){ n.classList.remove('bp-visible'); }, 5000);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
      alert(msg);
    }
  }

  function checkFlash() {
    // Check #bp-mixed-cart-error (AJAX add-to-cart path — from Cart UX plugin fragments)
    var aerr = document.getElementById('bp-mixed-cart-error');
    if (aerr) {
      var amsg = (aerr.getAttribute('data-msg') || '').trim();
      if (amsg) { showMixedCartNotice(amsg); aerr.setAttribute('data-msg', ''); }
    }
    // Check #bp-cart-blocked (session/non-AJAX path)
    var flash = document.getElementById('bp-cart-blocked');
    if (flash) {
      var fmsg = (flash.getAttribute('data-msg') || '').trim();
      if (fmsg) { showMixedCartNotice(fmsg); flash.setAttribute('data-msg', ''); }
    }
  }

  // Handle ?bp_cart_block=1 (set by Cart UX plugin on mixed-cart redirect)
  document.addEventListener('DOMContentLoaded', function() {
    var p = new URLSearchParams(window.location.search);
    if (p.get('bp_cart_block') === '1') {
      // Fetch message from session
      var xhr = new XMLHttpRequest();
      xhr.open('POST', AJAX_URL, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function() {
        try {
          var resp = JSON.parse(xhr.responseText);
          var msg = (resp && resp.success && resp.data && resp.data.msg)
            ? resp.data.msg
            : 'You already have Rental items in your cart. Buy items can\'t be mixed. Please clear the cart or finish your Rental checkout first.';
          showMixedCartNotice(msg);
        } catch(e) {
          showMixedCartNotice('You already have Rental items in your cart. Buy items can\'t be mixed.');
        }
      };
      xhr.send('action=bp_cartux_checkout_meta');
      // Clean URL
      var u = new URL(window.location.href);
      u.searchParams.delete('bp_cart_block');
      window.history.replaceState({}, '', u);
    }
  });

  // Listen for WC fragment events (fires after AJAX add-to-cart attempts, including blocked ones)
  if (typeof jQuery !== 'undefined') {
    jQuery(document.body).on('wc_fragments_refreshed added_to_cart removed_from_cart', function(){
      checkFlash();
    });
    jQuery(document).on('xoo_wsc_open xoo_wsc_opened xoo_wsc_cart_updated xoo_wsc_updated', function(){
      checkFlash();
    });
  }

  /* -------------------------------------------------------
     ORIGINAL archive card enhancement — 100% unchanged
  ------------------------------------------------------- */
  var BUY_NOW_POSITION='below';
  function $all(s,c){try{return(c||document).querySelectorAll(s);}catch(e){return[];}}
  function enhance(card){
    if(!card||card.dataset.bpEnhanced==='1')return;
    var cls=card.className||'';
    var isSimple=/product-type-simple/.test(cls);
    var isVariable=/product-type-variable/.test(cls);
    var addBtn=card.querySelector('a.add_to_cart_button,a.button');
    var footer=card.querySelector('.wd-product-footer')||card;
    var cta=footer.querySelector('.bp-cta');
    if(!cta){cta=document.createElement('div');cta.className='bp-cta';footer.appendChild(cta);}
    var priceRow=card.querySelector('.wrap-price');
    var actions=card.querySelector('.wd-action-buttons');
    if(priceRow&&actions&&actions.parentElement!==priceRow){try{priceRow.appendChild(actions);}catch(e){}}
    if(addBtn&&addBtn.parentElement!==cta){try{cta.appendChild(addBtn);}catch(e){}}
    if(isVariable){card.dataset.bpEnhanced='1';return;}
    var pid=(addBtn&&(addBtn.getAttribute('data-product_id')||((addBtn.getAttribute('href')||'').match(/add-to-cart=(\d+)/)||[])[1]))||
             (card.id&&(card.id.match(/post-(\d+)/)||[])[1])||'';
    if(pid&&!cta.querySelector('.kh-buy-now-archive')){
      var buy=document.createElement('a');
      buy.className='button kh-buy-now-archive';
      buy.textContent='Buy Now';
      buy.href='?add-to-cart='+encodeURIComponent(pid)+'&kh_buy_now=1';
      cta.appendChild(buy);
    }
    card.dataset.bpEnhanced='1';
  }
  function scan(){$all('.product,li.product').forEach(enhance);}
  if(document.readyState!=='loading')scan();else document.addEventListener('DOMContentLoaded',scan);
  setTimeout(scan,200);setTimeout(scan,800);

  /* -------------------------------------------------------
     FIX: Intercept both Buy Now AND Add to Cart clicks
     on archive pages. Check cart type first via AJAX.
     If rental items exist → show message, block action.
     If empty or buy → let the click proceed normally.
     Uses capture phase (true) to fire before WooCommerce's
     own AJAX add-to-cart listener.
  ------------------------------------------------------- */
  var MIXED_MSG = 'You already have Rental items in your cart. Buy items can\'t be mixed. Please clear the cart or finish your Rental checkout first.';
  var _intercepting = false; // guard to prevent infinite re-click loop

  function checkCartThenProceed(e, btn) {
    if (_intercepting) return; // already re-firing — let it through
    e.preventDefault();
    e.stopImmediatePropagation();

    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
      try {
        var resp     = JSON.parse(xhr.responseText);
        var cartType = (resp && resp.success && resp.data) ? resp.data.type : 'empty';
        if (cartType === 'rental') {
          showMixedCartNotice(MIXED_MSG);
        } else {
          // Re-fire the original click — _intercepting flag lets it pass through
          _intercepting = true;
          btn.click();
          _intercepting = false;
        }
      } catch(err) {
        _intercepting = true; btn.click(); _intercepting = false;
      }
    };
    xhr.onerror = function() { _intercepting = true; btn.click(); _intercepting = false; };
    xhr.send('action=bp_cartux_checkout_meta');
  }

  // Capture phase so we fire BEFORE WooCommerce's bubble-phase AJAX listener
  document.addEventListener('click', function(e) {
    if (_intercepting) return;
    if (!document.body.classList.contains('bp-buy-archive')) return;

    var addToCart = e.target.closest('a.add_to_cart_button, a.ajax_add_to_cart');
    if (addToCart) { checkCartThenProceed(e, addToCart); return; }

    var buyNow = e.target.closest('.kh-buy-now-archive');
    if (buyNow) { checkCartThenProceed(e, buyNow); }
  }, true); // true = capture phase

})();
</script>
<?php } );

/* =========================
   PDP Buy Now JS redirect — UNCHANGED
========================= */
add_action( 'wp_footer', function () {
	if ( ! function_exists('is_product') || ! is_product() ) return;
	if ( ! bp_in_buy_tree( get_queried_object_id() ) ) return;
	$checkout_url = function_exists('bp_cartux_checkout_url_for_cart') ? bp_cartux_checkout_url_for_cart() : wc_get_checkout_url();
	?>
<script>
jQuery(function($){
  var CHECKOUT_URL = "<?php echo esc_js( $checkout_url ); ?>";
  $(document).on('click','#kh-buy-now',function(e){
    e.preventDefault();
    var $form = $('form.cart');
    var qty   = $form.find('input.qty').val() || 1;
    var pid   = $form.find('input[name=add-to-cart]').val() || "<?php echo (int) get_queried_object_id(); ?>";
    window.location.href = '?add-to-cart='+encodeURIComponent(pid)+'&quantity='+encodeURIComponent(qty)+'&kh_buy_now=1';
  });
});
</script>
<?php } );

/* =========================
   Performance trims
   FIX: Added bp_is_buy_archive() to keep wc-cart-fragments
   alive on archive pages — needed for AJAX validation
   notices (mixed cart) to reach the browser via fragments.
   All other logic unchanged.
========================= */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) return;
	$allow =
		( function_exists('is_cart')            && is_cart() )            ||
		( function_exists('is_checkout')        && is_checkout() )        ||
		( function_exists('is_wc_endpoint_url') && is_wc_endpoint_url() ) ||
		bp_is_buy_archive();  // FIX: keep fragments on archive so AJAX notices work
	if ( ! $allow ) {
		wp_dequeue_script( 'wc-cart-fragments' );
		wp_deregister_script( 'wc-cart-fragments' );
		add_filter( 'woocommerce_cart_fragment_refresh_rate', '__return_false' );
	}
}, 999 );

/* UNCHANGED */
add_action( 'init', function () {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
} );

/* UNCHANGED */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() ) wp_dequeue_style( 'dashicons' );
}, 100 );

/* =========================
   Keep Add to Cart visible (PDP, Buy Store only)
   UNCHANGED from original
========================= */
add_action( 'wp_footer', function () {
	if ( ! function_exists('is_product') || ! is_product() ) return;
	if ( ! bp_in_buy_tree( get_queried_object_id() ) ) return; ?>
<script>
(function(){
  function unhideBtn(btn){
    if (!btn) return;
    // Only remove what's explicitly hiding it; do NOT force a display mode.
    if (btn.style && btn.style.display === 'none') btn.style.removeProperty('display');
    if (btn.classList.contains('bp-disabled-btn')) btn.classList.remove('bp-disabled-btn');

    // If any ancestor inline-hides the button, remove just that inline rule.
    var a = btn;
    for (var i=0;i<3 && a;i++){ // check up to 3 ancestors (button -> form.cart -> wrapper)
      a = a.parentElement;
      if (!a) break;
      if (a.style && a.style.display === 'none') a.style.removeProperty('display');
      if (a.classList && a.classList.contains('bp-disabled-btn')) a.classList.remove('bp-disabled-btn');
    }
  }

  function scanOnce(){
    var btn = document.querySelector('body.bp-buy-single form.cart .single_add_to_cart_button');
    if (!btn) return;
    unhideBtn(btn);

    // Observe future attempts to hide it.
    if (!btn._bpKeepVisibleObs){
      var obs = new MutationObserver(function(muts){
        for (var i=0;i<muts.length;i++){
          var m = muts[i];
          if (m.type === 'attributes' &&
              (m.attributeName === 'style' || m.attributeName === 'class')) {
            unhideBtn(btn);
          }
        }
      });
      obs.observe(btn, { attributes:true, attributeFilter:['style','class'] });

      // Also watch the nearest form for inline hiding.
      var form = btn.closest('form.cart');
      if (form){
        var fObs = new MutationObserver(function(muts){
          muts.forEach(function(m){
            if (m.type === 'attributes' && m.attributeName === 'style') unhideBtn(btn);
          });
        });
        fObs.observe(form, { attributes:true, attributeFilter:['style'] });
      }
      btn._bpKeepVisibleObs = true;
    }
  }

  if (document.readyState !== 'loading') scanOnce(); else document.addEventListener('DOMContentLoaded', scanOnce);
  setTimeout(scanOnce, 250);
  setTimeout(scanOnce, 900);
  setTimeout(scanOnce, 1600);
})();
</script>
<?php } );

/* =========================
   FIX: Removed woocommerce_email_enabled → __return_false
   That line was disabling ALL WooCommerce emails sitewide
   (order confirmations, abandoned cart, password reset etc.)
   It should never be in production code.
========================= */
