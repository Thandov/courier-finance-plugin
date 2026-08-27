<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * View waybill template 1 — current card layout.
 */
?>
<style>
    .waybill-items-container {
        max-width: 100%;
        overflow: hidden;
    }

    .waybill-items-container table {
        table-layout: auto;
        width: 100%;
        max-width: 100%;
    }
</style>

<div class="mx-auto p-3 md:p-6 space-y-4 md:space-y-6 bg-white rounded-lg shadow-md">

    <!-- Breadcrumb Navigation -->
    <?php if (isset($breadwaylinks) && is_array($breadlinks) && !empty($breadlinks)): ?>
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="flex items-center text-sm text-gray-500 space-x-2">
                <?php foreach ($breadlinks as $index => $link): ?>
                    <?php if ($index > 0): ?>
                        <li class="text-gray-400">/</li>
                    <?php endif; ?>
                    <li>
                        <?php if (!empty($link['slug']) && $index < count($breadlinks) - 1): ?>
                            <a href="<?php echo esc_url($link['slug']); ?>" class="text-gray-600 hover:text-gray-900 hover:underline">
                                <?php echo esc_html($link['name']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-gray-900 font-medium"><?php echo esc_html($link['name']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <div class="flex flex-col space-y-6 justify-between items-start border-b pb-4">
        <?=  KIT_Commons::prettyHeading([
            'tag' => 'h1',
            'words' => 'Waybill #' . htmlspecialchars($waybill['waybill_no'] ?? 'N/A'),
            'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /><path d="M8 13h8M8 17h8M8 9h2" />',
        ]) ?>
        <div class="gap-3 md:gap-4 w-full min-w-0 items-start">
            <div class="mb-4">
                <div class="min-w-0 w-full md:flex-1">
                    <?php
                    // Customer | Tracking | Invoice | Delivery | Grand Total. The waybill
                    // number is excluded: the h1 sits directly above this strip, so
                    // repeating it here bought nothing and cost a column.
                    $waybill_info_options = [
                        'show_amount' => true,
                        'amount' => $grand_total_display,
                        'exclude' => ['waybill'],
                    ];
                    require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/waybillInfoDisplay.php';
                    unset($waybill_info_options);
                    ?>
                </div>
            </div>
            <div class="flex flex-row min-w-0 w-full md:w-auto md:shrink">
                <div class="space-y-4">
                    <?php
                    // Two groups, not one undifferentiated row. Exports read the waybill;
                    // the controls in the panel below change approval, invoicing and
                    // delivery state. Giving them identical weight side by side invited
                    // a status change while reaching for the PDF button.
                    ?>
                    <?php
                    // $canAccessPDF / $pdf_url come from the shared view-model.
                    ?>
                    <?php if ($canAccessPDF): ?>
                    <div class="flex flex-wrap items-end gap-3 mb-3">
                            <div class="waybill-action-btn-wrap inline-flex shrink-0 gap-3">
                                <div class="flex flex-col">
                                    <?= KIT_Commons::label(['text' => 'Download:']) ?>
                                    <?= KIT_Commons::renderButton(
                                        'PDF',
                                        'primary',
                                        'lg',
                                        [
                                            'href' => $pdf_url,
                                            'target' => '_blank',
                                            'rel' => 'noopener noreferrer',
                                            'gradient' => true,
                                            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />',
                                        ]
                                    ); ?>
                                </div>
                                <div class="flex flex-col">
                                    <?= KIT_Commons::label(['text' => 'Email:']) ?>
                                    <?= KIT_Commons::renderButton(
                                        'Email',
                                        'ghost-primary',
                                        'lg',
                                        [
                                            'type' => 'button',
                                            'classes' => 'js-kit-email-waybill-pdf',
                                            'data-waybill-no' => (string) (int) ($waybill['waybill_no'] ?? 0),
                                            'data-email-nonce' => wp_create_nonce('email_waybill_pdf'),
                                            'gradient' => true,
                                            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9 2.5 2.5 0 000-5z" />',
                                        ]
                                    ); ?>
                                </div>
                            </div>
                    </div>
                    <?php endif; ?>
                    <div class="flex flex-wrap items-end gap-3 mb-3 bg-gray-50 border border-gray-200 rounded-md p-3">
                        <div class="flex flex-col">
                            <?= KIT_Commons::label(['text' => 'Invoice Status:']) ?>
                            <?= KIT_Commons::waybillQuoteStatus(esc_attr((string)($waybill['waybill_no'] ?? '')), esc_attr((string)($waybill['id'] ?? '')), 'select'); ?>
                        </div>
                        <div class="flex flex-col">
                            <?= KIT_Commons::label(['text' => 'Approval Status:']) ?>
                            <?= KIT_Commons::waybillApprovalStatus(esc_attr((string)($waybill['waybill_no'] ?? '')), esc_attr((string)($waybill['id'] ?? '')), esc_attr((string)($waybill['approval'] ?? '')), 'select'); ?>
                        </div>
                        <?php
                        $is_approved = (isset($waybill['approval']) && ($waybill['approval'] === 'approved' || $waybill['approval'] === 'completed'));
                        $is_invoiced = (isset($waybill['status']) && $waybill['status'] === 'invoiced');
                        $show_approve_invoice = (KIT_User_Roles::can_approve() && KIT_User_Roles::can_invoice() && !($is_approved && $is_invoiced));
                        if ($show_approve_invoice) :
                            $waybill_no_attr = esc_attr((string)($waybill['waybill_no'] ?? ''));
                            $waybill_id_attr = esc_attr((string)($waybill['id'] ?? ''));
                        ?>
                            <div class="flex flex-col">
                                <?php // Names what it is: a shortcut for the two dropdowns beside it. ?>
                                <?= KIT_Commons::label(['text' => 'Shortcut:']) ?>
                                <form method="POST" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="inline">
                                    <input type="hidden" name="action" value="waybill_approve_and_invoice">
                                    <input type="hidden" name="waybillno" value="<?= $waybill_no_attr ?>">
                                    <input type="hidden" name="waybillid" value="<?= $waybill_id_attr ?>">
                                    <?php wp_nonce_field('update_waybill_approval_nonce'); ?>
                                    <?= KIT_Commons::renderButton('Approve & Invoice', 'primary', 'lg', [
                                        'type' => 'submit',
                                        'data-kit-confirm-question' => sprintf('Approve and invoice waybill %s?', (string) ($waybill['waybill_no'] ?? '')),
                                        'data-kit-confirm' => 'This sets approval to Approved and the invoice status to Invoiced in one step.',
                                        'data-kit-confirm-accept' => 'Approve & Invoice',
                                    ]); ?>
                                    <?php // Emitted once per request; the status dropdowns above may be badges for this role. ?>
                                    <?= KIT_Commons::confirmDialogScript() ?>
                                </form>
                            </div>
                        <?php endif; ?>
                        <?php
                        // Show warehouse dropdown for waybills in warehouse
                        $is_in_warehouse = isset($waybill['warehouse']) && (intval($waybill['warehouse']) == 1 || $waybill['warehouse'] === true);

                        if ($is_in_warehouse):
                            // Absolute path: the relative one was a directory too high, so every
                            // warehoused waybill fatalled on this layout.
                            if (!class_exists('KIT_Warehouse')) {
                                require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/warehouse/warehouse-functions.php';
                            }
                            $warehouse_items = KIT_Warehouse::getWarehouseItems($waybill['id']);
                            if (!empty($warehouse_items)): ?>
                                <div class="flex flex-col">
                                    <?= KIT_Commons::label(['text' => 'Warehoused:']) ?>
                                    <?= KIT_Commons::warehouseDeliveryAssignment(
                                        $waybill['id'],
                                        $waybill['waybill_no'],
                                        $waybill['destination_country'] ?? '',
                                        $waybill['destination_country_id'] ?? '',
                                        $waybill['status']
                                    ); ?>
                                </div>
                        <?php
                            endif;
                        endif;
                        ?>
                    </div>
                    <?php if (($waybill['approval'] ?? '') === 'pending') : ?>
                        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mb-3">
                            This waybill is pending manager approval before it can be processed.
                        </p>
                    <?php endif; ?>

                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/view-waybill/kit-waybill-byline.php'; ?>

                    <?php // Action feedback is rendered once by viewWaybill.php so both layouts show it. ?>

                </div>
            </div>

        </div>

        <!-- VAT Warning Display -->
        <?php if (isset($_GET['vat_warning']) && $_GET['vat_warning'] == '1'): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            <strong>VAT Warning:</strong> VAT was checked but no parcels were found. No VAT was added to the total.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 w-full">
            <div class="col-span-2 md:col-span-2 w-full">
                <?= KIT_Commons::prettyHeading([
                    'words' => 'Waybill Description',
                    'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /><path d="M8 13h8M8 17h5" />',
                ]) ?>
                <?php if ($description_lines === []): ?>
                    <p class="text-sm italic text-gray-400">No description provided</p>
                <?php else: ?>
                    <div class="text-sm text-gray-700 space-y-1">
                        <?php foreach ($description_lines as $desc_line): ?>
                            <p><?= esc_html($desc_line) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- COMPACT COST SUMMARY -->
    <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
        <div class="bg-gray-50 border border-gray-200 rounded-md p-3 mb-4">
        <?= KIT_Commons::prettyHeading([
                'words' => 'Cost Summary',
                'icon' => '<circle cx="12" cy="12" r="9" /><path d="M12 7v10M15 9.5c0-1.1-1.3-2-3-2s-3 .9-3 2 1.3 2 3 2 3 .9 3 2-1.3 2-3 2-3-.9-3-2" />',
            ]) ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- LEFT: Components (dense) -->
                <div class="space-y-2">
                    <div class="bg-white border border-gray-200 rounded p-3">
                        <div class="flex justify-between text-xs text-gray-600">
                            <span>Mass</span>
                            <span>
                                <?php if ($show_mass_formula): ?>
                                    <?= esc_html(KIT_Commons::trimDecimals($total_mass_kg, 2)) ?>kg × <?= esc_html(KIT_Commons::moneyRate($mass_rate)) ?> = <?= esc_html(KIT_Commons::money($mass_charge)) ?>
                                <?php else: ?>
                                    <?= esc_html(KIT_Commons::money($mass_charge)) ?> <span class="text-gray-400">(mass not captured)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="flex justify-between text-xs text-gray-600 mt-1">
                            <span>Volume</span>
                            <span>
                                <?php if ($show_volume_formula): ?>
                                    <?= esc_html(KIT_Commons::trimDecimals($total_volume, 5)) ?>m³ × <?= esc_html(KIT_Commons::moneyRate($volume_rate)) ?> = <?= esc_html(KIT_Commons::money($volume_display_charge)) ?>
                                <?php else: ?>
                                    <?= esc_html(KIT_Commons::money($volume_display_charge)) ?> <span class="text-gray-400">(volume not captured)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center mt-2 pt-2 border-t">
                            <span class="text-xs font-semibold">Billed on <?= esc_html(strtolower($preferred_charge)) ?></span>
                            <span class="text-sm font-bold"><?= esc_html(KIT_Commons::money($primary_charge)) ?></span>
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded p-3">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-600">Goods value <span class="text-gray-400">(not charged as freight)</span></span>
                            <span class="font-semibold"><?= esc_html(KIT_Commons::money($waybill_items_total)) ?></span>
                        </div>
                        <?php if ($waybill_items_total > 0 && $vat_charge > 0): ?>
                            <div class="flex justify-between text-xs mt-1">
                                <span class="text-gray-600">VAT — <?= esc_html(KIT_Commons::trimDecimals($vat_rate_pct, 2)) ?>% of goods value</span>
                                <span class="font-semibold"><?= esc_html(KIT_Commons::money($vat_charge)) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex justify-between text-xs mt-1">
                            <span class="text-gray-600">Miscellaneous</span>
                            <span class="font-semibold"><?= esc_html(KIT_Commons::money($misc_total)) ?></span>
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded p-3">
                        <?php if ($include_sad500): ?>
                            <div class="flex justify-between text-xs"><span class="text-gray-600">SAD500</span><span class="font-medium"><?= esc_html(KIT_Commons::money($sad500_amount)) ?></span></div>
                        <?php endif; ?>
                        <?php if ($include_sadc): ?>
                            <div class="flex justify-between text-xs mt-1"><span class="text-gray-600">SADC</span><span class="font-medium"><?= esc_html(KIT_Commons::money($sadc_amount)) ?></span></div>
                        <?php endif; ?>
                        <?php if (!$include_sad500 && !$include_sadc && $handling_fee <= 0 && !isset($misc_data['others']['international_price_rands'])): ?>
                            <div class="text-[11px] text-gray-500">No additional charges<?php if (isset($waybill['international_price_in_rands']) && floatval($waybill['international_price_in_rands']) > 0): ?> — International Price: <span class="font-medium text-gray-700"><?= esc_html(KIT_Commons::money($waybill['international_price_in_rands'])) ?></span><?php endif; ?></div>
                        <?php else: ?>
                            <div class="flex justify-between items-center mt-2 pt-2 border-t">
                                <span class="text-xs font-semibold">Additional total</span>
                                <span class="text-sm font-bold"><?= esc_html(KIT_Commons::money($additional_charges_summary)) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RIGHT: What the customer is charged -->
                <div class="space-y-2">
                    <div class="bg-white border border-gray-200 rounded p-3">
                        <div class="flex justify-between text-xs">
                            <span>Freight (<?= esc_html(strtolower($preferred_charge)) ?> basis)</span>
                            <span class="font-medium"><?= esc_html(KIT_Commons::money($primary_charge)) ?></span>
                        </div>
                        <?php if ($vat_charge > 0): ?>
                            <div class="flex justify-between text-xs mt-1">
                                <span>VAT on goods (<?= esc_html(KIT_Commons::trimDecimals($vat_rate_pct, 2)) ?>% of <?= esc_html(KIT_Commons::money($waybill_items_total)) ?>)</span>
                                <span class="font-medium"><?= esc_html(KIT_Commons::money($vat_charge)) ?></span>
                            </div>
                        <?php elseif ($handling_fee > 0): ?>
                            <div class="flex justify-between text-xs mt-1"><span>Handling fee</span><span class="font-medium"><?= esc_html(KIT_Commons::money($handling_fee)) ?></span></div>
                        <?php endif; ?>
                        <div class="flex justify-between text-xs mt-1"><span>Miscellaneous</span><span class="font-medium"><?= esc_html(KIT_Commons::money($misc_total)) ?></span></div>
                        <div class="flex justify-between text-xs mt-1"><span>SAD500 &amp; SADC</span><span class="font-medium"><?= esc_html(KIT_Commons::money($additional_charges_display)) ?></span></div>

                        <?php // items-baseline is not in the shipped CSS build, so it aligned nothing. ?>
                        <div class="flex justify-between items-center mt-3 pt-3 border-t border-gray-300">
                            <span class="text-sm font-semibold text-gray-900">Grand Total</span>
                            <span class="text-xl font-bold text-gray-900"><?= KIT_Commons::displayWaybillTotal($grand_total_display) ?></span>
                        </div>

                        <div class="mt-3 pt-3 border-t grid grid-cols-3 gap-2 text-[11px] text-gray-600">
                            <div>VAT: <strong class="text-gray-900"><?= $include_vat ? 'Yes' : 'No' ?></strong></div>
                            <div>SAD500: <strong class="text-gray-900"><?= $include_sad500 ? 'Yes' : 'No' ?></strong></div>
                            <div>SADC: <strong class="text-gray-900"><?= $include_sadc ? 'Yes' : 'No' ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- BASIC WAYBILL INFORMATION -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 mb-4 md:mb-6">
        <!-- Document Information -->
        <div class="border border-gray-200 rounded-lg p-3 md:p-4 overflow-x-hidden md:overflow-x-auto">
            <?= KIT_Commons::prettyHeading([
                'words' => 'Document Information',
                'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /><path d="M8 13h8M8 17h8" />',
            ]) ?>
            <div class="space-y-3">
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Route</span>
                    <span class="text-sm font-semibold text-gray-900 text-right">
                        <?= esc_html($route_label) ?>
                    </span>
                </div>
                <?php if ($client_invoice !== '' && strcasecmp($client_invoice, (string) ($waybill['product_invoice_number'] ?? '')) !== 0): ?>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-sm text-gray-700">Client Invoice</span>
                        <span class="text-sm font-semibold text-gray-900 text-right">
                            <?= esc_html($client_invoice) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Customer Information -->
        <div class="border border-gray-200 rounded-lg p-3 md:p-4">
            <?= KIT_Commons::prettyHeading([
                'words' => $is_company_customer ? 'Company Information' : 'Customer Information',
                'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
            ]) ?>
            <div class="space-y-3">
                <?php if ($is_company_customer) : ?>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-sm text-gray-700">Company Name</span>
                        <span class="text-sm font-semibold text-gray-900 text-right">
                            <?= esc_html($kit_empty_label($view_company_name)) ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-sm text-gray-700">VAT Number</span>
                        <span class="text-sm font-semibold text-gray-900 text-right">
                            <?= esc_html($kit_empty_label($view_vat)) ?>
                        </span>
                    </div>
                <?php else : ?>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-sm text-gray-700">Name</span>
                        <span class="text-sm font-semibold text-gray-900 text-right">
                            <?= esc_html($kit_empty_label((string) ($waybill['customer_name'] ?? ''))) ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-sm text-gray-700">Surname</span>
                        <span class="text-sm font-semibold text-gray-900 text-right">
                            <?= esc_html($kit_empty_label((string) ($waybill['customer_surname'] ?? ''))) ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Contact</span>
                    <span class="text-sm font-semibold text-gray-900 text-right <?= $view_contact === '' ? 'text-gray-400 font-normal' : '' ?>">
                        <?= esc_html($kit_empty_label($view_contact)) ?>
                    </span>
                </div>
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Email</span>
                    <span class="text-sm font-semibold text-gray-900 text-right <?= $view_email === '' ? 'text-gray-400 font-normal' : '' ?>">
                        <?= esc_html($kit_empty_label($view_email)) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Shipment Details -->
        <div class="border border-gray-200 rounded-lg p-3 md:p-4">
            <?= KIT_Commons::prettyHeading([
                'words' => 'Shipment Details',
                'icon' => '<path d="M9 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM19 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0z" /><path d="M13 16V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10h1m9-1H9m4-8h2.6a1 1 0 0 1 .7.3l3.4 3.4a1 1 0 0 1 .3.7V16h-1" />',
            ]) ?>
            <div class="space-y-3">
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Origin</span>
                    <span class="text-sm font-semibold text-gray-900 text-right">
                        <?= esc_html($originPlaceLabel) ?>
                    </span>
                </div>
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Destination</span>
                    <span class="text-sm font-semibold text-gray-900 text-right">
                        <?= esc_html($destinationPlaceLabel) ?>
                    </span>
                </div>
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Dispatch</span>
                    <span class="text-sm font-semibold text-gray-900 text-right">
                        <?= esc_html($dispatch_display) ?>
                    </span>
                </div>
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Driver</span>
                    <span class="text-sm font-semibold text-gray-900 text-right">
                        <?= esc_html($driver_display) ?>
                    </span>
                </div>
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Truck</span>
                    <span class="text-sm font-semibold text-gray-900 text-right">
                        <?= esc_html($truck_display) ?>
                    </span>
                </div>
                <div class="flex justify-between items-start gap-3">
                    <span class="text-sm text-gray-700">Dimensions</span>
                    <span class="text-sm font-semibold text-gray-900 text-right">
                        <?php
                        if ($len <= 0 && $wid <= 0 && $hei <= 0) {
                            echo 'Not provided';
                        } elseif ($dims_are_derived_cube) {
                            echo esc_html(number_format($len, 2) . ' × ' . number_format($wid, 2) . ' × ' . number_format($hei, 2) . ' cm');
                            echo '<div class="text-[11px] font-normal text-gray-500 mt-0.5">Approx. cube from volume</div>';
                        } else {
                            echo esc_html(number_format($len, 2) . ' × ' . number_format($wid, 2) . ' × ' . number_format($hei, 2) . ' cm');
                        }
                        ?>
                    </span>
                </div>
                <div class="flex justify-between items-center gap-3">
                    <span class="text-sm text-gray-700">Total Mass</span>
                    <span class="text-sm font-semibold text-gray-900 text-right">
                        <?= esc_html(number_format((float) ($waybill['total_mass_kg'] ?? 0), 2)) ?> kg
                    </span>
                </div>
                <?php if ($volume_val > 0): ?>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-sm text-gray-700">Total Volume</span>
                        <span class="text-sm font-semibold text-gray-900 text-right">
                            <?= number_format($volume_val, 3) ?> m³
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 <?= $has_misc_items ? 'lg:grid-cols-2' : '' ?> gap-4 waybill-items-container">
        <div class="min-w-0">
            <div class="border border-gray-200 rounded-lg p-3 md:p-4">
                <?= KIT_Commons::prettyHeading([
                    'words' => 'Parcels',
                    'icon' => '<path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />',
                ]) ?>
                <div class="overflow-x-auto">
                    <?php
                    echo KIT_Commons::waybillTrackAndData($waybill['items']);
                    ?>
                </div>
            </div>
        </div>
        <!-- Miscellaneous Items Section -->
        <?php if ($has_misc_items): ?>
        <div class="min-w-0">
            <div class="border border-gray-200 rounded-lg p-3 md:p-4">
                <?= KIT_Commons::prettyHeading([
                    'words' => 'Miscellaneous Items',
                    'icon' => '<path d="M4 6h16M4 12h16M4 18h10" />',
                ]) ?>
                <?php
                    // Use getMiscCharges to process the misc data
                    $misc_total = 0;

                    // Convert the stored format to the format expected by getMiscCharges
                    $misc_data_for_processing = [
                        'misc_item' => [],
                        'misc_price' => [],
                        'misc_quantity' => []
                    ];

                    foreach ($misc_data['misc_items'] as $item) {
                        $misc_data_for_processing['misc_item'][] = $item['misc_item'];
                        $misc_data_for_processing['misc_price'][] = $item['misc_price'];
                        $misc_data_for_processing['misc_quantity'][] = $item['misc_quantity'];
                    }

                    $misc_result = self::getMiscCharges($misc_data_for_processing, []);
                    $misc_total = floatval($misc_result->misc_total);
                ?>
                    <div class="overflow-x-auto min-w-0">
                        <table class="<?= KIT_Commons::tableClasses(); ?> w-full min-w-full">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th class="<?= KIT_Commons::thClasses() ?> text-left whitespace-nowrap">Description</th>
                                    <th class="<?= KIT_Commons::thClasses() ?> text-right whitespace-nowrap">Price</th>
                                    <th class="<?= KIT_Commons::thClasses() ?> text-center whitespace-nowrap">Qty</th>
                                    <th class="<?= KIT_Commons::thClasses() ?> text-right whitespace-nowrap">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="<?= KIT_Commons::tbodyClasses() ?>">
                                <?php $temTotal = 0;

                                foreach ($misc_data['misc_items'] as $key => $item): ?>
                                    <tr class="border-t border-gray-100">

                                        <td class="<?= KIT_Commons::tcolClasses() ?> break-words">
                                            <?= htmlspecialchars($item['misc_item']) ?></td>
                                        <td class="<?= KIT_Commons::tcolClasses() ?> text-right whitespace-nowrap"><?= esc_html(KIT_Commons::money($item['misc_price'])) ?></td>
                                        <td class="<?= KIT_Commons::tcolClasses() ?> text-center whitespace-nowrap"><?= intval($item['misc_quantity']) ?>
                                        </td>
                                        <td class="<?= KIT_Commons::tcolClasses() ?> text-right whitespace-nowrap"><?= esc_html(KIT_Commons::money($item['misc_price'] * $item['misc_quantity'])) ?></td>
                                    </tr>
                                    <?php $temTotal += $item['misc_price'] * $item['misc_quantity']; ?>
                                <?php endforeach; ?>
                                <tr class="border-t border-gray-100">
                                    <td colspan="3" class="<?= KIT_Commons::tcolClasses() ?> text-right font-semibold whitespace-nowrap">
                                        Total</td>
                                    <td class="<?= KIT_Commons::tcolClasses() ?> text-right font-bold whitespace-nowrap"><?= esc_html(KIT_Commons::money($misc_total)) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!$has_misc_items): ?>
        <p class="text-sm italic text-gray-400 mt-2">No miscellaneous charges on this waybill.</p>
    <?php endif; ?>

    <div class="flex justify-end gap-3 border-t pt-4">
        <?php if ($can_edit): ?>
            <div class="waybill-action-btn-wrap inline-flex shrink-0">
                <?= KIT_Commons::renderButton(
                    'Edit',
                    'primary',
                    'lg',
                    [
                        'href' => $edit_url,
                        'gradient' => true,
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>',
                        'iconPosition' => 'left',
                    ]
                ); ?>
            </div>
        <?php elseif (($waybill['approval'] ?? 'pending') === 'approved' || ($waybill['approval'] ?? 'pending') === 1): ?>
            <div class="waybill-action-btn-wrap inline-flex shrink-0">
                <?= KIT_Commons::renderButton(
                    'Edit Waybill (Locked)',
                    'secondary',
                    'lg',
                    [
                        'type' => 'button',
                        'disabled' => true,
                        'title' => 'Waybill is approved and locked for editing. Only administrators can edit approved waybills.',
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a2 2 0 00-2-2H4a2 2 0 00-2 2v4h8z"></path>',
                        'iconPosition' => 'left',
                    ]
                ); ?>
            </div>
        <?php endif; ?>

    </div>
</div>
<?php
// Ensure WordPress functions are available
if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return $path;
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = '')
    {
        return '';
    }
}
if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($original)
    {
        if (is_serialized($original)) {
            return unserialize($original);
        }
        return $original;
    }
    function is_serialized($data)
    {
        // If it isn't a string, it isn't serialized
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ('N;' == $data) return true;
        if (!preg_match('/^([adObis]):/', $data, $badions)) return false;
        switch ($badions[1]) {
            case 'a':
            case 'O':
            case 's':
                if (preg_match("/^{$badions[1]}:[0-9]+:/s", $data)) return true;
                break;
            case 'b':
            case 'i':
            case 'd':
                if (preg_match("/^{$badions[1]}:[0-9.E-]+;$/", $data)) return true;
                break;
        }
        return false;
    }
}
?>
