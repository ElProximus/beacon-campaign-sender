/**
 * Beacon Campaign Sender - Templates Page JavaScript
 *
 * Handles template preview modal, duplication, and deletion
 * with inline confirmation. Templates are server-rendered in
 * the view; JS binds actions to the existing cards.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

(function($) {
    'use strict';

    var Templates = {

        /**
         * Initialize the templates page.
         */
        init: function() {
            this.bindModal();
            this.bindActions();
        },

        /* ============================================================
           Modal (Preview)
           ============================================================ */

        /**
         * Bind modal open/close interactions.
         */
        bindModal: function() {
            var self = this;

            $(document).on('click', '.bcsend-preview-template-btn', function() {
                var htmlContent = $(this).data('template-html') || '';

                if (htmlContent) {
                    self.openModal(htmlContent);
                } else {
                    Bcsend.notify('No HTML content to preview.', 'warning');
                }
            });

            $('#bcsend-close-template-modal').on('click', function() {
                self.closeModal();
            });

            $('#bcsend-template-preview-modal').on('click', function(e) {
                if (e.target === this) {
                    self.closeModal();
                }
            });

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    self.closeModal();
                }
            });
        },

        /**
         * Open the preview modal with HTML content.
         *
         * @param {string} html HTML content for the iframe.
         */
        openModal: function(html) {
            var $overlay = $('#bcsend-template-preview-modal');
            var $iframe = $('#bcsend-template-modal-iframe');
            $iframe[0].srcdoc = html;
            $overlay.show();
        },

        /**
         * Close the preview modal.
         */
        closeModal: function() {
            var $overlay = $('#bcsend-template-preview-modal');
            $overlay.hide();
            $('#bcsend-template-modal-iframe')[0].srcdoc = '';
        },

        /* ============================================================
           Template Actions
           ============================================================ */

        /**
         * Bind duplicate and delete actions on template cards.
         */
        bindActions: function() {

            $(document).on('click', '.bcsend-duplicate-template', function() {
                var $btn = $(this);
                var id = $btn.data('template-id');

                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_duplicate_template', { id: id }, function(response) {
                    Bcsend.loading($btn, false);

                    if (response.success) {
                        Bcsend.notify(response.data.message || 'Template duplicated.', 'success');
                        window.location.reload();
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Failed to duplicate template.';
                        Bcsend.notify(errMsg, 'error');
                    }
                });
            });

            $(document).on('click', '.bcsend-delete-template', function() {
                var $btn = $(this);
                var $card = $btn.closest('.bcsend-template-card');
                var id = $btn.data('template-id');

                // Inline confirmation: change button text temporarily.
                if ($btn.data('confirming')) {
                    Bcsend.loading($btn, true);

                    Bcsend.ajax('bcsend_delete_template', { id: id }, function(response) {
                        Bcsend.loading($btn, false);

                        if (response.success) {
                            $card.css('transition', 'opacity 0.3s ease, transform 0.3s ease');
                            $card.css({ opacity: 0, transform: 'scale(0.95)' });
                            setTimeout(function() {
                                $card.remove();
                            }, 300);
                            Bcsend.notify(response.data.message || 'Template deleted.', 'success');
                        } else {
                            var errMsg = (response.data && response.data.message) ? response.data.message : 'Failed to delete template.';
                            Bcsend.notify(errMsg, 'error');
                            $btn.text('Delete').data('confirming', false);
                        }
                    });
                } else {
                    $btn.text('Confirm?').data('confirming', true);
                    setTimeout(function() {
                        if ($btn.data('confirming')) {
                            $btn.text('Delete').data('confirming', false);
                        }
                    }, 3000);
                }
            });
        }
    };

    $(document).ready(function() {
        Templates.init();
    });

})(jQuery);
