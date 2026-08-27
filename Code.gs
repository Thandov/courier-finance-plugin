/**
 * Waybill Projection — single-file Apps Script.
 * Waybills → kit_drivers, kit_deliveries, kit_customers, kit_company_customers, kit_waybills.
 * Col I (Company) is the party type:
 *   "Private" / blank / N/A → individual → kit_customers (from col F).
 *   any other value → company → kit_company_customers; if col F has a person
 *   name, also create kit_customers and set company_id.
 * Waybills may carry both customer_id and company_id. Deploy: paste entire file into Apps Script.
 * WordPress KIT_Seed_Pipeline reads kit_* → MySQL.
 * NEVER write the Waybills tab (IMPORTRANGE). Reads only.
 * NEVER write trip_membership (IMPORTRANGE of the confirmation Deliveries tab).
 * Waybills col B = sequential trip #. Confirmation col I (Trip) is kit delivery_id.
 */


// =============================================================================
// 00_Config.gs
// =============================================================================

/**
 * Projection config — Waybills → kit_* sheets.
 */
var CFG = {
  sourceSheetName: 'Waybills',
  waybillsSheetName: 'kit_waybills',
  customersSheetName: 'kit_customers',
  companyCustomersSheetName: 'kit_company_customers',
  deliveriesSheetName: 'kit_deliveries',
  driversSheetName: 'kit_drivers',
  citiesSheetName: 'kit_operating_cities',
  errorSheetName: 'sync_errors',
  validationSheetName: 'sync_validation',
  dataStartRow: 2,
  waybillsMaxCols: 60,
  kitWaybillsMaxCols: 50,
  /** Hard cap — Tables / sparse formatting can inflate getLastRow() into timeouts. */
  maxSheetRows: 12000,
  /** Rows per setValues / getValues chunk. */
  writeChunkRows: 400,
  lockTimeoutMs: 30000,
  sheetsRetryAttempts: 5,
  countryIdDefault: 2,
  defaultDirectionId: 2,
  customerSeedMinId: 8600,
  companySeedMinId: 8600,
  /** When false, ignore Waybills CL INV for product_invoice_number. */
  productInvoiceUseWaybillsSheet: false,
  /** preserve | sheet_or_allocate | always_allocate */
  productInvoiceNumberMode: 'preserve',
  waybillSyncUserId: '',
  /** Display-name → WP user id when Waybills Created ID is blank/FALSE. Keys are lowercased names. */
  waybillCreatorNameToId: {
    'mel welmans': 1594,
    'mel': 1594,
    'sinazo ntsomi': 1596,
    'sinazo': 1596
  },
  /**
   * Waybills col B (0-based): sequential trip # on the source tab.
   * Remapped to kit delivery_id via tripMembershipSpreadsheetId col I (Trip).
   */
  waybillsSourceTripCol: 1,
  /**
   * Local tab on THIS spreadsheet. IMPORTRANGE only — never clear or setValues.
   * Col I = real kit delivery_id. CSV waybill lists bind membership.
   */
  tripMembershipSheetName: 'trip_membership',
  /** Confirmation workbook (08600AfricaWaybills) — read-only source. */
  tripMembershipSpreadsheetId: '1yRoRdtOlDrCexnvnx0E2QBfe3xZq6NBpwZbCAQj6dak',
  /** gid of the two-row Trip / Waybills confirmation tab. */
  tripMembershipSheetGid: 536070626,
  /** IMPORTRANGE range on that workbook (tab name Deliveries). */
  tripMembershipImportRange: 'Deliveries!A:I',
  /** Lazily resolved via scriptTimeZone_(). */
  timeZone: '',
  /** After wipe, keep header + this many blank data rows. Projection inserts more as needed. */
  wipeKeepEmptyRows: 5,
  /**
   * Tabs wiped by wipeKitProjectionSheets() — headers kept, grid shrunk to
   * header + wipeKeepEmptyRows blank rows. Never includes reference / rate sheets.
   */
  wipeSheetNames: [
    'sync_errors',
    'sync_validation',
    'kit_waybills',
    'kit_customers',
    'kit_company_customers',
    'kit_drivers',
    'kit_deliveries',
    'kit_waybill_items',
    'kit_quotations',
    'kit_invoices',
    'kit_sync_runs',
    'kit_sync_run_rows'
  ],
  /** Always left alone — source + reference / rate kit_* sheets. */
  wipeProtectedSheetNames: [
    'Waybills',
    'kit_company_details',
    'kit_shipping_directions',
    'kit_operating_countries',
    'kit_operating_cities',
    'kit_shipping_rates_volume',
    'kit_shipping_rates_mass',
    'kit_shipping_rate_types',
    'kit_shipping_dedicated_truck_rates',
    'settings',
    'trip_membership'
  ]
};

function scriptTimeZone_() {
  if (!CFG.timeZone) {
    CFG.timeZone = Session.getScriptTimeZone() || 'Africa/Johannesburg';
  }
  return CFG.timeZone;
}


// =============================================================================
// 10_Headers.gs
// =============================================================================

/**
 * Header alias maps and index resolution.
 * Fail loud when required columns are missing.
 */

var SRC_ALIASES = {
  waybillNo: { required: true, aliases: [
    'waybill #', 'waybill#', 'waybill no', 'waybill no.', 'waybill number',
    'waybillnodetails', 'waybill no details', 'waybill no/details', 'waybill_no_details',
    'waybill details', 'wb details', 'waybill number details',
    'wb #', 'wb no', 'wb_no', 'parcel_id', 'parcel id', 'parcel no', 'parcel_no',
    'newwb', 'new wb', 'no', 'no.', 'number'
  ] },
  itemDesc: { required: false, aliases: ['item description', 'item desc', 'items description'] },
  waybillDesc: { required: false, aliases: ['waybill description', 'wb description'] },
  directionId: { required: false, aliases: ['direction_id', 'direction id', 'direction'] },
  cityId: { required: false, aliases: ['city_id', 'city id', 'destination_city_id'] },
  cityName: { required: false, aliases: ['city', 'city_name', 'destination_city', 'destination city'] },
  deliveryId: { required: false, aliases: [
    'delivery_id', 'delivery id', 'del_id', 'delivery',
    'trip #', 'trip#', 'trip no', 'trip no.', 'trip number'
  ] },
  driver: { required: false, aliases: ['driver', 'driver_name', 'driver name', 'truck_driver', 'truck driver'] },
  customerId: { required: false, aliases: ['customer_id', 'customer id', 'cust_id'] },
  approval: { required: false, aliases: ['approval'] },
  approvalUserId: { required: false, aliases: ['approval_userid', 'approval_user_id', 'approval user id'] },
  customer: { required: false, aliases: ['customer', 'cust name', 'client', 'customer name'] },
  companyLabel: { required: false, aliases: ['company', 'company name', 'company_name', 'business', 'business name', 'business_name'] },
  clInv: { required: false, aliases: ['cl inv #', 'cl inv', 'client invoice', 'inv #', 'inv no', 'invoice #'] },
  custInvR: { required: false, aliases: ['customer inv( r)', 'customer inv', 'customer inv r', 'customer inv (r)', 'cust inv'] },
  sad500: { required: false, aliases: ['sad500', 'sad 500'] },
  sadc: { required: false, aliases: ['sadc'] },
  length: { required: false, aliases: ['length', 'item length', 'len'] },
  width: { required: false, aliases: ['width', 'item width'] },
  height: { required: false, aliases: ['height', 'item height'] },
  tMass: { required: false, aliases: ['t mass', 't-mass', 'total mass', 'total_mass_kg', 'mass kg'] },
  tVol: { required: false, aliases: ['t volume', 't-volume', 'total volume', 'total_volume', 'volume'] },
  massCost: { required: false, aliases: ['mass cost', 'mass_charge', 'mass charge'] },
  volCost: { required: false, aliases: ['vol cost', 'volume cost', 'volume_charge', 'vol charge'] },
  basis: { required: false, aliases: ['basis', 'charge basis', 'charge_basis'] },
  vat: { required: false, aliases: ['vat', 'vat include', 'vat_include', 'vat included'] },
  tracking: { required: false, aliases: ['tracking number', 'tracking_number', 'tracking', 'track no'] },
  status: { required: false, aliases: ['status', 'state'] },
  parcelText: { required: false, aliases: ['parcel', 'parcel #', 'parcel number', 'waybill label'] },
  dateReceived: { required: false, aliases: ['date received', 'date recieved', 'date_received', 'received date'] },
  dispatchDate: { required: false, aliases: ['dispatch date', 'dispatch_date', 'truck dispatch date', 'truck_dispatch_date', 'trip date', 'trip_date'] },
  cell: { required: false, aliases: ['cell', 'cellphone', 'mobile', 'mobile number', 'mobile_number'] },
  telephone: { required: false, aliases: ['telephone', 'tel', 'phone', 'phone number', 'phone_number'] },
  email: { required: false, aliases: ['email', 'email address', 'email_address', 'e-mail'] },
  address: { required: false, aliases: ['address', 'physical address', 'postal address'] },
  createdBy: { required: false, aliases: ['created by', 'created_by', 'creator', 'entered by'] },
  createdById: { required: false, aliases: ['created id', 'created_id', 'creator id', 'created userid', 'created user id'] }
};

var TGT_WAYBILL_ALIASES = {
  id: { required: true, aliases: ['id'] },
  parcel_id: { required: false, aliases: ['parcel_id', 'parcel id', 'parcel_no', 'parcel no'] },
  waybill_no: { required: true, aliases: ['waybill_no', 'waybill no', 'waybill number'] },
  description: { required: true, aliases: ['description'] },
  direction_id: { required: false, aliases: ['direction_id', 'direction id'] },
  city_id: { required: false, aliases: ['city_id', 'city id'] },
  delivery_id: { required: false, aliases: ['delivery_id', 'delivery id'] },
  cust_name_ignore: { required: false, aliases: ['cust_name_ignore', 'cust name', 'customer name ignore'] },
  customer_id: { required: false, aliases: ['customer_id', 'customer id', 'cust_id'] },
  company_id: { required: false, aliases: ['company_id', 'company id'] },
  approval: { required: false, aliases: ['approval'] },
  approval_userid: { required: false, aliases: ['approval_userid', 'approval_user_id'] },
  product_invoice_number: { required: false, aliases: ['product_invoice_number', 'product invoice number'] },
  product_invoice_amount: { required: false, aliases: ['product_invoice_amount', 'product invoice amount'] },
  waybill_items_total: { required: false, aliases: ['waybill_items_total', 'waybill items total'] },
  sad500_amount: { required: false, aliases: ['sad500_amount', 'sad500 amount'] },
  sadc_amount: { required: false, aliases: ['sadc_amount', 'sadc amount'] },
  item_length: { required: false, aliases: ['item_length', 'item length', 'length'] },
  item_width: { required: false, aliases: ['item_width', 'item width', 'width'] },
  item_height: { required: false, aliases: ['item_height', 'item height', 'height'] },
  total_mass_kg: { required: false, aliases: ['total_mass_kg', 'total mass kg', 't mass'] },
  total_volume: { required: false, aliases: ['total_volume', 'total volume', 't volume'] },
  mass_charge: { required: false, aliases: ['mass_charge', 'mass charge'] },
  volume_charge: { required: false, aliases: ['volume_charge', 'volume charge'] },
  charge_basis: { required: false, aliases: ['charge_basis', 'charge basis', 'basis'] },
  vat_include: { required: false, aliases: ['vat_include', 'vat include'] },
  include_sad500: { required: false, aliases: ['include_sad500', 'include sad500'] },
  include_sadc: { required: false, aliases: ['include_sadc', 'include sadc'] },
  warehouse: { required: false, aliases: ['warehouse'] },
  tracking_number: { required: false, aliases: ['tracking_number', 'tracking number'] },
  status: { required: false, aliases: ['status'] },
  created_by: { required: false, aliases: ['created_by', 'created by'] },
  created_at: { required: false, aliases: ['created_at', 'created at'] },
  last_updated_by: { required: false, aliases: ['last_updated_by', 'last updated by'] },
  last_updated_at: { required: false, aliases: ['last_updated_at', 'last updated at'] },
  miscellaneous: { required: false, aliases: ['miscellaneous', 'misc', 'misc_serialized'] },
  misc_total: { required: false, aliases: ['misc_total', 'misc total'] }
};

function normHeader_(h) {
  return String(h == null ? '' : h)
    .toLowerCase()
    .replace(/^#+\s*/, '')
    .replace(/[\s_]+/g, ' ')
    .trim();
}

function colIndex_(headers, aliases) {
  var normalized = {};
  for (var i = 0; i < headers.length; i++) {
    var n = normHeader_(headers[i]);
    if (n && normalized[n] === undefined) {
      normalized[n] = i;
    }
  }
  for (var a = 0; a < aliases.length; a++) {
    var key = normHeader_(aliases[a]);
    if (normalized[key] !== undefined) {
      return normalized[key];
    }
  }
  return -1;
}

function buildResolvedMap_(headers, schema) {
  var map = {};
  var missing = [];
  var keys = Object.keys(schema);
  for (var i = 0; i < keys.length; i++) {
    var field = keys[i];
    var def = schema[field];
    var idx = colIndex_(headers, def.aliases);
    map[field] = idx;
    if (def.required && idx < 0) {
      missing.push(field);
    }
  }
  map._missing = missing;
  return map;
}

function assertRequiredHeaders_(indexMap, sheetLabel) {
  var missing = indexMap._missing || [];
  if (missing.length) {
    throw new Error(sheetLabel + ' missing required column(s): ' + missing.join(', '));
  }
}

function headersHaveRef_(headers) {
  if (!headers) return false;
  for (var i = 0; i < headers.length; i++) {
    if (isSheetErrorValue_(headers[i])) return true;
  }
  return false;
}

function waybillsHeadersAreBroken_(headers) {
  if (!headers || !headers.length) return true;
  var usable = 0;
  var errors = 0;
  for (var i = 0; i < headers.length; i++) {
    var h = cleanText_(headers[i]);
    if (!h) continue;
    if (isSheetErrorValue_(headers[i])) errors++;
    else usable++;
  }
  return usable === 0 && errors > 0;
}

function salvageWaybillsSourceIndexes_(headers, sourceIdx) {
  if (sourceIdx.waybillNo < 0) {
    var extra = colIndex_(headers, [
      'waybillnodetails', 'waybill no details', 'waybill no/details',
      'waybill_no_details', 'waybill details', 'wb details',
      'waybill number details'
    ]);
    if (extra >= 0) sourceIdx.waybillNo = extra;
  }
  if (sourceIdx.waybillNo < 0 && sourceIdx.parcelText >= 0) {
    sourceIdx.waybillNo = sourceIdx.parcelText;
  }
  if (sourceIdx.waybillNo >= 0 && sourceIdx._missing && sourceIdx._missing.length) {
    sourceIdx._missing = sourceIdx._missing.filter(function (f) {
      return f !== 'waybillNo' && f !== 'waybillNoDetails';
    });
  }
  // Waybills col B is the trip # even when the header still says Dispatch Date
  // (column insert without renaming). Confirmation sheet col I is the real id.
  if (sourceIdx.deliveryId < 0) {
    sourceIdx.deliveryId = CFG.waybillsSourceTripCol;
  }
}

function buildDriverTargetIndexes_(headers) {
  return {
    id: colIndex_(headers, ['id', 'driver_id']),
    name: colIndex_(headers, ['name', 'driver', 'driver_name']),
    phone: colIndex_(headers, ['phone', 'telephone', 'cell']),
    email: colIndex_(headers, ['email', 'email_address']),
    license: colIndex_(headers, ['license_number', 'licence_number', 'license']),
    isActive: colIndex_(headers, ['is_active', 'active', 'status']),
    createdAt: colIndex_(headers, ['created_at', 'created at']),
    updatedAt: colIndex_(headers, ['updated_at', 'updated at'])
  };
}

function buildDeliveryTargetIndexes_(headers) {
  return {
    id: colIndex_(headers, ['id']),
    delId: colIndex_(headers, ['del_id', 'delivery_id']),
    ref: colIndex_(headers, ['delivery_reference', 'delivery_ref']),
    direction: colIndex_(headers, ['direction_id', 'direction id']),
    city: colIndex_(headers, ['destination_city_id', 'city_id', 'destination city id']),
    dispatch: colIndex_(headers, ['dispatch_date', 'dispatch date']),
    truck: colIndex_(headers, ['truck_number', 'truck number', 'truck']),
    driver: colIndex_(headers, ['driver_id', 'driver id']),
    status: colIndex_(headers, ['status']),
    createdBy: colIndex_(headers, ['created_by', 'created by']),
    createdAt: colIndex_(headers, ['created_at', 'created at'])
  };
}

function buildCustomerTargetIndexes_(headers) {
  var idx = {
    id: colIndex_(headers, ['id', '#', 'row_id']),
    custId: colIndex_(headers, [
      'cust_id', 'customer_id', 'cust id', 'customer id', 'cust#', 'cust #',
      'customer #', 'customer no', 'customer number', 'cust no', 'custid'
    ]),
    name: colIndex_(headers, ['name', 'customer_name', 'first name', 'firstname', 'first_name']),
    surname: colIndex_(headers, ['surname', 'last_name', 'last name', 'lastname']),
    combined: colIndex_(headers, [
      'combined_name_ignore', 'combined name', 'combined', 'display_name',
      'display name', 'full name', 'full_name'
    ]),
    company: colIndex_(headers, ['company_name', 'company', 'company name', 'business', 'business_name']),
    companyId: colIndex_(headers, ['company_id', 'company id', 'linked_company_id']),
    cell: colIndex_(headers, ['cell', 'cellphone', 'mobile', 'mobile_number', 'mobile number']),
    telephone: colIndex_(headers, ['telephone', 'tel', 'phone', 'phone_number', 'phone number']),
    email: colIndex_(headers, ['email_address', 'email', 'e-mail', 'email address']),
    country: colIndex_(headers, ['country_id', 'country', 'country id']),
    city: colIndex_(headers, ['city_id', 'city', 'city id']),
    vat: colIndex_(headers, ['vat_number', 'vat', 'vat number']),
    address: colIndex_(headers, ['address', 'physical address']),
    created: colIndex_(headers, ['created_at', 'created at'])
  };

  // Canonical WP / push_all layout (A–M) when headers are missing or mangled:
  // A=id B=cust_id C=name D=surname E=cell F=telephone G=email H=country_id
  // I=city_id J=vat_number K=address L=company_id M=created_at
  if (headers.length >= 4 && (idx.custId < 0 || (idx.name < 0 && idx.company < 0 && idx.combined < 0))) {
    if (idx.id < 0) idx.id = 0;
    if (idx.custId < 0) idx.custId = 1;
    if (idx.name < 0) idx.name = 2;
    if (idx.surname < 0) idx.surname = 3;
    if (headers.length >= 5 && idx.cell < 0) idx.cell = 4;
    if (headers.length >= 6 && idx.telephone < 0) idx.telephone = 5;
    if (headers.length >= 7 && idx.email < 0) idx.email = 6;
    if (headers.length >= 8 && idx.country < 0) idx.country = 7;
    if (headers.length >= 9 && idx.city < 0) idx.city = 8;
    if (headers.length >= 10 && idx.vat < 0) idx.vat = 9;
    if (headers.length >= 11 && idx.address < 0) idx.address = 10;
    if (headers.length >= 12 && idx.companyId < 0) idx.companyId = 11;
    if (headers.length >= 13 && idx.created < 0) idx.created = 12;
    // Optional combined column often sits after M
    if (idx.combined < 0 && headers.length >= 14) {
      var maybeCombined = colIndex_(headers, ['combined_name_ignore', 'combined name', 'combined']);
      if (maybeCombined >= 0) idx.combined = maybeCombined;
    }
  }

  return idx;
}


// =============================================================================
// 11_Parse.gs
// =============================================================================

/**
 * Parsing helpers: numbers, dates (ZA DD/MM + ISO), booleans, waybill ids.
 */

function cleanText_(value) {
  if (value == null) return '';
  if (Object.prototype.toString.call(value) === '[object Date]' && !isNaN(value.getTime())) {
    return Utilities.formatDate(value, scriptTimeZone_(), 'yyyy-MM-dd HH:mm:ss');
  }
  return String(value).replace(/\u00a0/g, ' ').trim();
}

/** True only for formula failure tokens, not for blank cells. */
function isFormulaErrorText_(value) {
  var s = cleanText_(value);
  if (!s) return false;
  if (/^#(ERROR!|N\/A|NA|REF!|VALUE!|DIV\/0!|NAME\?|NULL!|NUM!)$/i.test(s)) return true;
  return /^#[A-Z]/i.test(s) && s.length < 16;
}

/** Blank out #REF! / #N/A so they never land in kit_* contact columns. */
function usableSheetText_(value) {
  if (isFormulaErrorText_(value)) return '';
  return cleanText_(value);
}
function isSheetErrorValue_(value) {
  var s = cleanText_(value);
  if (!s) return true;
  return /^#(ERROR!|N\/A|NA|REF!|VALUE!|DIV\/0!|NAME\?|NULL!|NUM!)$/i.test(s);
}

/**
 * Trailing / formula-only rows inflate getLastRow() (Cell often shows #ERROR!).
 * Those are not waybills — skip them instead of "Missing parseable waybill number".
 */
function isEmptyWaybillSourceRow_(raw) {
  if (!raw || !raw.length) return true;
  for (var i = 0; i < raw.length; i++) {
    if (!isSheetErrorValue_(raw[i])) return false;
  }
  return true;
}

function extractWaybillNo_(raw) {
  var s = cleanText_(raw);
  if (!s) return '';
  var m = s.match(/(\d{3,})/);
  return m ? m[1] : '';
}

function toPositiveId_(value) {
  if (value === '' || value == null) return '';
  if (value === true || value === false) return '';
  var raw = String(value).trim();
  if (!raw) return '';
  var low = raw.toLowerCase();
  if (low === 'true' || low === 'false') return '';
  var n = parseInt(raw.replace(/[^\d-]/g, ''), 10);
  return n > 0 ? n : '';
}

function parseSourceDeliveryNo_(value) {
  var n = toPositiveId_(value);
  return n === '' ? 0 : n;
}

function parseNumber_(value) {
  if (value === '' || value == null) return '';
  if (typeof value === 'number' && !isNaN(value)) return value;
  var s = String(value).replace(/R\s*/gi, '').replace(/\s/g, '').replace(/,/g, '');
  if (s === '' || s === '-') return '';
  var n = parseFloat(s);
  return isNaN(n) ? '' : n;
}

function parseBooleanFlexible_(value) {
  if (value === true || value === 1) return true;
  if (value === false || value === 0 || value === '') return false;
  if (typeof value === 'number') return value !== 0;
  var s = String(value).trim().toUpperCase();
  if (['1', 'TRUE', 'YES', 'Y', 'ON', 'X', '✓', '✔'].indexOf(s) >= 0) return true;
  if (['0', 'FALSE', 'NO', 'N', 'OFF', '-', 'N/A', 'NA', 'NULL'].indexOf(s) >= 0) return false;
  var num = parseFloat(s.replace(/[^\d.-]/g, ''));
  if (!isNaN(num) && num > 0) return true;
  return false;
}

/**
 * Normalize to yyyy-MM-dd. Prefers Date objects; string fallback is DD/MM (ZA).
 */
function normalizeDispatchDateOnly_(value) {
  if (value == null || value === '') return '';
  if (Object.prototype.toString.call(value) === '[object Date]' && !isNaN(value.getTime())) {
    return Utilities.formatDate(value, scriptTimeZone_(), 'yyyy-MM-dd');
  }
  var s = String(value).trim();
  if (!s) return '';

  var iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (iso) return iso[1] + '-' + iso[2] + '-' + iso[3];

  var dmy = s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/);
  if (dmy) {
    var d = parseInt(dmy[1], 10);
    var m = parseInt(dmy[2], 10);
    var y = parseInt(dmy[3], 10);
    if (y < 100) y += 2000;
    if (m >= 1 && m <= 12 && d >= 1 && d <= 31) {
      return y + '-' + pad2_(m) + '-' + pad2_(d);
    }
  }

  var parsed = new Date(s);
  if (!isNaN(parsed.getTime())) {
    return Utilities.formatDate(parsed, scriptTimeZone_(), 'yyyy-MM-dd');
  }
  return '';
}

function normalizeSheetDateTime_(value) {
  if (value == null || value === '') return '';
  if (Object.prototype.toString.call(value) === '[object Date]' && !isNaN(value.getTime())) {
    return Utilities.formatDate(value, scriptTimeZone_(), 'yyyy-MM-dd HH:mm:ss');
  }
  var dateOnly = normalizeDispatchDateOnly_(value);
  if (dateOnly) return dateOnly + ' 00:00:00';
  var s = cleanText_(value);
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(s)) return s;
  return '';
}

