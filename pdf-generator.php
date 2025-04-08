<?php

/**
 * PDF Generator for Quotations
 */

// Exit if accessed directly
if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4) . '/'); // Adjust the path as needed
}


require_once __DIR__ . '/vendor/autoload.php';
require_once(ABSPATH . 'wp-load.php');

if (!isset($_GET['quotation_id']) || !current_user_can('manage_options')) {
    wp_die('Invalid request');
}

$quotation_id = intval($_GET['quotation_id']);
global $wpdb;
$table_name = $wpdb->prefix . 'kit_quotations';

$quotation = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $quotation_id)
);

// Get the image file content and convert it to Base64
$imagePath = 'icons/pin.png';
$imageData = file_get_contents($imagePath);
$pin = 'data:image/png;base64,' . base64_encode($imageData);

$emailPath = 'icons/email.png';
$imageData = file_get_contents($emailPath);
$email = 'data:image/png;base64,' . base64_encode($imageData);

$webPath = 'icons/web.png';
$imageData = file_get_contents($webPath);
$web = 'data:image/png;base64,' . base64_encode($imageData);

$contactPath = 'icons/contact.png';
$imageData = file_get_contents($contactPath);
$contact = 'data:image/png;base64,' . base64_encode($imageData);

if (!$quotation) {
    wp_die('Quotation not found');
}

$our_details = (object)[
    "name" => "Standard Bank",
    "contact" => 1244253464576,
    "email" => "info@08600africa.co.za",
    "Addess" => "00000",
    "VAT" => "00000",
];

$payment_details = (object)[
    "bank_name" => "Standard Bank",
    "account" => 1244253464576,
    "branch" => "00000"
];

// Create PDF content
ob_start();
?>
<!DOCTYPE html>
<html style="margin:0">

