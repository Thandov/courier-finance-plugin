=== 08600 Services and Quotations ===
Contributors: kayiseit
Tags: logistics, quotations, waybill, delivery, invoicing
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 3.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A logistics and quotations management plugin for creating waybills, deliveries, and customer quotations.

== Description ==
08600 Services and Quotations helps logistics businesses manage services, customers, quotations, waybills, and deliveries from the WordPress admin.

Key features:
- Create and manage waybills and deliveries
- Generate customer quotations and PDFs
- Roles and permissions for pricing visibility
- Admin pages for services, warehouse tracking, and more

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Access the 08600 menu in the WordPress admin to begin configuration.

== Frequently Asked Questions ==
= Does this plugin create database tables? =
Yes. On activation, it creates required tables for services, waybills, deliveries, and quotations if they don't exist.

= Does it work with PHP 8+? =
Yes, it is tested with PHP 8.0 and above.

== Screenshots ==
1. Admin dashboard modules
2. Waybill management
3. Quotation preview and PDF

== Changelog ==
= 3.2.0 =
- Customer portal (login, register, booking, dashboard, profile)
- Google Sheets seed/sync pipeline with charge-basis totals and verification
- Booking requests, sync runs admin, waybill view/edit templates and PDF updates
- Admin menu nesting, security hardening, customer merge/near-duplicate guards

= 3.1.0 =
- Version bump to 3.1.0

= 2.0.0 =
- Major release: stability improvements and UI refinements
- Updated PDF generation and admin pages

= 1.0.0 =
- Initial release

== Upgrade Notice ==
= 3.2.0 =
Adds customer portal, seed/sync pipeline, and waybill template updates. Back up your site before updating.

= 3.1.0 =
Maintenance release. Back up your site before updating.

= 2.0.0 =
This is a major update with improvements to quotations and waybill management. Please back up your database before upgrading.
