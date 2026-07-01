<?php
/**
 * Plugin Name: BP Order Export – Client Safe (MU)
 * Description: Stable WooCommerce order export with product-level details (no 504)
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

/*--------------------------------------------------
 Admin Menu
--------------------------------------------------*/
add_action('admin_menu', function () {
    if (!class_exists('WooCommerce')) return;

    add_submenu_page(
        'woocommerce',
        'Order Export',
        'Order Export',
        'manage_woocommerce',
        'bp-safe-export',
        'bp_safe_export_ui'
    );
});

/*--------------------------------------------------
 UI
--------------------------------------------------*/
function bp_safe_export_ui() {
?>
<div class="wrap">
    <h1>Order Export</h1>

    <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="bp_safe_export">

        <table class="form-table">
            <tr>
                <th>Date range</th>
                <td>
                    <input type="date" name="from" required>
                    <input type="date" name="to" required>
                </td>
            </tr>

            <tr>
                <th>City</th>
                <td><input type="text" name="city"></td>
            </tr>

            <tr>
                <th>UTM source</th>
                <td><input type="text" name="utm"></td>
            </tr>

            <tr>
                <th>Product name contains</th>
                <td><input type="text" name="product_word"></td>
            </tr>
			
			<tr>
    <th>Order Status</th>
    <td>
        <select name="order_status[]" multiple style="width:250px;height:140px">
            <?php foreach (wc_get_order_statuses() as $key => $label): ?>
                <option value="<?php echo esc_attr(str_replace('wc-', '', $key)); ?>">
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            Hold Ctrl / Cmd to select multiple statuses. Leave empty for all.
        </p>
    </td>
</tr>


            <tr>
                <th>Product URL contains</th>
                <td><input type="text" name="url_word"></td>
            </tr>
        </table>

        <?php submit_button('Download CSV'); ?>
    </form>
</div>
<?php
}

/*--------------------------------------------------
 EXPORT HANDLER (SAFE)
--------------------------------------------------*/
add_action('admin_post_bp_safe_export', function () {

    if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

    // Hard safety
    ignore_user_abort(true);
    set_time_limit(0);
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=orders-export.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'Order ID','Order Date','Order Status',
        'Customer Name','Email','Address','City','Pincode',
        'Products','Categories',
        'Rental (Non-Tax)','Delivery','Deposit','Order Total',
        'UTM Source'
    ]);

    // ---------- INPUTS ----------
    $from   = sanitize_text_field($_GET['from']);
    $to     = sanitize_text_field($_GET['to']);

    $statuses = array_map('sanitize_text_field', $_GET['order_status'] ?? []);

    $cities  = array_filter(array_map('trim', explode(',', strtolower($_GET['city'] ?? ''))));
    $utms    = array_filter(array_map('trim', explode(',', strtolower($_GET['utm'] ?? ''))));
    $pwords  = array_filter(array_map('trim', explode(',', strtolower($_GET['product_word'] ?? ''))));
    $uwords  = array_filter(array_map('trim', explode(',', strtolower($_GET['url_word'] ?? ''))));

    $page = 1;
    $limit = 20;

    do {

        $orders = wc_get_orders([
            'limit'      => $limit,
            'paged'      => $page,
            'status'     => $statuses ?: array_keys(wc_get_order_statuses()),
            'date_after' => $from,
            'date_before'=> $to,
            'return'     => 'objects',
        ]);

        foreach ($orders as $order) {

            // ---------- CITY FILTER ----------
            if ($cities) {
                $city = strtolower($order->get_billing_city());
                if (!array_filter($cities, fn($c) => str_contains($city, $c))) continue;
            }

            // ---------- UTM FILTER ----------
            if ($utms) {
                $utm = strtolower((string)$order->get_meta('_afl_wc_utm_utm_source'));
                if (!array_filter($utms, fn($u) => str_contains($utm, $u))) continue;
            }

            // ---------- PRODUCT PROCESSING ----------
            $products = [];
            $urls     = [];
            $cats     = [];
            $rental   = 0;
            $matched  = false;

            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) continue;

                $name = strtolower($product->get_name());
                $url  = strtolower(get_permalink($product->get_id()));

                if ($pwords && !array_filter($pwords, fn($w) => str_contains($name, $w))) continue;
                if ($uwords && !array_filter($uwords, fn($w) => str_contains($url, $w))) continue;

                $matched = true;

                $products[] = $product->get_name();
                $urls[]     = get_permalink($product->get_id());
                $rental    += (float)$item->get_subtotal();

                $cats = array_merge(
                    $cats,
                    wp_get_post_terms($product->get_id(), 'product_cat', ['fields'=>'names'])
                );
            }

            if (($pwords || $uwords) && !$matched) continue;

            // ---------- DEPOSIT ----------
            $deposit = 0;
            foreach ($order->get_items('fee') as $fee) {
                $deposit += (float)$fee->get_total();
            }

            fputcsv($out, [
                $order->get_id(),
                $order->get_date_created()->date('Y-m-d H:i:s'),
                $order->get_status(),
                $order->get_formatted_billing_full_name(),
                $order->get_billing_email(),
                trim($order->get_billing_address_1().' '.$order->get_billing_address_2()),
                $order->get_billing_city(),
                $order->get_billing_postcode(),
                implode(' | ', $products),
                implode(' | ', array_unique($cats)),
                round($rental,2),
                $order->get_shipping_total(),
                round($deposit,2),
                $order->get_total(),
                $order->get_meta('_afl_wc_utm_utm_source')
            ]);
        }

        $page++;

    } while (!empty($orders));

    fclose($out);
    exit;
});
