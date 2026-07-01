<?php
/**
 * Plugin Name: Bragpacker – GST CSV Export (Bulk Actions + Counts)
 * Description: Monthly GST CSV export from Orders screen (respects month/status filters). Also adds an admin page with month picker & live status counts.
 * Author: Bragpacker
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Brag_GST_Export_Pro' ) ) :

class Brag_GST_Export_Pro {

	public function __construct() {
		if ( is_admin() ) {
			// Bulk action hooks on Orders list
			add_action( 'admin_footer-edit.php', [ $this, 'add_bulk_action_option' ] );
			add_action( 'load-edit.php',          [ $this, 'handle_bulk_action' ] );

			// Optional: menu page with month picker + counts
			add_action( 'admin_menu',             [ $this, 'menu' ] );
			add_action( 'admin_post_brag_export_gst_csv', [ $this, 'handle_page_download' ] );

			// AJAX for counts on the page
			add_action( 'wp_ajax_brag_gst_counts', [ $this, 'ajax_counts' ] );
		}
	}

	/* ------------------------------
	 * UI: add "Export GST CSV" bulk action on Orders
	 * ------------------------------ */
	public function add_bulk_action_option() {
		global $post_type;
		if ( $post_type !== 'shop_order' ) return; ?>
		<script>
		jQuery(function($){
		  var opt = $('<option>').val('brag_export_gst_csv').text('Export GST CSV');
		  $('select[name="action"], select[name="action2"]').each(function(){
		    // Avoid duplicates
		    var has = false;
		    $(this).find('option').each(function(){ if($(this).val()==='brag_export_gst_csv') has = true; });
		    if (!has) $(this).append(opt.clone());
		  });
		});
		</script>
		<?php
	}

	public function handle_bulk_action() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'edit-shop_order' ) return;

		// Get current list table action
		$tbl = _get_list_table( 'WP_Posts_List_Table' );
		$action = $tbl ? $tbl->current_action() : '';

		if ( $action !== 'brag_export_gst_csv' ) return;

		check_admin_referer( 'bulk-posts' );

		// Selected IDs (if any)
		$ids = isset( $_REQUEST['post'] ) ? array_map( 'absint', (array) $_REQUEST['post'] ) : [];

		// Build a query based on the current screen filters if no IDs selected
		if ( empty( $ids ) ) {
			$orders = $this->query_orders_from_current_list();
		} else {
			$orders = wc_get_orders( [
				'limit'      => -1,
				'type'       => 'shop_order',
				'status'     => 'any',
				'include'    => $ids,
				'orderby'    => 'date',
				'order'      => 'ASC',
			] );
		}

		$this->stream_csv( $orders, 'bulk' );
		exit;
	}

	/**
	 * Read current Orders list query (month/status/search) and build wc_get_orders() args.
	 * - Month comes from `m=YYYYMM`
	 * - Status can be wc-*, or "all"
	 */
	private function query_orders_from_current_list() {
		$m          = isset( $_REQUEST['m'] ) ? sanitize_text_field( $_REQUEST['m'] ) : '';
		$post_stat  = isset( $_REQUEST['post_status'] ) ? sanitize_text_field( $_REQUEST['post_status'] ) : 'all';

		// Map list status to order statuses
		$statuses = 'any';
		if ( $post_stat && $post_stat !== 'all' ) {
			// Accept single or comma separated; normalize "wc-xxx"
			$raw = array_map( 'trim', explode( ',', $post_stat ) );
			$st  = [];
			foreach ( $raw as $s ) {
				$s = ltrim( $s, '!' ); // list screens sometimes prefix
				if ( strpos( $s, 'wc-' ) !== 0 ) $s = 'wc-' . $s;
				$st[] = $s;
			}
			if ( ! empty( $st ) ) $statuses = $st;
		}

		$args = [
			'limit'  => -1,
			'type'   => 'shop_order',
			'status' => $statuses,
			'orderby'=> 'date',
			'order'  => 'ASC',
		];

		// Month filter (YYYYMM)
		if ( preg_match( '~^\d{6}$~', $m ) ) {
			$year  = substr( $m, 0, 4 );
			$month = substr( $m, 4, 2 );

			try {
				$start = new DateTime( sprintf( '%s-%s-01 00:00:00', $year, $month ), wp_timezone() );
				$end   = (clone $start)->modify( 'last day of this month 23:59:59' );
				$args['date_created'] = $this->between( $start, $end );
			} catch ( \Throwable $e ) {
				// ignore bad month
			}
		}

		return wc_get_orders( $args );
	}

	/* ------------------------------
	 * Optional Menu page (with counts)
	 * ------------------------------ */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Export GST CSV',
			'Export GST CSV',
			'manage_woocommerce',
			'brag-export-gst',
			[ $this, 'render_page' ]
		);
	}

	private function months_dropdown_options( $recent = 24 ) {
		$out = '';
		$now = current_time( 'timestamp' );
		for ( $i = 0; $i < $recent; $i++ ) {
			$ts   = strtotime( "-$i months", $now );
			$val  = gmdate( 'Y-m', $ts );   // 2025-10
			$nice = gmdate( 'F Y', $ts );   // October 2025
			$out .= sprintf( '<option value="%s">%s</option>', esc_attr( $val ), esc_html( $nice ) );
		}
		return $out;
	}

	public function render_page() { ?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Export GST CSV</h1>
			<p>Select a month; we’ll export orders created in that month. You can include/exclude statuses and see counts before you download.</p>

			<form id="brag-gst-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<input type="hidden" name="action" value="brag_export_gst_csv">
				<?php wp_nonce_field( 'brag_export_gst_csv' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="brag_month">Month</label></th>
						<td>
							<select id="brag_month" name="brag_month" required>
								<option value="">Select month…</option>
								<?php echo $this->months_dropdown_options(); // phpcs:ignore ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Statuses</th>
						<td>
							<label><input type="checkbox" name="status[]" value="processing" checked> Processing</label>&nbsp;&nbsp;
							<label><input type="checkbox" name="status[]" value="completed" checked> Completed</label>&nbsp;&nbsp;
							<label><input type="checkbox" name="status[]" value="on-hold"> On hold</label>
							<div id="brag-counts" style="margin-top:8px;color:#444;"></div>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Download CSV' ); ?>
			</form>
		</div>
		<script>
		(function($){
			function fetchCounts(){
				var m = $('#brag_month').val();
				var st = [];
				$('input[name="status[]"]:checked').each(function(){ st.push($(this).val()); });
				if(!m) { $('#brag-counts').html(''); return; }

				$('#brag-counts').html('Counting…');
				$.post(ajaxurl, {
					action: 'brag_gst_counts',
					nonce: '<?php echo esc_js( wp_create_nonce( 'brag_gst_counts' ) ); ?>',
					month: m,
					status: st
				}, function(resp){
					if(!resp || !resp.success){ $('#brag-counts').html(''); return; }
					var d = resp.data || {};
					console.log(d);
					var html = '<strong>Counts</strong>: ';
					var parts = [];
					if (d.processing !== undefined) parts.push('Processing: ' + d.processing);
					if (d.completed  !== undefined) parts.push('Completed: ' + d.completed);
					if (d['on-hold'] !== undefined) parts.push('On hold: ' + d['on-hold']);
					parts.push('<em>Total: ' + (d.total||0) + '</em>');
					$('#brag-counts').html(html + parts.join(' &nbsp;|&nbsp; '));
				}, 'json');
			}
			$(document).on('change', '#brag_month, input[name="status[]"]', fetchCounts);
		})(jQuery);
		</script>
	<?php }

	public function ajax_counts() {
		check_ajax_referer( 'brag_gst_counts', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();

		$month    = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$statuses = isset( $_POST['status'] ) && is_array( $_POST['status'] ) ? array_map( 'wc_clean', $_POST['status'] ) : [ 'processing', 'completed' ];

		if ( ! preg_match( '~^\d{4}-\d{2}$~', $month ) ) wp_send_json_error();

		$start = new DateTime( $month . '-01 00:00:00', wp_timezone() );
		$end   = (clone $start)->modify( 'last day of this month 23:59:59' );

		$counts = [ 'processing' => 0, 'completed' => 0, 'on-hold' => 0, 'total' => 0 ];

		foreach ( $statuses as $st ) {
			$orders = wc_get_orders( [
				'limit'        => -1,
				'type'         => 'shop_order',
				'status'       => [ 'wc-' . $st ],
				'date_created' => $this->between( $start, $end ),
				'return'       => 'ids',
			] );
			$counts[ $st ] = is_array( $orders ) ? count( $orders ) : 0;
			$counts['total'] += $counts[ $st ];
		}

		wp_send_json_success( $counts );
	}

	/* ------------------------------
	 * Handle menu-page download
	 * ------------------------------ */
	public function handle_page_download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized', 403 );
		check_admin_referer( 'brag_export_gst_csv' );

		$month    = isset( $_POST['brag_month'] ) ? sanitize_text_field( wp_unslash( $_POST['brag_month'] ) ) : '';
		$statuses = isset( $_POST['status'] ) && is_array( $_POST['status'] ) ? array_map( 'wc_clean', $_POST['status'] ) : [ 'processing', 'completed' ];

		if ( ! preg_match( '~^\d{4}-\d{2}$~', $month ) ) wp_die( 'Invalid month format.' );

		$start = new DateTime( $month . '-01 00:00:00', wp_timezone() );
		$end   = (clone $start)->modify( 'last day of this month 23:59:59' );
		
		//$wc_statuses = array_map( fn($s) => 'wc-' . ltrim( $s, 'wc-' ), $statuses );
		
		$wc_statuses = array_values( array_filter( array_map( function( $s ) {
		$s = wc_clean( $s );
		$s = trim( $s );
		// remove exact 'wc-' prefix if present (safe)
		if ( str_starts_with( $s, 'wc-' ) ) {
				$s = substr( $s, 3 );
			}
			return $s === '' ? null : $s;
		}, $statuses ) ) );
		
		$orders = wc_get_orders( [
			'limit'        => -1,
			'type'         => 'shop_order',
			'status'       => $wc_statuses,
			'date_created' => $this->between( $start, $end ),
		] );

		$this->stream_csv( $orders, $start->format( 'Y-m' ) );
		exit;
	}

	/* ------------------------------
	 * CSV generator (shared)
	 * ------------------------------ */
	private function stream_csv( $orders, $label = 'export' ) {
		if ( ! is_array( $orders ) ) $orders = [];

		$filename = sprintf( 'export_order_tax_%s.csv', is_string($label) ? $label : date('Y-m-d') );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		$headers = [
			'NAME OF THE CUSTOMER',
			'CITY',
			'GSTIN NUMBER OF CUSTOMER',
			'INVOICE NO',
			'INVOICE DATE',
			'TAXABLE AMOUNT',
			'GST %',
			'SGST',
			'CGST',
			'TOTAL',
		];
		fputcsv( $out, $headers );

		foreach ( $orders as $order ) {
			if ( is_numeric( $order ) ) $order = wc_get_order( $order );
			if ( ! $order instanceof WC_Order ) continue;

			$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$city          = $order->get_shipping_city() ?: $order->get_billing_city();
			$gstin         = get_user_meta( (int) $order->get_customer_id(), 'customer_gst_number', true );
			$invoice_no    = $order->get_order_number();
			$invoice_date  = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '';

			$discount_total     = (float) $order->get_discount_total();
			$order_shipping     = (float) $order->get_shipping_total();
			$order_shipping_tax = (float) $order->get_shipping_tax();

			$taxable = 0.0;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$data = $item->get_data();
				$taxable += (float) ( $data['subtotal'] ?? 0 );
			}
			foreach ( $order->get_items( 'fee' ) as $fee ) {
				$fee_total = (float) $fee->get_total();
				$fee_tax   = (float) $fee->get_total_tax();
				if ( $fee_tax > 0 ) $taxable += $fee_total;
			}
			if ( $order_shipping > 0 ) $taxable += $order_shipping;

			$taxable -= $discount_total;
			if ( $taxable < 0 ) $taxable = 0;

			$cgst = 0.0; $sgst = 0.0; $igst = 0.0; $total_tax = 0.0; $rate_guess = '';

			foreach ( $order->get_items( 'tax' ) as $tax_item ) {
				$label    = (string) $tax_item->get_label(); // e.g., "18% CGST"
				$line_tax = (float) $tax_item->get_tax_total() + (float) $tax_item->get_shipping_tax_total();
				$total_tax += $line_tax;

				$L = strtoupper( $label );
				if ( strpos( $L, 'CGST' ) !== false ) $cgst += $line_tax;
				elseif ( strpos( $L, 'SGST' ) !== false ) $sgst += $line_tax;
				elseif ( strpos( $L, 'IGST' ) !== false ) $igst += $line_tax;

				if ( $rate_guess === '' && preg_match( '~(\d+(?:\.\d+)?)\s*%~', $label, $m ) ) {
					$rate_guess = $m[1];
				}
			}

			// If plugin only shows "GST", split evenly
			if ( $cgst == 0 && $sgst == 0 && $total_tax > 0 && $igst == 0 ) {
				$cgst = $total_tax / 2;
				$sgst = $total_tax / 2;
			}

			if ( $rate_guess === '' && $taxable > 0 && $total_tax > 0 ) {
				$rate_guess = round( ( $total_tax / $taxable ) * 100, 2 );
			}

			$row = [
				$customer_name,
				$city,
				$gstin,
				$invoice_no,
				$invoice_date,
				number_format( (float) $taxable, 2, '.', '' ),
				$rate_guess,
				number_format( (float) $sgst, 2, '.', '' ),
				number_format( (float) $cgst, 2, '.', '' ),
				number_format( (float) $order->get_total(), 2, '.', '' ),
			];

			fputcsv( $out, array_map( [ $this, 'csv_safe' ], $row ) );
		}

		fclose( $out );
	}

	/* ------------------------------
	 * Helpers
	 * ------------------------------ */
	private function between( DateTime $start, DateTime $end ) {
		$s = clone $start; $e = clone $end;
		$s->setTimezone( new DateTimeZone( 'UTC' ) );
		$e->setTimezone( new DateTimeZone( 'UTC' ) );
		return sprintf( '%s...%s', $s->format( 'Y-m-d H:i:s' ), $e->format( 'Y-m-d H:i:s' ) );
	}

	private function csv_safe( $v ) {
		$v = (string) $v;
		if ( preg_match( '/^[=\-+@]/', $v ) ) $v = "'".$v;
		return $v;
	}
}

new Brag_GST_Export_Pro();

endif;
