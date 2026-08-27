<?php

if (!defined('ABSPATH')) {
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Absolute filesystem path to plugin root (trailing slash).
 */
function kit_waybill_pdf_plugin_root(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'kit-waybill-pdf-invoice.php';

/**
 * Build waybill PDF bytes via the active invoice HTML template + Dompdf.
 *
 * @throws RuntimeException When vendor, waybill data, or render fails.
 */
function kit_waybill_pdf_generate_binary(int $waybill_no): string
{
    $plugin_root = kit_waybill_pdf_plugin_root();

    $vendor_autoload = $plugin_root . 'vendor/autoload.php';
    if (!class_exists(Dompdf::class)) {
        if (!file_exists($vendor_autoload)) {
            throw new RuntimeException('Vendor autoload not found. Run composer install.');
        }
        require_once $vendor_autoload;
    }

    $html = kit_waybill_pdf_render_invoice_html($waybill_no);

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('isFontSubsettingEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('isPhpEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $out = $dompdf->output();
    if (!is_string($out) || $out === '') {
        throw new RuntimeException('PDF generation returned empty output.');
    }

    return $out;
}
