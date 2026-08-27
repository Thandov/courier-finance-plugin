<?php

/**
 * PDF Generator for Waybills/Quotations (HTTP stream).
 * Core generation: includes/pdf/kit-waybill-pdf-binary.php
 */

// Bootstrap WordPress if accessed directly
if (! defined('ABSPATH')) {
  // Try multiple possible paths to find WordPress root
  $possible_paths = [
    dirname(__FILE__, 4) . '/wp-load.php',  // Plugin -> wp-content -> plugins -> root
    dirname(__FILE__, 3) . '/wp-load.php',  // Plugin -> wp-content -> root
    dirname(__FILE__, 2) . '/wp-load.php',  // Plugin -> root
  ];

  $wp_load_found = false;

  foreach ($possible_paths as $path) {
    if (file_exists($path)) {
      // Suppress errors during WordPress loading
      $old_error_reporting = error_reporting();
      error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);

      require_once $path;

      // Restore error reporting
      error_reporting($old_error_reporting);
      $wp_load_found = true;
      break;
    }
  }

  if (!$wp_load_found) {
    die('WordPress not found. Please ensure the plugin is installed in the correct directory.');
  }
}

$nonce = isset($_GET['pdf_nonce']) ? sanitize_text_field($_GET['pdf_nonce']) : '';
if (! wp_verify_nonce($nonce, 'pdf_nonce')) {
  wp_die('Invalid request', 403);
}

$waybill_no = isset($_GET['waybill_no']) ? intval($_GET['waybill_no']) : 0;
if (! $waybill_no) {
  wp_die('Missing waybill_no');
}

// Ensure user is logged in and has proper session
if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
  wp_die('You must be logged in to access PDFs. Please log in and try again.', 403);
}

// 🔒 SECURITY: Only authorized administrators (Thando, Mel, Patricia) can access PDFs
if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::can_see_prices()) {
  $current_user = wp_get_current_user();
  $username = isset($current_user->user_login) ? strtolower($current_user->user_login) : 'not logged in';
  wp_die('Access denied. PDF access is restricted to authorized administrators only. Current user: ' . $username, 403);
}

// Autoload dompdf (helper skips re-load if already present)
$vendor_autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($vendor_autoload)) {
  wp_die('Error: Vendor autoload file not found. Please run composer install.', 500);
}
require_once $vendor_autoload;

require_once __DIR__ . '/includes/pdf/kit-waybill-pdf-binary.php';

try {
  $binary = kit_waybill_pdf_generate_binary($waybill_no);
} catch (Throwable $e) {
  $msg = $e->getMessage();
  $response = (stripos($msg, 'not found') !== false) ? 404 : 500;
  wp_die(esc_html($msg), 'PDF', ['response' => $response]);
}

if (!headers_sent()) {
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="waybill-' . esc_attr($waybill_no) . '.pdf"');
  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Pragma: public');
  header('X-Content-Type-Options: nosniff');
  if (function_exists('is_ssl') && (is_ssl() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))) {
    header('Strict-Transport-Security: max-age=31536000');
  }
}

echo $binary;
exit;