function currentMysqlTimestamp_() {
  return Utilities.formatDate(new Date(), scriptTimeZone_(), 'yyyy-MM-dd HH:mm:ss');
}

function pad2_(n) {
  return n < 10 ? '0' + n : String(n);
}

function isValidDeliveryReference_(ref) {
  var s = cleanText_(ref);
  if (!s) return false;
  if (s.toLowerCase() === 'pending') return true;
  return /^DEL-\d{8}-\d{3}$/i.test(s);
}

function allocateDeliveryReference_(dispatchDate, usedRefs) {
  var ymd = String(dispatchDate || '').replace(/-/g, '');
  if (ymd.length !== 8) {
    ymd = Utilities.formatDate(new Date(), scriptTimeZone_(), 'yyyyMMdd');
  }
  var seq = 1;
  while (seq <= 999) {
    var candidate = 'DEL-' + ymd + '-' + pad3_(seq);
    var key = candidate.toUpperCase();
    if (!usedRefs[key]) {
      usedRefs[key] = true;
      return candidate;
    }
    seq++;
  }
  throw new Error('Exhausted DEL sequence for date ' + ymd);
}

function pad3_(n) {
  if (n < 10) return '00' + n;
  if (n < 100) return '0' + n;
  return String(n);
}

function generateTrackingNumber_() {
  var ts = Utilities.formatDate(new Date(), scriptTimeZone_(), 'yyyyMMddHHmmss');
  var r = Math.floor(Math.random() * 900) + 100;
  return 'TRK' + ts + r;
}

function allocateProductInvoiceNumber_(waybillNo) {
  return 'INV13' + String(waybillNo);
}

function valueByField_(row, indexMap, field) {
  var idx = indexMap[field];
  if (idx === undefined || idx < 0) return '';
  return row[idx];
}

function setByField_(record, indexMap, field, value) {
  var idx = indexMap[field];
  if (idx === undefined || idx < 0) return;
  record[idx] = value;
}


// =============================================================================
// 12_Names.gs
// =============================================================================

/**
 * Name normalization aligned with KIT_Customers PHP helpers.
 * Particles stripped so "van den Berg" ≈ "Van Der Berg".
 */

var PERSON_NAME_PARTICLES_ = {
  van: 1, den: 1, der: 1, de: 1, du: 1, la: 1, le: 1, ter: 1, ten: 1,
  von: 1, of: 1, the: 1, da: 1, dos: 1, das: 1, del: 1, di: 1
};

var COMPANY_SUFFIXES_ = [
  'proprietary limited', 'pty limited', 'pty ltd', 'pvt ltd', 'private limited',
  'limited', 'ltd', 'inc', 'incorporated', 'llc', 'plc', 'cc', 'co', 'company',
  'corp', 'corporation'
];

/** Aligned with PHP kit_seed_customer_looks_like_business() / kit-business-name.php */
var BUSINESS_KEYWORDS_ = {
  ltd: 1, limited: 1, pty: 1, cc: 1, inc: 1, llc: 1, plc: 1, company: 1,
  co: 1, corp: 1, corporation: 1, trading: 1, enterprises: 1, enterprise: 1,
  logistics: 1, transport: 1, services: 1, group: 1, holdings: 1, investments: 1,
  traders: 1, camp: 1, camps: 1, safari: 1, safaris: 1, lodge: 1, lodges: 1,
  hotel: 1, hotels: 1, tours: 1, tour: 1, technologies: 1, industries: 1,
  works: 1, expeditions: 1, foods: 1, laundry: 1, creations: 1,
  adventure: 1, adventures: 1, emporium: 1, emporiums: 1, destinations: 1,
  destination: 1, wilderness: 1, tanzania: 1, kenya: 1, zambia: 1, africa: 1,
  suppliers: 1, supplier: 1, imports: 1, import: 1, exports: 1, export: 1,
  motors: 1, motor: 1, furniture: 1, hardware: 1, wholesale: 1, retail: 1,
  construction: 1, engineering: 1, solutions: 1, properties: 1, property: 1,
  estate: 1, estates: 1, brewing: 1, brewery: 1, breweries: 1, gmbh: 1, sa: 1
};

var BUSINESS_WORD_RE_ = /\b(ltd|limited|pty|inc|corp|llc|plc|group|traders|logistics|camp|camps|safari|safaris|lodge|lodges|hotel|hotels|tours|tour|investments|technologies|industries|works|expeditions|foods|laundry|creations|holdings|adventure|adventures|emporium|emporiums|destinations|destination|enterprise|enterprises|wilderness|tanzania|kenya|zambia|africa|suppliers|supplier|imports|import|exports|export|motors|motor|furniture|hardware|wholesale|retail|construction|engineering|solutions|services|properties|property|estate|estates|trading|company|brewing|brewery|breweries)\b/i;

var NON_DRIVER_TOKENS_ = {
  warehouse: 1, cancel: 1, cancelled: 1, canceled: 1, duplicate: 1, dup: 1,
  void: 1, skip: 1, 'n/a': 1, na: 1, none: 1, pending: 1
};

function stripAccents_(value) {
  var s = String(value || '');
  try {
    return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  } catch (e) {
    return s;
  }
}

function normalizeCompareString_(value) {
  return stripAccents_(String(value || '').toLowerCase()).trim();
}

function normalizePersonNameKey_(name, surname) {
  var full = normalizeCompareString_(String(name || '') + ' ' + String(surname || ''));
  // Underscore-joined surnames (Van_Zyl) compare as the same person as spaced form.
  full = full.replace(/_/g, ' ').replace(/[^a-z0-9\s]+/g, ' ').replace(/\s+/g, ' ').trim();
  if (!full) return '';
  var tokens = full.split(' ');
  var kept = [];
  for (var i = 0; i < tokens.length; i++) {
    var t = tokens[i];
    if (!t || PERSON_NAME_PARTICLES_[t]) continue;
    kept.push(t);
  }
  return kept.join(' ');
}

function normalizeCompanyCompareKey_(companyName) {
  var s = normalizeCompareString_(companyName);
  s = s.replace(/[^a-z0-9\s]+/g, ' ').replace(/\s+/g, ' ').trim();
  if (!s) return '';
  for (var i = 0; i < COMPANY_SUFFIXES_.length; i++) {
    var suffix = COMPANY_SUFFIXES_[i];
    var needle = ' ' + suffix;
    if (s === suffix) {
      s = '';
      break;
    }
    if (s.length >= needle.length && s.slice(-needle.length) === needle) {
      s = s.slice(0, -needle.length).trim();
    }
  }
  return s.replace(/\s+/g, ' ').trim();
}

function normalizeName_(value) {
  return normalizeCompareString_(value).replace(/\s+/g, ' ');
}

function customerIdentityKey_(label) {
  var raw = cleanText_(label);
  if (!raw) return '';
  if (isLikelyBusinessName_(raw)) {
    var ck = normalizeCompanyCompareKey_(raw);
    return ck ? 'c:' + ck : '';
  }
  var pk = normalizePersonNameKey_(raw, '');
  return pk ? 'p:' + pk : '';
}

function isLikelyBusinessName_(text) {
  var raw = cleanText_(text);
  if (!raw) return false;
  // Formula / sheet join residue is never a person.
  if (raw.indexOf('=') >= 0) return true;
  if (BUSINESS_WORD_RE_.test(raw)) return true;
  var s = normalizeCompareString_(raw);
  var tokens = s.replace(/[^a-z0-9\s]+/g, ' ').replace(/\s+/g, ' ').trim().split(' ');
  if (tokens.length === 1 && BUSINESS_KEYWORDS_[tokens[0]]) return true;
  for (var i = 0; i < tokens.length; i++) {
    if (BUSINESS_KEYWORDS_[tokens[i]]) return true;
  }
  return /[&]/.test(raw);
}

/** Only these starters become one token (Van_Zyl, Engle_Brecht, Dos_Ramos, Dr_Sabine). */
var COMPOUND_SURNAME_PREFIXES_ = ['van', 'engle', 'dos', 'dr'];

function surnameStartsWithCompoundPrefix_(surname) {
  var first = cleanText_(surname).split(/[\s_]+/)[0] || '';
  return COMPOUND_SURNAME_PREFIXES_.indexOf(first.toLowerCase()) >= 0;
}

/**
 * Join Van / Engle / Dos surnames with _. Leave every other multi-word
 * surname spaced. Undo a prior underscore if the prefix is not in the list.
 */
function compactSurname_(surname) {
  var raw = cleanText_(surname);
  if (!raw) return '';
  var parts = raw.split(/\s+/);
  if (parts.length === 1) {
    if (raw.indexOf('_') >= 0 && !surnameStartsWithCompoundPrefix_(raw)) {
      return raw.replace(/_/g, ' ');
    }
    return raw;
  }
  if (!surnameStartsWithCompoundPrefix_(parts[0])) {
    return parts.join(' ');
  }
  return parts.join('_');
}

/** "Mariaan Van Zyl" → "Mariaan Van_Zyl". Business labels unchanged. */
function compactPersonLabel_(label) {
  var raw = cleanText_(label);
  if (!raw || isSheetErrorValue_(raw) || isLikelyBusinessName_(raw)) return raw;
  var split = splitPersonName_(raw);
  if (!split.surname) return split.name || raw;
  return (split.name + ' ' + split.surname).trim();
}

/**
 * Compact person labels in memory only. Never write the Waybills source
 * sheet — it is IMPORTRANGE-owned and setValues on col F blocks the import.
 */
function rewriteWaybillCompoundSurnames_(ss, dtoRows) {
  if (dtoRows) {
    for (var i = 0; i < dtoRows.length; i++) {
      var d = dtoRows[i];
      if (!d.customerName || !d.isPersonCustomer || isLikelyBusinessName_(d.customerName)) continue;
      d.customerName = compactPersonLabel_(d.customerName);
    }
  }
  return { rewritten: 0 };
}

function splitPersonName_(combined) {
  var raw = cleanText_(combined);
  if (!raw) return { name: '', surname: '' };
  var parts = raw.split(/\s+/);
  if (parts.length === 1) {
    return { name: parts[0], surname: '' };
  }
  // "Dr Sabine Marten" → name Dr_Sabine, surname Marten
  if (surnameStartsWithCompoundPrefix_(parts[0])) {
    return {
      name: parts[0] + '_' + parts[1],
      surname: compactSurname_(parts.slice(2).join(' '))
    };
  }
  return {
    name: parts[0],
    surname: compactSurname_(parts.slice(1).join(' '))
  };
}

/** @deprecated use splitPersonName_ + isLikelyBusinessName_ */
function splitCustomerName_(combined) {
  var raw = cleanText_(combined);
  if (!raw) return { name: '', surname: '', company_name: '' };
  if (isLikelyBusinessName_(raw)) {
    return { name: '', surname: '', company_name: raw };
  }
  var p = splitPersonName_(raw);
  return { name: p.name, surname: p.surname, company_name: '' };
}

function isNonDriverName_(candidate) {
  var s = normalizeName_(candidate);
  if (!s) return true;
  if (/^\d+$/.test(s)) return true;
  var tokens = s.split(' ');
  for (var i = 0; i < tokens.length; i++) {
    if (NON_DRIVER_TOKENS_[tokens[i]]) return true;
  }
  return false;
}

function isWarehouseLabel_(text) {
  return /\bwarehouse\b/i.test(cleanText_(text));
}

function shouldSkipWaybillByColA_(colAText) {
  var s = normalizeName_(colAText);
  if (!s) return false;
  return /\b(duplicate|dup|cancel|cancelled|canceled|void)\b/.test(s);
}

function isPlaceholderName_(nameNorm) {
  return !nameNorm || ['n/a', 'na', 'none', '-', '--', 'null', 'private', 'individual', '0', 'customer', 'client'].indexOf(nameNorm) >= 0;
}

function isSuspiciousCustomerName_(name) {
  var s = cleanText_(name);
  if (!s || s.length < 2) return true;
  if (/^\d+$/.test(s)) return true;
  if (isPlaceholderName_(normalizeName_(s))) return true;
  return false;
}

/** True for blank / Individual / Private company labels (Waybills col I). */
function isPlaceholderCompanyLabel_(name) {
  var s = cleanText_(name);
  if (!s) return true;
  return isPlaceholderName_(normalizeName_(s));
}

/**
 * Col F has a person we should write to kit_customers (name, and surname when present).
 * Business-looking labels are not persons — unless col I is Private (see applyWaybillPartyFlags_).
 */
function hasPersonCustomerDetails_(label) {
  var raw = cleanText_(label);
  if (!raw || isSheetErrorValue_(raw) || isSuspiciousCustomerName_(raw)) return false;
  if (isLikelyBusinessName_(raw)) return false;
  return true;
}

/** Identity key for kit_customers rows — always person (p:), even for Private + business-looking F. */
function personIdentityKey_(label) {
  var pk = normalizePersonNameKey_(label, '');
  return pk ? 'p:' + pk : '';
}

/**
 * Col I decides party type. Never promote col F to a company when I is Private.
 *   Private / blank → individual (col F → kit_customers).
 *   Real company    → kit_company_customers; person in F is linked via company_id.
 */
function applyWaybillPartyFlags_(dto) {
  if (isPlaceholderCompanyLabel_(dto.companyName)) {
    dto.companyName = '';
  }
  dto.hasLinkedCompany = !!dto.companyName;
  dto.isPersonCustomer = dto.hasLinkedCompany
    ? hasPersonCustomerDetails_(dto.customerName)
    : !!(cleanText_(dto.customerName) && !isSuspiciousCustomerName_(dto.customerName));
  dto.isCompanyCustomer = dto.hasLinkedCompany && !dto.isPersonCustomer;
  return dto;
}

/** Company label from Waybills col I only (Private / blank already cleared). */
function resolveCompanyLabelFromDto_(d) {
  return (d.companyName && !isPlaceholderCompanyLabel_(d.companyName))
    ? d.companyName
    : '';
}

