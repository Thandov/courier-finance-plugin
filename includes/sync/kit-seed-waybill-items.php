<?php
/**
 * Seed helpers: Item Description → wp_kit_waybill_items (no kit_parcels bridge).
 *
 * Ports Apps Script splitItemDescriptionIntoParcels_ + distributeParcelPrices_.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kit_seed_clean_item_description_text')) {
    /**
     * Strip trailing WB:- / Supplier: annotations often appended to descriptions.
     */
    function kit_seed_clean_item_description_text(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\s+WB:\s*-\s*.*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\s+Supplier:\s*.*$/iu', '', $text) ?? $text;
        return trim($text);
    }
}

if (!function_exists('kit_seed_split_item_description')) {
    /**
     * Split Item Description on "+" (same rules as Code.gs splitItemDescriptionIntoParcels_).
     *
     * @return array<int, string>
     */
    function kit_seed_split_item_description(string $item_desc_text): array
    {
        $text = kit_seed_clean_item_description_text($item_desc_text);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/\s*\+\s*/u', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out !== [] ? $out : [$text];
    }
}

if (!function_exists('kit_seed_distribute_parcel_prices')) {
    /**
     * Distribute total across N parcels. Last parcel absorbs rounding so the sum equals total.
     *
     * @return array<int, float>
     */
    function kit_seed_distribute_parcel_prices(string $waybill_no, float $total_amount, int $parcel_count): array
    {
        unset($waybill_no); // reserved for seeded RNG parity with Apps Script if needed later
        $total = round($total_amount, 2);
        if ($parcel_count <= 0) {
            return [];
        }
        if ($parcel_count === 1) {
            return [$total];
        }

        $each = round($total / $parcel_count, 2);
        $prices = [];
        $allocated = 0.0;
        for ($p = 0; $p < $parcel_count - 1; $p++) {
            $prices[] = $each;
            $allocated += $each;
        }
        $prices[] = round($total - $allocated, 2);
        return $prices;
    }
}

if (!function_exists('kit_seed_build_custom_items_from_description')) {
    /**
     * Build updateWaybillItems / save_waybill_items payload from a description string.
     *
     * @return array<int, array<string, mixed>>
     */
    function kit_seed_build_custom_items_from_description(
        string $description,
        string $waybill_no,
        float $items_total,
        string $client_invoice = ''
    ): array {
        $names = kit_seed_split_item_description($description);
        if ($names === []) {
            $names = ['Item'];
        }

        $prices = kit_seed_distribute_parcel_prices($waybill_no, max(0.0, $items_total), count($names));
        $items = [];
        foreach ($names as $i => $name) {
            $price = (float) ($prices[$i] ?? 0);
            $items[] = [
                'item_name'      => $name,
                'quantity'       => 1,
                'unit_price'     => $price,
                'unit_mass'      => 0,
                'unit_volume'    => 0,
                'total_price'    => $price,
                'client_invoice' => $client_invoice,
            ];
        }
        return $items;
    }
}
