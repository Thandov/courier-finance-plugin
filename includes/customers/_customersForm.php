<?php
if (!defined('ABSPATH')) {
    exit;
}

function kit_customers_form_normalize_customer($customer)
{
    $cust = [];
    if (is_array($customer)) {
        $cust = $customer;
    } elseif (is_object($customer)) {
        $cust = get_object_vars($customer);
    }
    return $cust;
}

/**
 * Normalise waybill row to an array (multiform JSON, DB row, or object).
 *
 * @param mixed $waybill
 * @return array
 */
function kit_customers_form_waybill_as_array($waybill)
{
    if ($waybill === null || $waybill === false) {
        return [];
    }
    if (is_array($waybill)) {
        return $waybill;
    }
    if (is_object($waybill)) {
        return get_object_vars($waybill);
    }
    return [];
}

/**
 * Origin country/city defaults for waybill UI (shared by selectsOrigin.php and kit_render_customers_form_fields).
 *
 * @param array|object|null     $waybill
 * @param object|null           $delivery
 * @param array|object|null     $customer
 * @param array                 $waybillFromStats
 * @return array{default_country_id:int,default_city_id:int,origin_country_initial:string,origin_city_initial:string,country_select_options:array}
 */
function kit_resolve_waybill_origin_select_defaults($waybill = null, $delivery = null, $customer = null, $waybillFromStats = [])
{
    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
    }

    $waybill = kit_customers_form_waybill_as_array($waybill);
    $waybillFromStats = is_array($waybillFromStats) ? $waybillFromStats : [];

    $origin_city_id = 0;
    $origin_country_id = 0;

    if (isset($waybill['miscellaneous']) && is_array($waybill['miscellaneous'])) {
        $misc = maybe_unserialize($waybill['miscellaneous']);
        if (is_array($misc) && isset($misc['others'])) {
            $origin_city_id = intval($misc['others']['origin_city_id'] ?? 0);
            $origin_country_id = intval($misc['others']['origin_country_id'] ?? 0);
        }
    }

    if (!$origin_city_id && isset($waybill['origin_city_id'])) {
        $origin_city_id = intval($waybill['origin_city_id']);
    } elseif (!$origin_city_id && isset($waybill['origin_city'])) {
        $origin_city_id = intval($waybill['origin_city']);
    }

    if (!$origin_country_id && isset($waybill['origin_country_id'])) {
        $origin_country_id = intval($waybill['origin_country_id']);
    } elseif (!$origin_country_id && isset($waybill['origin_country'])) {
        $origin_country_id = intval($waybill['origin_country']);
    }

    $defaultCountryId = ($origin_country_id) ? $origin_country_id : 1;

    if ($defaultCountryId == 1 && isset($delivery) && $delivery && isset($delivery->origin_country_id)) {
        $defaultCountryId = (int) $delivery->origin_country_id;
    } elseif ($defaultCountryId == 1 && isset($waybill['delivery_id']) && !empty($waybill['delivery_id'])) {
        $delData = KIT_Deliveries::get_delivery($waybill['delivery_id']);
        if ($delData && isset($delData->origin_country_id)) {
            $defaultCountryId = (int) $delData->origin_country_id;
        }
    } elseif (isset($waybillFromStats['country_id'])) {
        $defaultCountryId = (int) $waybillFromStats['country_id'];
    } elseif (isset($customer) && isset($customer->country_id)) {
        $defaultCountryId = (int) $customer->country_id;
    } elseif (isset($customer) && $customer && is_array($customer) && isset($customer['country_id'])) {
        $defaultCountryId = (int) $customer['country_id'];
    }

    $defaultCityId = ($origin_city_id) ? $origin_city_id : 1;

    if (!$origin_city_id && isset($customer)) {
        if (is_array($customer) && !empty($customer['city_id'])) {
            $defaultCityId = (int) $customer['city_id'];
        } elseif (is_object($customer) && !empty($customer->city_id)) {
            $defaultCityId = (int) $customer->city_id;
        }
    }

    if ($defaultCityId == 1 && isset($waybill['delivery_id']) && !empty($waybill['delivery_id'])) {
        $delData = KIT_Deliveries::get_delivery($waybill['delivery_id']);
        if ($delData && isset($delData->origin_country_id)) {
            $defaultCityId = 1;
        }
    }

    $country_select_options = [];
    if (isset($_GET['page']) && in_array($_GET['page'], ['route-create'], true)) {
        $country_select_options = [
            'show_all_countries' => true,
            'show_inactive_indicators' => true,
        ];
    }

    return [
        'default_country_id' => (int) $defaultCountryId,
        'default_city_id' => (int) $defaultCityId,
        'origin_country_initial' => (string) $defaultCountryId,
        'origin_city_initial' => (string) ($origin_city_id ?: ''),
        'country_select_options' => $country_select_options,
    ];
}

/**
 * Shared origin country + city select markup (waybill, delivery, customer, routes).
 *
 * @param array $args {
 *     @type int    $default_country_id
 *     @type int    $default_city_id
 *     @type string $origin_country_initial
 *     @type string $origin_city_initial
 *     @type array  $country_select_options
 *     @type string $country_name           Input name (origin_country | country_id).
 *     @type string $city_name              Input name (origin_city | city_id).
 *     @type string $country_select_id
 *     @type string $city_select_id
 *     @type string $country_label
 *     @type string $city_label
 *     @type string $required
 *     @type string $field_wrap_class
 *     @type bool   $include_initial_hiddens
 *     @type bool   $echo
 * }
 * @return string|void HTML when $echo is false.
 */