/**
 * Near-duplicate check (approx PHP customer_labels_are_similar).
 */
function labelsAreSimilar_(a, b) {
  a = String(a || '').trim();
  b = String(b || '').trim();
  if (!a || !b) return false;
  if (a === b) return true;
  var lenA = a.length;
  var lenB = b.length;
  var max = Math.max(lenA, lenB);
  var min = Math.min(lenA, lenB);
  if (min < 4 || max <= 0) return false;
  if (min / max < 0.55) return false;
  var pct = similarityPct_(a, b);
  if (pct >= 88) return true;
  var dist = levenshtein_(a, b);
  if (dist <= 2 && max >= 8) return true;
  if (dist / max <= 0.15) return true;
  return false;
}

function similarityPct_(a, b) {
  if (a === b) return 100;
  var longer = a.length > b.length ? a : b;
  var shorter = a.length > b.length ? b : a;
  if (!longer.length) return 100;
  var matches = 0;
  var window = shorter.length;
  for (var i = 0; i <= longer.length - window && window > 0; i++) {
    var slice = longer.substr(i, window);
    var common = 0;
    for (var j = 0; j < window; j++) {
      if (slice.charAt(j) === shorter.charAt(j)) common++;
    }
    if (common > matches) matches = common;
  }
  // Dice-ish fallback using bigrams
  var pairs1 = bigrams_(a);
  var pairs2 = bigrams_(b);
  var intersect = 0;
  var keys = Object.keys(pairs1);
  for (var k = 0; k < keys.length; k++) {
    var key = keys[k];
    intersect += Math.min(pairs1[key], pairs2[key] || 0);
  }
  var total = 0;
  keys = Object.keys(pairs1);
  for (k = 0; k < keys.length; k++) total += pairs1[keys[k]];
  keys = Object.keys(pairs2);
  for (k = 0; k < keys.length; k++) total += pairs2[keys[k]];
  if (total === 0) return 0;
  return (2 * intersect * 100) / total;
}

function bigrams_(s) {
  var out = {};
  var str = String(s);
  for (var i = 0; i < str.length - 1; i++) {
    var bg = str.substr(i, 2);
    out[bg] = (out[bg] || 0) + 1;
  }
  return out;
}

function levenshtein_(a, b) {
  a = String(a);
  b = String(b);
  var m = a.length;
  var n = b.length;
  if (m === 0) return n;
  if (n === 0) return m;
  var prev = [];
  var cur = [];
  for (var j = 0; j <= n; j++) prev[j] = j;
  for (var i = 1; i <= m; i++) {
    cur[0] = i;
    for (j = 1; j <= n; j++) {
      var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
      cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
    }
    var tmp = prev;
    prev = cur;
    cur = tmp;
  }
  return prev[n];
}


// =============================================================================
// 13_SheetIO.gs
// =============================================================================

/**
 * Sheet I/O: bounded reads, error/validation writers, lock helper.
 */

function withLock_(fn) {
  var lock = LockService.getScriptLock();
  var got = lock.tryLock(CFG.lockTimeoutMs);
  if (!got) {
    throw new Error('Projection lock timeout after ' + CFG.lockTimeoutMs + 'ms — another run is in progress.');
  }
  try {
    return fn();
  } finally {
    lock.releaseLock();
  }
}

/** Retry Spreadsheet reads/writes on service timeouts. */
function sheetsCall_(fn, label) {
  var attempts = CFG.sheetsRetryAttempts || 5;
  var delay = 2000;
  var lastErr = null;
  for (var a = 1; a <= attempts; a++) {
    try {
      return fn();
    } catch (e) {
      lastErr = e;
      var msg = String((e && e.message) || e);
      var retryable = /timed out|timeout|service spreadsheets|internal error|rate limit|503|500/i.test(msg);
      if (!retryable || a === attempts) {
        throw new Error((label ? label + ': ' : '') + msg);
      }
      Utilities.sleep(delay);
      delay = Math.min(20000, Math.floor(delay * 1.75));
    }
  }
  throw lastErr;
}

function readSheetValuesSmart_(sheet, maxColsCap) {
  var maxRows = CFG.maxSheetRows || 12000;
  var lastRow = Math.min(sheet.getLastRow(), maxRows);
  if (lastRow < 1) return [[]];
  var sheetLastCol = sheet.getLastColumn();
  if (sheetLastCol < 1) return [[]];
  var headerScanCols = (maxColsCap && maxColsCap > 0)
    ? Math.min(sheetLastCol, maxColsCap)
    : Math.min(sheetLastCol, 60);
  var headerRow = sheetsCall_(function () {
    return sheet.getRange(1, 1, 1, headerScanCols).getValues()[0];
  }, sheet.getName() + ' header');
  var lastCol = 1;
  for (var c = headerRow.length - 1; c >= 0; c--) {
    if (cleanText_(headerRow[c]) !== '') {
      lastCol = c + 1;
      break;
    }
  }

  // Chunked read avoids one giant getValues timing out on large Waybills/kit tabs.
  var chunk = CFG.writeChunkRows || 400;
  var out = [headerRow.slice(0, lastCol)];
  if (lastRow < 2) return out;
  for (var start = 2; start <= lastRow; start += chunk) {
    var end = Math.min(lastRow, start + chunk - 1);
    var block = sheetsCall_(function () {
      return sheet.getRange(start, 1, end - start + 1, lastCol).getValues();
    }, sheet.getName() + ' rows ' + start + '-' + end);
    for (var r = 0; r < block.length; r++) {
      out.push(block[r]);
    }
  }
  return out;
}

function requireSheet_(ss, name) {
  var sheet = ss.getSheetByName(name);
  if (!sheet) {
    throw new Error('Missing required sheet: ' + name);
  }
  return sheet;
}

/** Waybills is IMPORTRANGE-owned. Any write (including col F) breaks the import. */
function isSourceWaybillsSheet_(sheet) {
  if (!sheet) return false;
  return String(sheet.getName()).toLowerCase() === String(CFG.sourceSheetName).toLowerCase();
}

function assertNotSourceWaybillsWrite_(sheet, action) {
  if (isSourceWaybillsSheet_(sheet)) {
    throw new Error('Refusing to ' + (action || 'write') + ' the Waybills source sheet (IMPORTRANGE).');
  }
  assertNotTripMembershipWrite_(sheet, action);
}

function isTripMembershipSheet_(sheet) {
  if (!sheet) return false;
  var want = String(CFG.tripMembershipSheetName || 'trip_membership').toLowerCase();
  return String(sheet.getName()).toLowerCase() === want;
}

function assertNotTripMembershipWrite_(sheet, action) {
  if (isTripMembershipSheet_(sheet)) {
    throw new Error(
      'Refusing to ' + (action || 'write') +
        ' trip_membership (IMPORTRANGE). That tab is read-only.'
    );
  }
}

function findSheetByName_(ss, name) {
  var want = String(name || '').toLowerCase();
  if (!want) return null;
  var sheets = ss.getSheets();
  for (var i = 0; i < sheets.length; i++) {
    if (String(sheets[i].getName()).toLowerCase() === want) return sheets[i];
  }
  return null;
}

function findSheetByGid_(ss, gid) {
  gid = Number(gid);
  if (!ss || !gid) return null;
  var sheets = ss.getSheets();
  for (var i = 0; i < sheets.length; i++) {
    if (sheets[i].getSheetId() === gid) return sheets[i];
  }
  return null;
}

function writeRowErrors_(ss, errors) {
  var sheet = findSheetByName_(ss, CFG.errorSheetName);
  if (!sheet) {
    sheet = ss.insertSheet(CFG.errorSheetName);
  }
  unlockSheetGrid_(sheet);
  sheetsCall_(function () { sheet.clearContents(); }, 'clear sync_errors');
  var rows = [['source_row', 'message', 'logged_at']];
  var now = currentMysqlTimestamp_();
  errors = errors || [];
  for (var i = 0; i < errors.length; i++) {
    rows.push([errors[i].row || '', errors[i].message || '', now]);
  }
  ensureSheetRows_(sheet, rows.length);
  writeRowsChunked_(sheet, 1, rows);
  trimTrailingEmptyRows_(sheet, 0);
}

function ensureSheetRows_(sheet, lastNeededRow) {
  lastNeededRow = Number(lastNeededRow);
  if (!(lastNeededRow >= 1)) return;
  var maxRows = sheet.getMaxRows();
  if (maxRows >= lastNeededRow) return;
  var add = lastNeededRow - maxRows;
  sheetsCall_(function () {
    sheet.insertRowsAfter(maxRows, add);
  }, sheet.getName() + ' insert ' + add + ' rows');
}

function writeRowsChunked_(sheet, startRow, rows) {
  assertNotSourceWaybillsWrite_(sheet, 'writeRowsChunked');
  if (!rows || !rows.length) return;
  startRow = Number(startRow);
  if (!(startRow >= 1)) {
    throw new Error(sheet.getName() + ' write skipped: invalid start row ' + startRow);
  }
  var numCols = rows[0].length;
  var chunk = CFG.writeChunkRows || 400;
  for (var i = 0; i < rows.length; i += chunk) {
    var slice = rows.slice(i, i + chunk);
    var row = startRow + i;
    ensureSheetRows_(sheet, row + slice.length - 1);
    sheetsCall_(function () {
      sheet.getRange(row, 1, slice.length, numCols).setValues(slice);
    }, sheet.getName() + ' write @' + row);
  }
}

/** First row after header that can take new data (reuses blank rows left by a wipe). */
function nextAppendRow_(sheet) {
  var headerRow = CFG.dataStartRow || 2;
  var last = sheet.getLastRow();
  if (last < headerRow) return headerRow;
  var lastCol = Math.max(sheet.getLastColumn(), 1);
  var height = last - headerRow + 1;
  var values = sheetsCall_(function () {
    return sheet.getRange(headerRow, 1, height, lastCol).getValues();
  }, sheet.getName() + ' scan append start');
  var lastFilled = -1;
  for (var i = 0; i < values.length; i++) {
    for (var c = 0; c < values[i].length; c++) {
      if (cleanText_(values[i][c]) !== '') {
        lastFilled = i;
        break;
      }
    }
  }
  return lastFilled < 0 ? headerRow : headerRow + lastFilled + 1;
}

function logSummary_(summary) {
  Logger.log(JSON.stringify(summary));
}

function pushRowError_(bucket, sourceRowNum, message) {
  bucket.push({ row: sourceRowNum, message: message });
}

/**
 * Upsert helper: append new rows; patch existing sheet rows by absolute row number.
 * Contiguous patches are merged into one setValues (avoids N timed-out API calls).
 * patches = [{ sheetRow: number, values: array }]
 * appends = [array, ...]
 */
function applyUpserts_(sheet, patches, appends, numCols) {
  assertNotSourceWaybillsWrite_(sheet, 'applyUpserts');
  patches = patches || [];
  appends = appends || [];

  if (patches.length) {
    patches = patches.filter(function (p) {
      return p && Number(p.sheetRow) >= 1;
    }).sort(function (a, b) {
      return a.sheetRow - b.sheetRow;
    });
    var i = 0;
    while (i < patches.length) {
      var block = [patches[i].values];
      var startRow = patches[i].sheetRow;
      while (
        i + 1 < patches.length &&
        patches[i + 1].sheetRow === patches[i].sheetRow + 1
      ) {
        i++;
        block.push(patches[i].values);
      }
      writeRowsChunked_(sheet, startRow, block);
      i++;
    }
  }

  if (appends.length > 0) {
    var start = nextAppendRow_(sheet);
    // Pad each append to numCols.
    var padded = [];
    for (var a = 0; a < appends.length; a++) {
      var row = appends[a].slice();
      while (row.length < numCols) row.push('');
      if (row.length > numCols) row = row.slice(0, numCols);
      padded.push(row);
    }
    writeRowsChunked_(sheet, start, padded);
  }

  if (patches.length || appends.length) {
    trimTrailingEmptyRows_(sheet, 0);
  }
}

function emptyRow_(width) {
  var row = [];
  for (var i = 0; i < width; i++) row.push('');
  return row;
}


// =============================================================================
// 20_WaybillsSource.gs
// =============================================================================

/**
 * Read Waybills once into normalized row DTOs.
 * Column A = driver / status; B = sequential trip # (not always kit delivery_id);
 * C = Dispatch Date. Confirmation sheet Trip (col I) remaps B → delivery_id.
 */

var WAYBILLS_COL_A_ = 0;
var WAYBILLS_COL_TRIP_ = 1; // B
var WAYBILLS_COL_DISPATCH_FALLBACK_ = 2; // C
var WAYBILLS_COL_CUSTOMER_F_ = 5; // F — person or lone business
var WAYBILLS_COL_COMPANY_I_ = 8; // I — Company (link for person customers)
var WAYBILLS_COL_AA_ = 26; // AA (0-based)
var WAYBILLS_COL_CREATED_BY_ = 32; // AG — display name
var WAYBILLS_COL_CREATED_ID_ = 35; // AJ — WP user id when present

/**
 * @returns {{rows: Array, sourceIdx: Object, headers: Array, errors: Array}}
 */
function readWaybillsSource_(ss) {
  var sheet = requireSheet_(ss, CFG.sourceSheetName);
  var data = readSheetValuesSmart_(sheet, CFG.waybillsMaxCols);
  var errors = [];
  if (!data || data.length < 1) {
    throw new Error('Waybills sheet has no header row.');
  }
  var headers = data[0] || [];
  var nonEmpty = [];
  for (var hi = 0; hi < headers.length; hi++) {
    var hv = cleanText_(headers[hi]);
    if (hv) nonEmpty.push({ col: hi, value: hv });
  }
  if (waybillsHeadersAreBroken_(headers)) {
    throw new Error(
      'Waybills header row is #REF! (broken IMPORTRANGE). ' +
      'Re-authorize or fix the import so Waybill # / waybillNoDetails is visible.'
    );
  }
  var sourceIdx = buildResolvedMap_(headers, SRC_ALIASES);
  salvageWaybillsSourceIndexes_(headers, sourceIdx);
  if (sourceIdx.waybillNo < 0) {
    throw new Error(
      'Waybills missing required column(s): waybillNoDetails. ' +
      'Need a header named Waybill #, waybillNoDetails, or Parcel.' +
      (headersHaveRef_(headers) ? ' Row 1 also has #REF! — re-authorize IMPORTRANGE.' : '')
    );
  }
  assertRequiredHeaders_(sourceIdx, CFG.sourceSheetName);

  var rows = [];
  for (var r = 1; r < data.length; r++) {
    var raw = data[r];
    var sourceRowNum = r + 1;
    var colA = cleanText_(raw[WAYBILLS_COL_A_]);
    if (shouldSkipWaybillByColA_(colA)) {
      continue;
    }
    if (isEmptyWaybillSourceRow_(raw)) {
      continue;
    }

    var waybillNo = extractWaybillNo_(valueByField_(raw, sourceIdx, 'waybillNo'))
      || extractWaybillNo_(valueByField_(raw, sourceIdx, 'parcelText'));
    if (!waybillNo) {
      pushRowError_(errors, sourceRowNum, 'Missing parseable waybill number.');
      continue;
    }

    var driverRaw = cleanText_(valueByField_(raw, sourceIdx, 'driver'));
    var dispatch = normalizeDispatchDateOnly_(valueByField_(raw, sourceIdx, 'dispatchDate'));
    if (!dispatch) {
      dispatch = normalizeDispatchDateOnly_(raw[WAYBILLS_COL_DISPATCH_FALLBACK_]);
    }
    var tripCol = CFG.waybillsSourceTripCol;
    var deliveryTripId = parseSourceDeliveryNo_(raw[tripCol]);
    if (!(deliveryTripId > 0)) {
      deliveryTripId = parseSourceDeliveryNo_(valueByField_(raw, sourceIdx, 'deliveryId'));
    }

    var dto = {
      sourceRowNum: sourceRowNum,
      waybillNo: waybillNo,
      colA: colA,
      isWarehouse: isWarehouseLabel_(colA) || isWarehouseLabel_(driverRaw),
      driverName: driverRaw,
      deliveryTripId: deliveryTripId,
      sourceTripColB: deliveryTripId,
      dispatchDate: dispatch,
      dateReceived: normalizeSheetDateTime_(valueByField_(raw, sourceIdx, 'dateReceived')),
      customerName: cleanText_(valueByField_(raw, sourceIdx, 'customer'))
        || cleanText_(raw[WAYBILLS_COL_CUSTOMER_F_]),
      companyName: cleanText_(valueByField_(raw, sourceIdx, 'companyLabel'))
        || cleanText_(raw[WAYBILLS_COL_COMPANY_I_]),
      customerId: toPositiveId_(valueByField_(raw, sourceIdx, 'customerId')),
      cityName: usableSheetText_(valueByField_(raw, sourceIdx, 'cityName')),
      cityId: toPositiveId_(valueByField_(raw, sourceIdx, 'cityId')),
      directionId: toPositiveId_(valueByField_(raw, sourceIdx, 'directionId')),
      waybillDesc: cleanText_(valueByField_(raw, sourceIdx, 'waybillDesc')),
      itemDesc: cleanText_(valueByField_(raw, sourceIdx, 'itemDesc')),
      clInv: cleanText_(valueByField_(raw, sourceIdx, 'clInv')),
      clientInvoiceAa: cleanText_(raw[WAYBILLS_COL_AA_] || ''),
      custInvR: parseNumber_(valueByField_(raw, sourceIdx, 'custInvR')),
      sad500: valueByField_(raw, sourceIdx, 'sad500'),
      sadc: valueByField_(raw, sourceIdx, 'sadc'),
      length: parseNumber_(valueByField_(raw, sourceIdx, 'length')),
      width: parseNumber_(valueByField_(raw, sourceIdx, 'width')),
      height: parseNumber_(valueByField_(raw, sourceIdx, 'height')),
      tMass: parseNumber_(valueByField_(raw, sourceIdx, 'tMass')),
      tVol: parseNumber_(valueByField_(raw, sourceIdx, 'tVol')),
      massCost: parseNumber_(valueByField_(raw, sourceIdx, 'massCost')),
      volCost: parseNumber_(valueByField_(raw, sourceIdx, 'volCost')),
      basis: cleanText_(valueByField_(raw, sourceIdx, 'basis')),
      vat: valueByField_(raw, sourceIdx, 'vat'),
      tracking: cleanText_(valueByField_(raw, sourceIdx, 'tracking')),
      status: cleanText_(valueByField_(raw, sourceIdx, 'status')),
      approval: cleanText_(valueByField_(raw, sourceIdx, 'approval')),
      approvalUserId: toPositiveId_(valueByField_(raw, sourceIdx, 'approvalUserId')),
      cell: usableSheetText_(valueByField_(raw, sourceIdx, 'cell')),
      telephone: usableSheetText_(valueByField_(raw, sourceIdx, 'telephone')),
      email: usableSheetText_(valueByField_(raw, sourceIdx, 'email')),
      address: usableSheetText_(valueByField_(raw, sourceIdx, 'address')),
      createdByName: cleanText_(valueByField_(raw, sourceIdx, 'createdBy'))
        || cleanText_(raw[WAYBILLS_COL_CREATED_BY_] || ''),
      createdById: toPositiveId_(valueByField_(raw, sourceIdx, 'createdById'))
        || toPositiveId_(raw[WAYBILLS_COL_CREATED_ID_] || ''),
      raw: raw
    };
    applyWaybillPartyFlags_(dto);
    rows.push(dto);
  }

  var membership = applyTripMembershipToDtos_(rows, errors);
  return {
    rows: rows,
    sourceIdx: sourceIdx,
    headers: headers,
    errors: errors,
    membership: membership
  };
}


