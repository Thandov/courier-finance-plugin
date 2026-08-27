<?php
/**
 * Shared date normalization for Google Sheet seeding (kit_deliveries dispatch_date).
 *
 * kit_deliveries cells are formatted US locale (M/D/YYYY). Legacy parsers assumed DD/MM/YYYY.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extract Y-m-d from DEL-YYYYMMDD-### when the segment is a valid calendar date.
 */
function kit_seed_date_from_delivery_ref(string $delivery_ref): string
{
    if (!preg_match('/^DEL-(\d{4})(\d{2})(\d{2})-/i', trim($delivery_ref), $m)) {
        return '';
    }

    $year = (int) $m[1];
    $month = (int) $m[2];
    $day = (int) $m[3];
    if (!checkdate($month, $day, $year)) {
        return '';
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * Normalize kit_deliveries dispatch_date from sheet text.
 *
 * @param string $raw             Cell value (e.g. 10/6/2025, 2025-10-06)
 * @param string $delivery_ref    Optional DEL-YYYYMMDD-### for disambiguation / fallback
 */
function kit_seed_normalize_dispatch_date(string $raw, string $delivery_ref = ''): string
{
    $ref_date = kit_seed_date_from_delivery_ref($delivery_ref);
    $raw = trim($raw);

    if ($raw === '' || preg_match('/^(warehouse|cancelled|duplicate|null|n\/a)$/i', $raw)) {
        return $ref_date !== '' ? $ref_date : current_time('Y-m-d');
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        if (checkdate($day, $month, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $day, $month);
        }
        if ($ref_date !== '') {
            return $ref_date;
        }
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {
        $part_a = (int) $m[1];
        $part_b = (int) $m[2];
        $year = (int) $m[3];

        $candidates = [];
        if ($part_a >= 1 && $part_a <= 12 && $part_b >= 1 && $part_b <= 31) {
            $candidates[] = sprintf('%04d-%02d-%02d', $year, $part_a, $part_b);
        }
        if ($part_b >= 1 && $part_b <= 12 && $part_a >= 1 && $part_a <= 31) {
            $candidates[] = sprintf('%04d-%02d-%02d', $year, $part_b, $part_a);
        }
        $candidates = array_values(array_unique($candidates));

        if ($ref_date !== '') {
            foreach ($candidates as $candidate) {
                if ($candidate === $ref_date) {
                    return $candidate;
                }
            }
        }

        if ($part_a > 12 && $part_b <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $part_b, $part_a);
        }
        if ($part_b > 12 && $part_a <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $part_a, $part_b);
        }

        // Ambiguous M/D vs D/M — kit_deliveries tab uses US M/D/YYYY from Google Sheets.
        if ($part_a <= 12 && $part_b <= 12 && !empty($candidates)) {
            return $candidates[0];
        }
    }

    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return $ref_date !== '' ? $ref_date : current_time('Y-m-d');
}
