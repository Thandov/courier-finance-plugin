<?php
/**
 * RETIRED: kit_parcels sheet → wp_kit_waybill_items bridge.
 *
 * Line items are now built directly in setup seed / KIT_Waybill_Seeder via
 * kit_seed_build_custom_items_from_description() (includes/sync/kit-seed-waybill-items.php).
 *
 * @package CourierFinancePlugin
 * @deprecated 2026-08-03
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Parcel_Seeder
{
    const DEFAULT_SHEET_NAME = 'kit_parcels';
    const DEFAULT_SHEET_RANGE = 'A1:H5000';

    /**
     * @param bool $shadow
     * @param array<int, array<int, mixed>>|null $preloaded_rows
     * @return array{success:bool,message:string,counts:array,shadow:bool}
     */
    public static function run(bool $shadow = false, ?array $preloaded_rows = null): array
    {
        return [
            'success' => true,
            'message' => 'KIT_Parcel_Seeder retired — waybill items seed from Item Description into wp_kit_waybill_items.',
            'counts'  => [
                'waybills_updated' => 0,
                'items_written'    => 0,
                'skipped'          => 0,
                'errors'           => [],
            ],
            'shadow'  => $shadow,
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array<string, array<int, array{item_description:string, item_price:float, item_index:int}>>
     */
    public static function group_parcels_by_waybill(array $rows): array
    {
        return [];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public static function read_sheet_rows(): array
    {
        return [];
    }
}
