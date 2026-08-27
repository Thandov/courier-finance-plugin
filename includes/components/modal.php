<?php
if (! defined('ABSPATH')) {
    exit;
}

class KIT_Modal
{
    /**
     * @param string $id Modal element ID
     * @param string $title Modal title
     * @param string $content Modal body HTML
     * @param string $size sm|md|lg|xl|2xl|3xl|4xl|5xl|6xl|full
     * @param bool $show_button Whether to render trigger button
     * @param string $button_text Button label (defaults to title)
     * @param bool|null $use_bootstrap True = Bootstrap 5 markup/JS, false = custom. Null = auto (Bootstrap on frontend)
     */
    public static function render($id, $title = '', $content = '', $size = '3xl', $show_button = true, $button_text = '', $use_bootstrap = null)
    {
        static $kit_modal_styles_printed = false;

        if ($use_bootstrap === null) {
            $use_bootstrap = ! is_admin();
        }

        $size_classes = [
            'sm'   => 'w-[95vw] max-w-2xl',
            'md'   => 'w-[95vw] max-w-3xl',
            'lg'   => 'w-[95vw] max-w-4xl',
            'xl'   => 'w-[95vw] max-w-5xl',
            '2xl'  => 'w-[95vw] max-w-6xl',
            '3xl'  => 'w-[96vw] max-w-[1100px]',
            '4xl'  => 'w-[96vw] max-w-[1250px]',
            '5xl'  => 'w-[97vw] max-w-[1380px]',
            '6xl'  => 'w-[98vw] max-w-[1500px]',
            'full' => 'w-[98vw] max-w-[1700px]',
        ];
        $bootstrap_size_classes = [
            'sm'   => 'kit-modal-bs-sm',
            'md'   => 'kit-modal-bs-md',
            'lg'   => 'kit-modal-bs-lg',
            'xl'   => 'kit-modal-bs-xl',
            '2xl'  => 'kit-modal-bs-2xl',
            '3xl'  => 'kit-modal-bs-3xl',
            '4xl'  => 'kit-modal-bs-4xl',
            '5xl'  => 'kit-modal-bs-5xl',
            '6xl'  => 'kit-modal-bs-6xl',
            'full' => 'kit-modal-bs-full',
        ];

        $size_class = $size_classes[$size] ?? $size_classes['xl'];
        $bs_dialog_class = $bootstrap_size_classes[$size] ?? $bootstrap_size_classes['xl'];

        ob_start();

        if ($use_bootstrap) {
            if (! $kit_modal_styles_printed) {
                $kit_modal_styles_printed = true;
                ?>
                <style id="kit-global-modal-styles">
                    .modal-backdrop.show {
                        opacity: .55;
                        backdrop-filter: blur(2px);
                    }
                    .modal-content {
                        border-radius: 14px;
                        box-shadow: 0 18px 48px rgba(0, 0, 0, 0.18);
                        border: 1px solid rgba(15, 23, 42, 0.08);
                    }
                    .modal-header {
                        padding: 1rem 1.25rem;
                        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                    }
                    .modal-body {
                        padding: 1.25rem;
                    }
                    @media (min-width: 992px) {
                        .kit-modal-bs-sm { max-width: 700px; }
                        .kit-modal-bs-md { max-width: 900px; }
                        .kit-modal-bs-lg { max-width: 1050px; }
                        .kit-modal-bs-xl { max-width: 1180px; }
                        .kit-modal-bs-2xl { max-width: 1280px; }
                        .kit-modal-bs-3xl { max-width: 1360px; }
                        .kit-modal-bs-4xl { max-width: 1460px; }
                        .kit-modal-bs-5xl { max-width: 1560px; }
                        .kit-modal-bs-6xl { max-width: 1680px; }
                        .kit-modal-bs-full { max-width: 96vw; }
                    }
                </style>
                <?php
            }
            // --- Bootstrap 5 (frontend / employee dashboard) ---
            if ($show_button) {
                $button_label = $button_text ?: $title;
                //otherz
                echo KIT_Commons::renderButton($button_label, 'primary', 'lg', [
                    'data-bs-toggle' => 'modal',
                    'data-bs-target' => '#' . $id,
                    'icon'           => '<path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />',
                ]);
            }
            ?>
            <div id="<?php echo esc_attr($id); ?>" class="modal fade" tabindex="-1" aria-labelledby="<?php echo esc_attr($id); ?>-label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable <?php echo esc_attr($bs_dialog_class); ?>">
                    <div class="modal-content">
                        <div class="modal-header">
                            <?php if ($title) : ?>
                                <h5 class="modal-title" id="<?php echo esc_attr($id); ?>-label"><?php echo esc_html($title); ?></h5>
                            <?php endif; ?>
                            <?php echo KIT_Commons::renderButton('', 'ghost', 'lg', [
                                'type'           => 'button',
                                'classes'        => 'btn-close-placeholder text-gray-500 hover:text-gray-700',
                                'ariaLabel'      => __('Close', 'courier-finance-plugin'),
                                'iconOnly'       => true,
                                'icon'           => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />',
                                'data-bs-dismiss' => 'modal',
                            ]); ?>
                        </div>
                        <div class="modal-body">
                            <?php echo $content; ?>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            jQuery(document).ready(function($) {
                var $modal = $('#<?php echo esc_js($id); ?>');
                $modal.on('shown.bs.modal', function() {
                    $modal.trigger('modal:opened');
                    $(document).trigger('modal:opened', [$modal]);
                });
            });
            </script>
            <?php
            return ob_get_clean();
        }

        // --- Custom modal (admin / Tailwind) ---
        if (! $kit_modal_styles_printed) {
            $kit_modal_styles_printed = true;
            ?>
            <style id="kit-global-modal-styles">
                .modal-backdrop.show {
                    opacity: .55;
                    backdrop-filter: blur(2px);
                }
                .modal-content {
                    border-radius: 14px;
                    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.18);
                    border: 1px solid rgba(15, 23, 42, 0.08);
                }
                .modal-header {
                    padding: 1rem 1.25rem;
                    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                }
                .modal-body {
                    padding: 1.25rem;
                }
                .kit-tailwind-modal-shell {
                    backdrop-filter: blur(2px);
                }
                .kit-tailwind-modal-panel {
                    width: 96vw;
                    border-radius: 14px;
                    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.18);
                }
                @media (max-width: 767px) {
                    .kit-tailwind-modal-panel {
                        width: 98vw;
                        margin-top: 0.75rem;
                        margin-bottom: 0.75rem;
                    }
                }
                @media (min-width: 992px) {
                    .kit-modal-bs-sm { max-width: 700px; }
                    .kit-modal-bs-md { max-width: 900px; }
                    .kit-modal-bs-lg { max-width: 1050px; }
                    .kit-modal-bs-xl { max-width: 1180px; }
                    .kit-modal-bs-2xl { max-width: 1280px; }
                    .kit-modal-bs-3xl { max-width: 1360px; }
                    .kit-modal-bs-4xl { max-width: 1460px; }
                    .kit-modal-bs-5xl { max-width: 1560px; }
                    .kit-modal-bs-6xl { max-width: 1680px; }
                    .kit-modal-bs-full { max-width: 96vw; }
                }
            </style>
            <?php
        }

        if ($show_button) {
            $button_label = $button_text ?: $title;
            echo KIT_Commons::renderButton($button_label, 'primary', 'lg', [
                'modal' => $id,
                'icon'  => '<path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />',
            ]);
        }
        ?>
        <div id="<?php echo esc_attr($id); ?>"
            class="kit-tailwind-modal-shell hidden fixed inset-0 z-[99999] items-start pt-6 md:pt-10 justify-center bg-black bg-opacity-50 overflow-y-auto">
            <div class="kit-tailwind-modal-panel bg-white overflow-y-auto <?php echo esc_attr($size_class); ?> max-h-[92vh] my-3 md:my-8">
                <div class="flex justify-between items-center px-6 py-4 border-b sticky top-0 bg-white z-10">
                    <?php if ($title) : ?>
                        <h3 class="text-lg font-semibold text-gray-800"><?php echo esc_html($title); ?></h3>
                    <?php endif; ?>
                    <?php echo KIT_Commons::renderButton('', 'ghost', 'lg', ['type' => 'button', 'classes' => 'modal-close text-gray-500 hover:text-gray-700 focus:outline-none', 'ariaLabel' => 'Close', 'iconOnly' => true, 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />']); ?>
                </div>
                <div class="p-6">
                    <?php echo $content; ?>
                </div>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($) {
                const modal = $('#<?php echo esc_js($id); ?>');
                const body = $('body');
                $(document).on('click', '[data-modal="<?php echo esc_js($id); ?>"]', function(e) {
                    e.preventDefault();
                    modal.removeClass('hidden').addClass('flex');
                    body.addClass('overflow-hidden');
                    modal.trigger('modal:opened');
                    $(document).trigger('modal:opened', [modal]);
                });
                modal.find('.modal-close').on('click', function(e) {
                    e.preventDefault();
                    modal.removeClass('flex').addClass('hidden');
                    body.removeClass('overflow-hidden');
                });
                modal.on('click', function(e) {
                    if (e.target === this) {
                        modal.removeClass('flex').addClass('hidden');
                        body.removeClass('overflow-hidden');
                    }
                });
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && modal.hasClass('flex')) {
                        modal.removeClass('flex').addClass('hidden');
                        body.removeClass('overflow-hidden');
                    }
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }
}
