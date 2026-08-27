<?php
/**
 * Google Sheets API client using Service Account authentication.
 *
 * Setup:
 * 1. Create a project in Google Cloud Console, enable Google Sheets API.
 * 2. Create a Service Account, download JSON key.
 * 3. Put the JSON file in the plugin (e.g. credentials/google-service-account.json) and add to .gitignore.
 * 4. Share the target Google Sheet with the service account email (e.g. xxx@project.iam.gserviceaccount.com).
 * 5. Define COURIER_GOOGLE_CREDENTIALS_PATH and optionally COURIER_GOOGLE_SPREADSHEET_ID in wp-config or plugin.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Courier_Google_Sheets {

	/** @var string|null Path to service account JSON key file */
	protected static $credentials_path;

	/**
	 * Default spreadsheet ID — the LIVE working document, "08600Africa".
	 *
	 * Was '1p1jZowAa12d6bMsKSeW9j5yDB7jMTuxa6TT6MG2SPjQ' until 2026-08-05. That
	 * document is titled "08600BackUp": a backup copy. Every seed was therefore
	 * reading a stale snapshot — it held 1040 waybills against the live sheet's
	 * 1132, so 98 waybills could never reach the database no matter how many
	 * times the seed was run, and everything else came from an old copy. It also
	 * lacks the source "Waybills" tab and the sync_errors tab entirely, and
	 * carries a stray "Copy of kit_waybills" tab.
	 *
	 * This is the cause of the long-standing "the script pulls the wrong
	 * information out of the spreadsheet" report.
	 *
	 * Override per-environment in wp-config.php without editing this file:
	 *     define('COURIER_GOOGLE_SPREADSHEET_ID', '...');
	 *
	 * @var string
	 */
	protected static $default_spreadsheet_id = '1w-9PfeN198UoLp-LO-ZFUYYjWiewuIfsp9r-2lY_Xec';

	/**
	 * Drivers roster lives on 08600AfricaWaybills (tab Drivers / kit_drivers).
	 * The live 08600Africa kit_drivers tab is a sync dump and is not authoritative.
	 *
	 * Override: define('COURIER_GOOGLE_DRIVERS_SPREADSHEET_ID', '...');
	 *
	 * @var string
	 */
	protected static $default_drivers_spreadsheet_id = '1yRoRdtOlDrCexnvnx0E2QBfe3xZq6NBpwZbCAQj6dak';

	/** @var \Google_Client|null */
	protected static $client;

	/** @var \Google_Service_Sheets|null */
	protected static $service;

	/**
	 * Get path to credentials JSON. Prefers constant, then filter, then default under plugin.
	 *
	 * @return string
	 */
	public static function get_credentials_path() {
		if (self::$credentials_path !== null) {
			return self::$credentials_path;
		}
		if (defined('COURIER_GOOGLE_CREDENTIALS_PATH') && COURIER_GOOGLE_CREDENTIALS_PATH) {
			self::$credentials_path = COURIER_GOOGLE_CREDENTIALS_PATH;
			return self::$credentials_path;
		}
		$default = defined('COURIER_FINANCE_PLUGIN_PATH')
			? COURIER_FINANCE_PLUGIN_PATH . 'credentials/google-service-account.json'
			: '';
		self::$credentials_path = (string) apply_filters('courier_google_credentials_path', $default);
		return self::$credentials_path;
	}

	/**
	 * Set credentials path (e.g. for tests).
	 *
	 * @param string $path
	 */
	public static function set_credentials_path($path) {
		self::$credentials_path = $path;
		self::$client = null;
		self::$service = null;
	}

	/**
	 * Spreadsheet ID for pull/seed reads (source). Prefers constant, then filter, then built-in default.
	 *
	 * @return string
	 */
	public static function get_source_spreadsheet_id() {
		if (defined('COURIER_GOOGLE_SPREADSHEET_ID') && COURIER_GOOGLE_SPREADSHEET_ID) {
			return COURIER_GOOGLE_SPREADSHEET_ID;
		}
		return (string) apply_filters('courier_google_spreadsheet_id', self::$default_spreadsheet_id);
	}

	/**
	 * Spreadsheet that owns the driver roster (08600AfricaWaybills).
	 */
	public static function get_drivers_spreadsheet_id() {
		if (defined('COURIER_GOOGLE_DRIVERS_SPREADSHEET_ID') && COURIER_GOOGLE_DRIVERS_SPREADSHEET_ID) {
			return (string) COURIER_GOOGLE_DRIVERS_SPREADSHEET_ID;
		}
		return (string) apply_filters('courier_google_drivers_spreadsheet_id', self::$default_drivers_spreadsheet_id);
	}

	/**
	 * Spreadsheet ID for push/auto-sync writes (destination). Falls back to source when unset.
	 *
	 * @return string
	 */
	public static function get_sync_spreadsheet_id() {
		if (defined('COURIER_GOOGLE_SYNC_SPREADSHEET_ID') && COURIER_GOOGLE_SYNC_SPREADSHEET_ID) {
			return COURIER_GOOGLE_SYNC_SPREADSHEET_ID;
		}
		return (string) apply_filters('courier_google_sync_spreadsheet_id', self::get_source_spreadsheet_id());
	}

	/**
	 * Get default spreadsheet ID. Prefers constant, then filter, then built-in default.
	 *
	 * @return string
	 */
	public static function get_default_spreadsheet_id() {
		return self::get_source_spreadsheet_id();
	}

	/**
	 * Build Google_Client with service account auth and Sheets scope.
	 *
	 * @return \Google_Client
	 * @throws \Exception If credentials file missing or invalid.
	 */
	public static function get_client() {
		if (self::$client !== null) {
			return self::$client;
		}

		$autoload = defined('COURIER_FINANCE_PLUGIN_PATH')
			? COURIER_FINANCE_PLUGIN_PATH . 'vendor/autoload.php'
			: __DIR__ . '/../vendor/autoload.php';
		if (!file_exists($autoload)) {
			throw new \Exception('Google API client not installed. Run: composer require google/apiclient');
		}
		require_once $autoload;

		$path = self::get_credentials_path();
		if (!is_readable($path)) {
			throw new \Exception('Google Sheets credentials file not found or not readable: ' . $path);
		}

		$client = new \Google_Client();
		$client->setAuthConfig($path);
		$client->setScopes(['https://www.googleapis.com/auth/spreadsheets']);
		$client->setApplicationName('Courier Finance Plugin');

		self::$client = $client;
		return self::$client;
	}

	/**
	 * Get Google_Service_Sheets instance.
	 *
	 * @return \Google_Service_Sheets
	 */
	public static function get_service() {
		if (self::$service !== null) {
			return self::$service;
		}
		self::$service = new \Google_Service_Sheets(self::get_client());
		return self::$service;
	}

	/**
	 * Get the list of sheet (tab) names for the spreadsheet.
	 *
	 * @param string $spreadsheet_id Optional. Default from constant/filter.
	 * @return array List of sheet tab titles.
	 * @throws \Exception On API or auth errors.
	 */
	public static function get_sheet_names($spreadsheet_id = '') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$service = self::get_service();
		$ss = $service->spreadsheets->get($spreadsheet_id, ['fields' => 'sheets(properties(title))']);
		$names = [];
		foreach ($ss->getSheets() as $sheet) {
			$title = $sheet->getProperties()->getTitle();
			if ($title !== null && $title !== '') {
				$names[] = $title;
			}
		}
		return $names;
	}

	/**
	 * Read values from a sheet range.
	 *
	 * @param string      $spreadsheet_id Optional. Default from constant/filter.
	 * @param string      $range         A1 notation, e.g. "Sheet1!A1:Z1000" or "Sheet1".
	 * @param string|null $sheet_name     Optional. If given, $range is treated as "A1:Z100" and sheet name is prepended.
	 * @return array Two-dimensional array of cell values (rows).
	 * @throws \Exception On API or auth errors.
	 */
	public static function get_values($spreadsheet_id = '', $range = 'Sheet1', $sheet_name = null) {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		// Ensure range has valid A1 notation (both start and end with row number) so API accepts it
		if (preg_match('/^([A-Z]+):([A-Z]+)(\d+)$/i', $range, $m)) {
			$range = $m[1] . '1:' . $m[2] . $m[3];
		}
		if ($sheet_name !== null && $sheet_name !== '') {
			// A1 notation: sheet names are quoted so the API parses the range correctly (avoids "Unable to parse range").
			$escaped = str_replace("'", "''", (string) $sheet_name);
			$range = "'" . $escaped . "'!" . $range;
		}

		$service = self::get_service();
		$response = $service->spreadsheets_values->get($spreadsheet_id, $range);
		$rows = $response->getValues();
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Resolve sheet name from explicit parameter or "Sheet!A:D" range.
	 *
	 * @param string      $range
	 * @param string|null $sheet_name
	 * @return string
	 */
	protected static function resolve_sheet_name_from_range($range, $sheet_name = null) {
		if ($sheet_name !== null && $sheet_name !== '') {
			return (string) $sheet_name;
		}
		$bang_pos = strpos((string) $range, '!');
		if ($bang_pos === false) {
			return '';
		}
		$raw = substr((string) $range, 0, $bang_pos);
		$raw = trim((string) $raw);
		// Remove optional A1 notation quoting around sheet names.
		if (strlen($raw) >= 2 && $raw[0] === "'" && substr($raw, -1) === "'") {
			$raw = substr($raw, 1, -1);
			$raw = str_replace("''", "'", $raw);
		}
		return $raw;
	}

	/**
	 * True when column A header is "id" (case-insensitive).
	 *
	 * @param string $spreadsheet_id
	 * @param string $sheet_name
	 * @return bool
	 */
	protected static function sheet_has_auto_increment_id_column($spreadsheet_id, $sheet_name) {
		if ($sheet_name === '') {
			return false;
		}
		try {
			$header_rows = self::get_values($spreadsheet_id, 'A1:A1', $sheet_name);
		} catch (\Throwable $e) {
			return false;
		}
		$header = isset($header_rows[0][0]) ? trim((string) $header_rows[0][0]) : '';
		return strtolower($header) === 'id';
	}

	/**
	 * Get next auto-increment ID from column A values (max + 1).
	 *
	 * @param string $spreadsheet_id
	 * @param string $sheet_name
	 * @return int
	 */
	protected static function get_next_auto_increment_id($spreadsheet_id, $sheet_name) {
		$max_id = 0;
		try {
			$id_rows = self::get_values($spreadsheet_id, 'A2:A', $sheet_name);
			if (is_array($id_rows)) {
				foreach ($id_rows as $id_row) {
					$value = isset($id_row[0]) ? trim((string) $id_row[0]) : '';
					if ($value !== '' && preg_match('/^\d+$/', $value)) {
						$int_val = (int) $value;
						if ($int_val > $max_id) {
							$max_id = $int_val;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			$max_id = 0;
		}
		return $max_id + 1;
	}

	/**
	 * Force auto-increment IDs in column A for sheets whose A1 header is "id".
	 *
	 * @param array       $rows
	 * @param string      $spreadsheet_id
	 * @param string      $range
	 * @param string|null $sheet_name
	 * @return array
	 */
	protected static function apply_auto_increment_ids_to_rows(array $rows, $spreadsheet_id, $range, $sheet_name = null) {
		$resolved_sheet = self::resolve_sheet_name_from_range($range, $sheet_name);
		if ($resolved_sheet === '') {
			return $rows;
		}
		if (!self::sheet_has_auto_increment_id_column($spreadsheet_id, $resolved_sheet)) {
			return $rows;
		}
		$next_id = self::get_next_auto_increment_id($spreadsheet_id, $resolved_sheet);
		foreach ($rows as $i => $row) {
			if (!is_array($row)) {
				$row = [(string) $row];
			}
			$row[0] = $next_id++;
			$rows[$i] = $row;
		}
		return $rows;
	}

	/**
	 * Append values to a sheet range.
	 *
	 * @param array       $rows         Array of rows (each row is array of cell values)
	 * @param string      $spreadsheet_id Optional. Default from constant/filter.
	 * @param string      $range        A1 notation, e.g. "Sheet1!A1" or "Sheet1!A:D"
	 * @param string|null $sheet_name   Optional. If given, range is sheet_name!A:D (append to sheet)
	 * @param string      $value_input_option 'USER_ENTERED' (parse formulas) or 'RAW' (store as-is; use for phone numbers to avoid formula parse errors)
	 * @return bool True on success
	 * @throws \Exception On API or auth errors.
	 */
	public static function append_values(array $rows, $spreadsheet_id = '', $range = 'A:D', $sheet_name = null, $value_input_option = 'USER_ENTERED') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		if ($sheet_name !== null && $sheet_name !== '') {
			$range = $sheet_name . '!' . $range;
		}
		$rows = self::apply_auto_increment_ids_to_rows($rows, $spreadsheet_id, $range, $sheet_name);
		$service = self::get_service();
		$body = new \Google_Service_Sheets_ValueRange(['values' => $rows]);
		$params = ['valueInputOption' => $value_input_option, 'insertDataOption' => 'INSERT_ROWS'];
		$service->spreadsheets_values->append($spreadsheet_id, $range, $body, $params);
		return true;
	}

	/**
	 * Ensure the sheet has at least $min_rows rows; add rows via API if needed (avoids "exceeds grid limits" when sheet is full).
	 *
	 * @param string $spreadsheet_id
	 * @param string $sheet_name    Tab title
	 * @param int    $min_rows      Minimum 1-based row index we need to write to
	 * @return void
	 */
	protected static function ensure_sheet_rows($spreadsheet_id, $sheet_name, $min_rows) {
		$service = self::get_service();
		try {
			$ss = $service->spreadsheets->get($spreadsheet_id, ['fields' => 'sheets(properties(sheetId,title,gridProperties(rowCount,columnCount)))']);
		} catch (\Throwable $e) {
			return;
		}
		$sheet_id = null;
		$row_count = 0;
		$sheet_name_lower = strtolower(trim($sheet_name));
		foreach ($ss->getSheets() as $sheet) {
			$title = $sheet->getProperties()->getTitle();
			if (strtolower(trim((string) $title)) === $sheet_name_lower) {
				$sheet_id = $sheet->getProperties()->getSheetId();
				$gp = $sheet->getProperties()->getGridProperties();
				if ($gp !== null) {
					$row_count = (int) $gp->getRowCount();
				}
				break;
			}
		}
		if ($sheet_id === null) {
			return;
		}
		if ($min_rows <= $row_count) {
			return;
		}
		$add = $min_rows - $row_count;
		$request = [
			'appendDimension' => [
				'sheetId'   => $sheet_id,
				'dimension' => 'ROWS',
				'length'    => $add,
			],
		];
		$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
		$service->spreadsheets->batchUpdate($spreadsheet_id, $body);
	}

	/**
	 * Append one row to a sheet without writing to skipped column indices (e.g. preserve formula in F).
	 * Writes one physical row by updating the next row index (two segments), so we do not create two rows.
	 *
	 * @param array  $row_data              Full row values (0-based indices)
	 * @param string $sheet_name            Tab name
	 * @param int[]  $skip_column_indices   0-based column indices to skip (e.g. [5] for city_id)
	 * @param string $spreadsheet_id        Optional
	 * @return void
	 */
	public static function append_row_skip_columns(array $row_data, $sheet_name, array $skip_column_indices, $spreadsheet_id = '') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		if (self::sheet_has_auto_increment_id_column($spreadsheet_id, (string) $sheet_name)) {
			$row_data[0] = self::get_next_auto_increment_id($spreadsheet_id, (string) $sheet_name);
		}
		$to_col = function ($idx_0based) {
			$n = $idx_0based + 1;
			$s = '';
			while ($n > 0) {
				$n--;
				$s = chr(65 + ($n % 26)) . $s;
				$n = (int) floor($n / 26);
			}
			return $s;
		};
		$len = count($row_data);
		// Get next row number by reading column A and appending after last row (single row, not two)
		$rows = self::get_values($spreadsheet_id, 'A:A', $sheet_name);
		$next_row = is_array($rows) ? count($rows) + 1 : 1;
		// If sheet has a fixed row count and we're past it, add rows so the update doesn't fail (e.g. "exceeds grid limits. Max rows: 498")
		self::ensure_sheet_rows($spreadsheet_id, $sheet_name, $next_row);
		$attempt = 0;
		$max_attempts = 2;
		while ($attempt < $max_attempts) {
			try {
				$seg_start = 0;
				for ($col = 0; $col <= $len; $col++) {
					if ($col === $len || in_array($col, $skip_column_indices, true)) {
						if ($seg_start < $col) {
							$slice = array_slice($row_data, $seg_start, $col - $seg_start);
							$col_start = $to_col($seg_start);
							$col_end = $to_col($col - 1);
							$range = $col_start . $next_row . ':' . $col_end . $next_row;
							self::update_range($sheet_name, $range, [$slice], $spreadsheet_id);
						}
						$seg_start = $col + 1;
					}
				}
				break;
			} catch (\Throwable $e) {
				$msg = $e->getMessage();
				$is_grid_limit = (strpos($msg, 'exceeds grid limits') !== false || (strpos($msg, 'INVALID_ARGUMENT') !== false && strpos($msg, 'Max rows') !== false));
				if ($is_grid_limit && $attempt === 0) {
					// Grid may not be extended yet; ensure rows again then retry once
					self::ensure_sheet_rows($spreadsheet_id, $sheet_name, $next_row);
					$attempt++;
				} else {
					throw $e;
				}
			}
		}
	}

	/**
	 * Delete a row from a sheet where a column value matches (e.g. driver name in column B).
	 *
	 * @param string $match_value Value to find (e.g. driver name)
	 * @param string $sheet_name  Tab name
	 * @param int    $column_index 0-based column index to search (1 = column B for name)
	 * @param string $spreadsheet_id Optional
	 * @return bool True if row was found and deleted
	 * @throws \Exception On API errors
	 */
	public static function delete_row_by_value($match_value, $sheet_name, $column_index = 1, $spreadsheet_id = '') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$service = self::get_service();
		$ss = $service->spreadsheets->get($spreadsheet_id);
		$sheet_id = null;
		foreach ($ss->getSheets() as $sheet) {
			if ($sheet->getProperties()->getTitle() === $sheet_name) {
				$sheet_id = $sheet->getProperties()->getSheetId();
				break;
			}
		}
		if ($sheet_id === null) {
			throw new \Exception("Sheet '{$sheet_name}' not found.");
		}
		$range = $sheet_name . '!A:Z';
		$response = $service->spreadsheets_values->get($spreadsheet_id, $range);
		$rows = $response->getValues();
		if (empty($rows)) {
			return false;
		}
		$match_value = trim((string) $match_value);
		$row_index = null;
		foreach ($rows as $i => $row) {
			$cell = isset($row[$column_index]) ? trim((string) $row[$column_index]) : '';
			if ($cell === $match_value) {
				$row_index = $i;
				break;
			}
		}
		if ($row_index === null) {
			return false;
		}
		$request = [
			'deleteDimension' => [
				'range' => [
					'sheetId'   => $sheet_id,
					'dimension' => 'ROWS',
					'startIndex' => (int) $row_index,
					'endIndex'   => (int) $row_index + 1,
				],
			],
		];
		$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
		$service->spreadsheets->batchUpdate($spreadsheet_id, $body);
		return true;
	}

	/**
	 * Delete all rows where a column value matches.
	 *
	 * @param string $match_value Value to find
	 * @param string $sheet_name  Tab name
	 * @param int    $column_index 0-based column index to search
	 * @param string $spreadsheet_id Optional
	 * @return int Number of rows deleted
	 */
	public static function delete_rows_by_value($match_value, $sheet_name, $column_index = 0, $spreadsheet_id = '') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$service = self::get_service();
		$ss = $service->spreadsheets->get($spreadsheet_id);
		$sheet_id = null;
		foreach ($ss->getSheets() as $sheet) {
			if ($sheet->getProperties()->getTitle() === $sheet_name) {
				$sheet_id = $sheet->getProperties()->getSheetId();
				break;
			}
		}
		if ($sheet_id === null) {
			return 0;
		}
		$range = $sheet_name . '!A:Z';
		$response = $service->spreadsheets_values->get($spreadsheet_id, $range);
		$rows = $response->getValues();
		if (empty($rows)) {
			return 0;
		}
		$match_value = trim((string) $match_value);
		$to_delete = [];
		foreach ($rows as $i => $row) {
			$cell = isset($row[$column_index]) ? trim((string) $row[$column_index]) : '';
			if ($cell === $match_value) {
				$to_delete[] = $i;
			}
		}
		if (empty($to_delete)) {
			return 0;
		}
		$requests = [];
		foreach (array_reverse($to_delete) as $row_index) {
			$requests[] = [
				'deleteDimension' => [
					'range' => [
						'sheetId'   => $sheet_id,
						'dimension' => 'ROWS',
						'startIndex' => (int) $row_index,
						'endIndex'   => (int) $row_index + 1,
					],
				],
			];
		}
		$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
		$service->spreadsheets->batchUpdate($spreadsheet_id, $body);
		return count($to_delete);
	}

	/**
	 * Update a row where a column value matches.
	 *
	 * @param string $match_value Value to find
	 * @param string $sheet_name  Tab name
	 * @param array  $row_data    New row values
	 * @param int    $match_column_index 0-based column to search
	 * @param string $spreadsheet_id Optional
	 * @return bool True if updated
	 */
	public static function update_row_by_value($match_value, $sheet_name, array $row_data, $match_column_index = 0, $spreadsheet_id = '', $value_input_option = 'USER_ENTERED') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$range = $sheet_name . '!A:ZZ';
		$response = self::get_service()->spreadsheets_values->get($spreadsheet_id, $range);
		$rows = $response->getValues();
		$match_value = trim((string) $match_value);
		foreach ($rows as $i => $row) {
			$cell = isset($row[$match_column_index]) ? trim((string) $row[$match_column_index]) : '';
			if ($cell === $match_value) {
				$update_range = $sheet_name . '!A' . ($i + 1);
				$body = new \Google_Service_Sheets_ValueRange(['values' => [$row_data]]);
				self::get_service()->spreadsheets_values->update($spreadsheet_id, $update_range, $body, ['valueInputOption' => $value_input_option]);
				return true;
			}
		}
		return false;
	}

	/**
	 * Update a row where a column value matches, skipping given column indices (e.g. to preserve formulas).
	 *
	 * @param string $match_value Value to find
	 * @param string $sheet_name  Tab name
	 * @param array  $row_data    Full row values
	 * @param int    $match_column_index 0-based column to search (e.g. 11 for waybill_no in L)
	 * @param int[]  $skip_column_indices 0-based column indices to skip (e.g. [5] for city_id)
	 * @param string $spreadsheet_id Optional
	 * @return bool True if updated
	 */
	public static function update_row_by_value_skip_columns($match_value, $sheet_name, array $row_data, $match_column_index, array $skip_column_indices, $spreadsheet_id = '') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$range = $sheet_name . '!A:ZZ';
		$response = self::get_service()->spreadsheets_values->get($spreadsheet_id, $range);
		$rows = $response->getValues();
		$match_value = trim((string) $match_value);
		$to_col = function ($idx_0based) {
			$n = $idx_0based + 1;
			$s = '';
			while ($n > 0) {
				$n--;
				$s = chr(65 + ($n % 26)) . $s;
				$n = (int) floor($n / 26);
			}
			return $s;
		};
		foreach ($rows as $i => $row) {
			$cell = isset($row[$match_column_index]) ? trim((string) $row[$match_column_index]) : '';
			if ($cell === $match_value) {
				$row_num = $i + 1;
				$service = self::get_service();
				$len = count($row_data);
				$seg_start = 0;
				for ($col = 0; $col <= $len; $col++) {
					if ($col === $len || in_array($col, $skip_column_indices, true)) {
						if ($seg_start < $col) {
							$slice = array_slice($row_data, $seg_start, $col - $seg_start);
							$col_start = $to_col($seg_start);
							$col_end = $to_col($col - 1);
							$update_range = $sheet_name . '!' . $col_start . $row_num . ':' . $col_end . $row_num;
							$body = new \Google_Service_Sheets_ValueRange(['values' => [$slice]]);
							$service->spreadsheets_values->update($spreadsheet_id, $update_range, $body, ['valueInputOption' => 'USER_ENTERED']);
						}
						$seg_start = $col + 1;
					}
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Update a range of cells in a sheet.
	 *
	 * @param string      $sheet_name     Tab name
	 * @param string      $range          A1 notation, e.g. "A491:O491"
	 * @param array       $values         Two-dimensional array of values (rows)
	 * @param string      $spreadsheet_id Optional
	 * @return bool True on success
	 */
	public static function update_range($sheet_name, $range, array $values, $spreadsheet_id = '') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$full_range = ($sheet_name !== '' && $sheet_name !== null) ? $sheet_name . '!' . $range : $range;
		$body = new \Google_Service_Sheets_ValueRange(['values' => $values]);
		self::get_service()->spreadsheets_values->update(
			$spreadsheet_id,
			$full_range,
			$body,
			['valueInputOption' => 'USER_ENTERED']
		);
		return true;
	}

	/**
	 * Clear a range of cells in a sheet.
	 *
	 * @param string|null $sheet_name   Tab name. If null, range must include sheet (e.g. "Sheet1!A2:Z10000").
	 * @param string      $range        A1 notation, e.g. "A2:Z10000". If $sheet_name is set, this is the range within the sheet.
	 * @param string      $spreadsheet_id Optional.
	 * @return bool
	 */
	public static function clear_range($sheet_name, $range = 'A2:Z', $spreadsheet_id = '') {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		if ($sheet_name !== null && $sheet_name !== '') {
			$range = $sheet_name . '!' . $range;
		}
		$service = self::get_service();
		$service->spreadsheets_values->clear($spreadsheet_id, $range, new \Google_Service_Sheets_ClearValuesRequest());
		return true;
	}

	/**
	 * Check if the plugin is configured for Google Sheets (credentials file exists).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$path = self::get_credentials_path();
		return $path !== '' && is_readable($path);
	}

	/**
	 * Convert 0-based column index to A1 column letters (0 → A, 25 → Z, 26 → AA).
	 */
	public static function column_letter(int $index_0based): string {
		$n = $index_0based + 1;
		$s = '';
		while ($n > 0) {
			$n--;
			$s = chr(65 + ($n % 26)) . $s;
			$n = intdiv($n, 26);
		}
		return $s;
	}

	/**
	 * Run a Sheets API call with retries on HTTP 429 rate limits.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	protected static function with_sheets_retry(callable $fn, int $max_attempts = 8) {
		$attempt = 0;
		$delay = 15;
		while (true) {
			$attempt++;
			try {
				return $fn();
			} catch (\Throwable $e) {
				$msg = $e->getMessage();
				$is_429 = (strpos($msg, '429') !== false)
					|| (stripos($msg, 'RATE_LIMIT') !== false)
					|| (stripos($msg, 'Quota exceeded') !== false);
				if (!$is_429 || $attempt >= $max_attempts) {
					throw $e;
				}
				sleep($delay);
				$delay = min(90, (int) ceil($delay * 1.5));
			}
		}
	}

	/**
	 * Ensure a tab named $title exists; return its sheetId.
	 *
	 * @return int sheetId
	 */
	/**
	 * Ensure a tab named $title exists; return [sheetId, rowCount, columnCount].
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	public static function ensure_sheet(string $title, string $spreadsheet_id = '', int $min_rows = 1000, int $min_cols = 26): array {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$service = self::get_service();
		$ss = self::with_sheets_retry(static function () use ($service, $spreadsheet_id) {
			return $service->spreadsheets->get($spreadsheet_id, [
				'fields' => 'sheets(properties(sheetId,title,gridProperties(rowCount,columnCount)),tables)',
			]);
		});
		$title_lc = strtolower(trim($title));
		foreach ($ss->getSheets() as $sheet) {
			$props = $sheet->getProperties();
			if (strtolower(trim((string) $props->getTitle())) === $title_lc) {
				$gp = $props->getGridProperties();
				return [
					(int) $props->getSheetId(),
					$gp ? (int) $gp->getRowCount() : 0,
					$gp ? (int) $gp->getColumnCount() : 0,
				];
			}
		}

		$min_rows = max(2, $min_rows);
		$min_cols = max(1, $min_cols);
		$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
			'requests' => [[
				'addSheet' => [
					'properties' => [
						'title' => $title,
						'gridProperties' => [
							'rowCount' => $min_rows,
							'columnCount' => $min_cols,
						],
					],
				],
			]],
		]);
		$resp = self::with_sheets_retry(static function () use ($service, $spreadsheet_id, $body) {
			return $service->spreadsheets->batchUpdate($spreadsheet_id, $body);
		});
		$replies = $resp->getReplies();
		$added = $replies[0]->getAddSheet() ?? null;
		if ($added && $added->getProperties()) {
			return [(int) $added->getProperties()->getSheetId(), $min_rows, $min_cols];
		}
		throw new \Exception('Failed to create sheet tab: ' . $title);
	}

	/**
	 * Resize a sheet grid so it can hold the given rows/columns (no-op if already large enough).
	 */
	public static function resize_sheet_grid(int $sheet_id, int $row_count, int $column_count, string $spreadsheet_id = '', int $current_rows = 0, int $current_cols = 0): void {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$row_count = max(2, $row_count);
		$column_count = max(1, $column_count);
		if ($current_rows >= $row_count && $current_cols >= $column_count) {
			return;
		}
		$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
			'requests' => [[
				'updateSheetProperties' => [
					'properties' => [
						'sheetId' => $sheet_id,
						'gridProperties' => [
							'rowCount' => max($current_rows, $row_count),
							'columnCount' => max($current_cols, $column_count),
						],
					],
					'fields' => 'gridProperties.rowCount,gridProperties.columnCount',
				],
			]],
		]);
		self::with_sheets_retry(static function () use ($spreadsheet_id, $body) {
			self::get_service()->spreadsheets->batchUpdate($spreadsheet_id, $body);
		});
	}

	/**
	 * Collect deleteTable requests for tables on $sheet_id or named $table_name.
	 *
	 * @param \Google_Service_Sheets_Spreadsheet $ss
	 * @return array<int, array>
	 */
	protected static function collect_delete_table_requests($ss, int $sheet_id, string $table_name = ''): array {
		$requests = [];
		$name_lc = strtolower(trim($table_name));
		foreach ($ss->getSheets() as $sheet) {
			$tables = method_exists($sheet, 'getTables') ? ($sheet->getTables() ?: []) : [];
			foreach ($tables as $table) {
				$tid = (string) $table->getTableId();
				if ($tid === '') {
					continue;
				}
				$range = $table->getRange();
				$on_sheet = $range && (int) $range->getSheetId() === $sheet_id;
				$name_match = $name_lc !== '' && strtolower(trim((string) $table->getName())) === $name_lc;
				if ($on_sheet || $name_match) {
					$requests[] = ['deleteTable' => ['tableId' => $tid]];
				}
			}
		}
		return $requests;
	}

	/**
	 * Delete every Google Sheets Table whose range sits on $sheet_id, or whose name matches $table_name.
	 */
	public static function delete_tables_for_sheet(int $sheet_id, string $table_name = '', string $spreadsheet_id = ''): void {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$service = self::get_service();
		try {
			$ss = self::with_sheets_retry(static function () use ($service, $spreadsheet_id) {
				return $service->spreadsheets->get($spreadsheet_id, ['fields' => 'sheets(tables)']);
			});
		} catch (\Throwable $e) {
			return;
		}
		$requests = self::collect_delete_table_requests($ss, $sheet_id, $table_name);
		if ($requests) {
			$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
			self::with_sheets_retry(static function () use ($service, $spreadsheet_id, $body) {
				$service->spreadsheets->batchUpdate($spreadsheet_id, $body);
			});
		}
	}

	/**
	 * Create a Google Sheets Table covering header + data, named like the kit_* DB table.
	 */
	public static function add_kit_table(int $sheet_id, string $table_name, int $num_rows_including_header, int $num_cols, string $spreadsheet_id = ''): void {
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}
		$num_rows_including_header = max(1, $num_rows_including_header);
		$num_cols = max(1, $num_cols);
		$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
			'requests' => [[
				'addTable' => [
					'table' => [
						'name' => $table_name,
						'range' => [
							'sheetId' => $sheet_id,
							'startRowIndex' => 0,
							'endRowIndex' => $num_rows_including_header,
							'startColumnIndex' => 0,
							'endColumnIndex' => $num_cols,
						],
					],
				],
			]],
		]);
		self::with_sheets_retry(static function () use ($spreadsheet_id, $body) {
			self::get_service()->spreadsheets->batchUpdate($spreadsheet_id, $body);
		});
	}

	/**
	 * Create/refresh a kit_* sheet from a MySQL table: headers = column names, rows = data,
	 * then wrap the range in a Google Sheets Table named after the kit_* table.
	 *
	 * @param string $kit_table e.g. kit_customers (without wp_ prefix)
	 * @return array{success:bool,message:string,sheet:string,rows:int,cols:int}
	 */
	public static function export_kit_db_table(string $kit_table, string $spreadsheet_id = ''): array {
		global $wpdb;

		$kit_table = preg_replace('/[^a-z0-9_]/', '', strtolower($kit_table));
		if ($kit_table === '' || strpos($kit_table, 'kit_') !== 0) {
			return ['success' => false, 'message' => 'Invalid kit_* table name.', 'sheet' => '', 'rows' => 0, 'cols' => 0];
		}
		if ($spreadsheet_id === '') {
			$spreadsheet_id = self::get_default_spreadsheet_id();
		}

		$db_table = $wpdb->prefix . $kit_table;
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $db_table));
		if ($exists !== $db_table) {
			return ['success' => false, 'message' => "DB table missing: {$db_table}", 'sheet' => $kit_table, 'rows' => 0, 'cols' => 0];
		}

		$columns = $wpdb->get_col("SHOW COLUMNS FROM `{$db_table}`");
		if (!$columns) {
			return ['success' => false, 'message' => "No columns for {$db_table}", 'sheet' => $kit_table, 'rows' => 0, 'cols' => 0];
		}
		$col_count = count($columns);
		$end_col = self::column_letter($col_count - 1);

		$db_rows = $wpdb->get_results("SELECT * FROM `{$db_table}`", ARRAY_A) ?: [];
		$values = [$columns];
		foreach ($db_rows as $row) {
			$line = [];
			foreach ($columns as $col) {
				$v = $row[$col] ?? '';
				if ($v === null) {
					$v = '';
				} elseif (is_bool($v)) {
					$v = $v ? '1' : '0';
				} else {
					$v = (string) $v;
				}
				$line[] = $v;
			}
			$values[] = $line;
		}

		$total_rows = count($values); // includes header
		$need_rows = max($total_rows + 50, 100);
		$need_cols = max($col_count + 2, 10);

		$service = self::get_service();
		$ss = self::with_sheets_retry(static function () use ($service, $spreadsheet_id) {
			return $service->spreadsheets->get($spreadsheet_id, [
				'fields' => 'sheets(properties(sheetId,title,gridProperties(rowCount,columnCount)),tables)',
			]);
		});

		$sheet_id = null;
		$cur_rows = 0;
		$cur_cols = 0;
		$title_lc = strtolower(trim($kit_table));
		foreach ($ss->getSheets() as $sheet) {
			$props = $sheet->getProperties();
			if (strtolower(trim((string) $props->getTitle())) === $title_lc) {
				$sheet_id = (int) $props->getSheetId();
				$gp = $props->getGridProperties();
				$cur_rows = $gp ? (int) $gp->getRowCount() : 0;
				$cur_cols = $gp ? (int) $gp->getColumnCount() : 0;
				break;
			}
		}

		$batch_requests = [];
		if ($sheet_id === null) {
			$batch_requests[] = [
				'addSheet' => [
					'properties' => [
						'title' => $kit_table,
						'gridProperties' => [
							'rowCount' => $need_rows,
							'columnCount' => $need_cols,
						],
					],
				],
			];
		} else {
			$batch_requests = array_merge(
				$batch_requests,
				self::collect_delete_table_requests($ss, $sheet_id, $kit_table)
			);
			if ($cur_rows < $need_rows || $cur_cols < $need_cols) {
				$batch_requests[] = [
					'updateSheetProperties' => [
						'properties' => [
							'sheetId' => $sheet_id,
							'gridProperties' => [
								'rowCount' => max($cur_rows, $need_rows),
								'columnCount' => max($cur_cols, $need_cols),
							],
						],
						'fields' => 'gridProperties.rowCount,gridProperties.columnCount',
					],
				];
			}
		}

		if ($batch_requests) {
			$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $batch_requests]);
			$resp = self::with_sheets_retry(static function () use ($service, $spreadsheet_id, $body) {
				return $service->spreadsheets->batchUpdate($spreadsheet_id, $body);
			});
			if ($sheet_id === null) {
				$replies = $resp->getReplies();
				foreach ($replies as $reply) {
					$added = $reply->getAddSheet();
					if ($added && $added->getProperties()) {
						$sheet_id = (int) $added->getProperties()->getSheetId();
						break;
					}
				}
			}
		}
		if ($sheet_id === null) {
			return ['success' => false, 'message' => 'Could not resolve sheetId for ' . $kit_table, 'sheet' => $kit_table, 'rows' => 0, 'cols' => 0];
		}

		// Write data (overwrite). Chunk only when payload is large.
		$chunk = 800;
		for ($i = 0; $i < $total_rows; $i += $chunk) {
			$slice = array_slice($values, $i, $chunk);
			$start = $i + 1;
			$end = $i + count($slice);
			$range = $kit_table . '!A' . $start . ':' . $end_col . $end;
			$vr = new \Google_Service_Sheets_ValueRange(['values' => $slice]);
			self::with_sheets_retry(static function () use ($service, $spreadsheet_id, $range, $vr) {
				$service->spreadsheets_values->update(
					$spreadsheet_id,
					$range,
					$vr,
					['valueInputOption' => 'RAW']
				);
			});
		}

		$table_ok = true;
		$table_err = '';
		try {
			// Re-delete by name in case of race, then add Table in one batch.
			$ss2 = self::with_sheets_retry(static function () use ($service, $spreadsheet_id) {
				return $service->spreadsheets->get($spreadsheet_id, ['fields' => 'sheets(tables)']);
			});
			$reqs = self::collect_delete_table_requests($ss2, $sheet_id, $kit_table);
			$reqs[] = [
				'addTable' => [
					'table' => [
						'name' => $kit_table,
						'range' => [
							'sheetId' => $sheet_id,
							'startRowIndex' => 0,
							'endRowIndex' => $total_rows,
							'startColumnIndex' => 0,
							'endColumnIndex' => $col_count,
						],
					],
				],
			];
			$body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $reqs]);
			self::with_sheets_retry(static function () use ($service, $spreadsheet_id, $body) {
				$service->spreadsheets->batchUpdate($spreadsheet_id, $body);
			});
		} catch (\Throwable $e) {
			$table_ok = false;
			$table_err = $e->getMessage();
		}

		return [
			'success' => true,
			'message' => $table_ok
				? sprintf('Exported %d data rows to %s with Table "%s".', count($db_rows), $kit_table, $kit_table)
				: sprintf('Exported %d data rows to %s; Table create failed: %s', count($db_rows), $kit_table, $table_err),
			'sheet' => $kit_table,
			'rows' => count($db_rows),
			'cols' => $col_count,
		];
	}

	/**
	 * Export every install kit_* table (and sync audit tables) to matching sheet tabs.
	 *
	 * @param string   $spreadsheet_id
	 * @param string[] $only optional subset of kit_* names to export
	 * @return array{success:bool,message:string,results:array<string,array>}
	 */
	public static function export_all_kit_tables(string $spreadsheet_id = '', array $only = []): array {
		$tables = [
			'kit_customers',
			'kit_company_customers',
			'kit_services',
			'kit_operating_countries',
			'kit_operating_cities',
			'kit_shipping_directions',
			'kit_shipping_rate_types',
			'kit_shipping_rates_mass',
			'kit_shipping_rates_volume',
			'kit_shipping_dedicated_truck_rates',
			'kit_drivers',
			'kit_deliveries',
			'kit_company_details',
			'kit_waybills',
			'kit_waybill_items',
			'kit_quotations',
			'kit_invoices',
			'kit_discounts',
			'kit_sync_runs',
			'kit_sync_run_rows',
		];
		if ($only) {
			$only_map = array_fill_keys(array_map('strtolower', $only), true);
			$tables = array_values(array_filter($tables, static function ($t) use ($only_map) {
				return isset($only_map[$t]);
			}));
		}

		$results = [];
		$errors = [];
		foreach ($tables as $i => $table) {
			try {
				$r = self::export_kit_db_table($table, $spreadsheet_id);
			} catch (\Throwable $e) {
				$r = [
					'success' => false,
					'message' => $e->getMessage(),
					'sheet' => $table,
					'rows' => 0,
					'cols' => 0,
				];
			}
			$results[$table] = $r;
			if (empty($r['success'])) {
				$errors[] = $table . ': ' . ($r['message'] ?? 'failed');
			}
			// Stay under WriteRequestsPerMinutePerUser (60/min).
			if ($i < count($tables) - 1) {
				sleep(3);
			}
		}

		if ($errors) {
			return [
				'success' => false,
				'message' => 'Some exports failed — ' . implode('; ', $errors),
				'results' => $results,
			];
		}
		$total = 0;
		foreach ($results as $r) {
			$total += (int) ($r['rows'] ?? 0);
		}
		return [
			'success' => true,
			'message' => sprintf('Exported %d tables (%d data rows) to kit_* sheets with Tables.', count($tables), $total),
			'results' => $results,
		];
	}
}
