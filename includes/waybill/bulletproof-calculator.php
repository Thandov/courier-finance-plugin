<?php
/**
 * BULLETPROOF Waybill Calculator
 * 
 * This class provides a clean, reliable waybill calculation system
 * that eliminates all the inconsistencies in the current system.
 */

class KIT_Bulletproof_Calculator
{
    /**
     * Calculate waybill total with all charges
     * 
     * @param array $params All calculation parameters
     * @return array Complete calculation breakdown
     */
    public static function calculate_waybill_total($params)
    {
        // Extract parameters with defaults
        $mass_charge = floatval($params['mass_charge'] ?? 0);
        $volume_charge = floatval($params['volume_charge'] ?? 0);
        $misc_total = floatval($params['misc_total'] ?? 0);
        $waybill_items_total = floatval($params['waybill_items_total'] ?? 0);
        $charge_basis = $params['charge_basis'] ?? 'auto';
        
        // Charge options
        $include_sad500 = (bool)($params['include_sad500'] ?? false);
        $include_sadc = (bool)($params['include_sadc'] ?? false);
        $include_vat = (bool)($params['include_vat'] ?? false);
        // Enforce business rule server-side: VAT and SADC are mutually exclusive.
        if ($include_vat) {
            $include_sadc = false;
        }
        // Optional explicit international price (e.g., stored snapshot)
        $international_price_override = isset($params['international_price_override'])
            ? floatval($params['international_price_override'])
            : null;
        
        // Initialize calculation breakdown
        $breakdown = [
            'base_charges' => [],
            'additional_charges' => [],
            'totals' => []
        ];
        
        // 1. DETERMINE PRIMARY CHARGE (Better Charge)
        $primary_charge = self::determine_primary_charge($mass_charge, $volume_charge, $charge_basis);
        $breakdown['base_charges'] = [
            'mass_charge' => $mass_charge,
            'volume_charge' => $volume_charge,
            'primary_charge' => $primary_charge,
            'charge_basis' => $primary_charge['basis']
        ];
        
        // 2. CALCULATE ADDITIONAL CHARGES
        $additional_charges = self::calculate_additional_charges([
            'primary_amount' => $primary_charge['amount'],
            'waybill_items_total' => $waybill_items_total,
            'misc_total' => $misc_total,
            'include_sad500' => $include_sad500,
            'include_sadc' => $include_sadc,
            'include_vat' => $include_vat,
            'international_price_override' => $international_price_override
        ]);
        
        $breakdown['additional_charges'] = $additional_charges;
        
        // 3. CALCULATE FINAL TOTALS
        $subtotal = $primary_charge['amount'] + $additional_charges['total'];
        $breakdown['totals'] = [
            'subtotal' => $subtotal,
            'waybill_amount' => $primary_charge['amount'],
            'additional_charges_total' => $additional_charges['total'],
            'final_total' => $subtotal
        ];
        
        return $breakdown;
    }
    
    /**
     * Determine the primary charge (mass vs volume)
     */
    private static function determine_primary_charge($mass_charge, $volume_charge, $charge_basis)
    {
        if ($charge_basis === 'mass' || $charge_basis === 'weight') {
            return [
                'amount' => $mass_charge,
                'basis' => 'mass',
                'description' => 'Mass-based charge'
            ];
        } elseif ($charge_basis === 'volume') {
            return [
                'amount' => $volume_charge,
                'basis' => 'volume',
                'description' => 'Volume-based charge'
            ];
        } else {
            // Auto-select: choose the higher amount
            if ($mass_charge >= $volume_charge) {
                return [
                    'amount' => $mass_charge,
                    'basis' => 'mass',
                    'description' => 'Mass-based charge (auto-selected)'
                ];
            } else {
                return [
                    'amount' => $volume_charge,
                    'basis' => 'volume',
                    'description' => 'Volume-based charge (auto-selected)'
                ];
            }
        }
    }
    