function kit_render_origin_selects_markup($args = [])
{
    $args = wp_parse_args($args, [
        'default_country_id' => 1,
        'default_city_id' => 1,
        'origin_country_initial' => '',
        'origin_city_initial' => '',
        'country_select_options' => [],
        'country_name' => 'origin_country',
        'city_name' => 'origin_city',
        'country_select_id' => 'origin_country_select',
        'city_select_id' => 'origin_city_select',
        'country_label' => 'Origin Country',
        'city_label' => 'Origin City',
        'required' => 'required',
        'field_wrap_class' => '',
        'include_initial_hiddens' => true,
        'echo' => false,
        // compact = narrow country + wide city (waybill); equal = 50/50 (company forms)
        'layout' => 'compact',
    ]);

    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
    }

    $wrap_class = trim((string) $args['field_wrap_class']);
    $country_wrap_class = trim($wrap_class . ' min-w-0');
    $city_wrap_class = trim($wrap_class . ' min-w-0');
    $grid_class = ($args['layout'] === 'equal')
        ? 'grid grid-cols-1 sm:grid-cols-2 gap-4 items-end'
        : 'grid grid-cols-[minmax(5rem,6.5rem)_minmax(0,1fr)] gap-3 items-end';

    ob_start();
    ?>
    <div class="<?php echo esc_attr($grid_class); ?>">
        <div class="<?php echo esc_attr($country_wrap_class); ?>">
            <label for="<?php echo esc_attr($args['country_select_id']); ?>" class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>"><?php echo esc_html($args['country_label']); ?></label>
            <?php
            echo KIT_Deliveries::selectAllCountries(
                $args['country_name'],
                $args['country_select_id'],
                (int) $args['default_country_id'],
                $args['required'],
                'origin',
                is_array($args['country_select_options']) ? $args['country_select_options'] : []
            );
            ?>
        </div>
        <div class="<?php echo esc_attr($city_wrap_class); ?>">
            <label for="<?php echo esc_attr($args['city_select_id']); ?>" class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>"><?php echo esc_html($args['city_label']); ?></label>
            <?php
            echo KIT_Deliveries::selectAllCitiesByCountry(
                $args['city_name'],
                $args['city_select_id'],
                (int) $args['default_country_id'],
                (int) $args['default_city_id']
            );
            ?>
        </div>
    </div>
    <?php if (!empty($args['include_initial_hiddens'])) : ?>
        <input type="hidden" id="origin_country_initial" value="<?php echo esc_attr((string) $args['origin_country_initial']); ?>">
        <input type="hidden" id="origin_city_initial" value="<?php echo esc_attr((string) $args['origin_city_initial']); ?>">
    <?php endif; ?>
    <?php
    $html = ob_get_clean();
    if (!empty($args['echo'])) {
        echo $html;
        return;
    }
    return $html;
}

/**
 * Whether stored company_name represents a business (not Individual/placeholder).
 */
function kit_customers_form_is_business_company($company_name)
{
    $company_name = trim((string) $company_name);
    $company_normalized = strtolower($company_name);

    return $company_name !== '' && !in_array($company_normalized, ['individual', '1ndividual', 'private', 'n/a', 'na', 'none'], true);
}

/**
 * Fallback city from the customer's latest waybill when kit_customers.city_id is empty.
 *
 * @param int $cust_id
 * @param int $country_id Customer country; only cities in this country are used.
 * @return int City id or 0.
 */
function kit_customers_form_fallback_city_id_from_waybills($cust_id, $country_id = 0)
{
    global $wpdb;
    $cust_id = (int) $cust_id;
    $country_id = (int) $country_id;
    if ($cust_id <= 0) {
        return 0;
    }

    $cities_table = $wpdb->prefix . 'kit_operating_cities';
    $waybills_table = $wpdb->prefix . 'kit_waybills';

    if ($country_id > 0) {
        $city_id = $wpdb->get_var($wpdb->prepare(
            "SELECT w.city_id
             FROM {$waybills_table} w
             INNER JOIN {$cities_table} oc ON oc.id = w.city_id
             WHERE w.customer_id = %d AND w.city_id > 0 AND oc.country_id = %d
             ORDER BY w.id DESC
             LIMIT 1",
            $cust_id,
            $country_id
        ));
    } else {
        $city_id = $wpdb->get_var($wpdb->prepare(
            "SELECT w.city_id
             FROM {$waybills_table} w
             WHERE w.customer_id = %d AND w.city_id > 0
             ORDER BY w.id DESC
             LIMIT 1",
            $cust_id
        ));
    }

    return ($city_id !== null && (int) $city_id > 0) ? (int) $city_id : 0;
}

/**
 * Effective city id for form display: stored customer city, else latest matching waybill city.
 *
 * @param array $cust Normalized customer row.
 * @return int
 */
function kit_customers_form_resolve_effective_city_id(array $cust)
{
    $city_id = isset($cust['city_id']) ? (int) $cust['city_id'] : 0;
    if ($city_id > 0) {
        return $city_id;
    }

    $cust_id = isset($cust['cust_id']) ? (int) $cust['cust_id'] : 0;
    $country_id = isset($cust['country_id']) ? (int) $cust['country_id'] : 0;

    return kit_customers_form_fallback_city_id_from_waybills($cust_id, $country_id);
}

