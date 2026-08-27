<?php
/**
 * Charge-basis helpers, shared by every path that needs the billed freight total.
 *
 * These used to live only in includes/seed/seed-verification.php, which is
 * required from settings.php — an admin-only file. That left KIT_Waybill_Seeder
 * silently falling back to max(mass, volume) on cron runs while the admin-side
 * verifier used the charge_basis rule, so the same waybill could be given two
 * different totals depending on who triggered the seed.
 *
 * Both definitions are function_exists-guarded, so loading this file first makes
 * seed-verification.php skip its own copies and everything agrees.
 *
 * kit_waybills column AB (charge_basis) is the source of truth: it selects
 * column Z (mass_charge) or column AA (volume_charge) as the billed total.
 * Empty / auto / max / higher / best → max(mass_charge, volume_charge).
 * Never invent a MASS/VOLUME default when AB is blank.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kit_seed_normalize_charge_basis')) {
    /** Normalize sheet/DB charge_basis to 'mass', 'volume', or '' when unset/auto. */
    function kit_seed_normalize_charge_basis(string $basis): string
    {
        $v = strtolower(trim($basis));
        if ($v === '' || in_array($v, ['auto', 'max', 'higher', 'best'], true)) {
            return '';
        }
        if (strpos($v, 'vol') === 0 || $v === 'v' || $v === 'cbm' || $v === 'm3') {
            return 'volume';
        }
        if (strpos($v, 'mass') === 0 || strpos($v, 'weight') === 0 || $v === 'w' || $v === 'kg') {
            return 'mass';
        }
        return '';
    }
}

if (!function_exists('kit_seed_total_from_charge_basis')) {
    /**
     * Billed freight total from mass/vol charges using charge_basis (AB).
     * Does not use max(mass, vol) when a basis is set — that is the whole point.
     */
    function kit_seed_total_from_charge_basis(float $mass_charge, float $volume_charge, string $charge_basis): float
    {
        $basis = kit_seed_normalize_charge_basis($charge_basis);
        if ($basis === 'volume') {
            return round($volume_charge, 2);
        }
        if ($basis === 'mass') {
            return round($mass_charge, 2);
        }
        // No explicit basis: fall back to higher charge (legacy / auto).
        return round(max($mass_charge, $volume_charge), 2);
    }
}

if (!function_exists('kit_seed_sanitize_inverted_charge_basis')) {
    /**
     * Clear MASS/VOLUME when the selected side is zero and the other side has money.
     * Empty result → kit_seed_total_from_charge_basis uses max(mass, volume).
     * Seed + verification must both call this so expected/actual agree.
     */
    function kit_seed_sanitize_inverted_charge_basis(string $charge_basis, float $mass_charge, float $volume_charge): string
    {
        $basis = kit_seed_normalize_charge_basis($charge_basis);
        if ($basis === 'mass' && $mass_charge <= 0.0 && $volume_charge > 0.0) {
            return '';
        }
        if ($basis === 'volume' && $volume_charge <= 0.0 && $mass_charge > 0.0) {
            return '';
        }
        return $basis;
    }
}
