<?php
/**
 * kit_drivers sheet → wp_kit_drivers.
 *
 * Must run before kit_deliveries seeding: deliveries carry driver_id that
 * matches the sheet's driver id column.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Driver_Seeder
{
    const DEFAULT_SHEET_NAME  = 'kit_drivers';
    const DEFAULT_SHEET_RANGE = 'A1:K500';

    /**
     * Seed drivers from the kit_drivers tab.
     *
     * @param bool $shadow when true, compute everything but write nothing
     * @return array{success:bool,message:string,counts:array,rows:array}
     */
    public static function run(bool $shadow = false): array
    {
        global $wpdb;

        $counts = [
            'sheet_rows' => 0,
            'inserted'   => 0,
            'updated'    => 0,
            'skipped'    => 0,
            'errored'    => 0,
        ];

        if (!class_exists('Courier_Google_Sheets') || !Courier_Google_Sheets::is_configured()) {
            return ['success' => false, 'message' => 'Google Sheets not configured.', 'counts' => $counts, 'rows' => []];
        }

        try {
            $rows = Courier_Google_Sheets::get_values('', self::DEFAULT_SHEET_RANGE, self::DEFAULT_SHEET_NAME);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Sheet read failed: ' . $e->getMessage(), 'counts' => $counts, 'rows' => []];
        }

        if (!is_array($rows) || count($rows) < 2) {
            return ['success' => false, 'message' => 'kit_drivers sheet returned no data rows.', 'counts' => $counts, 'rows' => []];
        }

        $header = array_map(
            static function ($c) {
                $h = strtolower(trim((string) $c));
                $h = preg_replace('/^#+\s*/', '', $h);
                return str_replace(' ', '_', $h);
            },
            $rows[0]
        );
        $col = [];
        foreach ($header as $i => $h) {
            if ($h !== '' && !isset($col[$h])) {
                $col[$h] = $i;
            }
        }

        $table = $wpdb->prefix . 'kit_drivers';
        $report = [];
        $now = current_time('mysql');

        // Prefetch existing rows for id- and name-keyed upserts.
        $by_id = [];
        $by_name = [];
        foreach ((array) $wpdb->get_results("SELECT id, name FROM {$table}", ARRAY_A) as $r) {
            $id = (int) $r['id'];
            $by_id[$id] = $id;
            $name_key = self::name_key((string) $r['name']);
            if ($name_key !== '') {
                $by_name[$name_key] = $id;
            }
        }

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $counts['sheet_rows']++;

            $sheet_id = self::int_cell($row, $col, ['id', 'driver_id']);
            $name = self::text_cell($row, $col, ['name']);
            if ($name === '') {
                $counts['skipped']++;
                $report[] = ['source_row' => $r + 1, 'action' => 'skip', 'why' => 'empty name'];
                continue;
            }

            $phone = self::text_cell($row, $col, ['phone', 'telephone', 'cell']);
            $email = self::text_cell($row, $col, ['email', 'email_address']);
            $license = self::text_cell($row, $col, ['license_number', 'licence_number', 'license']);
            $is_active = self::parse_active(self::text_cell($row, $col, ['is_active', 'active', 'status'], '1'));

            $payload = [
                'name'           => $name,
                'phone'          => $phone !== '' ? $phone : null,
                'email'          => $email !== '' ? $email : null,
                'license_number' => $license !== '' ? $license : null,
                'is_active'      => $is_active,
            ];
            $formats = ['%s', '%s', '%s', '%s', '%d'];

            $existing_id = 0;
            if ($sheet_id > 0 && isset($by_id[$sheet_id])) {
                $existing_id = $sheet_id;
            } elseif ($sheet_id <= 0) {
                $name_key = self::name_key($name);
                if ($name_key !== '' && isset($by_name[$name_key])) {
                    $existing_id = $by_name[$name_key];
                }
            }

            if ($shadow) {
                $counts[$existing_id > 0 ? 'updated' : 'inserted']++;
                $report[] = [
                    'source_row' => $r + 1,
                    'sheet_id'   => $sheet_id,
                    'action'     => 'shadow',
                    'payload'    => $payload,
                ];
                continue;
            }

            if ($existing_id > 0) {
                $done = $wpdb->update($table, $payload, ['id' => $existing_id], $formats, ['%d']);
                if ($done === false) {
                    $counts['errored']++;
                    $report[] = ['source_row' => $r + 1, 'sheet_id' => $sheet_id, 'action' => 'error', 'why' => $wpdb->last_error];
                    continue;
                }
                if ($done > 0) {
                    $counts['updated']++;
                    $report[] = ['source_row' => $r + 1, 'sheet_id' => $sheet_id, 'db_id' => $existing_id, 'action' => 'updated'];
                } else {
                    $report[] = ['source_row' => $r + 1, 'sheet_id' => $sheet_id, 'db_id' => $existing_id, 'action' => 'unchanged'];
                }
                continue;
            }

            // Insert keyed on sheet id when present so deliveries.driver_id resolves.
            if ($sheet_id > 0) {
                $payload['id'] = $sheet_id;
                $formats = array_merge(['%d'], $formats);
            }
            $payload['created_at'] = $now;
            $formats[] = '%s';

            $done = $wpdb->insert($table, $payload, $formats);
            if (!$done) {
                $counts['errored']++;
                $report[] = ['source_row' => $r + 1, 'sheet_id' => $sheet_id, 'action' => 'error', 'why' => $wpdb->last_error];
                continue;
            }

            $new_id = $sheet_id > 0 ? $sheet_id : (int) $wpdb->insert_id;
            $by_id[$new_id] = $new_id;
            $by_name[self::name_key($name)] = $new_id;
            $counts['inserted']++;
            $report[] = ['source_row' => $r + 1, 'sheet_id' => $sheet_id, 'db_id' => $new_id, 'action' => 'inserted'];
        }

        $message = sprintf(
            '%s drivers: %d sheet row(s), %d inserted, %d updated, %d skipped, %d errored.',
            $shadow ? 'Shadow' : 'Production',
            $counts['sheet_rows'],
            $counts['inserted'],
            $counts['updated'],
            $counts['skipped'],
            $counts['errored']
        );

        return ['success' => $counts['errored'] === 0, 'message' => $message, 'counts' => $counts, 'rows' => $report];
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, int> $col
     * @param string[] $keys
     */
    private static function text_cell(array $row, array $col, array $keys, string $default = ''): string
    {
        foreach ($keys as $k) {
            if (!isset($col[$k])) {
                continue;
            }
            $v = trim((string) ($row[$col[$k]] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return $default;
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, int> $col
     * @param string[] $keys
     */
    private static function int_cell(array $row, array $col, array $keys): int
    {
        $raw = self::text_cell($row, $col, $keys);
        if ($raw === '' || !is_numeric($raw)) {
            return 0;
        }

        return max(0, (int) $raw);
    }

    private static function parse_active(string $raw): int
    {
        $u = strtoupper(trim($raw));
        if ($u === '' || in_array($u, ['1', 'TRUE', 'YES', 'ACTIVE', 'Y'], true)) {
            return 1;
        }
        if (in_array($u, ['0', 'FALSE', 'NO', 'INACTIVE', 'N'], true)) {
            return 0;
        }

        return is_numeric($raw) ? (((int) $raw) !== 0 ? 1 : 0) : 1;
    }

    private static function name_key(string $name): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }
}