/**
 * Client type + location + convert-to-company scripts for `.kit-customer-form` (output once per page).
 *
 * @return string HTML script block or empty string if already printed.
 */
function kit_render_customers_form_client_type_script()
{
    static $added = false;
    if ($added) {
        return '';
    }
    $added = true;

    ob_start();
    ?>
    <script>
    (function() {
        function initKitCustomerFormClientType(formRoot) {
            if (!formRoot || formRoot.dataset.clientTypeInit === '1') {
                return;
            }
            formRoot.dataset.clientTypeInit = '1';

            var radios = formRoot.querySelectorAll('.kit-customer-client-type-radio');
            var companyWrap = formRoot.querySelector('.kit-customer-form__company-wrap');
            var companyInput = formRoot.querySelector('#company_name');
            var individualFallback = formRoot.querySelector('#company_name_individual_fallback');
            var nameInput = formRoot.querySelector('#customer_name');
            var surnameInput = formRoot.querySelector('#customer_surname');

            function updateCompanyNameVisibility() {
                var selectedType = formRoot.querySelector('input[name="kit_customer_client_type"]:checked');
                selectedType = selectedType ? selectedType.value : 'business';
                var isIndividual = selectedType === 'individual';

                if (companyWrap) {
                    companyWrap.style.display = isIndividual ? 'none' : '';
                }
                if (companyInput) {
                    if (isIndividual) {
                        companyInput.value = '';
                        companyInput.removeAttribute('required');
                        companyInput.removeAttribute('name');
                    } else {
                        companyInput.setAttribute('name', 'company_name');
                        companyInput.setAttribute('required', 'required');
                    }
                }
                if (individualFallback) {
                    if (isIndividual) {
                        individualFallback.disabled = false;
                        individualFallback.setAttribute('name', 'company_name');
                    } else {
                        individualFallback.disabled = true;
                        individualFallback.removeAttribute('name');
                    }
                }
                if (nameInput) {
                    if (isIndividual) {
                        nameInput.setAttribute('required', 'required');
                    } else {
                        nameInput.removeAttribute('required');
                    }
                }
                if (surnameInput) {
                    if (isIndividual) {
                        surnameInput.setAttribute('required', 'required');
                    } else {
                        surnameInput.removeAttribute('required');
                    }
                }
            }

            radios.forEach(function(radio) {
                radio.addEventListener('change', updateCompanyNameVisibility);
            });

            var parentForm = formRoot.closest('form');
            if (parentForm) {
                parentForm.addEventListener('submit', function() {
                    updateCompanyNameVisibility();
                }, true);
            }

            updateCompanyNameVisibility();
        }

        function initKitCustomerFormConvertToCompany(formRoot) {
            if (!formRoot || formRoot.dataset.convertInit === '1') {
                return;
            }
            formRoot.dataset.convertInit = '1';

            var checkbox = formRoot.querySelector('#kit_convert_to_company');
            var companyWrap = formRoot.querySelector('.kit-customer-form__convert-company-wrap');
            var companyInput = formRoot.querySelector('#company_name');
            var individualFallback = formRoot.querySelector('#company_name_individual_fallback');
            var personWrap = formRoot.querySelector('.kit-customer-form__person-wrap');
            var nameInput = formRoot.querySelector('#customer_name');
            var surnameInput = formRoot.querySelector('#customer_surname');
            var submitBtn = formRoot.closest('form') ? formRoot.closest('form').querySelector('button[type="submit"], input[type="submit"]') : null;

            if (!checkbox) {
                return;
            }

            function syncConvertUi() {
                var converting = !!checkbox.checked;
                if (companyWrap) {
                    companyWrap.style.display = converting ? '' : 'none';
                }
                if (companyInput) {
                    if (converting) {
                        companyInput.setAttribute('name', 'company_name');
                        companyInput.setAttribute('required', 'required');
                    } else {
                        companyInput.removeAttribute('required');
                        companyInput.removeAttribute('name');
                        companyInput.value = '';
                    }
                }
                if (individualFallback) {
                    if (converting) {
                        individualFallback.disabled = true;
                        individualFallback.removeAttribute('name');
                    } else {
                        individualFallback.disabled = false;
                        individualFallback.setAttribute('name', 'company_name');
                        individualFallback.value = '';
                    }
                }
                if (nameInput) {
                    if (converting) {
                        nameInput.removeAttribute('required');
                    } else {
                        nameInput.setAttribute('required', 'required');
                    }
                }
                if (surnameInput) {
                    if (converting) {
                        surnameInput.removeAttribute('required');
                    } else {
                        surnameInput.setAttribute('required', 'required');
                    }
                }
                if (personWrap) {
                    personWrap.style.opacity = converting ? '0.65' : '';
                }
                if (submitBtn && submitBtn.tagName === 'BUTTON') {
                    submitBtn.textContent = converting ? 'Convert to Company' : 'Update Customer';
                }
            }

            checkbox.addEventListener('change', syncConvertUi);
            var parentForm = formRoot.closest('form');
            if (parentForm) {
                parentForm.addEventListener('submit', function() {
                    syncConvertUi();
                }, true);
            }
            syncConvertUi();
        }

        function initAllKitCustomerFormClientTypes() {
            document.querySelectorAll('.kit-customer-form').forEach(initKitCustomerFormClientType);
        }

        function initAllKitCustomerFormConvert() {
            document.querySelectorAll('.kit-customer-form').forEach(initKitCustomerFormConvertToCompany);
        }

        function initKitCustomerFormLocation(formRoot) {
            if (!formRoot || formRoot.dataset.locationInit === '1') {
                return;
            }
            formRoot.dataset.locationInit = '1';

            var countrySelect = formRoot.querySelector('#origin_country_select');
            var citySelect = formRoot.querySelector('#origin_city_select');
            var countryInitial = formRoot.querySelector('#origin_country_initial');
            var cityInitial = formRoot.querySelector('#origin_city_initial');
            if (!countrySelect || !countryInitial) {
                return;
            }

            var countryId = String(countryInitial.value || '').trim();
            var cityId = String(cityInitial.value || '').trim();
            if (cityId === '0') {
                cityId = '';
            }
            if (!countryId) {
                return;
            }

            function applyCitySelection() {
                if (!citySelect || !cityId) {
                    return false;
                }
                if (String(citySelect.value) === cityId) {
                    return true;
                }
                var matched = Array.from(citySelect.options || []).some(function(option) {
                    return String(option.value) === cityId;
                });
                if (matched) {
                    citySelect.value = cityId;
                    return true;
                }
                return false;
            }

            if (typeof handleCountryChange === 'function') {
                handleCountryChange(countryId, 'origin');
            } else if (countrySelect && countrySelect.value !== countryId) {
                countrySelect.value = countryId;
            }

            applyCitySelection();
            setTimeout(applyCitySelection, 100);
            setTimeout(applyCitySelection, 350);

            if (citySelect && cityId) {
                var observer = new MutationObserver(function() {
                    if (applyCitySelection()) {
                        observer.disconnect();
                    }
                });
                observer.observe(citySelect, { childList: true });
            }
        }

        function initAllKitCustomerFormLocations() {
            document.querySelectorAll('.kit-customer-form').forEach(initKitCustomerFormLocation);
        }

        function initAllKitCustomerForms() {
            initAllKitCustomerFormClientTypes();
            initAllKitCustomerFormConvert();
            initAllKitCustomerFormLocations();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAllKitCustomerForms);
        } else {
            initAllKitCustomerForms();
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Shared customer fields (profile, contact, location) for modal, edit page, and admin add form.
 *
 * @param array|object|null $customer Existing row or null.
 * @param array             $args {
 *     @type bool   $show_vat                Include VAT number (admin add / full-page form).
 *     @type bool   $show_hidden_cust_id     Output hidden `cust_id` (omit for new full-page add).
 *     @type array|null $waybill_origin_context Optional. Keys: waybill, delivery, customer, waybillFromStats — merges origin defaults via kit_resolve_waybill_origin_select_defaults().
 *     @type string $location_section_title  Heading above country/city (e.g. "Origin Location" on waybill).
 *     @type string $origin_country_label    Label for origin country select.
 *     @type string $origin_city_label        Label for origin city select.
 *     @type bool   $show_telephone           Extra landline field (waybill).
 *     @type bool   $show_customer_notes      Optional notes field (waybill).
 *     @type bool   $use_container_query_layout Use kit-customer-form container-query grid (theForm / narrow columns).
 *     @type bool   $show_client_type_toggle  Business / Individual radios; hides company name when Individual.
 *     @type bool   $show_convert_to_company  Edit-individual: checkbox to move row into kit_company_customers.
 *     @type string $company_name_wrapper_class Extra class on company name wrapper (e.g. kit-customer-form__company-wrap).
 * }
 * @return string HTML
 */
function kit_render_customers_form_fields($customer = null, $args = [])
{
    $args = wp_parse_args($args, [
        'show_vat' => false,
        'show_hidden_cust_id' => true,
        /** `customer_record` uses input names `name` / `surname` (admin/customer DB). `waybill` uses `customer_name` / `customer_surname` for waybill POST handlers. */
        'person_name_input_names' => 'customer_record',
        /** When set (e.g. `company_name_wrapper`), wraps the company name field for client-type show/hide JS. */
        'company_name_wrapper_id' => '',
        'company_name_wrapper_class' => '',
        /** When true, skip country/city (caller renders origin selects elsewhere). */
        'omit_location_section' => false,
        /** @var array|null Keys: waybill, delivery, customer, waybillFromStats */
        'waybill_origin_context' => null,
        'location_section_title' => 'Location',
        'origin_country_label' => 'Country',
        'origin_city_label' => 'City',
        'show_telephone' => false,
        'show_customer_notes' => false,
        'use_container_query_layout' => false,
        'show_client_type_toggle' => false,
        'show_convert_to_company' => false,
        /** When true (individual admin edit), hide company name unless converting. */
        'individual_profile' => false,
    ]);

    $cust = kit_customers_form_normalize_customer($customer);
    $cust['city_id'] = kit_customers_form_resolve_effective_city_id($cust);

    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
    }

    $originResolved = null;
    if (!empty($args['waybill_origin_context']) && is_array($args['waybill_origin_context'])) {
        $ctx = $args['waybill_origin_context'];
        $originResolved = kit_resolve_waybill_origin_select_defaults(
            $ctx['waybill'] ?? null,
            $ctx['delivery'] ?? null,
            $ctx['customer'] ?? null,
            $ctx['waybillFromStats'] ?? []
        );
    }

    $val = static function ($key) use ($cust, $args) {
        $post_key = $key;
        if ($args['person_name_input_names'] === 'waybill') {
            if ($key === 'name') {
                $post_key = 'customer_name';
            } elseif ($key === 'surname') {
                $post_key = 'customer_surname';
            }
        }
        if (array_key_exists($post_key, $_POST)) {
            $raw = wp_unslash($_POST[$post_key]);
            if (is_array($raw)) {
                return '';
            }
            return is_string($raw) ? $raw : (string) $raw;
        }
        if ($key === 'name') {
            $v = $cust['name'] ?? ($cust['customer_name'] ?? '');
            return $v === null || $v === false ? '' : (string) $v;
        }
        if ($key === 'surname') {
            $v = $cust['surname'] ?? ($cust['customer_surname'] ?? '');
            return $v === null || $v === false ? '' : (string) $v;
        }
        if (!array_key_exists($key, $cust)) {
            return '';
        }
        $v = $cust[$key];
        if ($v === null) {
            return '';
        }
        return is_scalar($v) ? (string) $v : '';
    };

    $defaultCountryId = 0;
    if (isset($_POST['origin_country']) && (string) $_POST['origin_country'] !== '') {
        $defaultCountryId = intval($_POST['origin_country']);
    } elseif (isset($_POST['country_id']) && (string) $_POST['country_id'] !== '') {
        $defaultCountryId = intval($_POST['country_id']);
    } elseif ($originResolved && $originResolved['default_country_id'] > 0) {
        $defaultCountryId = (int) $originResolved['default_country_id'];
    } elseif (isset($cust['country_id']) && (string) $cust['country_id'] !== '') {
        $defaultCountryId = intval($cust['country_id']);
    }

    $defaultCityId = 0;
    if (isset($_POST['origin_city']) && (string) $_POST['origin_city'] !== '' && (int) $_POST['origin_city'] > 0) {
        $defaultCityId = intval($_POST['origin_city']);
    } elseif (isset($_POST['city_id']) && (string) $_POST['city_id'] !== '' && (int) $_POST['city_id'] > 0) {
        $defaultCityId = intval($_POST['city_id']);
    } elseif ($originResolved && $originResolved['default_city_id'] > 0) {
        $defaultCityId = (int) $originResolved['default_city_id'];
    } elseif (isset($cust['city_id']) && (int) $cust['city_id'] > 0) {
        $defaultCityId = (int) $cust['city_id'];
    }

    $origin_country_initial = (string) $defaultCountryId;
    $origin_city_initial = (string) $defaultCityId;
    if ($originResolved) {
        if (isset($_POST['origin_country']) && (string) $_POST['origin_country'] !== '') {
            $origin_country_initial = (string) intval($_POST['origin_country']);
        } else {
            $origin_country_initial = $originResolved['origin_country_initial'];
        }
        if (isset($_POST['origin_city']) && (string) $_POST['origin_city'] !== '') {
            $origin_city_initial = (string) intval($_POST['origin_city']);
        } else {
            $origin_city_initial = $originResolved['origin_city_initial'];
        }
    }

    $country_select_options = ($originResolved && isset($originResolved['country_select_options']))
        ? $originResolved['country_select_options']
        : [];

    $cust_id_hidden = isset($cust['cust_id']) ? (string) $cust['cust_id'] : '';

    $use_container = !empty($args['use_container_query_layout']);
    $section_class = $use_container
        ? 'kit-customer-form__section min-w-0 w-full rounded-xl border border-gray-200 bg-white p-3 sm:p-4 md:p-5'
        : 'rounded-xl border border-gray-200 bg-white p-4 md:p-5';
    $heading_class = $use_container
        ? 'text-sm sm:text-base font-semibold text-gray-900 mb-3 sm:mb-4'
        : 'text-base font-semibold text-gray-900 mb-4';
    $inner_space_class = $use_container ? 'space-y-3 sm:space-y-4' : 'space-y-4';
    $main_grid_class = $use_container ? 'kit-customer-form__main' : 'grid grid-cols-1 lg:grid-cols-2 gap-4';
    $pair_grid_class = $use_container ? 'kit-customer-form__pair' : 'grid grid-cols-1 md:grid-cols-2 gap-4';
    $location_section_class = $section_class . ($use_container ? ' kit-customer-form__location' : ' lg:col-span-2');
    $location_grid_class = $use_container ? 'kit-customer-form__pair' : 'grid grid-cols-1 md:grid-cols-2 gap-4';
    $field_wrap_class = $use_container ? 'min-w-0' : '';

    $first_input_name = ($args['person_name_input_names'] === 'waybill') ? 'customer_name' : 'name';
    $surname_input_name = ($args['person_name_input_names'] === 'waybill') ? 'customer_surname' : 'surname';

    $is_business = kit_customers_form_is_business_company($val('company_name'));
    $company_wrap_id = $args['company_name_wrapper_id'];
    $company_wrap_class = trim($args['company_name_wrapper_class']);
    if (!empty($args['show_client_type_toggle'])) {
        $company_wrap_class = trim($company_wrap_class . ' kit-customer-form__company-wrap');
    }
    if (!empty($args['show_convert_to_company']) || !empty($args['individual_profile'])) {
        $company_wrap_class = trim($company_wrap_class . ' kit-customer-form__convert-company-wrap');
    }
    $wraps_company_name = $company_wrap_id !== '' || $company_wrap_class !== '' || !empty($args['show_client_type_toggle']) || !empty($args['show_convert_to_company']) || !empty($args['individual_profile']);
    $hide_company_by_default = (!empty($args['show_convert_to_company']) || !empty($args['individual_profile']))
        && empty($args['show_client_type_toggle']);

    $needs_form_root = $use_container || !empty($args['show_client_type_toggle']) || !empty($args['show_convert_to_company']) || !empty($args['individual_profile']);

    $convert_checked = !empty($_POST['kit_convert_to_company']);

    ob_start();
    ?>
    <?php if ($args['show_hidden_cust_id']) : ?>
        <input type="hidden" name="cust_id" id="cust_id" value="<?php echo esc_attr($cust_id_hidden); ?>">
    <?php endif; ?>

    <?php if ($needs_form_root) : ?><div class="kit-customer-form w-full min-w-0"><?php endif; ?>
    <div class="<?php echo esc_attr($main_grid_class); ?>">
        <section class="<?php echo esc_attr($section_class); ?>">
            <h2 class="<?php echo esc_attr($heading_class); ?>"><?php echo !empty($args['individual_profile']) || !empty($args['show_convert_to_company']) ? esc_html__('Individual Profile', '08600') : esc_html__('Customer Profile', '08600'); ?></h2>
            <div class="<?php echo esc_attr($inner_space_class); ?>">
                <?php if (!empty($args['show_client_type_toggle'])) : ?>
                <div class="<?php echo esc_attr($field_wrap_class); ?>">
                    <span class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>">Client Type</span>
                    <div class="flex flex-wrap gap-4 sm:gap-6 mt-1">
                        <?php
                        echo KIT_Commons::Lradio([
                            'name' => 'kit_customer_client_type',
                            'id' => 'kit_customer_client_type_business',
                            'value' => 'business',
                            'label' => 'Business',
                            'checked' => $is_business,
                            'class' => 'kit-customer-client-type-radio',
                        ]);
                        echo KIT_Commons::Lradio([
                            'name' => 'kit_customer_client_type',
                            'id' => 'kit_customer_client_type_individual',
                            'value' => 'individual',
                            'label' => 'Individual',
                            'checked' => !$is_business,
                            'class' => 'kit-customer-client-type-radio',
                        ]);
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($args['show_convert_to_company'])) : ?>
                <div class="<?php echo esc_attr($field_wrap_class); ?> rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <?php
                    echo KIT_Commons::Lcheckbox([
                        'name' => 'kit_convert_to_company',
                        'id' => 'kit_convert_to_company',
                        'value' => '1',
                        'label' => 'Convert this client into a company',
                        'checked' => $convert_checked,
                    ]);
                    ?>
                    <p class="text-xs text-amber-900 mt-2 mb-0">
                        <?php echo esc_html__('When checked and saved, this individual is moved to the Companies list. Waybills are re-linked to the company and the person record is removed.', '08600'); ?>
                    </p>
                </div>
                <?php endif; ?>

                <?php if ($wraps_company_name) : ?>
                <div
                    <?php if ($company_wrap_id !== '') : ?>id="<?php echo esc_attr($company_wrap_id); ?>"<?php endif; ?>
                    class="<?php echo esc_attr(trim($field_wrap_class . ' ' . $company_wrap_class)); ?>"
                    <?php
                    $hide_company = false;
                    if (!empty($args['show_client_type_toggle']) && !$is_business) {
                        $hide_company = true;
                    } elseif ($hide_company_by_default && !$convert_checked) {
                        $hide_company = true;
                    }
                    if ($hide_company) :
                        ?>style="display:none;"<?php
                    endif;
                    ?>
                >
                <?php endif; ?>
                <div class="<?php echo esc_attr($field_wrap_class); ?>">
                    <?php
                    $company_special = '';
                    if (!empty($args['show_client_type_toggle'])) {
                        $company_special = $is_business ? 'required' : '';
                    } elseif (!empty($args['show_convert_to_company']) || !empty($args['individual_profile'])) {
                        $company_special = $convert_checked ? 'required' : '';
                    } else {
                        $company_special = 'required';
                    }
                    $company_display_value = $val('company_name');
                    if ((!empty($args['show_client_type_toggle']) && !$is_business)
                        || ((!empty($args['show_convert_to_company']) || !empty($args['individual_profile'])) && !$convert_checked)
                    ) {
                        $company_display_value = '';
                    }
                    $company_input_name = 'company_name';
                    if ($hide_company_by_default && !$convert_checked && empty($args['show_client_type_toggle'])) {
                        // Omit name until convert is checked (JS re-adds it).
                        $company_input_name = '';
                    }
                    echo KIT_Commons::Linput([
                        'label' => 'Company Name',
                        'name' => $company_input_name,
                        'id' => 'company_name',
                        'type' => 'text',
                        'value' => $company_display_value,
                        'special' => $company_special,
                    ]);
                    ?>
                </div>
                <?php if ($wraps_company_name) : ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($args['show_client_type_toggle'])) : ?>
                <input type="hidden" id="company_name_individual_fallback" value="Individual"<?php echo $is_business ? ' disabled' : ' name="company_name"'; ?>>
                <?php elseif (!empty($args['individual_profile']) || !empty($args['show_convert_to_company'])) : ?>
                <input type="hidden" name="company_name" id="company_name_individual_fallback" value=""<?php echo $convert_checked ? ' disabled' : ''; ?>>
                <?php endif; ?>

                <div class="<?php echo esc_attr(trim($pair_grid_class . ' kit-customer-form__person-wrap')); ?>">
                    <div class="<?php echo esc_attr($field_wrap_class); ?>">
                        <?php
                        echo KIT_Commons::Linput([
                            'label' => 'First Name',
                            'name' => $first_input_name,
                            'id' => 'customer_name',
                            'type' => 'text',
                            'value' => $val('name'),
                            'special' => $convert_checked ? '' : 'required',
                        ]);
                        ?>
                    </div>
                    <div class="<?php echo esc_attr($field_wrap_class); ?>">
                        <?php
                        echo KIT_Commons::Linput([
                            'label' => 'Last Name',
                            'name' => $surname_input_name,
                            'id' => 'customer_surname',
                            'type' => 'text',
                            'value' => $val('surname'),
                            'special' => $convert_checked ? '' : 'required',
                        ]);
                        ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="<?php echo esc_attr($section_class); ?>">
            <h2 class="<?php echo esc_attr($heading_class); ?>">Contact</h2>
            <div class="<?php echo esc_attr($inner_space_class); ?>">
                <div class="<?php echo esc_attr($field_wrap_class); ?>">
                    <?php
                    echo KIT_Commons::Linput([
                        'label' => 'Cell Phone',
                        'name' => 'cell',
                        'id' => 'cell',
                        'type' => 'tel',
                        'value' => $val('cell'),
                        'special' => 'inputmode="tel" autocomplete="tel"',
                    ]);
                    ?>
                </div>
                <div class="<?php echo esc_attr($field_wrap_class); ?>">
                    <?php
                    echo KIT_Commons::Linput([
                        'label' => 'Email Address',
                        'name' => 'email_address',
                        'id' => 'email_address',
                        'type' => 'email',
                        'value' => $val('email_address'),
                        'special' => 'autocomplete="email"',
                    ]);
                    ?>
                </div>
                <div class="<?php echo esc_attr($field_wrap_class); ?>">
                    <?php
                    echo KIT_Commons::TextAreaField([
                        'label' => 'Address',
                        'name' => 'address',
                        'id' => 'address',
                        'value' => $val('address'),
                        'height' => '88',
                    ]);
                    ?>
                </div>
                <?php if ($args['show_telephone']) : ?>
                    <div class="<?php echo esc_attr($field_wrap_class); ?>">
                        <?php
                        echo KIT_Commons::Linput([
                            'label' => 'Telephone',
                            'name' => 'telephone',
                            'id' => 'telephone',
                            'type' => 'tel',
                            'value' => $val('telephone'),
                            'special' => 'autocomplete="tel"',
                        ]);
                        ?>
                    </div>
                <?php endif; ?>
                <?php if ($args['show_customer_notes']) : ?>
                    <div class="<?php echo esc_attr($field_wrap_class); ?>">
                        <?php
                        echo KIT_Commons::Linput([
                            'label' => 'Notes (optional)',
                            'name' => 'customer_notes',
                            'id' => 'customer_notes',
                            'type' => 'text',
                            'value' => $val('customer_notes'),
                            'special' => '',
                        ]);
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!$args['omit_location_section']) : ?>
        <section class="<?php echo esc_attr($location_section_class); ?>">
            <h2 class="<?php echo esc_attr($heading_class); ?>"><?php echo esc_html($args['location_section_title']); ?></h2>
            <div class="<?php echo esc_attr($location_grid_class); ?>">
                <?php
                echo kit_render_origin_selects_markup([
                    'default_country_id' => $defaultCountryId,
                    'default_city_id' => $defaultCityId,
                    'origin_country_initial' => $origin_country_initial,
                    'origin_city_initial' => $origin_city_initial,
                    'country_select_options' => $country_select_options,
                    'country_label' => $args['origin_country_label'],
                    'city_label' => $args['origin_city_label'],
                    'field_wrap_class' => $field_wrap_class,
                ]);
                ?>

                <?php if ($args['show_vat']) : ?>
                    <div class="md:col-span-2">
                        <?php
                        echo KIT_Commons::Linput([
                            'label' => 'VAT Number',
                            'name' => 'vat_number',
                            'id' => 'vat_number',
                            'type' => 'text',
                            'value' => $val('vat_number'),
                            'special' => '',
                        ]);
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
    <?php if ($needs_form_root) : ?></div><?php endif; ?>
    <?php
    return ob_get_clean();
}

