<?php
/**
 * Canonical numeric parser for values read out of Google Sheets.
 *
 * WHY THIS EXISTS
 * ---------------
 * Courier_Google_Sheets::get_values() does not set a valueRenderOption, so the
 * Sheets API returns FORMATTED values — what the cell *displays*, not what it
 * holds. A currency-formatted cell arrives as the string "R 1 200,50".
 *
 * Every seeding path used to parse those with some variation of
 *
 *     (float) preg_replace('/[^0-9.\-]/', '', $value)
 *
 * which strips the space and the comma and yields 120050 — a hundred times the
 * real amount. The DB then faithfully stores the wrong number, and because both
 * sides of any same-parser comparison agree, nothing complains. This is the
 * single biggest reason wp_kit_waybills "looks different" from the sheet.
 *
 * The render option is deliberately NOT changed to UNFORMATTED_VALUE: that
 * setting is global to get_values(), and it would also turn date cells into
 * serial numbers and strip the leading zeros off text-formatted waybill numbers
 * ("0052" → 52) across every other caller. Parsing the display text correctly is
 * the narrower, safer fix — the text contains everything we need.
 *
 * SEPARATOR RULES
 * ---------------
 * Handles the shapes Sheets actually emits, in either locale:
 *
 *     "R 1 200,50"  "R1,200.50"  "1 200.50"  "1.200,50"
 *     "1.200.000"   "-R 45,00"   "(45.00)"   "340"
 *
 * Both separators present → whichever comes LAST is the decimal separator.
 * Comma only → decimal comma when it is a lone comma with 1-2 trailing digits
 * ("1 200,50"), otherwise a thousands separator ("1,200").
 * Dot only → decimal point, unless there are several ("1.200.000").
 *
 * Negative is recognised only at the front ("-45", "R -45", "(45)") so that an
 * embedded hyphen in a reference-like value ("12-34") is not read as a sign.
 *
 * KIT_Waybill_Verifier keeps its own separate implementation of these rules on
 * purpose — it must be able to disagree with the writer. tests-waybill-verifier.php
 * asserts the two agree across a corpus so they cannot silently drift apart.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kit_parse_sheet_number')) {
    /**
     * Parse a sheet cell into a number.
     *
     * @param mixed $raw
     * @return float|null null when the cell holds no digits at all (empty,
     *                    "TRUE", "N/A", "-") — meaning "not a number", which
     *                    callers should treat as absent rather than as zero.
     */
    function kit_parse_sheet_number($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (is_bool($raw) || $raw === null) {
            return null;
        }

        $s = trim((string) $raw);
        if ($s === '' || !preg_match('/\d/', $s)) {
            return null;
        }

        // Sign: leading '-' (possibly behind a currency symbol) or parenthesised.
        $negative = (bool) preg_match('/^\(.*\)$/', $s) || (bool) preg_match('/^[^\d]*-/', $s);

        // Keep digits and separators only. Drops currency symbols, letters,
        // ordinary spaces, and the non-breaking space Sheets emits in numbers.
        $s = preg_replace('/[^\d.,]/u', '', $s);
        if ($s === '' || $s === null) {
            return null;
        }

        $last_dot = strrpos($s, '.');
        $last_comma = strrpos($s, ',');

        if ($last_dot !== false && $last_comma !== false) {
            if ($last_comma > $last_dot) {
                $s = str_replace(',', '.', str_replace('.', '', $s));
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($last_comma !== false) {
            $decimals = strlen($s) - $last_comma - 1;
            if (substr_count($s, ',') === 1 && $decimals >= 1 && $decimals <= 2) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($last_dot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }

        if (!is_numeric($s)) {
            return null;
        }

        $value = (float) $s;
        return $negative ? -abs($value) : $value;
    }
}

if (!function_exists('kit_parse_sheet_decimal')) {
    /**
     * Money / measurement columns. Non-numeric cells collapse to $default.
     *
     * @param mixed $raw
     */
    function kit_parse_sheet_decimal($raw, float $default = 0.0): float
    {
        $value = kit_parse_sheet_number($raw);
        return $value === null ? $default : $value;
    }
}

if (!function_exists('kit_parse_sheet_int')) {
    /**
     * ID / count columns. Truncates toward zero, matching the previous (int) cast.
     *
     * @param mixed $raw
     */
    function kit_parse_sheet_int($raw, int $default = 0): int
    {
        $value = kit_parse_sheet_number($raw);
        return $value === null ? $default : (int) $value;
    }
}