// =============================================================================
// 21_TripMembership.gs
// =============================================================================

/**
 * Client confirmation sheet (two-row Trip / Waybill blocks).
 * Col I = real kit delivery_id. CSV list owns membership.
 * Unlisted waybills keep existing kit_waybills.delivery_id (col F).
 */

function applyTripMembershipToDtos_(dtoRows, errors) {
  var summary = {
    trips: 0,
    waybills_listed: 0,
    remapped: 0,
    unlisted: 0,
    duplicates: 0,
    count_mismatches: 0,
    listed_missing: 0
  };
  var membership = readTripMembership_();
  summary.trips = membership.trips.length;
  summary.waybills_listed = membership.listedCount;
  summary.duplicates = membership.duplicates.length;
  if (!membership.trips.length) {
    throw new Error('Trip membership sheet has no Trip / Waybill blocks.');
  }
  for (var d = 0; d < membership.duplicates.length; d++) {
    pushRowError_(
      errors,
      0,
      'Trip membership: waybill ' + membership.duplicates[d].waybillNo +
        ' listed on trips ' + membership.duplicates[d].fromId +
        ' and ' + membership.duplicates[d].toId + ' (using ' + membership.duplicates[d].toId + ').'
    );
  }

  var byWb = membership.waybillToTripId;
  var sourceByWb = {};
  var i;
  for (i = 0; i < dtoRows.length; i++) {
    sourceByWb[dtoRows[i].waybillNo] = dtoRows[i];
  }

  for (i = 0; i < membership.trips.length; i++) {
    var trip = membership.trips[i];
    if (trip.statedCount > 0 && trip.statedCount !== trip.waybills.length) {
      summary.count_mismatches++;
      pushRowError_(
        errors,
        0,
        'Trip membership count: trip ' + trip.tripId +
          ' (' + (trip.tripName || '') + ') states ' + trip.statedCount +
          ' waybills but the list parsed to ' + trip.waybills.length + '.'
      );
    }
    for (var w = 0; w < trip.waybills.length; w++) {
      var listedWb = trip.waybills[w];
      if (!sourceByWb[listedWb]) {
        summary.listed_missing++;
        pushRowError_(
          errors,
          0,
          'Trip membership: waybill ' + listedWb +
            ' is listed on trip ' + trip.tripId + ' but is not on Waybills.'
        );
      }
    }
  }

  for (i = 0; i < dtoRows.length; i++) {
    var dto = dtoRows[i];
    if (dto.isWarehouse) continue;
    var listed = byWb[dto.waybillNo];
    if (listed > 0) {
      if (dto.deliveryTripId !== listed) summary.remapped++;
      dto.deliveryTripId = listed;
      dto.preserveKitDeliveryId = false;
      dto.tripMembershipSource = 'waybill_list';
      continue;
    }
    dto.preserveKitDeliveryId = true;
    dto.deliveryTripId = 0;
    dto.tripMembershipSource = 'keep_existing_f';
    summary.unlisted++;
  }
  summary.membership = membership;
  return summary;
}

function readTripMembership_() {
  var sheet = openTripMembershipSheet_();
  var data = readSheetValuesFixedWidth_(sheet, 12);
  return parseTripMembershipValues_(data);
}

/**
 * Open the local trip_membership tab. Read-only after IMPORTRANGE is in A1.
 * Never copies values from the confirmation workbook (that wipe was wrong).
 */
function openTripMembershipSheet_() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var localName = CFG.tripMembershipSheetName || 'trip_membership';
  var sheet = findSheetByName_(ss, localName);
  if (!sheet) {
    sheet = sheetsCall_(function () {
      return ss.insertSheet(localName);
    }, 'insert ' + localName);
  }
  ensureTripMembershipImportRange_(sheet);
  return sheet;
}

function tripMembershipImportFormula_() {
  var id = CFG.tripMembershipSpreadsheetId;
  var range = CFG.tripMembershipImportRange || 'Deliveries!A:I';
  if (!id) {
    throw new Error('CFG.tripMembershipSpreadsheetId is empty.');
  }
  return '=IMPORTRANGE("' + id + '","' + range + '")';
}

function tripMembershipHasImportFormula_(sheet) {
  if (!sheet) return false;
  var formula = '';
  try {
    formula = String(sheet.getRange(1, 1).getFormula() || '');
  } catch (e) {
    return false;
  }
  return formula.toUpperCase().indexOf('IMPORTRANGE') >= 0;
}

/**
 * Put IMPORTRANGE back in A1 when the broken value-dump wiped it.
 * Does not run on a tab that already has IMPORTRANGE.
 */
function ensureTripMembershipImportRange_(sheet) {
  if (tripMembershipHasImportFormula_(sheet)) return false;
  var formula = tripMembershipImportFormula_();
  sheetsCall_(function () { sheet.clearContents(); }, 'trip_membership restore clear');
  sheetsCall_(function () {
    sheet.getRange(1, 1).setFormula(formula);
  }, 'trip_membership restore IMPORTRANGE');
  SpreadsheetApp.flush();
  return true;
}

/** Menu: restore trip_membership to live IMPORTRANGE (undo the value dump). */
function restoreTripMembershipSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var localName = CFG.tripMembershipSheetName || 'trip_membership';
  var sheet = findSheetByName_(ss, localName);
  var created = false;
  if (!sheet) {
    sheet = sheetsCall_(function () {
      return ss.insertSheet(localName);
    }, 'insert ' + localName);
    created = true;
  }
  var restored = ensureTripMembershipImportRange_(sheet);
  var ui = SpreadsheetApp.getUi();
  var formula = tripMembershipImportFormula_();
  if (!created && !restored) {
    ui.alert(
      'trip_membership already live',
      'A1 already has IMPORTRANGE. Projection will not overwrite this tab.\n\n' + formula,
      ui.ButtonSet.OK
    );
    return { restored: false, formula: formula };
  }
  ui.alert(
    'trip_membership restored',
    'A1 is again:\n' + formula +
      '\n\nClick Allow access if Google asks. This tab is read-only — projection will not clear it.',
    ui.ButtonSet.OK
  );
  return { restored: true, formula: formula };
}

function readSheetValuesFixedWidth_(sheet, numCols) {
  var maxRows = CFG.maxSheetRows || 12000;
  var lastRow = Math.min(sheet.getLastRow(), maxRows);
  if (lastRow < 1) return [[]];
  var lastCol = Math.max(Number(numCols) || 9, sheet.getLastColumn() || 1);
  lastCol = Math.min(lastCol, 16);
  var chunk = CFG.writeChunkRows || 400;
  var out = [];
  for (var start = 1; start <= lastRow; start += chunk) {
    var end = Math.min(lastRow, start + chunk - 1);
    var block = sheetsCall_(function () {
      return sheet.getRange(start, 1, end - start + 1, lastCol).getValues();
    }, sheet.getName() + ' cols1-' + lastCol + ' rows ' + start + '-' + end);
    for (var i = 0; i < block.length; i++) {
      out.push(block[i]);
    }
  }
  return out;
}

function findTripMembershipSheetByShape_(ss) {
  if (!ss) return null;
  var sheets = ss.getSheets();
  for (var i = 0; i < sheets.length; i++) {
    var candidate = sheets[i];
    var name = String(candidate.getName() || '').toLowerCase();
    if (isSourceWaybillsSheet_(candidate)) continue;
    if (name.indexOf('kit_') === 0 || name.indexOf('sync_') === 0) continue;
    var probe;
    try {
      probe = sheetsCall_(function () {
        return candidate.getRange(1, 1, Math.min(40, Math.max(candidate.getLastRow(), 1)), 9).getValues();
      }, candidate.getName() + ' membership probe');
    } catch (e) {
      continue;
    }
    if (tripMembershipValuesLookValid_(probe)) return candidate;
  }
  return null;
}

function tripMembershipValuesLookValid_(data) {
  if (!data || data.length < 2) return false;
  var tripRows = 0;
  for (var r = 0; r < data.length; r++) {
    var label = cleanText_(data[r][1]).toLowerCase();
    if (label !== 'trip') continue;
    var next = r + 1 < data.length ? cleanText_(data[r + 1][1]).toLowerCase() : '';
    if (next === 'waybill' || next === 'waybills') tripRows++;
  }
  return tripRows > 0;
}

function parseTripMembershipValues_(data) {
  var waybillToTripId = {};
  var seqToTripId = {};
  var tripIds = {};
  var trips = [];
  var duplicates = [];
  var listedCount = 0;
  if (!data || !data.length) {
    return {
      waybillToTripId: waybillToTripId,
      seqToTripId: seqToTripId,
      tripIds: tripIds,
      trips: trips,
      duplicates: duplicates,
      listedCount: 0
    };
  }

  for (var r = 0; r < data.length; r++) {
    var row = data[r];
    if (!isTripMembershipHeaderRow_(row)) continue;
    var waybillRow = null;
    if (r + 1 < data.length && isTripMembershipWaybillRow_(data[r + 1])) {
      waybillRow = data[r + 1];
    }
    var parsed = parseTripMembershipBlock_(row, waybillRow);
    if (!(parsed.tripId > 0)) continue;
    trips.push(parsed);
    tripIds[parsed.tripId] = true;
    if (parsed.seq > 0) seqToTripId[parsed.seq] = parsed.tripId;
    for (var w = 0; w < parsed.waybills.length; w++) {
      var wb = parsed.waybills[w];
      listedCount++;
      if (waybillToTripId[wb] && waybillToTripId[wb] !== parsed.tripId) {
        duplicates.push({
          waybillNo: wb,
          fromId: waybillToTripId[wb],
          toId: parsed.tripId
        });
      }
      waybillToTripId[wb] = parsed.tripId;
    }
  }

  return {
    waybillToTripId: waybillToTripId,
    seqToTripId: seqToTripId,
    tripIds: tripIds,
    trips: trips,
    duplicates: duplicates,
    listedCount: listedCount
  };
}

function isTripMembershipHeaderRow_(row) {
  return cleanText_(row && row[1]).toLowerCase() === 'trip';
}

function isTripMembershipWaybillRow_(row) {
  var label = cleanText_(row && row[1]).toLowerCase();
  return label === 'waybill' || label === 'waybills';
}

function parseTripMembershipBlock_(headerRow, waybillRow) {
  var seq = toPositiveId_(headerRow[2]);
  var driver = cleanText_(headerRow[3]);
  if (/^driver$/i.test(driver)) driver = '';
  var dispatch = normalizeDispatchDateOnly_(headerRow[4]);
  var tripName = firstValidDeliveryReference_(headerRow[7], waybillRow && waybillRow[7]);
  var tripId = toPositiveId_(headerRow[8]);
  if (!(tripId > 0) && waybillRow) tripId = toPositiveId_(waybillRow[8]);
  var waybills = waybillRow ? parseWaybillList_(joinWaybillListCells_(waybillRow)) : [];
  var statedCount = waybillRow ? toPositiveId_(waybillRow[2]) : '';
  if (!(statedCount > 0)) statedCount = toPositiveId_(headerRow[6]);
  if (!(statedCount > 0) && waybillRow) statedCount = toPositiveId_(waybillRow[6]);
  return {
    seq: seq === '' ? 0 : seq,
    tripId: tripId === '' ? 0 : tripId,
    tripName: tripName,
    driver: driver,
    dispatchDate: dispatch,
    waybills: waybills,
    statedCount: statedCount === '' ? 0 : statedCount
  };
}

function firstValidDeliveryReference_(a, b) {
  var first = cleanText_(a);
  if (isValidDeliveryReference_(first) && first.toLowerCase() !== 'pending') return first;
  var second = cleanText_(b);
  if (isValidDeliveryReference_(second) && second.toLowerCase() !== 'pending') return second;
  return first || second;
}

function joinWaybillListCells_(row) {
  var chunks = [];
  for (var c = 3; c <= 5; c++) {
    var t = cleanText_(row[c]);
    if (t) chunks.push(t);
  }
  return chunks.join(', ');
}

function parseWaybillList_(raw) {
  var s = cleanText_(raw);
  if (!s) return [];
  var parts = s.split(/[,;\n]+/);
  var out = [];
  var seen = {};
  for (var i = 0; i < parts.length; i++) {
    var n = extractWaybillNo_(parts[i]);
    if (!n || seen[n]) continue;
    seen[n] = true;
    out.push(n);
  }
  return out;
}

function auditTripMembershipAgainstKitWaybills_(ss, membershipSummary, errors) {
  var parsed = membershipSummary && membershipSummary.membership;
  if (!parsed || !parsed.trips || !parsed.trips.length) return;
  var sheet = requireSheet_(ss, CFG.waybillsSheetName);
  var data = readSheetValuesSmart_(sheet, CFG.kitWaybillsMaxCols);
  if (!data || data.length < 2) return;
  var tgt = buildResolvedMap_(data[0], TGT_WAYBILL_ALIASES);
  var byDelivery = {};
  var byWb = {};
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    var wb = extractWaybillNo_(valueByField_(row, tgt, 'waybill_no'))
      || extractWaybillNo_(valueByField_(row, tgt, 'parcel_id'));
    if (!wb) continue;
    var delId = toPositiveId_(valueByField_(row, tgt, 'delivery_id'));
    byWb[wb] = delId;
    if (delId !== '') {
      if (!byDelivery[delId]) byDelivery[delId] = [];
      byDelivery[delId].push(wb);
    }
  }

  for (var t = 0; t < parsed.trips.length; t++) {
    var trip = parsed.trips[t];
    var tripId = trip.tripId;
    var listed = trip.waybills || [];
    var listedSet = {};
    var l;
    for (l = 0; l < listed.length; l++) listedSet[listed[l]] = true;
    var assigned = byDelivery[tripId] || [];
    if (assigned.length !== listed.length) {
      membershipSummary.count_mismatches = (membershipSummary.count_mismatches || 0) + 1;
      pushRowError_(
        errors,
        0,
        'Trip membership after projection: trip ' + tripId +
          ' list has ' + listed.length + ' waybills but kit_waybills col F has ' +
          assigned.length + '.'
      );
    }
    for (l = 0; l < listed.length; l++) {
      var want = listed[l];
      if (byWb[want] !== tripId) {
        pushRowError_(
          errors,
          0,
          'Trip membership after projection: waybill ' + want +
            ' should be delivery_id ' + tripId + ' (col F) but is ' +
            (byWb[want] === '' || byWb[want] == null ? 'blank' : byWb[want]) + '.'
        );
      }
    }
    for (var a = 0; a < assigned.length; a++) {
      var extra = assigned[a];
      if (!listedSet[extra]) {
        pushRowError_(
          errors,
          0,
          'Trip membership after projection: waybill ' + extra +
            ' is on delivery_id ' + tripId + ' but is not on the confirmation list.'
        );
      }
    }
  }
}


// =============================================================================
// 30_StageDrivers.gs
// =============================================================================

/**
 * Stage: Drivers only — persons from Waybills Driver column → kit_drivers.
 * Does not touch deliveries.
 */

/**
 * @param {SpreadsheetApp.Spreadsheet} ss
 * @param {Array} dtoRows from readWaybillsSource_
 * @returns {{summary: Object, nameToId: Object}}
 */
function stageDrivers_(ss, dtoRows) {
  var summary = {
    source_rows: dtoRows.length,
    existing: 0,
    appended: 0,
    skipped_non_driver: 0,
    skipped_existing: 0
  };

  var sheet = requireSheet_(ss, CFG.driversSheetName);
  var data = readSheetValuesSmart_(sheet, 12);
  if (!data || data.length < 1) {
    throw new Error('kit_drivers must have a header row.');
  }
  var headers = data[0];
  var idx = buildDriverTargetIndexes_(headers);
  if (idx.name < 0) {
    throw new Error('kit_drivers missing required column: name');
  }

  var nameToId = {};
  var maxId = 0;
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    var name = idx.name >= 0 ? cleanText_(row[idx.name]) : '';
    var id = idx.id >= 0 ? toPositiveId_(row[idx.id]) : '';
    if (id !== '' && id > maxId) maxId = id;
    var key = normalizeName_(name);
    if (key && id !== '') {
      nameToId[key] = id;
    } else if (key && nameToId[key] === undefined) {
      // Name present without id — keep placeholder until a numbered row appears.
      nameToId[key] = '';
    }
  }
  // Drop empty placeholders from count / map after scan
  var nameKeys = Object.keys(nameToId);
  for (var nk0 = 0; nk0 < nameKeys.length; nk0++) {
    if (nameToId[nameKeys[nk0]] === '') {
      delete nameToId[nameKeys[nk0]];
    }
  }
  summary.existing = Object.keys(nameToId).length;

  var wanted = {};
  for (var r = 0; r < dtoRows.length; r++) {
    var d = dtoRows[r];
    var driverName = d.driverName;
    if (!driverName || isNonDriverName_(driverName)) {
      summary.skipped_non_driver++;
      continue;
    }
    var nkey = normalizeName_(driverName);
    if (!wanted[nkey]) {
      wanted[nkey] = driverName;
    }
  }

  var appends = [];
  var keys = Object.keys(wanted);
  for (var k = 0; k < keys.length; k++) {
    var nk = keys[k];
    if (nameToId[nk]) {
      summary.skipped_existing++;
      continue;
    }
    maxId += 1;
    var out = emptyRow_(headers.length);
    if (idx.id >= 0) out[idx.id] = maxId;
    out[idx.name] = wanted[nk];
    if (idx.isActive >= 0) out[idx.isActive] = 1;
    var ts = currentMysqlTimestamp_();
    if (idx.createdAt >= 0) out[idx.createdAt] = ts;
    if (idx.updatedAt >= 0) out[idx.updatedAt] = ts;
    nameToId[nk] = maxId;
    appends.push(out);
    summary.appended++;
  }

  if (appends.length) {
    applyUpserts_(sheet, [], appends, headers.length);
  }

  return { summary: summary, nameToId: nameToId };
}


// =============================================================================
// 31_StageDeliveries.gs
// =============================================================================

/**
 * Stage: Deliveries / trucks only — trips from confirmation-sheet Trip id
 * (Waybills col B remapped) + Dispatch Date.
 * Upsert key = trip id (Delivery number). Never collapse by driver+date.
 * driver_id is an FK only; trips upsert even when driver is missing/warehouse.
 */

/**
 * @param {SpreadsheetApp.Spreadsheet} ss
 * @param {Array} dtoRows
 * @param {Object} nameToId from stageDrivers_
 */
