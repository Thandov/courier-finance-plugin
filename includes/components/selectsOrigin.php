<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kit_resolve_waybill_origin_select_defaults')) {
    require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/customers/_customersForm.php';
}

$r = kit_resolve_waybill_origin_select_defaults(
    $waybill ?? null,
    isset($delivery) ? $delivery : null,
    isset($customer) ? $customer : null,
    isset($waybillFromStats) && is_array($waybillFromStats) ? $waybillFromStats : []
);

$defaultCountryId = $r['default_country_id'];
$defaultCityId = $r['default_city_id'];
$country_select_options = $r['country_select_options'];
?>
<div>
    <label for="origin_country_select" class="<?= KIT_Commons::labelClass() ?>">Origin Country</label>
    <?php
    echo KIT_Deliveries::selectAllCountries(
        'origin_country',
        'origin_country_select',
        $defaultCountryId,
        'required',
        'origin',
        $country_select_options
    );
    ?>
</div>
<div>
    <label for="origin_city_select" class="<?= KIT_Commons::labelClass() ?>">Origin City</label>
    <?php
    echo KIT_Deliveries::selectAllCitiesByCountry('origin_city', 'origin_city_select', $defaultCountryId, $defaultCityId);
    ?>
</div>
<input type="hidden" id="origin_country_initial" value="<?= esc_attr($r['origin_country_initial']); ?>">
<input type="hidden" id="origin_city_initial" value="<?= esc_attr($r['origin_city_initial']); ?>">
