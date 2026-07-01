<?php
/**
 * Plugin Name: Bragpacker – Rental Checkout City + Metro + Pincode Logic
 * Description: Rental checkout only – City dropdown, Metro grouping, pincode auto-detection, popup for Other
 * Version: 6.0
 * Author: Bragpacker Dev
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------------------------
   METRO → PINCODE → CITY MAP
-------------------------------------------------- */
function brg_area_map() {
    return [
        'mumbai_mmr' => [
            'label' => 'Mumbai MMR',
            'cities' => [
                'Mumbai'            => [[400001, 400107]],
                'Thane'             => [[400601, 400615]],
                'Navi Mumbai'       => [[410206, 410210]],
                'Kalyan/Dombivli'   => [[421201, 421306]],
            ],
        ],
        'pune_mmr' => [
            'label' => 'Pune MMR',
            'cities' => [
                'Pune'              => [[411001, 411062]],
                'Pimpri-Chinchwad'  => [[412101, 412412]],
            ],
        ],
        'delhi_ncr' => [
            'label' => 'Delhi NCR',
            'cities' => [
                'Delhi'    => [[110001, 110096]],
                'Gurgaon'  => [[122001, 122018]],
                'Noida'    => [[201301, 201315]],
            ],
        ],
    ];
}

/* -------------------------------------------------
   DETECT METRO + CITY FROM PINCODE
-------------------------------------------------- */
function brg_detect_area_from_pincode($pincode) {
    $pincode = preg_replace('/\s+/', '', $pincode);
    if (!ctype_digit($pincode)) return false;

    $pin = (int) $pincode;

    foreach (brg_area_map() as $metro_key => $metro) {
        foreach ($metro['cities'] as $city => $ranges) {
            foreach ($ranges as $r) {
                if ($pin >= $r[0] && $pin <= $r[1]) {
                    return [
                        'metro' => $metro_key,
                        'city'  => $city,
                    ];
                }
            }
        }
    }
    return false;
}

/* -------------------------------------------------
   MODIFY CHECKOUT FIELDS (RENTAL ONLY)
-------------------------------------------------- */
add_filter('woocommerce_checkout_fields', function ($fields) {

    if (!is_page('rental-checkout')) return $fields;

    // Remove Address Line 2
    unset($fields['billing']['billing_address_2']);

    // Add Metro field
    $fields['billing']['billing_metro_area'] = [
        'type'     => 'select',
        'label'    => 'Metropolitan Area',
        'required' => true,
        'priority' => 60,
        'options'  => [
            ''            => 'Select Area',
            'mumbai_mmr'  => 'Mumbai MMR',
            'pune_mmr'    => 'Pune MMR',
            'delhi_ncr'   => 'Delhi NCR',
            'other'       => 'Other',
        ],
    ];

    /*
     * 🔥 FORCE ORDER:
     * City → Metro → Pincode
     */
    $ordered = [];

    foreach ($fields['billing'] as $key => $field) {
        if ($key === 'billing_city') {
            $ordered[$key] = $field;
            $ordered['billing_metro_area'] = $fields['billing']['billing_metro_area'];
        } elseif ($key !== 'billing_metro_area') {
            $ordered[$key] = $field;
        }
    }

    $fields['billing'] = $ordered;

    return $fields;
}, 20);

/* -------------------------------------------------
   FRONTEND JS – AUTO SELECT METRO + CITY + POPUP
-------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {

    if (!is_page('rental-checkout')) return;

    wp_register_script('brg-area-js', false);
    wp_enqueue_script('brg-area-js');

    wp_add_inline_script('brg-area-js', '
        const areaMap = ' . json_encode(brg_area_map()) . ';

        function detectArea(pin) {
            pin = pin.replace(/\\s+/g, "");
            if (!/^\\d{6}$/.test(pin)) return null;
            pin = parseInt(pin);

            for (const metro in areaMap) {
                for (const city in areaMap[metro].cities) {
                    for (const r of areaMap[metro].cities[city]) {
                        if (pin >= r[0] && pin <= r[1]) {
                            return { metro, city };
                        }
                    }
                }
            }
            return { metro: "other", city: "" };
        }

        document.addEventListener("input", function(e) {
            if (e.target.name !== "billing_postcode") return;

            const metroField = document.querySelector("select[name=billing_metro_area]");
            const cityField = document.querySelector("input[name=billing_city]");
            if (!metroField || !cityField) return;

            const result = detectArea(e.target.value);
            if (!result) return;

            metroField.value = result.metro;
            metroField.dispatchEvent(new Event("change"));

            if (result.city && cityField.querySelector(`option[value="${result.city}"]`)) {
                cityField.value = result.city;
            }
        });

        document.addEventListener("change", function(e) {

    if (e.target.name === "billing_metro_area") {

        const metroValue = e.target.value;
        let popup = document.getElementById("brg-shipping-popup");

        if (metroValue === "other") {

            if(!popup){

                popup = document.createElement("div");
                popup.id = "brg-shipping-popup";

                popup.innerHTML = `
                    <strong>Please note</strong><br>
                    We can ship Photography & Trekking Gear to major metros, subject to:<br><br>
                    • Minimum Rs 2000 rental fee<br>
                    • Minimum 4 days to rental start date
                `;

                popup.style.background = "#fff3cd";
                popup.style.color = "#8a4b00";
                popup.style.border = "1px solid #ffeeba";
                popup.style.padding = "14px 16px";
                popup.style.marginTop = "10px";
                popup.style.borderRadius = "6px";
                popup.style.fontSize = "14px";
                popup.style.lineHeight = "1.5";

                const metroField = document.querySelector("select[name=billing_metro_area]");

                if(metroField){
                    metroField.parentNode.appendChild(popup);
                }
            }

        } else {

            // remove popup if user changes selection
            if(popup){
                popup.remove();
            }

        }
    }
});

        document.addEventListener("DOMContentLoaded", function() {
            const form = document.querySelector("form.checkout");
            if (form && !form.querySelector("[name=is_rental_checkout]")) {
                const i = document.createElement("input");
                i.type = "hidden";
                i.name = "is_rental_checkout";
                i.value = "1";
                form.appendChild(i);
            }
        });
    ');
});

/* -------------------------------------------------
   HARD BACKEND VALIDATION (PINCODE + METRO ONLY)
-------------------------------------------------- */
add_action('woocommerce_checkout_create_order', function ($order, $data) {

    if (!isset($_POST['is_rental_checkout']) || $_POST['is_rental_checkout'] !== '1') {
        return;
    }

    $metro   = sanitize_text_field($_POST['billing_metro_area'] ?? '');
    $pincode = preg_replace('/\s+/', '', $_POST['billing_postcode'] ?? '');

    if ($metro === 'other') return;

    if (!$metro || !$pincode) {
        throw new Exception('Please select Metropolitan Area and enter a valid pincode.');
    }

    $detected = brg_detect_area_from_pincode($pincode);

    if (!$detected || $detected['metro'] !== $metro) {
        throw new Exception('Pincode is not serviceable for the selected metropolitan area.');
    }

}, 10, 2);
