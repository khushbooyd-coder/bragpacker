# Bragpacker WordPress Plugins

Custom plugins and MU plugins built for bragpacker.com

---

## Plugins (`/plugins`)

Each folder goes into `wp-content/plugins/` on the live site.

| Plugin | Description |
|--------|-------------|
| `bragpacker-cart-ux` v1.4.0 | Split checkout (Buy vs Rental), mixed cart blocking with notices, Buy Now AJAX, side-cart routing, qty steppers, rental UX, refundable deposit |
| `bragpacker-kh-buy-now` v1.1.0 | Buy Now button for Buy Store (PDP + archive). Intercepts Add to Cart + Buy Now clicks to block mixed carts with notices |
| `bragpacker-rental-checkout` v6.0 | Rental checkout — Metro grouping (Mumbai/Pune/Delhi), pincode auto-detect, popup for Other, backend validation |
| `brag-gst-export` v1.0 | Monthly GST CSV export from Orders screen. Bulk action + admin page with month picker and live status counts |
| `bp-order-export` v1.0 | Advanced order CSV export with filters — city, UTM, product name, URL, date range, status |
| `bp-split-variable` | Reorders variable products into a separate section on Buy Store archive pages |
| `brag-rental-step-and-ty` v1.0 | Rental 2-step flow — keeps rental orders as Pending, sends Booking Confirmation Pending email; routes thank-you page by order type |

---

## MU Plugins (`/mu-plugins`)

These go into `wp-content/mu-plugins/` — always-on, no activation needed.

| File | Description |
|------|-------------|
| `01-suppress-doing-it-wrong.php` | Silences `wp_add_inline_script` and `wc-accounting` doing_it_wrong notices; logs clean caller info |
| `02-dedupe-wcj-cron.php` | Prevents Booster/Jetpack XML cron time from being double-written in same request |
| `20-preload-textdomains.php` | Preloads plugin translations early to fix JIT textdomain notices |
| `21-rest-permission-fallbacks.php` | Adds `permission_callback` to public REST routes missing it (WP 5.5+ requirement) |
| `compat-wc-accounting.php` | Aliases `accounting` ↔ `wc-accounting` script handles; guarantees order button text exists |
| `db-health.php` | Weekly auto-cleanup — transients, WC sessions, Action Scheduler old entries, heavy autoload options |

---

## Excluded (intentionally not in repo)

| File | Reason |
|------|--------|
| `wc-email-debug.php` | Temp debug tool — remove from live site |
| `doingitwrong-backtrace.php` | Temp debug tool |
| `track-accounting-enqueue.php` | Temp debug tool |
| `_patchstack.php` | Auto-managed by Patchstack plugin |
| `firewall_php.bak` | Backup file |
| `health-check-troubleshooting-mode.php` | WordPress.org plugin, not custom code |
| `kh-buynow.php` (old) | Superseded by `bragpacker-kh-buy-now` v1.1.0 |

---

## Author
Khushboo Dahat