function stageDeliveries_(ss, dtoRows, nameToId) {
  var summary = {
    source_rows: dtoRows.length,
    existing: 0,
    appended: 0,
    updated: 0,
    skipped_no_trip_id: 0,
    skipped_warehouse: 0,
    trips_seen: 0
  };

  var sheet = requireSheet_(ss, CFG.deliveriesSheetName);
  var data = readSheetValuesSmart_(sheet, 16);
  if (!data || data.length < 1) {
    throw new Error('kit_deliveries must have a header row.');
  }
  var headers = data[0];
  var idx = buildDeliveryTargetIndexes_(headers);
  if (idx.ref < 0) {
    throw new Error('kit_deliveries missing required column: delivery_reference');
  }

  var byId = {}; // tripId -> { sheetRow, row }
  var usedRefs = {};
  var maxId = 0;
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    var tripId = idx.id >= 0 ? toPositiveId_(row[idx.id]) : '';
    if (tripId === '' && idx.delId >= 0) tripId = toPositiveId_(row[idx.delId]);
    if (tripId !== '' && tripId > maxId) maxId = tripId;
    var ref = cleanText_(row[idx.ref]);
    if (isValidDeliveryReference_(ref)) {
      usedRefs[ref.toUpperCase()] = true;
    }
    if (tripId !== '') {
      byId[tripId] = { sheetRow: i + 1, row: row.slice() };
    }
  }
  summary.existing = Object.keys(byId).length;

  // Group by trip id — last non-empty dispatch / best driver wins (no byDriverDate skip).
  var today = Utilities.formatDate(new Date(), scriptTimeZone_(), 'yyyy-MM-dd');
  var groups = {};
  for (var r = 0; r < dtoRows.length; r++) {
    var d = dtoRows[r];
    // Warehouse rows are never remapped to a real kit delivery_id by
    // applyTripMembershipToDtos_ (their deliveryTripId stays the raw Waybills
    // col B sequential trip #), so staging them here would create/patch a
    // kit_deliveries row under a bogus id — possibly colliding with an
    // unrelated real delivery that happens to share that number.
    if (d.isWarehouse) {
      summary.skipped_warehouse++;
      continue;
    }
    var trip = d.deliveryTripId;
    if (!(trip > 0)) {
      summary.skipped_no_trip_id++;
      continue;
    }
    if (!groups[trip]) {
      groups[trip] = {
        tripId: trip,
        dispatchDate: d.dispatchDate || today,
        driverName: '',
        directionId: d.directionId || '',
        cityId: d.cityId || ''
      };
    }
    var g = groups[trip];
    if (d.dispatchDate) g.dispatchDate = d.dispatchDate;
    if (d.driverName && !isNonDriverName_(d.driverName)) {
      g.driverName = d.driverName;
    }
    if (d.directionId) g.directionId = d.directionId;
    if (d.cityId) g.cityId = d.cityId;
  }

  var tripIds = Object.keys(groups).map(function (k) { return parseInt(k, 10); }).sort(function (a, b) { return a - b; });
  summary.trips_seen = tripIds.length;

  var patches = [];
  var appends = [];

  for (var t = 0; t < tripIds.length; t++) {
    var tripNo = tripIds[t];
    var group = groups[tripNo];
    var dispatch = group.dispatchDate || today;
    var driverId = '';
    if (group.driverName) {
      var dk = normalizeName_(group.driverName);
      if (nameToId[dk]) driverId = nameToId[dk];
    }

    if (byId[tripNo]) {
      var existing = byId[tripNo];
      var er = existing.row;
      var changed = false;
      if (idx.dispatch >= 0) {
        var oldD = normalizeDispatchDateOnly_(er[idx.dispatch]);
        if (oldD !== dispatch) {
          er[idx.dispatch] = dispatch;
          changed = true;
        }
      }
      if (idx.driver >= 0 && driverId !== '') {
        var oldDriver = toPositiveId_(er[idx.driver]);
        if (oldDriver !== driverId) {
          er[idx.driver] = driverId;
          changed = true;
        }
      }
      if (idx.ref >= 0) {
        var oldRef = cleanText_(er[idx.ref]);
        if (isValidDeliveryReference_(oldRef) && oldRef.toLowerCase() !== 'pending') {
          var ymd = String(dispatch).replace(/-/g, '');
          var m = oldRef.match(/^(DEL-)\d{8}(-\d{3})$/i);
          if (m && ymd.length === 8) {
            var newRef = 'DEL-' + ymd + m[2];
            if (newRef.toUpperCase() !== oldRef.toUpperCase()) {
              var newKey = newRef.toUpperCase();
              if (!usedRefs[newKey] || newKey === oldRef.toUpperCase()) {
                delete usedRefs[oldRef.toUpperCase()];
                usedRefs[newKey] = true;
                er[idx.ref] = newRef;
                changed = true;
              }
            }
          }
        } else if (!isValidDeliveryReference_(oldRef)) {
          er[idx.ref] = allocateDeliveryReference_(dispatch, usedRefs);
          changed = true;
        }
      }
      if (idx.direction >= 0 && group.directionId && !toPositiveId_(er[idx.direction])) {
        er[idx.direction] = group.directionId;
        changed = true;
      }
      if (idx.city >= 0 && group.cityId && !toPositiveId_(er[idx.city])) {
        er[idx.city] = group.cityId;
        changed = true;
      }
      if (changed) {
        patches.push({ sheetRow: existing.sheetRow, values: er });
        summary.updated++;
      }
      continue;
    }

    // New trip — preserve Waybills Delivery id as sheet id.
    var out = emptyRow_(headers.length);
    if (idx.id >= 0) out[idx.id] = tripNo;
    if (idx.delId >= 0) out[idx.delId] = tripNo;
    out[idx.ref] = allocateDeliveryReference_(dispatch, usedRefs);
    if (idx.dispatch >= 0) out[idx.dispatch] = dispatch;
    if (idx.driver >= 0 && driverId !== '') out[idx.driver] = driverId;
    if (idx.direction >= 0) out[idx.direction] = group.directionId || '';
    if (idx.city >= 0) out[idx.city] = group.cityId || '';
    if (idx.status >= 0) out[idx.status] = 'scheduled';
    if (idx.createdBy >= 0 && CFG.waybillSyncUserId) out[idx.createdBy] = CFG.waybillSyncUserId;
    if (idx.createdAt >= 0) out[idx.createdAt] = currentMysqlTimestamp_();
    byId[tripNo] = { sheetRow: -1, row: out };
    appends.push(out);
    summary.appended++;
    if (tripNo > maxId) maxId = tripNo;
  }

  applyUpserts_(sheet, patches, appends, headers.length);
  return { summary: summary };
}


// =============================================================================
// 32_StageCustomers.gs
// =============================================================================

/**
 * Stage: Customers — Waybills col I is the type switch:
 *   Private / blank → person (F) → kit_customers
 *   real company    → kit_company_customers; person (F) → kit_customers.company_id
 * Near-dupe keys aligned with KIT_Customers PHP normalize_* helpers.
 */

/** Canonical kit_customers header row (matches DB / export). */
function kitCustomersCanonicalHeaders_() {
  return [
    'id', 'cust_id', 'name', 'surname', 'cell', 'telephone', 'email_address',
    'country_id', 'city_id', 'vat_number', 'address', 'company_id', 'created_at'
  ];
}

/** Canonical kit_company_customers header row (matches DB / export). */
function kitCompanyCustomersCanonicalHeaders_() {
  return [
    'id', 'company_id', 'company_name', 'cell', 'telephone', 'email_address',
    'country_id', 'city_id', 'vat_number', 'address', 'created_at'
  ];
}

function buildCompanyTargetIndexes_(headers) {
  return {
    id: colIndex_(headers, ['id', 'row_id']),
    companyId: colIndex_(headers, ['company_id', 'company id', 'cust_id', 'customer_id']),
    companyName: colIndex_(headers, ['company_name', 'company', 'company name', 'business', 'business_name', 'name']),
    cell: colIndex_(headers, ['cell', 'cellphone', 'mobile']),
    telephone: colIndex_(headers, ['telephone', 'tel', 'phone']),
    email: colIndex_(headers, ['email_address', 'email']),
    country: colIndex_(headers, ['country_id', 'country']),
    city: colIndex_(headers, ['city_id', 'city']),
    vat: colIndex_(headers, ['vat_number', 'vat']),
    address: colIndex_(headers, ['address']),
    created: colIndex_(headers, ['created_at', 'created at'])
  };
}

function sheetLooksEmpty_(data) {
  if (!data || data.length < 1) return true;
  if (data.length === 1) {
    var row = data[0] || [];
    for (var i = 0; i < row.length; i++) {
      if (cleanText_(row[i]) !== '') return false;
    }
    return true;
  }
  return false;
}

function ensureSheetWithHeaders_(ss, sheetName, headers) {
  if (String(sheetName).toLowerCase() === String(CFG.sourceSheetName).toLowerCase()) {
    throw new Error('Refusing to bootstrap headers on the Waybills source sheet (IMPORTRANGE).');
  }
  var sheet = ss.getSheetByName(sheetName);
  if (!sheet) {
    sheet = sheetsCall_(function () {
      return ss.insertSheet(sheetName);
    }, 'insert ' + sheetName);
  }
  var data = readSheetValuesSmart_(sheet, headers.length + 2);
  var bootstrapped = false;
  if (sheetLooksEmpty_(data)) {
    sheetsCall_(function () { sheet.clearContents(); }, 'clear ' + sheetName);
    sheetsCall_(function () {
      sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
    }, 'headers ' + sheetName);
    data = [headers];
    bootstrapped = true;
  }
  return { sheet: sheet, data: data, bootstrapped: bootstrapped };
}

/**
 * kit_customers matches DB: … address, company_id, created_at.
 * Renames leftover company_name → company_id, or inserts company_id before created_at.
 */
function alignKitCustomersHeaders_(sheet, data) {
  var headers = (data[0] || []).slice();
  var companyIdIdx = colIndex_(headers, ['company_id', 'company id', 'linked_company_id']);
  if (companyIdIdx >= 0) {
    return data;
  }

  var companyNameIdx = colIndex_(headers, ['company_name', 'company name']);
  if (companyNameIdx >= 0) {
    headers[companyNameIdx] = 'company_id';
    data[0] = headers;
    sheetsCall_(function () {
      sheet.getRange(1, companyNameIdx + 1).setValue('company_id');
    }, 'rename kit_customers company_name → company_id');
    return data;
  }

  var createdIdx = colIndex_(headers, ['created_at', 'created at']);
  var insertAt = createdIdx >= 0 ? createdIdx : headers.length;
  headers.splice(insertAt, 0, 'company_id');
  for (var i = 1; i < data.length; i++) {
    var row = (data[i] || []).slice();
    while (row.length < insertAt) row.push('');
    row.splice(insertAt, 0, '');
    data[i] = row;
  }
  data[0] = headers;
  sheetsCall_(function () {
    sheet.insertColumnBefore(insertAt + 1);
    sheet.getRange(1, insertAt + 1).setValue('company_id');
  }, 'insert kit_customers company_id');
  return data;
}

function buildCompanyNameToIdMap_(ss) {
  var map = {};
  var sheet = ss.getSheetByName(CFG.companyCustomersSheetName);
  if (!sheet) return map;
  var data = readSheetValuesSmart_(sheet, 16);
  if (!data || data.length < 2) return map;
  var idx = buildCompanyTargetIndexes_(data[0]);
  if (idx.companyId < 0 || idx.companyName < 0) return map;
  for (var i = 1; i < data.length; i++) {
    var id = toPositiveId_(data[i][idx.companyId]);
    var label = cleanText_(data[i][idx.companyName]);
    if (!id || !label) continue;
    var key = normalizeCompanyCompareKey_(label);
    if (key && (!map[key] || id < map[key])) map[key] = id;
  }
  return map;
}

function resolveCompanyIdFromCell_(raw, nameToId) {
  var id = toPositiveId_(raw);
  if (id) return id;
  var label = cleanText_(raw);
  if (!label || isSheetErrorValue_(label)) return '';
  var key = normalizeCompanyCompareKey_(label);
  if (key && nameToId[key]) return nameToId[key];
  if (key) {
    var keys = Object.keys(nameToId);
    for (var i = 0; i < keys.length; i++) {
      if (labelsAreSimilar_(key, keys[i])) return nameToId[keys[i]];
    }
  }
  return '';
}

/** Replace leftover company names in kit_customers.company_id with numeric IDs. */
function coerceKitCustomersCompanyIds_(ss) {
  var sheet = ss.getSheetByName(CFG.customersSheetName);
  if (!sheet) return { converted: 0, cleared: 0, already_numeric: 0 };
  var data = readSheetValuesSmart_(sheet, 20);
  var idx = buildCustomerTargetIndexes_(data[0] || []);
  if (idx.companyId < 0) return { converted: 0, cleared: 0, already_numeric: 0 };
  var nameToId = buildCompanyNameToIdMap_(ss);
  var converted = 0;
  var cleared = 0;
  var already = 0;
  var patches = [];
  for (var i = 1; i < data.length; i++) {
    var raw = data[i][idx.companyId];
    if (raw === '' || raw == null) continue;
    if (toPositiveId_(raw)) {
      already++;
      continue;
    }
    var resolved = resolveCompanyIdFromCell_(raw, nameToId);
    var row = data[i].slice();
    row[idx.companyId] = resolved || '';
    if (resolved) converted++;
    else cleared++;
    patches.push({ sheetRow: i + 1, values: row });
  }
  if (patches.length) {
    applyUpserts_(sheet, patches, [], (data[0] || []).length);
  }
  return { converted: converted, cleared: cleared, already_numeric: already };
}

function mergeCandidateContact_(cand, d, cityMap, ss) {
  var cell = usableSheetText_(d.cell);
  var telephone = usableSheetText_(d.telephone);
  var email = usableSheetText_(d.email);
  var address = usableSheetText_(d.address);
  if (cell) cand.cell = cell;
  if (telephone) cand.telephone = telephone;
  if (email) cand.email = email;
  if (address) cand.address = address;
  if (d.customerId) cand.preferredId = d.customerId;
  if (!cand.cityId && (d.cityId || d.cityName)) {
    cand.cityId = d.cityId || resolveCityId_(ss, cityMap, d.cityName) || '';
  }
}

function isNearExistingLabel_(needle, key, existingKeys, existingLabels) {
  if (existingKeys[key]) return true;
  for (var e = 0; e < existingLabels.length; e++) {
    if (existingLabels[e].key === key || labelsAreSimilar_(needle, existingLabels[e].label)) {
      return true;
    }
  }
  return false;
}

/** Email identity — ignore #REF! / #N/A / blanks. */
function normalizeEmailKey_(value) {
  if (isSheetErrorValue_(value)) return '';
  var s = cleanText_(value).toLowerCase();
  if (!s || s.indexOf('@') < 1) return '';
  return s;
}

function applyPersonCandidateToRow_(row, idx, cand) {
  var split = isLikelyBusinessName_(cand.label)
    ? { name: cand.label, surname: '' }
    : splitPersonName_(cand.label);
  if (idx.name >= 0) row[idx.name] = split.name;
  if (idx.surname >= 0) row[idx.surname] = split.surname;
  if (idx.combined >= 0) row[idx.combined] = cand.label;
  if (cand.companyId && idx.companyId >= 0) {
    row[idx.companyId] = cand.companyId;
    if (idx.company >= 0) row[idx.company] = '';
  }
  if (cand.cell && idx.cell >= 0) row[idx.cell] = usableSheetText_(cand.cell);
  else if (idx.cell >= 0 && isFormulaErrorText_(row[idx.cell])) row[idx.cell] = '';
  if (cand.telephone && idx.telephone >= 0) row[idx.telephone] = usableSheetText_(cand.telephone);
  else if (idx.telephone >= 0 && isFormulaErrorText_(row[idx.telephone])) row[idx.telephone] = '';
  if (cand.email && idx.email >= 0) row[idx.email] = usableSheetText_(cand.email);
  else if (idx.email >= 0 && isFormulaErrorText_(row[idx.email])) row[idx.email] = '';
  if (cand.address && idx.address >= 0) row[idx.address] = usableSheetText_(cand.address);
  else if (idx.address >= 0 && isFormulaErrorText_(row[idx.address])) row[idx.address] = '';
  if (cand.cityId && idx.city >= 0 && !toPositiveId_(row[idx.city])) {
    row[idx.city] = cand.cityId;
  }
  return row;
}

function collectPersonRowMatches_(candKey, candEmailKey, candNeedle, people, droppedSheetRows) {
  var seen = {};
  var matches = [];
  for (var i = 0; i < people.length; i++) {
    var p = people[i];
    if (!p || droppedSheetRows[p.sheetRow] || seen[p.sheetRow]) continue;
    var hit = p.key === candKey
      || (candEmailKey && p.emailKey && p.emailKey === candEmailKey)
      || (candNeedle && p.label && labelsAreSimilar_(candNeedle, p.label));
    if (!hit) continue;
    seen[p.sheetRow] = true;
    matches.push(p);
  }
  return matches;
}

function pickOldestPersonRow_(matches) {
  var best = matches[0];
  for (var i = 1; i < matches.length; i++) {
    var p = matches[i];
    var a = toPositiveId_(best.custId);
    var b = toPositiveId_(p.custId);
    if (a === '') a = 999999999;
    if (b === '') b = 999999999;
    if (b < a || (b === a && p.sheetRow < best.sheetRow)) best = p;
  }
  return best;
}

function deleteSheetRowsDesc_(sheet, sheetRows) {
  assertNotSourceWaybillsWrite_(sheet, 'deleteRows');
  var uniq = {};
  var list = [];
  for (var i = 0; i < (sheetRows || []).length; i++) {
    var r = sheetRows[i];
    if (r >= 2 && !uniq[r]) {
      uniq[r] = true;
      list.push(r);
    }
  }
  list.sort(function (a, b) { return b - a; });
  if (!list.length) return 0;
  sheetsCall_(function () {
    for (var j = 0; j < list.length; j++) {
      sheet.deleteRow(list[j]);
    }
  }, 'delete collapsed kit_customers rows');
  return list.length;
}

function remapKitWaybillsCustomerIds_(ss, fromTo) {
  var keys = Object.keys(fromTo || {});
  if (!keys.length) return 0;
  var sheet = ss.getSheetByName(CFG.waybillsSheetName);
  if (!sheet) return 0;
  var data = readSheetValuesSmart_(sheet, CFG.kitWaybillsMaxCols);
  if (!data || data.length < 2) return 0;
  var headers = data[0];
  var tgt = buildResolvedMap_(headers, TGT_WAYBILL_ALIASES);
  if (!tgt || tgt.customer_id < 0) return 0;
  var patches = [];
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    var cur = String(toPositiveId_(valueByField_(row, tgt, 'customer_id')) || '');
    if (!cur || fromTo[cur] == null) continue;
    var next = row.slice();
    setByField_(next, tgt, 'customer_id', fromTo[cur]);
    patches.push({ sheetRow: i + 1, values: next });
  }
  if (patches.length) applyUpserts_(sheet, patches, [], headers.length);
  return patches.length;
}

/**
 * @param {SpreadsheetApp.Spreadsheet} ss
 * @param {Array} dtoRows
 * @returns {{summary: Object, custKeyToId: Object, companyKeyToId: Object}}
 */
function stageCustomers_(ss, dtoRows) {
  var surnameFix = rewriteWaybillCompoundSurnames_(ss, dtoRows);
  var cityMap = buildCityNameToIdMap_(ss);
  // Companies first so persons can link company_id from col I.
  var companies = stageCompanyCustomers_(ss, dtoRows, cityMap);
  var persons = stagePersonCustomers_(ss, dtoRows, cityMap, companies.companyKeyToId);
  clearFormulaErrorsInContactColumns_(ss.getSheetByName(CFG.companyCustomersSheetName));
  clearFormulaErrorsInContactColumns_(ss.getSheetByName(CFG.customersSheetName));
  return {
    summary: {
      source_rows: dtoRows.length,
      waybill_surnames_rewritten: surnameFix.rewritten,
      persons: persons.summary,
      companies: companies.summary,
      linked_persons: persons.summary.linked_company || 0,
      appended: (persons.summary.appended || 0) + (companies.summary.appended || 0)
    },
    custKeyToId: persons.custKeyToId,
    companyKeyToId: companies.companyKeyToId
  };
}

