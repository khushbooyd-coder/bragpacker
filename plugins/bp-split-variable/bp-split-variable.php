<?php
/**
 * BP – Reorder variable products into a second section (same UL, grid intact)
 */
add_action('wp_footer', function () {
  if ( ! function_exists('is_product_category') || ! is_product_category() ) return;

  $term  = get_queried_object();
  $title = ($term && !is_wp_error($term)) ? $term->name : 'Category';
  ?>
  <script>
  (function(){
    var MARK = 'data-bp-reordered';

    function getMainUL(){
      // Pick the UL with the most products
      var uls = Array.prototype.slice.call(document.querySelectorAll('ul.products'))
        .filter(function(ul){ return ul.querySelector(':scope > li.product'); });
      if (!uls.length) return null;
      uls.sort(function(a,b){
        return b.querySelectorAll(':scope > li.product').length -
               a.querySelectorAll(':scope > li.product').length;
      });
      return uls[0];
    }

    function reorder(){
      var ul = getMainUL();
      if (!ul || ul.hasAttribute(MARK)) return;

      // Collect cards only from THIS UL
      var all   = Array.prototype.slice.call(ul.querySelectorAll(':scope > li.product'));
      var vars  = all.filter(function(li){ return /\bproduct-type-variable\b/.test(li.className||''); });
      if (!vars.length) return;

      // Non-variable stay in place (we’ll rebuild order)
      var others = all.filter(function(li){ return vars.indexOf(li) === -1; });

      // Build a divider LI that spans full width
      var divider = document.createElement('li');
      divider.className = 'product bp-variable-divider';
      divider.setAttribute('aria-hidden','true');
      divider.innerHTML = '<h2 class="bp-variable-title">Products with Options in ' + <?php echo json_encode($title); ?> + '</h2>';

      // Rebuild list: others → divider → vars
      // 1) Remove all (keeps UL)
      all.forEach(function(li){ try{ ul.removeChild(li); }catch(e){} });
      // 2) Append others
      others.forEach(function(li){ try{ ul.appendChild(li); }catch(e){} });
      // 3) Append divider
      try{ ul.appendChild(divider); }catch(e){}
      // 4) Append variables
      vars.forEach(function(li){ try{ ul.appendChild(li); }catch(e){} });

      ul.setAttribute(MARK,'1');

      // Reflow Woodmart/Woo
      try { document.dispatchEvent(new Event('woodmart-products-loaded')); } catch(e){}
      try { document.dispatchEvent(new Event('updated_wc_div')); } catch(e){}
      try { window.dispatchEvent(new Event('resize')); } catch(e){}
      if (window.jQuery){
        try { jQuery(document).trigger('woodmart-products-loaded'); } catch(e){}
        try { jQuery(document).trigger('updated_wc_div'); } catch(e){}
      }
    }

    if (document.readyState !== 'loading') reorder();
    else document.addEventListener('DOMContentLoaded', reorder);

    // Re-run on lazy/AJAX loads
    ['woodmart-ajax-content-reloaded','woodmart-products-loaded','yith_infs_woocommerce_products_loaded','wc_fragments_refreshed']
      .forEach(function(ev){ document.addEventListener(ev, function(){ setTimeout(reorder, 60); }, {passive:true}); });

    // Safety delayed passes
    setTimeout(reorder, 250);
    setTimeout(reorder, 900);
  })();
  </script>
  <style>
    /* Full-width section header inside the same UL */
    .bp-variable-divider{
      width:100% !important;
      float:none !important;
      clear:both !important;
      display:block !important;
      margin: 12px 0 6px !important;
      padding: 0 !important;
      border: 0 !important;
      box-shadow:none !important;
      background:transparent !important;
    }
    .bp-variable-divider .bp-variable-title{
      font-size:20px; font-weight:700; line-height:1.3; margin:0; color:#222;
      padding:4px 0;
    }

    /* Ensure “Select options” gets the orange CTA styling everywhere */
    .bp-buy-archive a.product_type_variable.button,
    .bp-buy-archive a.product_type_grouped.button,
    .bp-buy-archive a.product_type_external.button{
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

    /* Keep icons in the price row, after price (“Buy for …”) */
    .bp-buy-archive .wrap-price{
      display:flex !important;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .bp-buy-archive .wd-action-buttons{
      display:flex !important;
      gap:10px;
      margin-left:0 !important;
      order:2; /* after price text */
    }
  </style>
  <?php
});
