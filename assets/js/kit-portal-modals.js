/**
 * Employee portal modal behaviour.
 * Runs after jQuery in the footer so [data-modal] open/close works on the frontend.
 * Modals are marked with class .kit-modal; triggers use attribute data-modal="modal-id".
 */
(function() {
    'use strict';

    function initModals() {
        if (typeof jQuery === 'undefined') {
            return;
        }
        var $ = jQuery;

        $('.kit-modal').each(function() {
            var modal = $(this);
            var id = modal.attr('id');
            if (!id) {
                return;
            }

            // Open: delegate so it works for dynamically added triggers
            $(document).off('click.portal-modal-open.' + id).on('click.portal-modal-open.' + id, '[data-modal="' + id + '"]', function(e) {
                e.preventDefault();
                modal.removeClass('hidden').addClass('flex');
                $('body').addClass('overflow-hidden');
                modal.trigger('modal:opened');
                $(document).trigger('modal:opened', [modal]);
            });

            // Close: close button (direct bind on modal content)
            modal.find('.modal-close').off('click.portal-modal-close').on('click.portal-modal-close', function(e) {
                e.preventDefault();
                modal.removeClass('flex').addClass('hidden');
                $('body').removeClass('overflow-hidden');
            });

            // Close: backdrop click (one handler per modal)
            modal.off('click.portal-modal-backdrop').on('click.portal-modal-backdrop', function(e) {
                if (e.target === this) {
                    modal.removeClass('flex').addClass('hidden');
                    $('body').removeClass('overflow-hidden');
                }
            });
        });

        // ESC: close any open modal (one global handler)
        $(document).off('keydown.portal-modal-esc').on('keydown.portal-modal-esc', function(e) {
            if (e.key === 'Escape') {
                $('.kit-modal.flex').removeClass('flex').addClass('hidden');
                $('body').removeClass('overflow-hidden');
            }
        });
    }

    if (typeof jQuery !== 'undefined') {
        jQuery(document).ready(initModals);
    }
})();