/**
 * Persons from Waybills col F → kit_customers (Private rows, or named people at a col I company).
 * Waybills spelling wins. Existing rows that share email or a near-duplicate
 * name are renamed onto the Waybills label and collapsed onto the oldest cust_id.
 * Rows that do not appear on Waybills at all are deleted (kit_customers is a projection).
 */
function stagePersonCustomers_(ss, dtoRows, cityMap, companyKeyToId) {
  companyKeyToId = companyKeyToId || {};
  var summary = {
    existing: 0,
    appended: 0,
    skipped_existing: 0,
    skipped_suspicious: 0,
    skipped_no_name: 0,
    skipped_company: 0,
    linked_company: 0,
    unique_candidates: 0,
    repaired_surname: 0,
    repaired_name: 0,
    collapsed_duplicates: 0,
    pruned_orphans: 0,
    remapped_waybills: 0,
    preferred_id_collisions: 0,
    headers_bootstrapped: false
  };

  var boot = ensureSheetWithHeaders_(ss, CFG.customersSheetName, kitCustomersCanonicalHeaders_());
  summary.headers_bootstrapped = boot.bootstrapped;
  var sheet = boot.sheet;
  var data = alignKitCustomersHeaders_(sheet, boot.data);
  var headers = data[0];
  var idx = buildCustomerTargetIndexes_(headers);
  if (idx.custId < 0 || (idx.name < 0 && idx.combined < 0)) {
    throw new Error('kit_customers missing required columns: cust_id + name (person rows).');
  }
  if (idx.companyId < 0) {
    throw new Error('kit_customers missing required column: company_id.');
  }

  var existingKeys = {};
  var existingLabels = [];
  var existingRows = {};
  var existingPeople = [];
  var patches = [];
  var patchBySheetRow = {};
  var maxId = 0;
  var maxCustId = CFG.customerSeedMinId - 1;
  /** Every cust_id already on the sheet — guards preferredId against collisions. */
  var usedCustIds = {};

  function rememberPatch_(sheetRow, values) {
    if (!(Number(sheetRow) >= 2)) return;
    if (patchBySheetRow[sheetRow]) {
      patchBySheetRow[sheetRow].values = values;
      return;
    }
    var p = { sheetRow: sheetRow, values: values };
    patches.push(p);
    patchBySheetRow[sheetRow] = p;
  }

  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    var id = idx.id >= 0 ? toPositiveId_(row[idx.id]) : '';
    var custId = toPositiveId_(row[idx.custId]);
    if (id !== '' && id > maxId) maxId = id;
    if (custId !== '' && custId > maxCustId) maxCustId = custId;
    if (custId !== '') usedCustIds[custId] = true;

    var label = resolveCustomerLabel_(row, idx);
    if (idx.surname >= 0 && !isLikelyBusinessName_(label)) {
      var currentSurname = cleanText_(row[idx.surname]);
      var fixedSurname = compactSurname_(currentSurname);
      if (fixedSurname && fixedSurname !== currentSurname) {
        var patched = row.slice();
        patched[idx.surname] = fixedSurname;
        data[i] = patched;
        row = patched;
        label = resolveCustomerLabel_(row, idx);
        rememberPatch_(i + 1, patched);
        summary.repaired_surname++;
      }
    }
    var key = personIdentityKey_(label);
    if (key) {
      var person = {
        sheetRow: i + 1,
        row: row,
        custId: custId,
        key: key,
        label: normalizePersonNameKey_(label, ''),
        emailKey: idx.email >= 0 ? normalizeEmailKey_(row[idx.email]) : ''
      };
      existingKeys[key] = custId || true;
      existingLabels.push({ key: key, label: person.label });
      existingRows[key] = person;
      existingPeople.push(person);
    }
  }
  summary.existing = Object.keys(existingKeys).length;

  var candidates = {};
  for (var r = 0; r < dtoRows.length; r++) {
    var d = dtoRows[r];
    var name = d.customerName;
    if (!name) {
      summary.skipped_no_name++;
      continue;
    }
    if (!d.isPersonCustomer) {
      summary.skipped_company++;
      continue;
    }
    if (isSuspiciousCustomerName_(name)) {
      summary.skipped_suspicious++;
      continue;
    }
    var ckey = personIdentityKey_(name);
    if (!ckey) {
      summary.skipped_suspicious++;
      continue;
    }
    var companyLabel = resolveCompanyLabelFromDto_(d);
    var linkedCompanyId = companyLabel
      ? lookupCompanyIdByKey_(companyKeyToId, companyLabel)
      : '';
    if (!candidates[ckey]) {
      candidates[ckey] = {
        label: name,
        preferredId: d.customerId || '',
        companyLabel: companyLabel || '',
        companyId: linkedCompanyId || '',
        cityId: d.cityId || resolveCityId_(ss, cityMap, d.cityName) || '',
        cell: isSheetErrorValue_(d.cell) ? '' : (d.cell || ''),
        telephone: isSheetErrorValue_(d.telephone) ? '' : (d.telephone || ''),
        email: isSheetErrorValue_(d.email) ? '' : (d.email || ''),
        address: isSheetErrorValue_(d.address) ? '' : (d.address || '')
      };
    } else {
      mergeCandidateContact_(candidates[ckey], d, cityMap, ss);
      if (companyLabel) candidates[ckey].companyLabel = companyLabel;
      if (linkedCompanyId) candidates[ckey].companyId = linkedCompanyId;
    }
  }

  var candKeys = Object.keys(candidates);
  summary.unique_candidates = candKeys.length;
  var appends = [];
  var droppedSheetRows = {};
  var keptSheetRows = {};
  var dropRows = [];
  var customerIdRemap = {};

  for (var k = 0; k < candKeys.length; k++) {
    var key = candKeys[k];
    var cand = candidates[key];
    var needle = key.slice(2);
    var candEmailKey = normalizeEmailKey_(cand.email);
    var matches = collectPersonRowMatches_(
      key, candEmailKey, needle, existingPeople, droppedSheetRows
    );

    if (matches.length) {
      summary.skipped_existing++;
      var keeper = pickOldestPersonRow_(matches);
      var beforeLabel = resolveCustomerLabel_(keeper.row, idx);
      var linked = applyPersonCandidateToRow_(keeper.row.slice(), idx, cand);
      keeper.row = linked;
      keeper.key = personIdentityKey_(cand.label) || key;
      keeper.label = needle;
      keeper.emailKey = candEmailKey || keeper.emailKey;
      if (keeper.sheetRow >= 2) {
        data[keeper.sheetRow - 1] = linked;
        rememberPatch_(keeper.sheetRow, linked);
        keptSheetRows[keeper.sheetRow] = true;
      } else if (typeof keeper.appendIndex === 'number' && keeper.appendIndex >= 0) {
        appends[keeper.appendIndex] = linked;
      }
      if (personIdentityKey_(beforeLabel) !== key || cleanText_(beforeLabel) !== cleanText_(cand.label)) {
        summary.repaired_name++;
      }
      if (cand.companyId) summary.linked_company++;

      existingKeys[key] = keeper.custId || true;
      existingKeys[keeper.key] = keeper.custId || true;
      existingRows[key] = keeper;
      existingRows[keeper.key] = keeper;
      existingLabels.push({ key: key, label: needle });

      for (var m = 0; m < matches.length; m++) {
        var extra = matches[m];
        existingKeys[extra.key] = keeper.custId || true;
        if (extra.sheetRow === keeper.sheetRow) continue;
        if (extra.sheetRow < 2) continue;
        droppedSheetRows[extra.sheetRow] = true;
        dropRows.push(extra.sheetRow);
        var extraId = String(toPositiveId_(extra.custId) || '');
        var keepId = toPositiveId_(keeper.custId);
        if (extraId && keepId !== '') customerIdRemap[extraId] = keepId;
        summary.collapsed_duplicates++;
      }
      continue;
    }

    maxId += 1;
    // A preferredId already belonging to a different existing customer (or to
    // another candidate allocated earlier in this same run) is not safe to
    // reuse — kit_customers.cust_id is the primary key the WordPress pipeline
    // joins on, so a collision would silently merge two unrelated customers.
    var usePreferred = cand.preferredId && !usedCustIds[cand.preferredId];
    if (cand.preferredId && !usePreferred) {
      summary.preferred_id_collisions++;
    }
    var newCustId = usePreferred ? cand.preferredId : Math.max(maxCustId + 1, CFG.customerSeedMinId);
    if (newCustId > maxCustId) {
      maxCustId = newCustId;
    }
    usedCustIds[newCustId] = true;
    var split = isLikelyBusinessName_(cand.label)
      ? { name: cand.label, surname: '' }
      : splitPersonName_(cand.label);
    var out = emptyRow_(headers.length);
    if (idx.id >= 0) out[idx.id] = maxId;
    out[idx.custId] = newCustId;
    if (idx.name >= 0) out[idx.name] = split.name;
    if (idx.surname >= 0) out[idx.surname] = split.surname;
    if (idx.combined >= 0) out[idx.combined] = cand.label;
    if (idx.company >= 0) out[idx.company] = '';
    if (idx.companyId >= 0) out[idx.companyId] = cand.companyId || '';
    if (cand.companyId) summary.linked_company++;
    if (idx.country >= 0) out[idx.country] = CFG.countryIdDefault;
    if (idx.city >= 0) out[idx.city] = cand.cityId || '';
    if (idx.vat >= 0) out[idx.vat] = 'N/A';
    if (idx.cell >= 0) out[idx.cell] = usableSheetText_(cand.cell);
    if (idx.telephone >= 0) out[idx.telephone] = usableSheetText_(cand.telephone);
    if (idx.email >= 0) out[idx.email] = usableSheetText_(cand.email);
    if (idx.address >= 0) out[idx.address] = usableSheetText_(cand.address);
    if (idx.created >= 0) out[idx.created] = new Date();

    existingPeople.push({
      sheetRow: 0,
      appendIndex: appends.length,
      row: out,
      custId: newCustId,
      key: key,
      label: needle,
      emailKey: candEmailKey
    });
    appends.push(out);
    existingKeys[key] = newCustId;
    existingLabels.push({ key: key, label: needle });
    summary.appended++;
  }

  for (var op = 0; op < existingPeople.length; op++) {
    var leftover = existingPeople[op];
    if (leftover.sheetRow < 2) continue;
    if (keptSheetRows[leftover.sheetRow] || droppedSheetRows[leftover.sheetRow]) continue;
    droppedSheetRows[leftover.sheetRow] = true;
    dropRows.push(leftover.sheetRow);
    summary.pruned_orphans++;
  }

  if (dropRows.length) {
    patches = patches.filter(function (p) {
      return !droppedSheetRows[p.sheetRow];
    });
  }
  if (patches.length || appends.length) {
    applyUpserts_(sheet, patches, appends, headers.length);
  }
  if (dropRows.length) {
    deleteSheetRowsDesc_(sheet, dropRows);
  }
  if (Object.keys(customerIdRemap).length) {
    summary.remapped_waybills = remapKitWaybillsCustomerIds_(ss, customerIdRemap);
  }

  return { summary: summary, custKeyToId: existingKeys };
}

/**
 * Companies from Waybills col I only (never from a Private / blank company cell).
 */
function stageCompanyCustomers_(ss, dtoRows, cityMap) {
  var summary = {
    existing: 0,
    appended: 0,
    skipped_existing: 0,
    skipped_suspicious: 0,
    skipped_no_name: 0,
    skipped_person: 0,
    unique_candidates: 0,
    preferred_id_collisions: 0,
    headers_bootstrapped: false
  };

  var boot = ensureSheetWithHeaders_(ss, CFG.companyCustomersSheetName, kitCompanyCustomersCanonicalHeaders_());
  summary.headers_bootstrapped = boot.bootstrapped;
  var sheet = boot.sheet;
  var data = boot.data;
  var headers = data[0];
  var idx = buildCompanyTargetIndexes_(headers);
  if (idx.companyId < 0 || idx.companyName < 0) {
    throw new Error('kit_company_customers missing required columns: company_id + company_name.');
  }

  var existingKeys = {};
  var existingLabels = [];
  var maxId = 0;
  var maxCompanyId = CFG.companySeedMinId - 1;
  /** Every company_id already on the sheet — guards preferredId against collisions. */
  var usedCompanyIds = {};

  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    var id = idx.id >= 0 ? toPositiveId_(row[idx.id]) : '';
    var companyId = toPositiveId_(row[idx.companyId]);
    if (id !== '' && id > maxId) maxId = id;
    if (companyId !== '' && companyId > maxCompanyId) maxCompanyId = companyId;
    if (companyId !== '') usedCompanyIds[companyId] = true;

    var label = cleanText_(row[idx.companyName]);
    var key = customerIdentityKey_(label);
    if (key && key.indexOf('c:') === 0) {
      existingKeys[key] = companyId || true;
      existingLabels.push({ key: key, label: normalizeCompanyCompareKey_(label) });
    }
  }
  summary.existing = Object.keys(existingKeys).length;

  var candidates = {};
  for (var r = 0; r < dtoRows.length; r++) {
    var d = dtoRows[r];
    var labels = [];
    var companyFromI = resolveCompanyLabelFromDto_(d);
    if (companyFromI) labels.push(companyFromI);

    if (!labels.length) {
      if (!d.customerName) summary.skipped_no_name++;
      else summary.skipped_person++;
      continue;
    }

    for (var li = 0; li < labels.length; li++) {
      var name = labels[li];
      if (isSuspiciousCustomerName_(name) && !isLikelyBusinessName_(name)) {
        summary.skipped_suspicious++;
        continue;
      }
      var ckey = customerIdentityKey_(name);
      if (!ckey || ckey.indexOf('c:') !== 0) {
        var ck = normalizeCompanyCompareKey_(name);
        ckey = ck ? 'c:' + ck : '';
      }
      if (!ckey) {
        summary.skipped_suspicious++;
        continue;
      }
      if (!candidates[ckey]) {
        candidates[ckey] = {
          label: name,
          preferredId: (d.isCompanyCustomer ? (d.customerId || '') : ''),
          cityId: d.cityId || resolveCityId_(ss, cityMap, d.cityName) || '',
          cell: d.cell || '',
          telephone: d.telephone || '',
          email: d.email || '',
          address: d.address || ''
        };
      } else {
        mergeCandidateContact_(candidates[ckey], d, cityMap, ss);
      }
    }
  }

  var candKeys = Object.keys(candidates);
  summary.unique_candidates = candKeys.length;
  var appends = [];

  for (var k = 0; k < candKeys.length; k++) {
    var key = candKeys[k];
    var cand = candidates[key];
    var needle = key.slice(2);
    if (isNearExistingLabel_(needle, key, existingKeys, existingLabels)) {
      summary.skipped_existing++;
      if (!existingKeys[key]) {
        for (var e = 0; e < existingLabels.length; e++) {
          if (labelsAreSimilar_(needle, existingLabels[e].label) && existingKeys[existingLabels[e].key]) {
            existingKeys[key] = existingKeys[existingLabels[e].key];
            break;
          }
        }
      }
      continue;
    }

    maxId += 1;
    // See stagePersonCustomers_: never reuse a preferredId that already
    // belongs to a different company row — company_id is the join key the
    // WordPress pipeline relies on.
    var usePreferred = cand.preferredId && !usedCompanyIds[cand.preferredId];
    if (cand.preferredId && !usePreferred) {
      summary.preferred_id_collisions++;
    }
    var newCompanyId = usePreferred ? cand.preferredId : Math.max(maxCompanyId + 1, CFG.companySeedMinId);
    if (newCompanyId > maxCompanyId) {
      maxCompanyId = newCompanyId;
    }
    usedCompanyIds[newCompanyId] = true;

    var out = emptyRow_(headers.length);
    if (idx.id >= 0) out[idx.id] = maxId;
    out[idx.companyId] = newCompanyId;
    out[idx.companyName] = cand.label;
    if (idx.country >= 0) out[idx.country] = CFG.countryIdDefault;
    if (idx.city >= 0) out[idx.city] = cand.cityId || '';
    if (idx.vat >= 0) out[idx.vat] = 'N/A';
    if (idx.cell >= 0) out[idx.cell] = usableSheetText_(cand.cell);
    if (idx.telephone >= 0) out[idx.telephone] = usableSheetText_(cand.telephone);
    if (idx.email >= 0) out[idx.email] = usableSheetText_(cand.email);
    if (idx.address >= 0) out[idx.address] = usableSheetText_(cand.address);
    if (idx.created >= 0) out[idx.created] = new Date();

    appends.push(out);
    existingKeys[key] = newCompanyId;
    existingLabels.push({ key: key, label: needle });
    summary.appended++;
  }

  if (appends.length) {
    applyUpserts_(sheet, [], appends, headers.length);
  }

  return { summary: summary, companyKeyToId: existingKeys };
}

function resolveCustomerLabel_(row, idx) {
  if (idx.combined >= 0 && cleanText_(row[idx.combined])) return cleanText_(row[idx.combined]);
  var n = idx.name >= 0 ? cleanText_(row[idx.name]) : '';
  var s = idx.surname >= 0 ? cleanText_(row[idx.surname]) : '';
  var person = (n + ' ' + s).trim();
  if (person) return person;
  if (idx.company >= 0 && cleanText_(row[idx.company])) return cleanText_(row[idx.company]);
  return '';
}

function buildCityNameToIdMap_(ss) {
  var map = {};
  var sheet = ss.getSheetByName(CFG.citiesSheetName);
  if (!sheet) return map;
  var data = readSheetValuesSmart_(sheet, 8);
  if (!data || data.length < 2) return map;
  var headers = data[0];
  var idIdx = colIndex_(headers, ['id', 'city_id']);
  var nameIdx = colIndex_(headers, ['city_name', 'city', 'name']);
  if (idIdx < 0 || nameIdx < 0) return map;
  for (var i = 1; i < data.length; i++) {
    var id = toPositiveId_(data[i][idIdx]);
    var name = normalizeName_(data[i][nameIdx]);
    if (id !== '' && name) map[name] = id;
  }
  return map;
}

var CITY_NAME_ALIASES_ = {
  'dar': 'dar es salaam',
  'dsm': 'dar es salaam',
  'dar es salam': 'dar es salaam',
  'dares salaam': 'dar es salaam',
  'sao-hill': 'sao hill',
  'saohill': 'sao hill',
  'pemba island': 'pemba',
  'pemba town': 'pemba'
};

function lookupCityIdByName_(map, cityName) {
  if (isFormulaErrorText_(cityName)) return '';
  var key = normalizeName_(cityName);
  if (!key || !map) return '';
  if (map[key]) return map[key];
  var alias = CITY_NAME_ALIASES_[key];
  if (alias && map[alias]) return map[alias];
  var names = Object.keys(map);
  for (var i = 0; i < names.length; i++) {
    if (labelsAreSimilar_(key, names[i])) return map[names[i]];
  }
  return '';
}

function resolveCityId_(ss, map, cityName) {
  var id = lookupCityIdByName_(map, cityName);
  if (id) return id;
  var raw = usableSheetText_(cityName);
  if (!raw) return '';
  return ensureOperatingCity_(ss, map, raw);
}

