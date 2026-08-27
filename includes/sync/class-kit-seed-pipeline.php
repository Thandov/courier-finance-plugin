<?php
/**
 * Ordered sheet → DB seed pipeline.
 *
 * Required order: drivers → deliveries → customers → waybills.
 * Customers and waybills already had seeders; this wires the first two stages
 * in front so waybill.delivery_id / delivery.driver_id resolve.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Seed_Pipeline
{
    /**
     * Run the full seed pipeline.
     *
     * @param bool $shadow when true, stages that support shadow write nothing
     * @return array{success:bool,message:string,stages:array,counts:array}
     */
    public static function run(bool $shadow = false): array
    {
        $stages = [];
        $aggregate = [];

        // ---- 1. Drivers ----------------------------------------------------
        if (!class_exists('KIT_Driver_Seeder')) {
            return self::abort($stages, $aggregate, 'drivers', 'KIT_Driver_Seeder not loaded.');
        }
        $drivers = KIT_Driver_Seeder::run($shadow);
        $stages['drivers'] = $drivers;
        $aggregate['drivers'] = $drivers['counts'] ?? [];
        if (empty($drivers['success'])) {
            return self::abort($stages, $aggregate, 'drivers', (string) ($drivers['message'] ?? 'drivers failed'));
        }

        // ---- 2. Deliveries (after drivers — rows carry driver_id) ----------
        if (!class_exists('KIT_Delivery_Seeder') || !class_exists('Courier_Google_Sheets')) {
            return self::abort($stages, $aggregate, 'deliveries', 'KIT_Delivery_Seeder or Sheets not loaded.');
        }
        try {
            $delivery_rows = Courier_Google_Sheets::get_values('', 'A1:K500', 'kit_deliveries');
        } catch (Throwable $e) {
            return self::abort($stages, $aggregate, 'deliveries', 'Sheet read failed: ' . $e->getMessage());
        }
        if (!is_array($delivery_rows) || count($delivery_rows) < 2) {
            return self::abort($stages, $aggregate, 'deliveries', 'kit_deliveries sheet returned no data rows.');
        }
        if ($shadow) {
            $valid = KIT_Delivery_Seeder::count_valid_rows($delivery_rows);
            $delivery_stats = [
                'inserted' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'deleted'  => 0,
                'trip_map' => [],
                'shadow_valid' => $valid,
            ];
            $deliveries = [
                'success' => true,
                'message' => sprintf('Shadow deliveries: %d valid sheet row(s), no writes.', $valid),
                'counts'  => $delivery_stats,
            ];
        } else {
            $delivery_stats = KIT_Delivery_Seeder::seed_from_sheet_rows($delivery_rows, true);
            $deliveries = [
                'success' => true,
                'message' => sprintf(
                    'Production deliveries: %d inserted, %d updated, %d skipped, %d deleted.',
                    (int) ($delivery_stats['inserted'] ?? 0),
                    (int) ($delivery_stats['updated'] ?? 0),
                    (int) ($delivery_stats['skipped'] ?? 0),
                    (int) ($delivery_stats['deleted'] ?? 0)
                ),
                'counts' => $delivery_stats,
            ];
        }
        $stages['deliveries'] = $deliveries;
        $aggregate['deliveries'] = $delivery_stats;
        if (empty($deliveries['success'])) {
            return self::abort($stages, $aggregate, 'deliveries', (string) ($deliveries['message'] ?? 'deliveries failed'));
        }

        // ---- 3. Customers --------------------------------------------------
        if (!class_exists('KIT_Customer_Seeder')) {
            return self::abort($stages, $aggregate, 'customers', 'KIT_Customer_Seeder not loaded.');
        }
        $customers = KIT_Customer_Seeder::run($shadow);
        $stages['customers'] = $customers;
        $aggregate['customers'] = $customers['counts'] ?? [];
        if (empty($customers['success'])) {
            return self::abort($stages, $aggregate, 'customers', (string) ($customers['message'] ?? 'customers failed'));
        }

        // ---- 4. Waybills ---------------------------------------------------
        if (!class_exists('KIT_Waybill_Seeder')) {
            return self::abort($stages, $aggregate, 'waybills', 'KIT_Waybill_Seeder not loaded.');
        }
        $waybills = KIT_Waybill_Seeder::run('pipeline', $shadow);
        $stages['waybills'] = $waybills;
        $aggregate['waybills'] = $waybills['counts'] ?? [];
        if (empty($waybills['success'])) {
            return self::abort($stages, $aggregate, 'waybills', (string) ($waybills['message'] ?? 'waybills failed'));
        }

        $messages = [];
        foreach (['drivers', 'deliveries', 'customers', 'waybills'] as $key) {
            $messages[] = $stages[$key]['message'] ?? $key;
        }

        return [
            'success' => true,
            'message' => implode(' | ', $messages),
            'stages'  => $stages,
            'counts'  => $aggregate,
        ];
    }

    /**
     * @param array<string, array> $stages
     * @param array<string, array> $aggregate
     * @return array{success:bool,message:string,stages:array,counts:array,aborted_at:string}
     */
    private static function abort(array $stages, array $aggregate, string $stage, string $why): array
    {
        return [
            'success'    => false,
            'message'    => sprintf('Pipeline aborted at stage "%s": %s', $stage, $why),
            'stages'     => $stages,
            'counts'     => $aggregate,
            'aborted_at' => $stage,
        ];
    }
}