function kit_render_customers_form($mode, $customer = null)
{
    $cust = kit_customers_form_normalize_customer($customer);

    $is_add = ($mode === 'add');
    $cust_id = isset($cust['cust_id']) ? (int) $cust['cust_id'] : 0;

    ob_start();
    ?>
    <form id="<?php echo esc_attr($is_add ? 'add-customer-form' : 'kit-customer-form-edit'); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="space-y-6">
        <?php if ($is_add) : ?>
            <input type="hidden" name="action" value="add_customer">
            <?php wp_nonce_field('add_customer_nonce', 'customer_nonce'); ?>
        <?php else : ?>
            <input type="hidden" name="action" value="update_customer">
            <?php wp_nonce_field('update_customer_nonce', 'cust_update_nonce'); ?>
            <input type="hidden" name="customer_id" value="<?php echo esc_attr((string) $cust_id); ?>">
            <input type="hidden" name="cust_id" id="cust_id" value="<?php echo esc_attr((string) $cust_id); ?>">
        <?php endif; ?>

        <?php
        if ($is_add) {
            echo kit_render_customers_form_fields($customer, [
                'show_vat' => true,
                'show_hidden_cust_id' => false,
                'show_client_type_toggle' => true,
            ]);
        } else {
            echo kit_render_customers_form_fields($customer, [
                'show_vat' => true,
                'show_hidden_cust_id' => false,
                'individual_profile' => true,
                'show_convert_to_company' => true,
            ]);
        }
        echo kit_render_customers_form_client_type_script();
        ?>

        <div class="flex justify-end gap-3 pt-6 border-t border-gray-200">
            <a href="<?php echo esc_url(admin_url('admin.php?page=08600-customers')); ?>"
               class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-blue-500">
                Cancel
            </a>
            <?php
            $submit_opts = ['type' => 'submit'];
            if (!$is_add) {
                $submit_opts['name'] = 'customer_submit';
            }
            echo KIT_Commons::renderButton(
                $is_add ? 'Save Customer' : 'Update Customer',
                'primary',
                'lg',
                $submit_opts
            );
            ?>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

/**
 * Company edit/create form (kit_company_customers — no person name fields).
 *
 * @param array|object|null $company
 * @return string
 */
function kit_render_company_form($company = null)
{
    $co = kit_customers_form_normalize_customer($company);
    $company_id = isset($co['company_id']) ? (int) $co['company_id'] : 0;

    ob_start();
    ?>
    <form id="kit-company-form-edit" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kit-company-form">
        <input type="hidden" name="action" value="update_company">
        <?php wp_nonce_field('update_company_nonce', 'company_update_nonce'); ?>
        <input type="hidden" name="company_id" value="<?php echo esc_attr((string) $company_id); ?>">

        <div class="kit-customer-form w-full min-w-0 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2 min-w-0">
                <?php
                echo KIT_Commons::Linput([
                    'label' => 'Company Name',
                    'name' => 'company_name',
                    'id' => 'company_name',
                    'type' => 'text',
                    'value' => (string) ($co['company_name'] ?? ''),
                    'special' => 'required',
                ]);
                ?>
            </div>
            <div class="min-w-0">
                <?php
                echo KIT_Commons::Linput([
                    'label' => 'Cell Phone',
                    'name' => 'cell',
                    'id' => 'cell',
                    'type' => 'tel',
                    'value' => (string) ($co['cell'] ?? ''),
                    'special' => 'inputmode="tel" autocomplete="tel"',
                ]);
                ?>
            </div>
            <div class="min-w-0">
                <?php
                echo KIT_Commons::Linput([
                    'label' => 'Email Address',
                    'name' => 'email_address',
                    'id' => 'company_email_address',
                    'type' => 'email',
                    'value' => (string) ($co['email_address'] ?? ''),
                    'special' => 'autocomplete="email"',
                ]);
                ?>
            </div>
            <div class="md:col-span-2 min-w-0">
                <?php
                echo KIT_Commons::TextAreaField([
                    'label' => 'Address',
                    'name' => 'address',
                    'id' => 'address',
                    'value' => (string) ($co['address'] ?? ''),
                    'height' => '72',
                ]);
                ?>
            </div>
            <div class="md:col-span-2 min-w-0">
                <?php
                echo kit_render_origin_selects_markup([
                    'default_country_id' => (int) ($co['country_id'] ?? 0),
                    'default_city_id' => (int) ($co['city_id'] ?? 0),
                    'origin_country_initial' => (string) ((int) ($co['country_id'] ?? 0)),
                    'origin_city_initial' => (string) ((int) ($co['city_id'] ?? 0)),
                    'country_label' => 'Country',
                    'city_label' => 'City',
                    'layout' => 'equal',
                ]);
                ?>
            </div>
            <div class="md:col-span-2 min-w-0 sm:max-w-sm">
                <?php
                echo KIT_Commons::Linput([
                    'label' => 'VAT Number',
                    'name' => 'vat_number',
                    'id' => 'vat_number',
                    'type' => 'text',
                    'value' => (string) ($co['vat_number'] ?? ''),
                    'special' => '',
                ]);
                ?>
            </div>
        </div>
        <?php echo kit_render_customers_form_client_type_script(); ?>

        <div class="flex flex-wrap items-center justify-end gap-3 mt-4 pt-4 border-t border-gray-200">
            <a href="<?php echo esc_url(admin_url('admin.php?page=08600-customers')); ?>"
               class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <?php echo esc_html__('Cancel', '08600'); ?>
            </a>
            <?php
            echo KIT_Commons::renderButton('Update Company', 'primary', 'md', [
                'type' => 'submit',
                'name' => 'company_submit',
            ]);
            ?>
        </div>
    </form>
    <?php
    return ob_get_clean();
}