function ensureOperatingCity_(ss, map, cityName) {
  var key = normalizeName_(cityName);
  if (!key) return '';
  if (map[key]) return map[key];
  var sheet = ss.getSheetByName(CFG.citiesSheetName);
  if (!sheet) return '';
  var data = readSheetValuesSmart_(sheet, 8);
  if (!data || !data.length) return '';
  var headers = data[0];
  var idIdx = colIndex_(headers, ['id', 'city_id']);
  var nameIdx = colIndex_(headers, ['city_name', 'city', 'name']);
  var countryIdx = colIndex_(headers, ['country_id', 'country']);
  var activeIdx = colIndex_(headers, ['is_active', 'active']);
  var createdIdx = colIndex_(headers, ['created_at', 'created at']);
  if (idIdx < 0 || nameIdx < 0) return '';
  var maxId = 0;
  for (var i = 1; i < data.length; i++) {
    var id = toPositiveId_(data[i][idIdx]);
    if (id !== '' && id > maxId) maxId = id;
  }
  var newId = maxId + 1;
  var row = emptyRow_(headers.length);
  row[idIdx] = newId;
  row[nameIdx] = cityName;
  if (countryIdx >= 0) row[countryIdx] = CFG.countryIdDefault;
  if (activeIdx >= 0) row[activeIdx] = 1;
  if (createdIdx >= 0) row[createdIdx] = new Date();
  applyUpserts_(sheet, [], [row], headers.length);
  map[key] = newId;
  return newId;
}

/** Blank #REF! / #N/A left in contact columns (copied from Waybills formulas). */
function clearFormulaErrorsInContactColumns_(sheet) {
  if (!sheet) return 0;
  var data = readSheetValuesSmart_(sheet, 20);
  if (!data || data.length < 2) return 0;
  var headers = data[0];
  var cols = [];
  var names = ['email_address', 'email', 'cell', 'telephone', 'address'];
  for (var n = 0; n < names.length; n++) {
    var idx = colIndex_(headers, [names[n]]);
    if (idx >= 0) cols.push(idx);
  }
  if (!cols.length) return 0;
  var patches = [];
  for (var r = 1; r < data.length; r++) {
    var changed = false;
    var next = data[r].slice();
    for (var c = 0; c < cols.length; c++) {
      if (isFormulaErrorText_(next[cols[c]])) {
        next[cols[c]] = '';
        changed = true;
      }
    }
    if (changed) patches.push({ sheetRow: r + 1, values: next });
  }
  if (patches.length) applyUpserts_(sheet, patches, [], headers.length);
  return patches.length;
}

function lookupCustomerIdByKey_(custKeyToId, label) {
  var key = personIdentityKey_(label);
  if (!key) return '';
  var v = custKeyToId[key];
  return typeof v === 'number' ? v : (toPositiveId_(v) || '');
}

function lookupCompanyIdByKey_(companyKeyToId, label) {
  var key = customerIdentityKey_(label);
  if (!key || key.indexOf('c:') !== 0) {
    var ck = normalizeCompanyCompareKey_(label);
    key = ck ? 'c:' + ck : '';
  }
  if (!key) return '';
  var v = companyKeyToId[key];
  return typeof v === 'number' ? v : (toPositiveId_(v) || '');
}


// =============================================================================
// 33_StageWaybills.gs
// =============================================================================

/**
 * Stage: kit_waybills — upsert by waybill_no (update-in-place; append new).
 * Does not create deliveries. delivery_id = confirmation-sheet Trip id
 * (Waybills col B remapped) or blank for warehouse.
 * charge_basis passed through raw; freight total is PHP-owned.
 */

/**
 * @param {SpreadsheetApp.Spreadsheet} ss
 * @param {Array} dtoRows
 * @param {{custKeyToId:Object, companyKeyToId:Object}|Object} partyMaps from stageCustomers_
 * @param {Array} rowErrors accumulator
 */
function stageWaybills_(ss, dtoRows, partyMaps, rowErrors) {
  // Backward compatible: bare custKeyToId map still accepted.
  if (partyMaps && !partyMaps.custKeyToId && !partyMaps.companyKeyToId) {
    partyMaps = { custKeyToId: partyMaps, companyKeyToId: {} };
  }
  partyMaps = partyMaps || { custKeyToId: {}, companyKeyToId: {} };
  var custKeyToId = partyMaps.custKeyToId || {};
  var companyKeyToId = partyMaps.companyKeyToId || {};
  var summary = {
    source_rows: dtoRows.length,
    existing: 0,
    updated: 0,
    appended: 0,
    skipped: 0
  };

  var sheet = requireSheet_(ss, CFG.waybillsSheetName);
  var data = readSheetValuesSmart_(sheet, CFG.kitWaybillsMaxCols);
  if (!data || data.length < 1) {
    throw new Error('kit_waybills must have a header row.');
  }
  var headers = data[0];
  var tgt = buildResolvedMap_(headers, TGT_WAYBILL_ALIASES);
  assertRequiredHeaders_(tgt, CFG.waybillsSheetName);

  var byWb = {};
  var maxId = 0;
  var usedInvoices = {};
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    var wb = extractWaybillNo_(valueByField_(row, tgt, 'waybill_no'));
    var id = toPositiveId_(valueByField_(row, tgt, 'id'));
    if (id !== '' && id > maxId) maxId = id;
    var inv = cleanText_(valueByField_(row, tgt, 'product_invoice_number'));
    if (inv) usedInvoices[inv.toUpperCase()] = true;
    if (wb) {
      byWb[wb] = {
        sheetRow: i + 1,
        row: row.slice(),
        id: id,
        link: {
          delivery_id: toPositiveId_(valueByField_(row, tgt, 'delivery_id')),
          direction_id: toPositiveId_(valueByField_(row, tgt, 'direction_id')),
          city_id: toPositiveId_(valueByField_(row, tgt, 'city_id')),
          customer_id: toPositiveId_(valueByField_(row, tgt, 'customer_id')),
          company_id: toPositiveId_(valueByField_(row, tgt, 'company_id')),
          approval: cleanText_(valueByField_(row, tgt, 'approval')),
          approval_userid: toPositiveId_(valueByField_(row, tgt, 'approval_userid')),
          tracking_number: cleanText_(valueByField_(row, tgt, 'tracking_number')),
          product_invoice_number: inv,
          created_at: normalizeSheetDateTime_(valueByField_(row, tgt, 'created_at')),
          created_by: toPositiveId_(valueByField_(row, tgt, 'created_by'))
        }
      };
    }
  }
  summary.existing = Object.keys(byWb).length;

  var cityMap = buildCityNameToIdMap_(ss);
  var patches = [];
  var appends = [];
  var seen = {};
  var creatorMap = buildCreatorNameToIdMap_(dtoRows);

  for (var r = 0; r < dtoRows.length; r++) {
    var d = dtoRows[r];
    if (seen[d.waybillNo]) {
      summary.skipped++;
      continue;
    }
    seen[d.waybillNo] = true;

    var existing = byWb[d.waybillNo];
    var link = existing ? existing.link : {};
    var id = existing && existing.id ? existing.id : (++maxId);

    var record = existing ? existing.row.slice() : emptyRow_(headers.length);
    // Ensure width
    while (record.length < headers.length) record.push('');

    var description = d.waybillDesc || d.itemDesc;
    var deliveryId = '';
    if (d.isWarehouse) {
      deliveryId = '';
    } else if (d.tripMembershipSource === 'waybill_list' && d.deliveryTripId > 0) {
      deliveryId = d.deliveryTripId;
    } else if (d.preserveKitDeliveryId) {
      deliveryId = link.delivery_id || d.sourceTripColB || '';
    } else {
      deliveryId = d.deliveryTripId > 0 ? d.deliveryTripId : (link.delivery_id || '');
    }
    var directionId = d.directionId || link.direction_id || CFG.defaultDirectionId;
    var cityId = d.cityId || resolveCityId_(ss, cityMap, d.cityName) || link.city_id || '';
    var companyLabel = resolveCompanyLabelFromDto_(d);
    var personLabel = d.isPersonCustomer ? cleanText_(d.customerName) : '';
    var customerId = '';
    var companyId = '';
    if (personLabel) {
      customerId = d.customerId
        ? d.customerId
        : (lookupCustomerIdByKey_(custKeyToId, personLabel) || link.customer_id || '');
    }
    if (companyLabel) {
      companyId = (d.isCompanyCustomer && d.customerId)
        ? d.customerId
        : (link.company_id || lookupCompanyIdByKey_(companyKeyToId, companyLabel) || '');
    }
    if (!customerId && !companyId && d.customerId) {
      if (d.hasLinkedCompany || d.isCompanyCustomer) companyId = d.customerId;
      else customerId = d.customerId;
    }

    setByField_(record, tgt, 'id', id);
    setByField_(record, tgt, 'waybill_no', d.waybillNo);
    setByField_(record, tgt, 'description', description);
    if (tgt.parcel_id >= 0) setByField_(record, tgt, 'parcel_id', d.waybillNo);
    setByField_(record, tgt, 'delivery_id', deliveryId);
    setByField_(record, tgt, 'direction_id', directionId);
    setByField_(record, tgt, 'city_id', cityId);
    setByField_(record, tgt, 'customer_id', customerId);
    if (tgt.company_id >= 0) setByField_(record, tgt, 'company_id', companyId);
    setByField_(record, tgt, 'cust_name_ignore', d.customerName);
    setByField_(record, tgt, 'approval', d.approval || link.approval || '');
    setByField_(record, tgt, 'approval_userid', d.approvalUserId || link.approval_userid || '');

    setByField_(record, tgt, 'product_invoice_number', resolveProductInvoiceNumber_(d, link, usedInvoices));
    // Sheet product_invoice_amount column may mean parcels total — write custInvR into waybill_items_total when present.
    if (tgt.waybill_items_total >= 0) {
      setByField_(record, tgt, 'waybill_items_total', d.custInvR);
    } else {
      setByField_(record, tgt, 'product_invoice_amount', d.custInvR);
    }

    setByField_(record, tgt, 'sad500_amount', parseNumber_(d.sad500));
    setByField_(record, tgt, 'sadc_amount', parseNumber_(d.sadc));
    setByField_(record, tgt, 'item_length', d.length);
    setByField_(record, tgt, 'item_width', d.width);
    setByField_(record, tgt, 'item_height', d.height);
    setByField_(record, tgt, 'total_mass_kg', d.tMass);
    setByField_(record, tgt, 'total_volume', d.tVol);
    setByField_(record, tgt, 'mass_charge', d.massCost);
    setByField_(record, tgt, 'volume_charge', d.volCost);
    // Prevent inverted basis (MASS with mass=0 / VOLUME with vol=0): clear so
    // PHP kit_seed_total_from_charge_basis uses max(mass, volume).
    setByField_(record, tgt, 'charge_basis', sanitizeChargeBasis_(d.basis, d.massCost, d.volCost));

    var vatRaw = d.vat;
    var legacyVat = String(vatRaw == null ? '' : vatRaw).trim().toUpperCase();
    var vatInclude = (legacyVat === 'SAD500' || legacyVat === 'SADC') ? false : parseBooleanFlexible_(vatRaw);
    setByField_(record, tgt, 'vat_include', vatInclude ? 1 : 0);
    setByField_(record, tgt, 'include_sad500', (legacyVat === 'SAD500' || parseBooleanFlexible_(d.sad500)) ? 1 : 0);
    setByField_(record, tgt, 'include_sadc', (legacyVat === 'SADC' || parseBooleanFlexible_(d.sadc)) ? 1 : 0);
    if (tgt.warehouse >= 0) {
      setByField_(record, tgt, 'warehouse', d.isWarehouse ? 1 : 0);
    }

    var tracking = d.tracking || link.tracking_number || '';
    setByField_(record, tgt, 'tracking_number', tracking || generateTrackingNumber_());
    setByField_(record, tgt, 'status', d.status || '');

    var custInvRef = resolveCustomerPurchaseInvoiceRef_(d);
    var misc = buildSerializedMiscellaneousCustomerInvoiceRef_(custInvRef);
    if (misc) {
      setByField_(record, tgt, 'miscellaneous', misc);
      setByField_(record, tgt, 'misc_total', 0);
    }

    var nowTs = currentMysqlTimestamp_();
    var auditUser = resolveWaybillAuditUser_(d, link, creatorMap);
    setByField_(record, tgt, 'created_at', d.dateReceived || link.created_at || nowTs);
    setByField_(record, tgt, 'created_by', auditUser);
    setByField_(record, tgt, 'last_updated_at', nowTs);
    setByField_(record, tgt, 'last_updated_by', auditUser);

    if (!cityId) {
      pushRowError_(rowErrors, d.sourceRowNum,
        'Could not resolve city_id for waybill ' + d.waybillNo +
        (d.cityName ? ' (city: ' + d.cityName + ')' : ''));
    }

    if (existing) {
      patches.push({ sheetRow: existing.sheetRow, values: record });
      summary.updated++;
    } else {
      appends.push(record);
      summary.appended++;
    }
  }

  applyUpserts_(sheet, patches, appends, headers.length);
  return { summary: summary };
}

/**
 * Build normalized creator display name → WP user id from Waybills rows + CFG.
 *
 * @param {Array} dtoRows
 * @return {Object.<string, number>}
 */
function buildCreatorNameToIdMap_(dtoRows) {
  var map = {};
  var cfg = CFG.waybillCreatorNameToId || {};
  var cfgKeys = Object.keys(cfg);
  for (var c = 0; c < cfgKeys.length; c++) {
    var cfgKey = normalizeName_(cfgKeys[c]);
    var cfgId = toPositiveId_(cfg[cfgKeys[c]]);
    if (cfgKey && cfgId !== '') {
      map[cfgKey] = cfgId;
    }
  }
  dtoRows = dtoRows || [];
  for (var r = 0; r < dtoRows.length; r++) {
    var d = dtoRows[r];
    var uid = toPositiveId_(d.createdById || '');
    var name = cleanText_(d.createdByName || '');
    if (uid === '' || !name) continue;
    map[normalizeName_(name)] = uid;
  }
  return map;
}

/**
 * Resolve a creator label to a numeric WP user id (never a display name).
 *
 * @param {string|number} label
 * @param {Object.<string, number>} creatorMap
 * @return {number|string} positive id or ''
 */
function lookupCreatorUserId_(label, creatorMap) {
  creatorMap = creatorMap || {};
  var id = toPositiveId_(label);
  if (id !== '') return id;

  var key = normalizeName_(label);
  if (!key) return '';
  if (creatorMap[key]) return creatorMap[key];

  var first = key.split(' ')[0];
  if (!first) return '';

  var matchedIds = {};
  var mapKeys = Object.keys(creatorMap);
  for (var i = 0; i < mapKeys.length; i++) {
    var mk = mapKeys[i];
    var mf = mk.split(' ')[0];
    if (mf === first) {
      matchedIds[creatorMap[mk]] = true;
    }
  }
  var ids = Object.keys(matchedIds);
  if (ids.length === 1) {
    return parseInt(ids[0], 10);
  }

  for (var j = 0; j < mapKeys.length; j++) {
    var mk2 = mapKeys[j];
    if (key.indexOf(mk2) === 0 || mk2.indexOf(key) === 0) {
      matchedIds[creatorMap[mk2]] = true;
    }
  }
  ids = Object.keys(matchedIds);
  return ids.length === 1 ? parseInt(ids[0], 10) : '';
}

/**
 * Numeric WP user id for kit_waybills created_by / last_updated_by.
 * Never writes display names into id columns.
 *
 * @param {Object} dto from readWaybillsSource_
 * @param {Object} link existing kit_waybills link fields
 * @param {Object.<string, number>} creatorMap
 * @return {number|string}
 */
function resolveWaybillAuditUser_(dto, link, creatorMap) {
  link = link || {};
  dto = dto || {};

  var fromDtoId = toPositiveId_(dto.createdById || '');
  if (fromDtoId !== '') return fromDtoId;

  var fromDtoName = lookupCreatorUserId_(dto.createdByName || '', creatorMap);
  if (fromDtoName !== '') return fromDtoName;

  var fromLinkId = toPositiveId_(link.created_by);
  if (fromLinkId !== '') return fromLinkId;

  var fromLinkName = lookupCreatorUserId_(link.created_by || '', creatorMap);
  if (fromLinkName !== '') return fromLinkName;

  var cfgUid = toPositiveId_(CFG.waybillSyncUserId || '');
  return cfgUid !== '' ? cfgUid : '';
}

function resolveProductInvoiceNumber_(dto, link, usedInvoices) {
  var mode = CFG.productInvoiceNumberMode || 'preserve';
  var clInv = '';
  if (CFG.productInvoiceUseWaybillsSheet) {
    clInv = cleanText_(dto.clInv);
  }
  var existing = cleanText_(link.product_invoice_number);

  if (mode === 'always_allocate') {
    return allocateProductInvoiceNumber_(dto.waybillNo);
  }
  if (mode === 'sheet_or_allocate') {
    if (clInv) return clInv;
    return allocateProductInvoiceNumber_(dto.waybillNo);
  }
  // preserve
  if (clInv) return clInv;
  if (existing) return existing;
  return allocateProductInvoiceNumber_(dto.waybillNo);
}

function resolveCustomerPurchaseInvoiceRef_(dto) {
  var ref = cleanText_(dto.clInv) || cleanText_(dto.clientInvoiceAa);
  if (!ref) return '';
  var low = ref.toLowerCase();
  if (['n/a', 'na', 'none', '-', '--', 'null', '0'].indexOf(low) >= 0) return '';
  return ref;
}

function phpSerializeString_(str) {
  var s = String(str);
  return 's:' + s.length + ':"' + s + '";';
}

function buildSerializedMiscellaneousCustomerInvoiceRef_(invoiceRef) {
  if (!invoiceRef) return '';
  // a:2:{s:10:"misc_items";a:0:{}s:6:"others";a:1:{s:14:"client_invoice";s:N:"...";}}
  var inner = phpSerializeString_(invoiceRef);
  var others = 'a:1:{s:14:"client_invoice";' + inner + '}';
  return 'a:2:{s:10:"misc_items";a:0:{}s:6:"others";' + others + '}';
}


// =============================================================================
// 40_Validate.gs
// =============================================================================

/**
 * Read-only validation report → sync_validation sheet.
 */

function runValidation() {
  return withLock_(function () {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var source = readWaybillsSource_(ss);
    var cityMap = buildCityNameToIdMap_(ss);
    var report = [];
    var counts = { pass: 0, warn: 0, error: 0 };

    for (var i = 0; i < source.rows.length; i++) {
      var d = source.rows[i];
      var issues = validateDto_(d, cityMap);
      var severity = 'pass';
      for (var j = 0; j < issues.length; j++) {
        if (issues[j].severity === 'error') severity = 'error';
        else if (issues[j].severity === 'warn' && severity !== 'error') severity = 'warn';
      }
      counts[severity]++;
      report.push([
        d.sourceRowNum,
        d.waybillNo,
        severity,
        issues.map(function (x) { return x.message; }).join(' | ') || 'OK',
        d.customerName,
        d.deliveryTripId || '',
        d.driverName,
        d.dispatchDate,
        d.cityName,
        d.basis
      ]);
    }

    // Also surface parse errors from source read
    for (var e = 0; e < source.errors.length; e++) {
      counts.error++;
      report.push([
        source.errors[e].row,
        '',
        'error',
        source.errors[e].message,
        '', '', '', '', '', ''
      ]);
    }

    writeValidationReport_(ss, report, counts);
    var summary = { counts: counts, rows: report.length };
    logSummary_(summary);
    return summary;
  });
}

