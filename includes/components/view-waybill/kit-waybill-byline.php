<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Created / approved / last-updated byline. Same facts in both view layouts.
 */
$byline_class = isset($kit_byline_class) ? (string) $kit_byline_class : 'flex flex-wrap items-center gap-4 text-xs text-gray-700 mb-3';
?>
<div class="<?php echo esc_attr($byline_class); ?>">
    <span class="whitespace-nowrap">
        <span class="font-semibold">Created by</span>
        <?php echo esc_html($createdByName); ?>
        <?php if ($createdAt): ?>
            <span class="text-gray-500">· <?php echo esc_html($createdAt); ?></span>
        <?php endif; ?>
    </span>
    <?php if ($approvedByName): ?>
        <span class="whitespace-nowrap">
            <span class="font-semibold">Approved by</span>
            <?php echo esc_html($approvedByName); ?>
            <?php if ($approvedAt): ?>
                <span class="text-gray-500">· <?php echo esc_html($approvedAt); ?></span>
            <?php endif; ?>
        </span>
    <?php endif; ?>
    <span class="whitespace-nowrap">
        <span class="font-semibold">Last updated by</span>
        <?php if ($lastUpdatedByName !== ''): ?>
            <?php echo esc_html($lastUpdatedByName); ?>
        <?php else: ?>
            <span class="text-gray-500">—</span>
        <?php endif; ?>
        <?php if ($lastUpdated): ?>
            <span class="text-gray-500">· <?php echo esc_html($lastUpdated); ?></span>
        <?php endif; ?>
    </span>
</div>
