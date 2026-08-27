<?php
/**
 * Business-name detection, shared by every path that has to decide whether a
 * customer is a company or a person.
 *
 * These used to live only in includes/admin-pages/settings.php, which is
 * required from the admin menu — so they did not exist during a cron or CLI
 * seed. KIT_Customer_Seeder guards its call with function_exists(), which meant
 * a CLI seed silently promoted nobody and left wp_kit_company_customers empty
 * while an admin-triggered seed populated it. Same failure mode as the
 * charge-basis helpers (see kit-charge-basis.php).
 *
 * Both copies are function_exists-guarded, so loading this file first makes
 * settings.php skip its own definitions and every context agrees.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kit_seed_is_sheet_error_value')) {
    /** Google Sheets formula / sync errors (#N/A, #REF!, …) are not real data. */
    function kit_seed_is_sheet_error_value(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        return (bool) preg_match('/^#(ERROR|REF|N\/A|NULL|VALUE|DIV\/0|NAME\?|NUM!)!?\s*$/i', $value);
    }
}

if (!function_exists('kit_seed_strip_private_company_label')) {
    /** Strip Individual/Private / sheet-error pseudo-company labels from seeded rows. */
    function kit_seed_strip_private_company_label(string $company): string
    {
        $company = trim($company);
        if ($company === '') {
            return '';
        }
        $lower = strtolower($company);
        if (in_array($lower, ['individual', 'private', 'n/a', 'na', 'none', '-', '--', 'null', '0'], true)) {
            return '';
        }
        if (kit_seed_is_sheet_error_value($company)) {
            return '';
        }
        return $company;
    }
}

if (!function_exists('kit_seed_customer_is_company_suffix_only')) {
    /** Tokens that are company suffixes/fragments — never a person's name. */
    function kit_seed_customer_is_company_suffix_only(string $text): bool
    {
        $t = strtolower(trim($text, " \t\n\r\0\x0B.)("));
        return in_array($t, [
            'ltd', 'limited', 'pty', 'inc', 'corp', 'llc', 'plc', 'sa', 'cc', 'gmbh',
            'investments', 'technologies', 'industries', 'works', 'expeditions',
            'tours', 'safaris', 'lodge', 'lodges', 'hotels', 'foods', 'laundry', 'creations',
            'holdings', 'dom', 'ltd.', 'adventures', 'adventure',
            'emporium', 'emporiums', 'destinations', 'destination', 'enterprise', 'enterprises',
            'tanzania', 'kenya', 'zambia', 'africa', 'wilderness',
        ], true);
    }
}

if (!function_exists('kit_seed_customer_looks_like_business')) {
    /** True when the whole label reads as a business/company (Ltd, Lodge, Tours, etc.). */
    function kit_seed_customer_looks_like_business(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }
        if (strpos($t, '=') !== false) {
            return true;
        }
        if (preg_match('/\b(ltd|limited|pty|inc|corp|llc|plc|group|traders|logistics|camp|camps|safari|safaris|lodge|lodges|hotel|hotels|tours|tour|investments|technologies|industries|works|expeditions|foods|laundry|creations|holdings|adventure|adventures|emporium|emporiums|destinations|destination|enterprise|enterprises|wilderness|tanzania|kenya|zambia|africa|suppliers|supplier|imports|import|exports|export|motors|motor|furniture|hardware|wholesale|retail|construction|engineering|solutions|services|properties|property|estate|estates|trading|company|brewing|brewery|breweries)\b/i', $t)) {
            return true;
        }
        return kit_seed_customer_is_company_suffix_only($t);
    }
}