function validateDto_(d, cityMap) {
  var issues = [];
  if (!d.waybillNo) {
    issues.push({ severity: 'error', message: 'Missing waybill number' });
  }
  if (!d.customerName) {
    issues.push({ severity: 'warn', message: 'Missing customer name' });
  } else if (isSuspiciousCustomerName_(d.customerName)) {
    issues.push({ severity: 'warn', message: 'Suspicious customer name' });
  }
  if (!d.isWarehouse && !(d.deliveryTripId > 0) && !d.preserveKitDeliveryId) {
    issues.push({ severity: 'warn', message: 'Missing Delivery trip id (confirmation list / existing F)' });
  }
  if (!d.dispatchDate && !d.isWarehouse) {
    issues.push({ severity: 'warn', message: 'Missing Dispatch Date (col C)' });
  }
  var cityId = d.cityId || lookupCityIdByName_(cityMap, d.cityName);
  if (!cityId) {
    issues.push({ severity: 'warn', message: 'Unresolved city' });
  }
  var mass = d.massCost === '' ? 0 : Number(d.massCost);
  var vol = d.volCost === '' ? 0 : Number(d.volCost);
  var basis = String(d.basis || '').toLowerCase();
  if ((basis === 'mass' || basis === 'weight') && mass === 0 && vol > 0) {
    issues.push({
      severity: 'warn',
      message: 'charge_basis=mass but mass_charge is 0 while volume_charge > 0 (cleared to empty on project)'
    });
  }
  if (basis === 'volume' && vol === 0 && mass > 0) {
    issues.push({
      severity: 'warn',
      message: 'charge_basis=volume but volume_charge is 0 while mass_charge > 0 (cleared to empty on project)'
    });
  }
  return issues;
}

/**
 * Empty / auto stay empty. Inverted MASS/VOLUME (zero on selected side, money on
 * the other) is cleared so seed uses max(mass, volume) instead of billing 0.
 */
function sanitizeChargeBasis_(basis, massCost, volCost) {
  var raw = String(basis == null ? '' : basis).trim();
  if (raw === '') {
    return '';
  }
  var b = raw.toLowerCase();
  if (b === 'auto' || b === 'max' || b === 'higher' || b === 'best') {
    return '';
  }
  var mass = massCost === '' || massCost == null ? 0 : Number(massCost);
  var vol = volCost === '' || volCost == null ? 0 : Number(volCost);
  if (isNaN(mass)) mass = 0;
  if (isNaN(vol)) vol = 0;
  var isMass = b.indexOf('mass') === 0 || b.indexOf('weight') === 0 || b === 'w' || b === 'kg';
  var isVol = b.indexOf('vol') === 0 || b === 'v' || b === 'cbm' || b === 'm3';
  if (isMass && mass <= 0 && vol > 0) {
    return '';
  }
  if (isVol && vol <= 0 && mass > 0) {
    return '';
  }
  return raw;
}

function writeValidationReport_(ss, report, counts) {
  var sheet = ss.getSheetByName(CFG.validationSheetName);
  if (!sheet) {
    sheet = ss.insertSheet(CFG.validationSheetName);
  }
  sheet.clearContents();
  var header = [
    'source_row', 'waybill_no', 'severity', 'messages', 'customer',
    'delivery_trip_id', 'driver', 'dispatch_date', 'city', 'charge_basis'
  ];
  var summaryRow = [
    'pass=' + counts.pass,
    'warn=' + counts.warn,
    'error=' + counts.error,
    '', '', '', '', '', '', ''
  ];
  var rows = [summaryRow, header].concat(report);
  ensureSheetRows_(sheet, rows.length);
  sheet.getRange(1, 1, rows.length, header.length).setValues(rows);
  trimTrailingEmptyRows_(sheet, 0);
}

function installValidationTrigger() {
  uninstallValidationTrigger();
  ScriptApp.newTrigger('runValidation').timeBased().everyMinutes(5).create();
  Logger.log('Installed 5-minute validation trigger.');
}

function uninstallValidationTrigger() {
  var triggers = ScriptApp.getProjectTriggers();
  for (var i = 0; i < triggers.length; i++) {
    var t = triggers[i];
    var fn = t.getHandlerFunction();
    if (fn === 'runValidation' || fn === 'syncAllWaybillData' || fn === 'runFullProjection') {
      // Only remove validation time triggers here; sync triggers removed separately in cutover.
      if (fn === 'runValidation') {
        ScriptApp.deleteTrigger(t);
      }
    }
  }
  Logger.log('Uninstalled validation triggers.');
}

/**
 * Disable leftover installable triggers from the old Code.gs monolith.
 * Run from the Waybill Projection menu, or it self-runs when a stub
 * such as syncKitWaybills is invoked by an old onChange trigger.
 */
function uninstallLegacySyncTriggers() {
  var legacy = {
    syncAllWaybillData: 1,
    syncKitWaybills: 1,
    syncKitWaybillsOnly: 1,
    syncCustomersFromKitWaybills: 1,
    syncKitDriversOnly: 1,
    syncKitDeliveriesOnly: 1,
    syncNewCustomersFromWaybills: 1,
    rebuildKitCustomersFromWaybills: 1,
    repairKitCustomersFromKitWaybills: 1
  };
  var triggers = ScriptApp.getProjectTriggers();
  var removed = 0;
  for (var i = 0; i < triggers.length; i++) {
    var fn = triggers[i].getHandlerFunction();
    if (legacy[fn]) {
      ScriptApp.deleteTrigger(triggers[i]);
      removed++;
    }
  }
  Logger.log('Removed ' + removed + ' legacy sync trigger(s).');
  return removed;
}


// =============================================================================
// 01_Main.gs
// =============================================================================

/**
 * Entry points + orchestration.
 * Order: lock → read Waybills → drivers → deliveries → persons/companies → waybills → errors.
 */

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('Waybill Projection')
    .addItem('Run Full Projection', 'runFullProjection')
    .addItem('Run Drivers Only', 'runDriversOnly')
    .addItem('Run Deliveries Only', 'runDeliveriesOnly')
    .addItem('Run Customers Only', 'runCustomersOnly')
    .addItem('Run Waybills Only', 'runWaybillsOnly')
    .addSeparator()
    .addItem('Wipe kit_* sheets (keep headers)', 'wipeKitProjectionSheets')
    .addItem('Restore trip_membership (IMPORTRANGE)', 'restoreTripMembershipSheet')
    .addSeparator()
    .addItem('Run Validation Now', 'runValidation')
    .addItem('Install Validation Trigger (5-min)', 'installValidationTrigger')
    .addItem('Uninstall Validation Trigger', 'uninstallValidationTrigger')
    .addItem('Uninstall Legacy Sync Triggers', 'uninstallLegacySyncTriggers')
    .addToUi();
}

/**
 * Wipe projection / sync kit_* sheets: keep row-1 headers, clear data rows.
 * Leaves alone: Waybills, company details, directions, countries, cities, rates.
 */
function wipeKitProjectionSheets() {
  var names = filterWipeSheetNames_(CFG.wipeSheetNames || []);
  var protectedNames = CFG.wipeProtectedSheetNames || [];
  var ui = SpreadsheetApp.getUi();
  var confirm = ui.alert(
    'Wipe kit_* + sync sheets?',
    'Clears DATA rows, keeps headers, and shrinks each tab to header + ' +
      (CFG.wipeKeepEmptyRows || 5) + ' empty rows on:\n\n' + names.join('\n') +
      '\n\nLEFT ALONE:\n' + protectedNames.join('\n') +
      '\n\nContinue?',
    ui.ButtonSet.OK_CANCEL
  );
  if (confirm !== ui.Button.OK) {
    return { cancelled: true };
  }

  return withLock_(function () {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var summary = wipeKitSheets_(ss, names);
    SpreadsheetApp.flush();
    logSummary_(summary);
    ui.alert(
      'Wipe complete',
      'Wiped: ' + (summary.wiped.join(', ') || '(none)') +
        (summary.skipped_protected.length ? '\nSkipped (protected): ' + summary.skipped_protected.join(', ') : '') +
        (summary.missing.length ? '\nMissing: ' + summary.missing.join(', ') : '') +
        (summary.errors.length ? '\nErrors: ' + summary.errors.join(' | ') : ''),
      ui.ButtonSet.OK
    );
    return summary;
  });
}

/** Drop any protected names from a wipe list (case-insensitive). */
function filterWipeSheetNames_(sheetNames) {
  var protectedMap = {};
  var protectedNames = CFG.wipeProtectedSheetNames || [];
  for (var p = 0; p < protectedNames.length; p++) {
    protectedMap[String(protectedNames[p]).toLowerCase()] = true;
  }
  var out = [];
  for (var i = 0; i < sheetNames.length; i++) {
    var name = sheetNames[i];
    if (!name) continue;
    if (protectedMap[String(name).toLowerCase()]) continue;
    out.push(name);
  }
  return out;
}

/**
 * @param {SpreadsheetApp.Spreadsheet} ss
 * @param {string[]} sheetNames
 * @returns {{wiped:string[], missing:string[], errors:string[], skipped_protected:string[], cleared_rows:number}}
 */
function wipeKitSheets_(ss, sheetNames) {
  var summary = {
    wiped: [],
    missing: [],
    errors: [],
    skipped_protected: [],
    cleared_rows: 0
  };
  var protectedMap = {};
  var protectedNames = CFG.wipeProtectedSheetNames || [];
  for (var p = 0; p < protectedNames.length; p++) {
    protectedMap[String(protectedNames[p]).toLowerCase()] = true;
  }

  for (var i = 0; i < sheetNames.length; i++) {
    var name = sheetNames[i];
    if (protectedMap[String(name).toLowerCase()]) {
      summary.skipped_protected.push(name);
      continue;
    }
    var sheet = findSheetByName_(ss, name);
    if (!sheet) {
      summary.missing.push(name);
      continue;
    }
    try {
      var cleared = wipeSheetDataKeepHeader_(sheet);
      summary.cleared_rows += cleared;
      summary.wiped.push(name + '(' + cleared + ')');
    } catch (e) {
      summary.errors.push(name + ': ' + ((e && e.message) || e));
    }
  }
  return summary;
}

/**
 * Clear data below the header, then shrink the grid to header + a few empty rows.
 * Projection inserts more rows when it writes.
 * @returns {number} data rows cleared
 */
function wipeSheetDataKeepHeader_(sheet) {
  assertNotSourceWaybillsWrite_(sheet, 'wipe');
  unlockSheetGrid_(sheet);
  var keepEmpty = CFG.wipeKeepEmptyRows || 5;
  var keepTotal = 1 + keepEmpty;
  var name = sheet.getName();
  var lastRow = Math.max(sheet.getLastRow(), sheet.getMaxRows());
  var lastCol = Math.max(sheet.getLastColumn(), 1);

  // Log/report tabs: clear everything, then put headers back so wipe leaves a blank log.
  if (name === CFG.errorSheetName || name === CFG.validationSheetName) {
    sheetsCall_(function () { sheet.clearContents(); }, name + ' wipe all');
    shrinkSheetToRowCount_(sheet, keepTotal);
    if (name === CFG.errorSheetName) {
      ensureSheetRows_(sheet, 1);
      sheetsCall_(function () {
        sheet.getRange(1, 1, 1, 3).setValues([['source_row', 'message', 'logged_at']]);
      }, 'sync_errors header');
    }
    return Math.max(0, lastRow);
  }

  // Preserve row 1 as headers when present.
  var header = sheetsCall_(function () {
    return sheet.getRange(1, 1, 1, lastCol).getValues()[0];
  }, sheet.getName() + ' wipe header');

  var hasHeader = false;
  for (var c = 0; c < header.length; c++) {
    if (cleanText_(header[c]) !== '') {
      hasHeader = true;
      break;
    }
  }

  if (!hasHeader) {
    sheetsCall_(function () { sheet.clearContents(); }, sheet.getName() + ' wipe all');
  } else if (sheet.getMaxRows() >= 2) {
    var dataRows = sheet.getMaxRows() - 1;
    sheetsCall_(function () {
      sheet.getRange(2, 1, dataRows, lastCol).clearContent();
    }, sheet.getName() + ' wipe data');
  }

  shrinkSheetToRowCount_(sheet, keepTotal);
  return Math.max(0, lastRow - 1);
}

/**
 * Tables / filters pin the old row count. deleteRows cannot shrink them.
 * Convert to a plain grid first so wipe can drop empty rows.
 */
function unlockSheetGrid_(sheet) {
  try {
    var filter = sheet.getFilter();
    if (filter) filter.remove();
  } catch (e1) {}
  if (typeof sheet.getTables === 'function') {
    var tables = sheet.getTables() || [];
    for (var t = tables.length - 1; t >= 0; t--) {
      try { tables[t].remove(); } catch (e2) {}
    }
  }
  try {
    var bandings = sheet.getBandings() || [];
    for (var b = 0; b < bandings.length; b++) {
      try { bandings[b].remove(); } catch (e3) {}
    }
  } catch (e4) {}
}

/** Keep the first `keepTotal` rows; delete the rest or insert blanks to reach that size. */
function shrinkSheetToRowCount_(sheet, keepTotal) {
  keepTotal = Math.max(1, Number(keepTotal) || 1);
  unlockSheetGrid_(sheet);
  var maxRows = sheet.getMaxRows();
  if (maxRows > keepTotal) {
    sheetsCall_(function () {
      sheet.deleteRows(keepTotal + 1, maxRows - keepTotal);
    }, sheet.getName() + ' shrink extra rows');
  } else if (maxRows < keepTotal) {
    sheetsCall_(function () {
      sheet.insertRowsAfter(maxRows, keepTotal - maxRows);
    }, sheet.getName() + ' pad empty rows');
  }
}

/** Drop blank rows below the last cell with content. */
function trimTrailingEmptyRows_(sheet, extraBlank) {
  extraBlank = extraBlank == null ? 0 : Number(extraBlank);
  var lastFilled = Math.max(sheet.getLastRow(), 1);
  shrinkSheetToRowCount_(sheet, lastFilled + extraBlank);
}

function runFullProjection() {
  var startedAt = new Date();
  var summary = {
    run_started_at: startedAt.toISOString(),
    drivers: {},
    deliveries: {},
    customers: {},
    waybills: {},
    membership: {},
    row_errors: 0,
    duration_ms: 0
  };

  withLock_(function () {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var source = readWaybillsSource_(ss);
    var rowErrors = source.errors.slice();
    summary.membership = source.membership || {};

    var drivers = stageDrivers_(ss, source.rows);
    summary.drivers = drivers.summary;
    SpreadsheetApp.flush();

    var deliveries = stageDeliveries_(ss, source.rows, drivers.nameToId);
    summary.deliveries = deliveries.summary;
    SpreadsheetApp.flush();

    var customers = stageCustomers_(ss, source.rows);
    summary.customers = customers.summary;
    SpreadsheetApp.flush();

    var waybills = stageWaybills_(ss, source.rows, {
      custKeyToId: customers.custKeyToId,
      companyKeyToId: customers.companyKeyToId
    }, rowErrors);
    summary.waybills = waybills.summary;
    auditTripMembershipAgainstKitWaybills_(ss, source.membership, rowErrors);
    if (summary.membership && summary.membership.membership) {
      delete summary.membership.membership;
    }

    summary.row_errors = rowErrors.length;
    writeRowErrors_(ss, rowErrors);
    SpreadsheetApp.flush();
  });

  summary.duration_ms = new Date().getTime() - startedAt.getTime();
  summary.run_finished_at = new Date().toISOString();
  logSummary_(summary);
  return summary;
}

function runDriversOnly() {
  return withLock_(function () {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var source = readWaybillsSource_(ss);
    var result = stageDrivers_(ss, source.rows);
    writeRowErrors_(ss, source.errors);
    logSummary_(result.summary);
    return result.summary;
  });
}

function runDeliveriesOnly() {
  return withLock_(function () {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var source = readWaybillsSource_(ss);
    var drivers = stageDrivers_(ss, source.rows);
    var result = stageDeliveries_(ss, source.rows, drivers.nameToId);
    writeRowErrors_(ss, source.errors);
    logSummary_({ drivers: drivers.summary, deliveries: result.summary });
    return result.summary;
  });
}

function runCustomersOnly() {
  return withLock_(function () {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var boot = ensureSheetWithHeaders_(ss, CFG.customersSheetName, kitCustomersCanonicalHeaders_());
    alignKitCustomersHeaders_(boot.sheet, boot.data);
    var source = readWaybillsSource_(ss);
    var customers = stageCustomers_(ss, source.rows);
    var coerced = coerceKitCustomersCompanyIds_(ss);
    var summary = {
      customers: customers.summary,
      company_ids: coerced
    };
    writeRowErrors_(ss, source.errors);
    return summary;
  });
}

function runWaybillsOnly() {
  return withLock_(function () {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var source = readWaybillsSource_(ss);
    var customers = stageCustomers_(ss, source.rows);
    var rowErrors = source.errors.slice();
    var result = stageWaybills_(ss, source.rows, {
      custKeyToId: customers.custKeyToId,
      companyKeyToId: customers.companyKeyToId
    }, rowErrors);
    writeRowErrors_(ss, rowErrors);
    auditTripMembershipAgainstKitWaybills_(ss, source.membership, rowErrors);
    writeRowErrors_(ss, rowErrors);
    logSummary_({ customers: customers.summary, waybills: result.summary, membership: source.membership });
  });
}

/** Backward-compatible alias if an old menu still points here after partial deploy. */
function syncAllWaybillData() {
  return runFullProjection();
}

/**
 * Leftover installable triggers from the old monolith still fire on sheet
 * change and email "Script function not found". These stubs exist so the
 * next fire can delete those triggers. They do not run a projection —
 * use Waybill Projection → Run Full Projection.
 */
function retireLegacySyncTrigger_(fnName) {
  var removed = 0;
  try {
    removed = uninstallLegacySyncTriggers();
  } catch (e) {
    Logger.log((fnName || 'legacy') + ': could not uninstall triggers: ' + ((e && e.message) || e));
  }
  Logger.log((fnName || 'legacy') + ': retired. Removed ' + removed + ' leftover trigger(s).');
  return { retired: true, function: fnName || '', triggers_removed: removed };
}

function syncKitWaybills() {
  return retireLegacySyncTrigger_('syncKitWaybills');
}

function syncKitWaybillsOnly() {
  return retireLegacySyncTrigger_('syncKitWaybillsOnly');
}

function syncCustomersFromKitWaybills() {
  return retireLegacySyncTrigger_('syncCustomersFromKitWaybills');
}

function syncKitDriversOnly() {
  return retireLegacySyncTrigger_('syncKitDriversOnly');
}

function syncKitDeliveriesOnly() {
  return retireLegacySyncTrigger_('syncKitDeliveriesOnly');
}

function syncNewCustomersFromWaybills() {
  return retireLegacySyncTrigger_('syncNewCustomersFromWaybills');
}

function rebuildKitCustomersFromWaybills() {
  return retireLegacySyncTrigger_('rebuildKitCustomersFromWaybills');
}

function repairKitCustomersFromKitWaybills() {
  return retireLegacySyncTrigger_('repairKitCustomersFromKitWaybills');
}

