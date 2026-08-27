Google Sheets API – Service Account setup
===========================================

1. Go to https://console.cloud.google.com/ and create or select a project.
2. Enable "Google Sheets API" (APIs & Services → Library → search "Google Sheets API").
3. Create a Service Account: APIs & Services → Credentials → Create Credentials → Service Account.
   Download the JSON key and save it here as: google-service-account.json
   (This file is ignored by git. Do not commit it.)
4. Share your Google Sheet with the service account email
   (e.g. something@your-project.iam.gserviceaccount.com).
   Use Editor access if you want driver sync (Add Driver → append to sheet); Viewer is enough for seed-only.
5. Optional: In wp-config.php you can set:
   define('COURIER_GOOGLE_CREDENTIALS_PATH', '/full/path/to/google-service-account.json');
   define('COURIER_GOOGLE_SPREADSHEET_ID', '1w-9PfeN198UoLp-LO-ZFUYYjWiewuIfsp9r-2lY_Xec');
   define('COURIER_GOOGLE_DRIVERS_SHEET', 'kit_drivers');       // optional; default: kit_drivers
   define('COURIER_GOOGLE_WAYBILLS_SHEET', 'kit_waybills');     // optional; default: kit_waybills
   define('COURIER_GOOGLE_WAYBILL_ITEMS_SHEET', 'kit_waybill_items');  // optional; default: kit_waybill_items
   define('COURIER_GOOGLE_CUSTOMERS_SHEET', 'kit_customers');   // optional; default: kit_customers
   define('COURIER_GOOGLE_DELIVERIES_SHEET', 'kit_deliveries'); // optional; default: kit_deliveries
   If not set, the plugin uses credentials/google-service-account.json and the 08600 waybills spreadsheet by default.

Production (e.g. www.08600africa.com) – Sheet not connecting
-----------------------------------------------------------
The credentials JSON is not included in the plugin zip (it is in .gitignore). On the live server:

A) Upload the JSON file:
   - Upload google-service-account.json to the server (e.g. into this plugin's credentials/ folder via FTP/SFTP),
   - Then in wp-config.php add:
     define('COURIER_GOOGLE_CREDENTIALS_PATH', '/absolute/path/to/wp-content/plugins/courier-finance-plugin/credentials/google-service-account.json');
   Replace with the real absolute path on your server (ask your host or use a file manager to see it).

B) Or put the file outside the web root (more secure) and point to it:
   define('COURIER_GOOGLE_CREDENTIALS_PATH', '/home/youruser/private/google-service-account.json');

C) Ensure the file is readable by the web server (e.g. chmod 640 and correct owner).
D) Share the Google Sheet with the service account email from the JSON (client_email field).
E) Reload the Google Sheets Test page in WP Admin to verify connection.

DB-to-Sheet sync: Add/Update/Delete for Drivers, Waybills, Waybill Items, Customers, and Deliveries are synced
   to kit_drivers, kit_waybills, kit_waybill_items, kit_customers, kit_deliveries tabs. Use constants to override.

kit_deliveries tab (pull/push column layout)
-------------------------------------------
Eleven columns: id | del_id | delivery_reference | direction_id | destination_city_id | dispatch_date |
truck_number | driver_id | status | created_by | created_at

Pull sync matches delivery_reference in column C (or legacy column B). Rows need a reference like DEL-20260323-001
or they are skipped.

Google Sheets formula (DEL-YYYYMMDD-###; uses dispatch_date column F when valid, else today; avoids DEL-18991230
when F is empty — Sheets treats blanks as date 0 → 1899-12-30). Paste into kit_deliveries cell C2, fill down:

   ="DEL-"&TEXT(IF(ISBLANK(F2),TODAY(),IF(AND(ISNUMBER(F2),F2<1),TODAY(),IF(ISTEXT(F2),IFERROR(DATEVALUE(F2),TODAY()),F2))),"yyyymmdd")&"-"&TEXT(ROWS($C$2:C2),"000")

Simpler (today only, no column F): ="DEL-"&TEXT(TODAY(),"yyyymmdd")&"-"&TEXT(ROWS($C$2:C2),"000")

In PHP the canonical string is: Courier_Google_Sheets_Sync::get_delivery_reference_sheet_formula()

Setup Seed / KIT_Seed_Pipeline (WP): reads kit_* tabs only (not Waybills). Order: drivers → deliveries →
customers → waybills. Rows on kit_deliveries with a valid delivery_reference (DEL-YYYYMMDD-### exactly 3
digits, or pending) are imported before waybills. Waybill seeding must NOT create deliveries — it only
references trips already on kit_deliveries / in the DB.

Apps Script (apps-script/): sole Waybills → kit_* sheet writer. DB→sheet push_all for drivers/customers/
deliveries/waybills is blocked while COURIER_APPS_SCRIPT_OWNS_KIT_SHEETS is true (default).

Usage in code:
  $rows = Courier_Google_Sheets::get_values('', 'Sheet1!A1:Z100');
  if (Courier_Google_Sheets::is_configured()) { ... }
