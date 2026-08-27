<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * View waybill template 2 — flat document + sticky totals rail.
 * Read-only counterpart of edit template 2. Totals come from the view bootstrap.
 */

$badge_class = match ($approval_status) {
    'pending'  => 'kit-t2-status--pending',
    'rejected' => 'kit-t2-status--rejected',
    default    => 'kit-t2-status--ok',
};
$fmt = static function (float $n): string {
    return KIT_Commons::money($n);
};
?>
<style>
    #wpfooter { position: relative !important; margin-top: 40px !important; }
</style>

<div class="kit-t2">
    <div class="kit-t2-bar">
        <div>
            <h1 class="kit-t2-wb"><?php echo esc_html($waybill['waybill_no'] ?? 'N/A'); ?></h1>
            <div class="kit-t2-meta">
                <span class="kit-t2-status <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($approval_label); ?></span>
                <?php if (!empty($waybill['tracking_number'])): ?>
                    <span>Tracking <?php echo esc_html($waybill['tracking_number']); ?></span>
                <?php endif; ?>
                <?php if (!empty($waybill['product_invoice_number'])): ?>
                    <span>Invoice <?php echo esc_html($waybill['product_invoice_number']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="kit-t2-actions">
            <?php if ($canAccessPDF): ?>
                <?php echo KIT_Commons::renderButton('PDF', 'secondary', 'lg', [
                    'href' => $pdf_url,
                    'target' => '_blank',
                    'rel' => 'noopener noreferrer',
                    'noLoading' => true,
                ]); ?>
                <?php echo KIT_Commons::renderButton('Email', 'ghost', 'lg', [
                    'type' => 'button',
                    'classes' => 'js-kit-email-waybill-pdf',
                    'data-waybill-no' => (string) (int) ($waybill['waybill_no'] ?? 0),
                    'data-email-nonce' => wp_create_nonce('email_waybill_pdf'),
                    'noLoading' => true,
                ]); ?>
            <?php endif; ?>
            <?php if ($can_edit): ?>
                <?php echo KIT_Commons::renderButton('Edit', 'primary', 'lg', [
                    'href' => $edit_url,
                    'noLoading' => true,
                ]); ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="kit-t2-context">
        <span>Dispatch <b><?php echo esc_html($dispatch_display); ?></b></span>
        <span>Trip <b><?php
        if ($effectively_warehouse) {
            echo 'Warehouse';
        } elseif ($delivery_ref !== '' && !empty($waybill['delivery_id'])) {
            echo '<a href="' . esc_url('?page=view-deliveries&delivery_id=' . (int) $waybill['delivery_id']) . '" target="_blank" rel="noopener">' . esc_html($delivery_ref) . '</a>';
        } else {
            echo esc_html($delivery_ref !== '' ? $delivery_ref : 'Not assigned');
        }
        ?></b></span>
        <span>Lane <b><?php echo esc_html($originPlaceLabel . ' → ' . $destinationPlaceLabel); ?></b></span>
        <span>Driver <b><?php echo esc_html($driver_display); ?></b></span>
        <span>Truck <b><?php echo esc_html($truck_display); ?></b></span>
    </div>
    <?php
    $kit_byline_class = 'kit-t2-sub';
    require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/view-waybill/kit-waybill-byline.php';
    unset($kit_byline_class);
    ?>

    <div class="kit-t2-shell">
        <div class="kit-t2-doc">
            <section class="kit-t2-sec">
                <div class="kit-t2-kicker">Customer</div>
                <h2 class="kit-t2-title"><?php echo esc_html($customer_title); ?></h2>
                <p class="kit-t2-sub">
                    <?php echo esc_html($kit_empty_label($view_email)); ?>
                    · <?php echo esc_html($kit_empty_label($view_contact)); ?>
                </p>
                <dl class="kit-t2-facts">
                    <?php if ($is_company_customer): ?>
                        <div><dt>Company</dt><dd><?php echo esc_html($kit_empty_label($view_company_name)); ?></dd></div>
                        <div><dt>VAT</dt><dd><?php echo esc_html($kit_empty_label($view_vat)); ?></dd></div>
                    <?php else: ?>
                        <div><dt>Name</dt><dd><?php echo esc_html($kit_empty_label((string) ($waybill['customer_name'] ?? ''))); ?></dd></div>
                        <div><dt>Surname</dt><dd><?php echo esc_html($kit_empty_label((string) ($waybill['customer_surname'] ?? ''))); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($client_invoice !== ''): ?>
                        <div><dt>Client invoice</dt><dd><?php echo esc_html($client_invoice); ?></dd></div>
                    <?php endif; ?>
                </dl>
            </section>

            <section class="kit-t2-sec">
                <div class="kit-t2-kicker">Route</div>
                <div class="kit-t2-route-grid">
                    <div>
                        <div class="kit-t2-route-end">From</div>
                        <div class="kit-t2-route-place"><?php echo esc_html($originPlaceLabel); ?></div>
                    </div>
                    <div class="kit-t2-route-arrow" aria-hidden="true">→</div>
                    <div>
                        <div class="kit-t2-route-end">To</div>
                        <div class="kit-t2-route-place"><?php echo esc_html($destinationPlaceLabel); ?></div>
                    </div>
                </div>
                <?php if ($description_lines !== []): ?>
                    <div class="kit-t2-sub" style="margin-top:14px;">
                        <?php foreach ($description_lines as $desc_line): ?>
                            <p><?php echo esc_html($desc_line); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="kit-t2-sec">
                <div class="kit-t2-kicker">Cargo</div>
                <div class="kit-t2-route-end">Mass</div>
                <?php if ($show_mass_formula): ?>
                    <dl class="kit-t2-formula">
                        <div><dt>Total mass (kg)</dt><dd><?php echo esc_html(KIT_Commons::trimDecimals($total_mass_kg, 2)); ?></dd></div>
                        <div class="kit-t2-op">×</div>
                        <div><dt>Rate</dt><dd><?php echo $can_see_prices ? esc_html(KIT_Commons::moneyRate($mass_rate)) : '—'; ?></dd></div>
                        <div class="kit-t2-op">=</div>
                        <div><dt>Mass charge</dt><dd><?php echo $can_see_prices ? esc_html($fmt((float) $mass_charge)) : '—'; ?></dd></div>
                    </dl>
                <?php else: ?>
                    <dl class="kit-t2-formula is-incomplete">
                        <div><dt>Mass charge</dt><dd><?php echo $can_see_prices ? esc_html($fmt((float) $mass_charge)) : '—'; ?></dd></div>
                        <div><dt>Total mass</dt><dd>Not captured</dd></div>
                    </dl>
                <?php endif; ?>
                <div class="kit-t2-route-end" style="margin-top:20px;">Volume</div>
                <dl class="kit-t2-dims">
                    <div><dt class="kit-t2-route-end">Length (cm)</dt><dd style="margin:0;font-weight:600;"><?php echo $len > 0 ? esc_html(number_format($len, 2)) : '—'; ?></dd></div>
                    <div><dt class="kit-t2-route-end">Width (cm)</dt><dd style="margin:0;font-weight:600;"><?php echo $wid > 0 ? esc_html(number_format($wid, 2)) : '—'; ?></dd></div>
                    <div><dt class="kit-t2-route-end">Height (cm)</dt><dd style="margin:0;font-weight:600;"><?php echo $hei > 0 ? esc_html(number_format($hei, 2)) : '—'; ?></dd></div>
                </dl>
                <?php if ($show_volume_formula): ?>
                    <dl class="kit-t2-formula">
                        <div><dt>Total volume (m³)</dt><dd><?php echo esc_html(KIT_Commons::trimDecimals($volume_val, 5)); ?></dd></div>
                        <div class="kit-t2-op">×</div>
                        <div><dt>Rate</dt><dd><?php echo $can_see_prices ? esc_html(KIT_Commons::moneyRate($volume_rate)) : '—'; ?></dd></div>
                        <div class="kit-t2-op">=</div>
                        <div><dt>Volume charge</dt><dd><?php echo $can_see_prices ? esc_html($fmt((float) $volume_display_charge)) : '—'; ?></dd></div>
                    </dl>
                <?php else: ?>
                    <dl class="kit-t2-formula is-incomplete">
                        <div><dt>Volume charge</dt><dd><?php echo $can_see_prices ? esc_html($fmt((float) $volume_display_charge)) : '—'; ?></dd></div>
                        <div><dt>Total volume</dt><dd>Not captured</dd></div>
                    </dl>
                <?php endif; ?>
            </section>

            <section class="kit-t2-sec">
                <div class="kit-t2-kicker">Parcels</div>
                <?php echo KIT_Commons::waybillTrackAndData($waybill['items'] ?? []); ?>
            </section>

            <?php if ($has_misc_items): ?>
            <section class="kit-t2-sec">
                <div class="kit-t2-kicker">Extras</div>
                <table class="kit-t2-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Qty</th>
                            <?php if ($can_see_prices): ?>
                                <th class="num">Price</th>
                                <th class="num">Total</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($misc_data['misc_items'] as $item):
                            $name = (string) ($item['misc_item'] ?? '');
                            $price = floatval($item['misc_price'] ?? 0);
                            $qty = floatval($item['misc_quantity'] ?? 0);
                            ?>
                            <tr>
                                <td><?php echo esc_html($name); ?></td>
                                <td class="num"><?php echo esc_html(number_format($qty, 0)); ?></td>
                                <?php if ($can_see_prices): ?>
                                    <td class="num"><?php echo esc_html($fmt($price)); ?></td>
                                    <td class="num"><?php echo esc_html($fmt($price * $qty)); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php else: ?>
                <p class="kit-t2-sub">No miscellaneous charges on this waybill.</p>
            <?php endif; ?>

            <div class="kit-t2-tools">
                <div>
                    <?= KIT_Commons::label(['text' => 'Invoice']) ?>
                    <?= KIT_Commons::waybillQuoteStatus(esc_attr((string) ($waybill['waybill_no'] ?? '')), esc_attr((string) ($waybill['id'] ?? '')), 'select'); ?>
                </div>
                <div>
                    <?= KIT_Commons::label(['text' => 'Approval']) ?>
                    <?= KIT_Commons::waybillApprovalStatus(esc_attr((string) ($waybill['waybill_no'] ?? '')), esc_attr((string) ($waybill['id'] ?? '')), esc_attr((string) ($waybill['approval'] ?? '')), 'select'); ?>
                </div>
                <?php if ($show_approve_invoice): ?>
                    <form method="POST" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                        <input type="hidden" name="action" value="waybill_approve_and_invoice">
                        <input type="hidden" name="waybillno" value="<?= esc_attr((string) ($waybill['waybill_no'] ?? '')) ?>">
                        <input type="hidden" name="waybillid" value="<?= esc_attr((string) ($waybill['id'] ?? '')) ?>">
                        <?php wp_nonce_field('update_waybill_approval_nonce'); ?>
                        <?= KIT_Commons::renderButton('Approve & Invoice', 'primary', 'md', [
                            'type' => 'submit',
                            'data-kit-confirm-question' => sprintf('Approve and invoice waybill %s?', (string) ($waybill['waybill_no'] ?? '')),
                            'data-kit-confirm' => 'This sets approval to Approved and the invoice status to Invoiced in one step.',
                            'data-kit-confirm-accept' => 'Approve & Invoice',
                        ]); ?>
                        <?php // Emitted once per request; the status dropdowns above may be badges for this role. ?>
                        <?= KIT_Commons::confirmDialogScript() ?>
                    </form>
                <?php endif; ?>
                <?php
                $is_in_warehouse = isset($waybill['warehouse']) && ((int) $waybill['warehouse'] === 1 || $waybill['warehouse'] === true);
                if ($is_in_warehouse) {
                    if (!class_exists('KIT_Warehouse')) {
                        require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/warehouse/warehouse-functions.php';
                    }
                    $warehouse_items = KIT_Warehouse::getWarehouseItems($waybill['id']);
                    if (!empty($warehouse_items)) {
                        echo '<div>';
                        echo KIT_Commons::label(['text' => 'Warehouse']);
                        echo KIT_Commons::warehouseDeliveryAssignment(
                            $waybill['id'],
                            $waybill['waybill_no'],
                            $waybill['destination_country'] ?? '',
                            $waybill['destination_country_id'] ?? '',
                            $waybill['status']
                        );
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>

        <?php if ($can_see_prices): ?>
        <aside class="kit-t2-rail" aria-label="Waybill totals">
            <h2>What we charge</h2>
            <div class="kit-t2-row<?php echo $basis_norm === 'mass' ? ' is-active' : ''; ?>">
                <span>Mass charge</span>
                <span><?php echo esc_html($fmt((float) $mass_charge)); ?></span>
            </div>
            <div class="kit-t2-row<?php echo $basis_norm === 'volume' ? ' is-active' : ''; ?>">
                <span>Volume charge</span>
                <span><?php echo esc_html($fmt((float) $volume_charge)); ?></span>
            </div>
            <div class="kit-t2-basis" aria-label="Charge basis">
                <span class="<?php echo $basis_norm === 'mass' ? 'is-on' : ''; ?>">Mass</span>
                <span class="<?php echo $basis_norm === 'volume' ? 'is-on' : ''; ?>">Volume</span>
                <span class="<?php echo $basis_norm === '' ? 'is-on' : ''; ?>">Higher</span>
            </div>
            <p class="kit-t2-hint"><?php
            if ($basis_norm === 'mass') {
                echo 'We are charging on mass.';
            } elseif ($basis_norm === 'volume') {
                echo 'We are charging on volume.';
            } else {
                echo 'We charge whichever is higher: mass or volume.';
            }
            ?></p>
            <div class="kit-t2-row">
                <span>Billed freight</span>
                <span><?php echo esc_html($fmt((float) $billed_freight)); ?></span>
            </div>
            <?php if ($misc_total > 0): ?>
                <div class="kit-t2-row"><span>Miscellaneous</span><span><?php echo esc_html($fmt((float) $misc_total)); ?></span></div>
            <?php endif; ?>
            <?php if ($include_sad500): ?>
                <div class="kit-t2-row"><span>SAD500</span><span><?php echo esc_html($fmt((float) $sad500_amount)); ?></span></div>
            <?php endif; ?>
            <?php if ($include_sadc): ?>
                <div class="kit-t2-row"><span>SADC</span><span><?php echo esc_html($fmt((float) $sadc_amount)); ?></span></div>
            <?php endif; ?>
            <?php if ($include_vat && $vat_charge > 0): ?>
                <div class="kit-t2-row is-muted">
                    <span>Goods value <small>(not charged as freight)</small></span>
                    <span><?php echo esc_html($fmt((float) $waybill_items_total)); ?></span>
                </div>
                <div class="kit-t2-row">
                    <span>VAT <small><?php echo esc_html(KIT_Commons::trimDecimals($vat_rate_pct, 2)); ?>% of goods value</small></span>
                    <span><?php echo esc_html($fmt((float) $vat_charge)); ?></span>
                </div>
            <?php elseif (!$include_vat && $handling_fee > 0): ?>
                <div class="kit-t2-row"><span>Handling</span><span><?php echo esc_html($fmt((float) $handling_fee)); ?></span></div>
            <?php endif; ?>
            <div class="kit-t2-total">
                <span>Total</span>
                <strong><?php echo KIT_Commons::displayWaybillTotal($grand_total_display); ?></strong>
            </div>
        </aside>
        <?php endif; ?>
    </div>
</div>