<head>
    <style>
        /* Variable Font (covers all weights dynamically) */
        @font-face {
            font-family: 'Inter';
            src: url('Inter/Inter-VariableFont_opsz,wght.ttf') format('truetype');
            font-weight: 100 900;
            font-style: normal;
        }

        /* Italic Variable Font (if needed) */
        @font-face {
            font-family: 'Inter';
            src: url('Inter/Inter-Italic-VariableFont_opsz,wght.ttf') format('truetype');
            font-weight: 100 900;
            font-style: italic;
        }

        /* Static Fallbacks (only critical weights) */
        @font-face {
            font-family: 'Inter';
            src: url('Inter/static/Inter_18pt-Regular.ttf') format('truetype');
            font-weight: 400;
        }

        @font-face {
            font-family: 'Inter';
            src: url('Inter/static/Inter_18pt-Bold.ttf') format('truetype');
            font-weight: 700;
        }

        /* Apply font to your document */
        body {
            font-family: 'Inter', sans-serif;
        }

        .page {
            height: 297mm;
            /* A4 page height in portrait */
            width: 210mm;
            page-break-after: always;
            /* if needed */
        }

        *,
        ::before,
        ::after {
            --tw-border-spacing-x: 0;
            --tw-border-spacing-y: 0;
            --tw-translate-x: 0;
            --tw-translate-y: 0;
            --tw-rotate: 0;
            --tw-skew-x: 0;
            --tw-skew-y: 0;
            --tw-scale-x: 1;
            --tw-scale-y: 1;
            --tw-pan-x: ;
            --tw-pan-y: ;
            --tw-pinch-zoom: ;
            --tw-scroll-snap-strictness: proximity;
            --tw-gradient-from-position: ;
            --tw-gradient-via-position: ;
            --tw-gradient-to-position: ;
            --tw-ordinal: ;
            --tw-slashed-zero: ;
            --tw-numeric-figure: ;
            --tw-numeric-spacing: ;
            --tw-numeric-fraction: ;
            --tw-ring-inset: ;
            --tw-ring-offset-width: 0px;
            --tw-ring-offset-color: #fff;
            --tw-ring-color: rgb(59 130 246 / 0.5);
            --tw-ring-offset-shadow: 0 0 #0000;
            --tw-ring-shadow: 0 0 #0000;
            --tw-shadow: 0 0 #0000;
            --tw-shadow-colored: 0 0 #0000;
            --tw-blur: ;
            --tw-brightness: ;
            --tw-contrast: ;
            --tw-grayscale: ;
            --tw-hue-rotate: ;
            --tw-invert: ;
            --tw-saturate: ;
            --tw-sepia: ;
            --tw-drop-shadow: ;
            --tw-backdrop-blur: ;
            --tw-backdrop-brightness: ;
            --tw-backdrop-contrast: ;
            --tw-backdrop-grayscale: ;
            --tw-backdrop-hue-rotate: ;
            --tw-backdrop-invert: ;
            --tw-backdrop-opacity: ;
            --tw-backdrop-saturate: ;
            --tw-backdrop-sepia: ;
            --tw-contain-size: ;
            --tw-contain-layout: ;
            --tw-contain-paint: ;
            --tw-contain-style: ;
        }

        ::backdrop {
            --tw-border-spacing-x: 0;
            --tw-border-spacing-y: 0;
            --tw-translate-x: 0;
            --tw-translate-y: 0;
            --tw-rotate: 0;
            --tw-skew-x: 0;
            --tw-skew-y: 0;
            --tw-scale-x: 1;
            --tw-scale-y: 1;
            --tw-pan-x: ;
            --tw-pan-y: ;
            --tw-pinch-zoom: ;
            --tw-scroll-snap-strictness: proximity;
            --tw-gradient-from-position: ;
            --tw-gradient-via-position: ;
            --tw-gradient-to-position: ;
            --tw-ordinal: ;
            --tw-slashed-zero: ;
            --tw-numeric-figure: ;
            --tw-numeric-spacing: ;
            --tw-numeric-fraction: ;
            --tw-ring-inset: ;
            --tw-ring-offset-width: 0px;
            --tw-ring-offset-color: #fff;
            --tw-ring-color: rgb(59 130 246 / 0.5);
            --tw-ring-offset-shadow: 0 0 #0000;
            --tw-ring-shadow: 0 0 #0000;
            --tw-shadow: 0 0 #0000;
            --tw-shadow-colored: 0 0 #0000;
            --tw-blur: ;
            --tw-brightness: ;
            --tw-contrast: ;
            --tw-grayscale: ;
            --tw-hue-rotate: ;
            --tw-invert: ;
            --tw-saturate: ;
            --tw-sepia: ;
            --tw-drop-shadow: ;
            --tw-backdrop-blur: ;
            --tw-backdrop-brightness: ;
            --tw-backdrop-contrast: ;
            --tw-backdrop-grayscale: ;
            --tw-backdrop-hue-rotate: ;
            --tw-backdrop-invert: ;
            --tw-backdrop-opacity: ;
            --tw-backdrop-saturate: ;
            --tw-backdrop-sepia: ;
            --tw-contain-size: ;
            --tw-contain-layout: ;
            --tw-contain-paint: ;
            --tw-contain-style: ;
        }

        .text-white {
            --tw-text-opacity: 1;
            color: rgb(255 255 255 / var(--tw-text-opacity, 1));
        }

        .text-gray-600 {
            --tw-text-opacity: 1;
            color: rgb(75 85 99 / var(--tw-text-opacity, 1));
        }

        .bg-gradient-to-r {
            background-image: linear-gradient(to right, var(--tw-gradient-stops));
        }

        .from-blue-600 {
            --tw-gradient-from: #2563eb var(--tw-gradient-from-position);
            --tw-gradient-to: rgb(37 99 235 / 0) var(--tw-gradient-to-position);
            --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to);
        }

        .to-blue-400 {
            --tw-gradient-to: #60a5fa var(--tw-gradient-to-position);
        }


        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .section {
            margin-bottom: 25px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th,
        td {
            padding: 5px;
            text-align: left;
        }

        table.foottable,
        table.foottable tr,
        table.foottable tr td {
            margin: 0;
            padding: 0;
        }

        table.tables tr th,
        table.tables tr td {
            padding: 1rem !important;
        }

        table.tables tr td {
            border-bottom: 1px solid rgba(209, 209, 209, 0.5);
        }

        table.tables tr th {
            border-radius: 10px;
        }

        .total {
            font-size: 1.2em;
            font-weight: bold;
            margin-top: 20px;
        }

        p {
            font-size: 13px;
            margin: 0;
        }

        .text-lg {
            font-size: 1.125rem;
            line-height: 1.75rem;
        }

        .font-semibold {
            font-weight: 600;
        }

        .mb-4 {
            margin-bottom: 1rem;
        }

        .block {
            display: flex;
        }

        .ml-3 {
            margin-left: 0.75rem;
        }

        .text-left {
            text-align: left;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-2xl {
            font-size: 1.5rem;
            line-height: 2rem;
        }

        .text-4xl {
            font-size: 2.25rem;
            line-height: 2.5rem;
        }

        .text-lg {
            font-size: 1.125rem;
            line-height: 1.75rem;
        }

        .text-sm {
            font-size: 0.875rem;
            line-height: 1.25rem;
        }

        .text-xl {
            font-size: 1.25rem;
            line-height: 1.75rem;
        }

        .text-xs {
            font-size: 0.75rem;
            line-height: 1rem;
        }

        .font-bold {
            font-weight: 700;
        }

        .font-medium {
            font-weight: 500;
        }

        .font-semibold {
            font-weight: 600;
        }

        .space-y-2> :not([hidden])~ :not([hidden]) {
            --tw-space-y-reverse: 0;
            margin-top: calc(0.5rem * calc(1 - var(--tw-space-y-reverse)));
            margin-bottom: calc(0.5rem * var(--tw-space-y-reverse));
        }

        .space-y-4> :not([hidden])~ :not([hidden]) {
            --tw-space-y-reverse: 0;
            margin-top: calc(1rem * calc(1 - var(--tw-space-y-reverse)));
            margin-bottom: calc(1rem * var(--tw-space-y-reverse));
        }

        .w-\[80px\] {
            width: 62px;
        }

        .mx-auto {
            margin-left: auto;
            margin-right: auto;
        }

        .max-w-2xl {
            max-width: 42rem;
        }

        .bg-gray-50 {
            --tw-bg-opacity: 1;
            background-color: rgb(249 250 251 / var(--tw-bg-opacity, 1));
        }

        .p-2 {
            padding: 0.5rem;
        }

        .p-6 {
            padding: 1.5rem;
        }

        .p-8 {
            padding: 2rem;
        }

        .border-b {
            border-bottom-width: 1px;
        }

        .bg-blue-50 {
            --tw-bg-opacity: 1;
            background-color: rgb(239 246 255 / var(--tw-bg-opacity, 1));
        }

        .text-blue-600 {
            --tw-text-opacity: 1;
            color: rgb(37 99 235 / var(--tw-text-opacity, 1));
        }

        .iconning {
            width: 18px;
            display: inline-block
        }
    </style>
</head>

<body class="page" style="margin:0">
    <div style="background-color: #086AD7" class="p-8">
        <div class="max-w-2xl mx-auto">
            <table>
                <tr>
                    <td>
                        <img style="width: 150px" src="<?php echo plugin_dir_url(__FILE__) . 'img/logo-white.png'; ?>" alt="Logo">
                    </td>
                    <td style="text-align: right">
                        <h1 class="text-white">Quotation <?php echo $quotation->id; ?></h1>
                        <p class="text-white">Date: <?php echo date('F j, Y', strtotime($quotation->date_created)); ?></p>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    <div class="max-w-2xl mx-auto">
        <table>
            <tr>
                <td width="50%" class="space-y-2">
                    <!-- Information from Sender -->
                    <table>
                        <tr>
                            <td valign="top">
                                <p class="font-bold w-[80px]">Address:</p>
                            </td>
                            <td>
                                <p class="font-medium text-gray-600"><?php echo esc_html(get_option('company_address', 'No address set'));  ?></p>
                            </td>
                        </tr>
                        <tr>
                            <td valign="top">
                                <p class="font-bold w-[80px]">Tel:</p>
                            </td>
                            <td>
                                <p class="font-medium text-gray-600"><?php echo ($quotation->customer_phone) ?? "Error";  ?></p>
                            </td>
                        </tr>
                        <tr>
                            <td valign="top">
                                <p class="font-bold w-[80px]">Email:</p>
                            </td>
                            <td>
                                <p class="font-medium text-gray-600"><?php echo ($quotation->customer_email) ?? "Error";  ?></p>
                            </td>
                        </tr>
                        <tr>
                            <td valign="top">
                                <p class="font-bold w-[80px]">VAT:</p>
                            </td>
                            <td>
                                <p class="font-medium text-gray-600"><?php echo ($quotation->vat) ?? "N/A";  ?></p>
                            </td>
                        </tr>
                    </table>

                </td>
                <td style="vertical-align: top; padding: 0">
                    <div style="background-color: #F9FAFB; padding: 5px 25px; border-radius: 15px;">
                        <h3 class="text-lg font-semibold text-gray-700" style="text-align: right; margin: 0">Bill To:</h3>
                        <!-- Information from client -->
                        <table style="margin:0">
                            <tr>
                                <td style="text-align:right">
                                    <p class="font-bold w-[80px]" style="display: inline;">Delivery Address:</p>
                                    <p class="font-medium text-gray-600" style="display: inline;"><?php echo ($quotation->delivery_address) ?? "Error";  ?></p>
                                </td>
                            </tr>
                            <tr>
                                <td style="text-align:right">
                                    <p class="font-bold w-[80px]" style="display: inline;">Tel:</p>
                                    <p class="font-medium text-gray-600" style="display: inline;"><?php echo ($quotation->receiver_phone) ?? "Error";  ?></p>
                                </td>
                            </tr>
                            <tr>
                                <td style="text-align:right;">
                                    <p class="font-bold w-[80px]" style="display: inline;">Email:</p>
                                    <p class="font-medium text-gray-600" style="display: inline;"><?php echo ($quotation->receiver_email) ?? "Error";  ?></p>
                                </td>
                            </tr>
                            <tr>
                                <td style="text-align:right;">
                                    <p class="font-bold w-[80px]" style="display: inline;">Receiver Name:</p>
                                    <p class="font-medium text-gray-600" style="display: inline;"><?php echo ($quotation->receiver_name) ?? "Error";  ?></p>
                                </td>
                            </tr>
                        </table>

                    </div>
                </td>
            </tr>
        </table>
        <div class="section" style="margin-top: 15px; border-top: 1px solid rgb(226, 226, 226);">
            <h3 class="text-xl text-blue-600">Cost Breakdown</h3>
            <table class="tables">
                <tr class="bg-gray-50 p-4 rounded">
                    <th>
                        <h4 style="margin: 0">Description</h4>
                    </th>
                    <th style="text-align: right;">
                        <h4 style="margin: 0">Amount (ZAR)</h4>
                    </th>
                </tr>
                <tr>
                    <td>
                        <p>Base Shipping Cost</p>
                    </td>
                    <td style="text-align: right;">
                        <p>R <?php echo number_format($quotation->sub_total, 2); ?></p>
                    </td>
                </tr>
                <?php if ($quotation->include_sad500) : ?>
                    <tr>
                        <td>
                            <p>SAD500 Fee</p>
                        </td>
                        <td style="text-align: right;">
                            <p>R 350.00</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($quotation->include_sadc) : ?>
                    <tr>
                        <td>
                            <p>SADC Certificate</p>
                        </td>
                        <td style="text-align: right;">
                            <p>R 1000.00</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($quotation->return_load) : ?>
                    <tr class="bg-blue-50 text-blue-600">
                        <td>
                            <p>Return Load Discount (10%)</p>
                        </td>
                        <td style="text-align: right;">
                            <p>- R <?php echo number_format($quotation->sub_total * 0.1, 2); ?></p>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr class="total">
                    <td style="text-align: right; border: 0px">
                        <p class="text-xl text-blue-600">Total Amount</p>
                    </td>
                    <td style="text-align: right; border: 0px">
                        <p class="text-xl text-blue-600">R <?php echo number_format($quotation->final_cost, 2); ?></p>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    <div style="position:absolute; bottom: 15px; width: 100%;">
        <div class="max-w-2xl mx-auto" style="background: #F9FAFB; padding: 5px 25px; border-radius: 15px;">
            <table>
                <tr>
                    <td style="width: 50%">
                        <div class="text-sm text-gray-600">
                            <p class="font-medium mb-2">Payment Details:</p>
                            <p>Bank: <?php echo ($payment_details->bank_name) ?? ""; ?></p>
                            <p>Account: <?php echo ($payment_details->account) ?? ""; ?></p>
                            <p>Branch: <?php echo ($payment_details->branch) ?? ""; ?></p>
                        </div>

                    </td>
                    <td>
                        <div class="text-gray-600">
                            <div class="diccc">
                                <?php echo esc_html(get_option('company_name', 'No address set'));  ?>
                            </div>
                            <div class="font-medium">
                                <table class="foottable">
                                    <tr>
                                        <td width="20px">
                                            <img class="iconning" src="<?php echo $pin; ?>" alt="pin">
                                        </td>
                                        <td>
                                            <p class="font-medium" style="display: inline-block; margin: 0"> <?php echo esc_html(get_option('company_address', 'No address set'));  ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="font-medium">
                                <table class="foottable">
                                    <tr>
                                        <td width="20px">
                                            <img class="iconning" src="<?php echo $contact; ?>" alt="web">
                                        </td>
                                        <td>
                                            <p class="font-medium" style="display: inline-block; margin: 0"> <?php echo esc_html(get_option('contact_1', 'No address set'));  ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="font-medium">
                                <table class="foottable">
                                    <tr>
                                        <td width="20px">
                                            <img class="iconning" src="<?php echo $email; ?>" alt="email">
                                        </td>
                                        <td>
                                            <p class="font-medium" style="display: inline-block; margin: 0"> <?php echo esc_html(get_option('email_address', 'No address set'));  ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                    </td>
                </tr>
            </table>
        </div>
        <div class="max-w-2xl mx-auto">
            <table>
                <tr>
                    <td colspan="2">
                        <div class="text-sm text-gray-600">
                            <p class="font-medium mb-2">Notes:</p>
                            <p>Payment due within 14 days</p>
                            <p>VAT included where applicable</p>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>

</html>
<?php
$html = ob_get_clean();

// Create PDF
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('isFontSubsettingEnabled', true); // Reduce file size
$options->set('defaultFont', 'CustomFont'); // Fallback font

$dompdf = new Dompdf($options); // Initialize Dompdf FIRST
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
$dompdf->stream("quotation-{$quotation->id}.pdf", [
    "Attachment" => true
]);
exit;
