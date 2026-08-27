<?php
/**
 * Bulk Invoice Builder
 *
 * Builds a single data structure for the bulk customer invoice PDF.
 * All calculations and aggregation happen here; the template only displays.
 *
 * @package Courier_Finance_Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bulk_Invoice_Builder {

    /**
     * Build invoice data from delivery or selected waybills.
     *
     * @param int    $delivery_id         Delivery ID (0 if using selected_ids).
     * @param int    $customer_id         Customer ID (required).
     * @param string $selected_ids_string Comma-separated waybill numbers (empty if using delivery_id).
     * @return array Invoice data: customer, company, delivery, waybill_*, transport_rows, misc_rows, processing_rows, border_clearing_rows, totals.
     */
    public static function build($delivery_id, $customer_id, $selected_ids_string = '') {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $waybills_data = [];
        $delivery = null;

        if ($delivery_id > 0) {
            $delivery = class_exists('KIT_Deliveries') ? KIT_Deliveries::get_delivery($delivery_id) : null;
            if (!$delivery) {
                return ['error' => 'Delivery not found'];
            }
            $waybills_data = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $waybills_table WHERE delivery_id = %d AND customer_id = %d ORDER BY waybill_no ASC",
                $delivery_id,
                $customer_id
            ), ARRAY_A);
        } elseif (!empty($selected_ids_string)) {
            $selected_ids_array = array_values(array_filter(array_map('trim', explode(',', $selected_ids_string))));
            $selected_ids_array = array_map('sanitize_text_field', $selected_ids_array);
            if (empty($selected_ids_array)) {
                return ['error' => 'No waybill IDs provided'];
            }
            $placeholders = implode(',', array_fill(0, count($selected_ids_array), '%s'));
            $waybills_data = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $waybills_table WHERE waybill_no IN ($placeholders) AND customer_id = %d ORDER BY waybill_no ASC",
                array_merge($selected_ids_array, [$customer_id])
            ), ARRAY_A);
        } else {
            return ['error' => 'Missing delivery ID or selected waybill numbers'];
        }

        if (empty($waybills_data)) {
            return ['error' => 'No waybills found for this customer'];
        }

        $customer = function_exists('get_customer_details') ? get_customer_details($customer_id) : null;
        if (!$customer) {
            return ['error' => 'Customer not found'];
        }

        $company = class_exists('KIT_Company') ? KIT_Company::get_details_array() : [];

        $waybill_numbers = [];
        $transport_rows = [];
        $aggregated_items = [];
        $aggregated_misc = [];
        $total_transport = 0.0;
        $total_mass = 0.0;
        $total_volume = 0.0;
        $charge_basis_counts = ['mass' => 0, 'volume' => 0];

        $sad500_count = 0;
        $sadc_count = 0;
        $intl_count = 0;
        $sad500_unit_price = 0.0;
        $sadc_unit_price = 0.0;
        $intl_unit_price = 0.0;

        foreach ($waybills_data as $wb) {
            $waybill_no = intval($wb['waybill_no']);
            $waybill_numbers[] = $waybill_no;

            $quotation = class_exists('KIT_Waybills') ? KIT_Waybills::getFullWaybillWithItems($waybill_no) : null;
            if (!$quotation || !isset($quotation->waybill)) {
                continue;
            }

            $waybill = (object) array_map(function ($v) {
                return $v === null ? '' : $v;
            }, (array) $quotation->waybill);

            $mass_charge = floatval($waybill->mass_charge ?? 0);
            $volume_charge = floatval($waybill->volume_charge ?? 0);
            $stored_basis = '';
            $stored_volume_rate = 0.0;
            $stored_mass_rate = 0.0;
            if (!empty($waybill->miscellaneous)) {
                $md = maybe_unserialize($waybill->miscellaneous);
                if (is_array($md) && isset($md['others'])) {
                    $stored_basis = isset($md['others']['used_charge_basis']) ? $md['others']['used_charge_basis'] : '';
                    $stored_volume_rate = isset($md['others']['volume_rate_used']) ? floatval($md['others']['volume_rate_used']) : 0.0;
                    $stored_mass_rate = isset($md['others']['mass_rate']) ? floatval($md['others']['mass_rate']) : 0.0;
                }
            }

            $charge_basis = '';
            if (!empty($waybill->charge_basis)) {
                $charge_basis = $waybill->charge_basis;
            } elseif (!empty($stored_basis)) {
                $charge_basis = $stored_basis;
            } else {
                $charge_basis = ($mass_charge > $volume_charge) ? 'mass' : 'volume';
            }
            $charge_basis = strtolower(trim((string) $charge_basis));
            if ($charge_basis === 'weight') {
                $charge_basis = 'mass';
            }

            $charge_amount = 0.0;
            $charge_quantity = 0.0;
            $charge_unit = '';
            $charge_rate = 0.0;
            $as_charged = false;

            if ($charge_basis === 'mass') {
                $charge_amount = $mass_charge;
                $charge_quantity = floatval($waybill->total_mass_kg ?? 0);
                $charge_unit = 'kg';
                $charge_rate = $stored_mass_rate;
                if ($charge_rate <= 0 && $charge_amount > 0 && $charge_quantity > 0) {
                    $charge_rate = $charge_amount / $charge_quantity;
                    $as_charged = true;
                }
                $total_transport += $charge_amount;
                $charge_basis_counts['mass']++;
                $total_mass += $charge_quantity;
            } else {
                $charge_amount = $volume_charge;
                $charge_quantity = floatval($waybill->total_volume ?? 0);
                if ($charge_quantity <= 0) {
                    $l = floatval($waybill->item_length ?? 0);
                    $w = floatval($waybill->item_width ?? 0);
                    $h = floatval($waybill->item_height ?? 0);
                    $charge_quantity = ($l * $w * $h) / 1000000;
                }
                $charge_unit = 'm³';
                $charge_rate = $stored_volume_rate;
                if ($charge_rate <= 0 && $charge_amount > 0 && $charge_quantity > 0) {
                    $charge_rate = $charge_amount / $charge_quantity;
                    $as_charged = true;
                }
                $total_transport += $charge_amount;
                $charge_basis_counts['volume']++;
                $total_volume += $charge_quantity;
            }

            $transport_rows[] = [
                'waybill_no'   => $waybill_no,
                // Mel (2026-08-05): generic transport label only — no goods description.
                'charge_type'  => 'Transport - ' . ucfirst($charge_basis) . ' Charge',
                'quantity'     => $charge_quantity,
                'unit'         => $charge_unit,
                'unit_rate'    => $charge_rate,
                'line_total'   => $charge_amount,
                'as_charged'   => $as_charged,
            ];

            if (!empty($quotation->items) && is_array($quotation->items)) {
                $waybill_vat_included = class_exists('KIT_Waybills')
                    && (KIT_Waybills::normalize_flag_int($waybill->vat_include ?? 0) === 1);
                if ($waybill_vat_included) {
                foreach ($quotation->items as $item) {
                    $item_name = $item['item_name'] ?? '';
                    $qty = intval($item['quantity'] ?? 0);
                    $unit_price = floatval($item['unit_price'] ?? 0);
                    if ($item_name === '') {
                        continue;
                    }
                    if (!isset($aggregated_items[$item_name])) {
                        $aggregated_items[$item_name] = ['qty' => 0, 'subtotal' => 0.0];
                    }
                    $aggregated_items[$item_name]['qty'] += $qty;
                    $aggregated_items[$item_name]['subtotal'] += $qty * $unit_price;
                }
                }
            }

            if (!empty($waybill->miscellaneous)) {
                $misc_data = maybe_unserialize($waybill->miscellaneous);
                if (is_array($misc_data) && isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
                    foreach ($misc_data['misc_items'] as $misc_item) {
                        $misc_name = $misc_item['misc_item'] ?? '';
                        $misc_qty = intval($misc_item['misc_quantity'] ?? 0);
                        $misc_price = floatval($misc_item['misc_price'] ?? 0);
                        if ($misc_name === '') {
                            continue;
                        }
                        if (!isset($aggregated_misc[$misc_name])) {
                            $aggregated_misc[$misc_name] = ['qty' => 0, 'price' => $misc_price, 'subtotal' => 0.0];
                        }
                        $aggregated_misc[$misc_name]['qty'] += $misc_qty;
                        $aggregated_misc[$misc_name]['subtotal'] += $misc_qty * $misc_price;
                    }
                }
                if (isset($misc_data['others'])) {
                    if (!empty($waybill->include_sad500) && intval($waybill->include_sad500) === 1) {
                        $amt = 0.0;
                        if (isset($misc_data['others']['include_sad500'])) {
                            $amt = class_exists('KIT_Waybills') ? KIT_Waybills::normalize_amount($misc_data['others']['include_sad500']) : 0;
                        }
                        if ($amt <= 0) {
                            $amt = class_exists('KIT_Waybills') ? floatval(KIT_Waybills::sadc_certificate()) : 0;
                        }
                        if ($sad500_unit_price <= 0 && $amt > 0) {
                            $sad500_unit_price = $amt;
                        }
                        $sad500_count++;
                    }
                    if (!empty($waybill->include_sadc) && intval($waybill->include_sadc) === 1) {
                        $amt = 0.0;
                        if (isset($misc_data['others']['include_sadc'])) {
                            $amt = class_exists('KIT_Waybills') ? KIT_Waybills::normalize_amount($misc_data['others']['include_sadc']) : 0;
                        }
                        if ($amt <= 0) {
                            $amt = class_exists('KIT_Waybills') ? floatval(KIT_Waybills::sad()) : 0;
                        }
                        if ($sadc_unit_price <= 0 && $amt > 0) {
                            $sadc_unit_price = $amt;
                        }
                        $sadc_count++;
                    }
                    if (KIT_Waybills::normalize_flag_int($waybill->vat_include ?? 0) === 0) {
                        $amt = 0.0;
                        if (isset($misc_data['others']['international_price_rands'])) {
                            $amt = floatval($misc_data['others']['international_price_rands']);
                        }
                        if ($amt <= 0) {
                            $amt = class_exists('KIT_Waybills') ? floatval(KIT_Waybills::international_price_in_rands()) : 0;
                        }
                        if ($intl_unit_price <= 0 && $amt > 0) {
                            $intl_unit_price = $amt;
                        }
                        $intl_count++;
                    }
                }
            }
        }

        $processing_rows = [];
        $total_sad500 = 0.0;
        $total_sadc = 0.0;
        $total_intl = 0.0;
        if ($sad500_count > 0) {
            if ($sad500_unit_price <= 0 && class_exists('KIT_Waybills')) {
                $sad500_unit_price = floatval(KIT_Waybills::sadc_certificate());
            }
            $total_sad500 = $sad500_unit_price * $sad500_count;
            $processing_rows[] = [
                'badge'       => 'Processing',
                'description' => 'SAD500',
                'qty'         => $sad500_count,
                'unit_price'  => $sad500_unit_price,
                'line_total'  => $total_sad500,
            ];
        }
        if ($sadc_count > 0) {
            if ($sadc_unit_price <= 0 && class_exists('KIT_Waybills')) {
                $sadc_unit_price = floatval(KIT_Waybills::sad());
            }
            $total_sadc = $sadc_unit_price * $sadc_count;
            $processing_rows[] = [
                'badge'       => 'Processing',
                'description' => 'SADC Certificate',
                'qty'         => $sadc_count,
                'unit_price'  => $sadc_unit_price,
                'line_total'  => $total_sadc,
            ];
        }
        if ($intl_count > 0) {
            if ($intl_unit_price <= 0 && class_exists('KIT_Waybills')) {
                $intl_unit_price = floatval(KIT_Waybills::international_price_in_rands());
            }
            $total_intl = $intl_unit_price * $intl_count;
            $processing_rows[] = [
                'badge'       => 'Customs Clearing',
                'description' => 'Agent Clearing & Documentation',
                'qty'         => $intl_count,
                'unit_price'  => $intl_unit_price,
                'line_total'  => $total_intl,
            ];
        }

        $total_misc = 0.0;
        $misc_rows = [];
        foreach ($aggregated_misc as $name => $data) {
            $total_misc += $data['subtotal'];
            $misc_rows[] = [
                'name'     => $name,
                'qty'      => $data['qty'],
                'price'    => $data['price'],
                'subtotal' => $data['subtotal'],
            ];
        }

        // Fee total only — itemised parcel names/values stay off the invoice
        // (Mel, 2026-08-05). Declared values were never part of grand_total.
        $border_clearing_rows = [];
        $border_clearing_total = 0.0;
        foreach ($aggregated_items as $item_name => $data) {
            $line_subtotal = $data['subtotal'];
            $fee_amount = class_exists('KIT_Waybills')
                ? KIT_Waybills::vatCharge($line_subtotal)
                : $line_subtotal * 0.10;
            $border_clearing_total += $fee_amount;
        }
        if ($border_clearing_total > 0) {
            $border_clearing_rows[] = [
                'item_name'            => 'Border Clearing Fee (10% of declared value)',
                'qty'                  => 1,
                'effective_unit_price' => $border_clearing_total,
                'line_subtotal'        => $border_clearing_total,
                'fee_amount'           => $border_clearing_total,
            ];
        }

        $processing_total = $total_sad500 + $total_sadc + $total_intl;
        $grand_total = $total_transport + $total_misc + $processing_total + $border_clearing_total;
        $primary_charge_basis = ($charge_basis_counts['mass'] >= $charge_basis_counts['volume']) ? 'mass' : 'volume';

        return [
            'customer'            => $customer,
            'company'             => $company ?: [],
            'delivery'            => $delivery,
            'delivery_id'         => $delivery_id,
            'waybill_count'       => count($waybill_numbers),
            'waybill_numbers'     => $waybill_numbers,
            'total_mass'          => $total_mass,
            'total_volume'        => $total_volume,
            'transport_rows'     => $transport_rows,
            'misc_rows'           => $misc_rows,
            'processing_rows'    => $processing_rows,
            'border_clearing_rows'=> $border_clearing_rows,
            'totals'              => [
                'transport'       => $total_transport,
                'misc'            => $total_misc,
                'processing'      => $processing_total,
                'border_clearing'=> $border_clearing_total,
                'grand_total'     => $grand_total,
            ],
            'primary_charge_basis'=> $primary_charge_basis,
        ];
    }
}
