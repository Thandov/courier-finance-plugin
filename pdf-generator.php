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

if (!$quotation) {
    wp_die('Quotation not found');
}

// Create PDF content
ob_start();
?>
<!DOCTYPE html>
<html>

<head>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            margin: 40px;
            font-size: 12px;
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
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .total {
            font-size: 1.2em;
            font-weight: bold;
            margin-top: 20px;
        }
    </style>
</head>

<body>

    <table>
        <tr>
            <td>
                <h1>Quotation <?php echo $quotation->id; ?></h1>
                <p>Date: <?php echo date('F j, Y', strtotime($quotation->date_created)); ?></p>
            </td>
        </tr>
    </table>
    <hr>
    <table>
        <tr>
            <td>
                <div class="section">
                    <div class="items-center mb-4">
                        <img class="w-[150px]" src="<?php echo plugin_dir_url(__FILE__) . 'img/logo.png'; ?>" alt="Logo">
                        <h2 class="text-xl font-bold text-gray-800">08600 Logistics</h2>
                    </div>
                    <p class="text-gray-600">123 Business Street</p>
                    <p class="text-gray-600">Johannesburg, 2000</p>
                    <p class="text-gray-600">South Africa</p>
                    <div class="mt-4">
                        <p class="text-blue-600">Tel: +27 11 123 4567</p>
                        <p class="text-blue-600">Email: info@08600.co.za</p>
                        <p class="text-blue-600">VAT: 123456789</p>
                    </div>
                </div>
            </td>
            <td width="50%">
                <div class="section">
                    <h3>Shipping Details</h3>
                    <p>Method: <?php echo strtoupper($quotation->shipping_method); ?>-BASED</p>
                    <?php if ($quotation->shipping_method === 'weight') : ?>
                        <p>Weight: <?php echo $quotation->weight; ?> kg</p>
                    <?php else : ?>
                        <p>Dimensions: <?php echo $quotation->length; ?>m × <?php echo $quotation->width; ?>m × <?php echo $quotation->height; ?>m</p>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    </table>


    <div class="section">
        <h3>Cost Breakdown</h3>
        <table>
            <tr>
                <th>Description</th>
                <th>Amount (ZAR)</th>
            </tr>
            <tr>
                <td>Base Shipping Cost</td>
                <td>R <?php echo number_format($quotation->sub_total, 2); ?></td>
            </tr>
            <?php if ($quotation->include_sad500) : ?>
                <tr>
                    <td>SAD500 Fee</td>
                    <td>R 350.00</td>
                </tr>
            <?php endif; ?>
            <?php if ($quotation->include_sadc) : ?>
                <tr>
                    <td>SADC Certificate</td>
                    <td>R 1000.00</td>
                </tr>
            <?php endif; ?>
            <?php if ($quotation->return_load) : ?>
                <tr>
                    <td>Return Load Discount (10%)</td>
                    <td>- R <?php echo number_format($quotation->sub_total * 0.1, 2); ?></td>
                </tr>
            <?php endif; ?>
            <tr class="total">
                <td>Total Amount</td>
                <td>R <?php echo number_format($quotation->final_cost, 2); ?></td>
            </tr>
        </table>
    </div>
</body>

</html>
<?php
$html = ob_get_clean();

// Create PDF
use Dompdf\Dompdf;

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
$dompdf->stream("quotation-{$quotation->id}.pdf", [
    "Attachment" => true
]);
exit;
