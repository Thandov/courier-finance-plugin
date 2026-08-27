<?php if (!defined('ABSPATH')) {
    exit;
}

/**
 * Destination country + city for delivery / waybill forms.
 * Always render options via KIT_Deliveries::selectAllCountries() and selectAllCitiesByCountry() — do not hand-build country/city <option> lists in templates.
 */

$destination_city_id = 0;
$destination_country_id = 0;

/**
 * Resolve a city's country_id from kit_operating_cities (0 if unknown).
 */
$kit_city_country_id = static function ($city_id) {
    $city_id = intval($city_id);
    if ($city_id <= 0) {
        return 0;
    }
    global $wpdb;
    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT country_id FROM {$wpdb->prefix}kit_operating_cities WHERE id = %d LIMIT 1",
        $city_id
    )));
};

// For waybill edit/view: prefer saved route from miscellaneous.others (source of truth from DB)
if (isset($waybill['miscellaneous'])) {
    $raw_misc = $waybill['miscellaneous'];
    $misc = is_array($raw_misc) ? $raw_misc : maybe_unserialize($raw_misc);
    if (is_array($misc) && isset($misc['others']) && is_array($misc['others'])) {
        $from_others = [
            'destination_city_id' => intval($misc['others']['destination_city_id'] ?? 0),
            'destination_country_id' => intval($misc['others']['destination_country_id'] ?? 0),
        ];
        if ($from_others['destination_country_id'] > 0 || $from_others['destination_city_id'] > 0) {
            $destination_country_id = $from_others['destination_country_id'];
            $destination_city_id = $from_others['destination_city_id'];
        }
    }
}

// Fallback: waybill row city_id (e.g. when miscellaneous not yet saved)
if (!$destination_country_id && !$destination_city_id && isset($waybill) && is_array($waybill) && !empty($waybill['city_id'])) {
    $destination_city_id = intval($waybill['city_id']);
}

// Check for delivery object (used on delivery forms) only if not already set from waybill
if (!$destination_city_id && !$destination_country_id && isset($delivery) && is_object($delivery)) {
    $destination_city_id = isset($delivery->destination_city_id) ? intval($delivery->destination_city_id) : 0;
    $destination_country_id = isset($delivery->destination_country_id) ? intval($delivery->destination_country_id) : 0;
}

// Fallback to top-level waybill values (e.g. from delivery direction JOIN)
if (!$destination_city_id && isset($waybill['destination_city_id'])) {
    $destination_city_id = intval($waybill['destination_city_id']);
} elseif (!$destination_city_id && isset($waybill['destination_city'])) {
    $destination_city_id = intval($waybill['destination_city']);
}

if (!$destination_country_id && isset($waybill['destination_country_id'])) {
    $destination_country_id = intval($waybill['destination_country_id']);
} elseif (!$destination_country_id && isset($waybill['destination_country'])) {
    // Numeric id only — country names like "Tanzania" must not become 0 via intval and look "set".
    if (is_numeric($waybill['destination_country'])) {
        $destination_country_id = intval($waybill['destination_country']);
    }
}

// Prefer direction destination country when available (authoritative for the route).
if (!$destination_country_id && isset($waybill) && is_array($waybill)) {
    if (!empty($waybill['destination_country_id']) && is_numeric($waybill['destination_country_id'])) {
        $destination_country_id = intval($waybill['destination_country_id']);
    }
}

// If saved city does not belong to destination country, try waybill.city_id when it does;
// otherwise drop the city so the select is empty rather than silently mismatched.
if ($destination_city_id > 0 && $destination_country_id > 0) {
    $city_country = $kit_city_country_id($destination_city_id);
    if ($city_country > 0 && $city_country !== $destination_country_id) {
        $fallback_city = isset($waybill['city_id']) ? intval($waybill['city_id']) : 0;
        $fallback_country = $fallback_city > 0 ? $kit_city_country_id($fallback_city) : 0;
        if ($fallback_city > 0 && $fallback_city !== $destination_city_id && $fallback_country === $destination_country_id) {
            $destination_city_id = $fallback_city;
        } else {
            $destination_city_id = 0;
        }
    }
} elseif ($destination_city_id > 0 && !$destination_country_id) {
    // Infer country from city when only city is known.
    $destination_country_id = $kit_city_country_id($destination_city_id);
}

$defaultCountryId = $destination_country_id ? $destination_country_id : 1;
?>
<div class="grid grid-cols-[minmax(5rem,6.5rem)_minmax(0,1fr)] gap-3 items-end">
    <div class="min-w-0">
        <label for="destination_country_select" class="<?= KIT_Commons::labelClass() ?>">Destination Country</label>
        <?php
        // Auto-detect context for enhanced behavior - routes need all countries
        $options = [];
        if (isset($_GET['page']) && in_array($_GET['page'], ['route-create'])) {
            $options = [
                'show_all_countries' => true,
                'show_inactive_indicators' => true
            ];
        }

        echo KIT_Deliveries::selectAllCountries('destination_country', 'destination_country_select', $defaultCountryId, "required", 'destination', $options);
        ?>
    </div>
    <div class="min-w-0">
        <label for="destination_city_select" class="<?= KIT_Commons::labelClass() ?>">Destination City</label>
        <?php
        // Must match the country dropdown above: when there is no saved delivery, $destination_country_id is 0
        // but $defaultCountryId is 1 (or another default). Cities were incorrectly requested with country_id 0 → empty list.
        $country_id_for_cities = $destination_country_id > 0 ? $destination_country_id : $defaultCountryId;
        $city_id_for_select     = $destination_city_id > 0 ? $destination_city_id : 0;

        echo KIT_Deliveries::selectAllCitiesByCountry('destination_city', 'destination_city_select', $country_id_for_cities, $city_id_for_select, 'required');
        ?>
    </div>
</div>
<input type="hidden" id="destination_country_initial" value="<?= esc_attr($defaultCountryId); ?>">
<input type="hidden" id="destination_city_initial" value="<?= esc_attr($destination_city_id ?: ''); ?>">