    /**
     * Calculate all additional charges
     */
    private static function calculate_additional_charges($params)
    {
        $charges = [
            'misc_total' => $params['misc_total'],
            'sad500' => 0,
            'sadc' => 0,
            'vat' => 0,
            'international_price' => 0,
            'total' => $params['misc_total']
        ];
        
        // SAD500 Charge
        if ($params['include_sad500']) {
            $charges['sad500'] = self::get_sad500_charge();
            $charges['total'] += $charges['sad500'];
        }
        
        // SADC Certificate Charge
        if ($params['include_sadc']) {
            $charges['sadc'] = self::get_sadc_charge();
            $charges['total'] += $charges['sadc'];
        }
        
        // VAT or International Price (mutually exclusive)
        if ($params['include_vat']) {
            // Business rule: VAT applies to parcel/items total only.
            $vat_base = $params['waybill_items_total'];
            $charges['vat'] = self::calculate_vat($vat_base);
            $charges['total'] += $charges['vat'];
        } else {
            // Add international price when VAT is not included
            if (isset($params['international_price_override']) && $params['international_price_override'] !== null) {
                $charges['international_price'] = floatval($params['international_price_override']);
            } else {
                $charges['international_price'] = self::get_international_price();
            }
            $charges['total'] += $charges['international_price'];
        }
        
        return $charges;
    }
    
    /**
     * Get SAD500 charge amount
     */
    private static function get_sad500_charge()
    {
        global $wpdb;
        $charge = null;

        if (isset($wpdb)) {
            $charge = $wpdb->get_var("SELECT sad500_charge FROM {$wpdb->prefix}kit_company_details LIMIT 1");
            if ($charge !== null) {
                $charge = floatval($charge);
                if ($charge > 0) {
                    return $charge;
                }
            }
        }

        if (class_exists('KIT_Waybills')) {
            return KIT_Waybills::defaultSad500Charge();
        }

        return 350.00;
    }
    
    /**
     * Get SADC certificate charge amount
     */
    private static function get_sadc_charge()
    {
        global $wpdb;
        $charge = null;

        if (isset($wpdb)) {
            $charge = $wpdb->get_var("SELECT sadc_charge FROM {$wpdb->prefix}kit_company_details LIMIT 1");
            if ($charge !== null) {
                $charge = floatval($charge);
                if ($charge > 0) {
                    return $charge;
                }
            }
        }

        if (class_exists('KIT_Waybills')) {
            return KIT_Waybills::defaultSadcCharge();
        }

        return 1000.00;
    }
    
    /**
     * Calculate VAT amount
     */
    private static function calculate_vat($base_amount)
    {
        $vat_rate = 0.10;
        if (class_exists('KIT_Waybills') && method_exists('KIT_Waybills', 'vatRate')) {
            $pct = floatval(KIT_Waybills::vatRate());
            $vat_rate = $pct > 1 ? $pct / 100.0 : $pct;
        }
        return $base_amount * $vat_rate;
    }
    
    /**
     * Get international price in Rands
     */
    private static function get_international_price()
    {
        if (class_exists('KIT_Waybills') && method_exists('KIT_Waybills', 'international_price_in_rands')) {
            return floatval(KIT_Waybills::international_price_in_rands());
        }
        return 1850.00;
    }
    
    /**
     * Format calculation for display
     */
    public static function format_calculation_display($breakdown)
    {
        $display = [];
        
        // Base charges
        $display['mass_charge'] = number_format($breakdown['base_charges']['mass_charge'], 2);
        $display['volume_charge'] = number_format($breakdown['base_charges']['volume_charge'], 2);
        $display['primary_charge'] = number_format($breakdown['base_charges']['primary_charge']['amount'], 2);
        $display['charge_basis'] = $breakdown['base_charges']['primary_charge']['basis'];
        
        // Additional charges
        $display['misc_total'] = number_format($breakdown['additional_charges']['misc_total'], 2);
        $display['sad500'] = number_format($breakdown['additional_charges']['sad500'], 2);
        $display['sadc'] = number_format($breakdown['additional_charges']['sadc'], 2);
        $display['vat'] = number_format($breakdown['additional_charges']['vat'], 2);
        $display['international_price'] = number_format($breakdown['additional_charges']['international_price'], 2);
        
        // Totals
        $display['waybill_amount'] = number_format($breakdown['totals']['waybill_amount'], 2);
        $display['additional_total'] = number_format($breakdown['additional_charges']['total'], 2);
        $display['final_total'] = number_format($breakdown['totals']['final_total'], 2);
        
        return $display;
    }
}
// Intentionally no closing PHP tag to prevent unintended output

