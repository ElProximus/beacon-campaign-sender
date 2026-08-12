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
            this.scaleThumbnails();

            var self = this;
            var resizeTimer = null;
            $(window).on('resize', function() {
                window.clearTimeout(resizeTimer);
                resizeTimer = window.setTimeout(function() {
                    self.scaleThumbnails();
                }, 150);
            });
        },

        /* ============================================================
           Full-email thumbnails
           ============================================================ */

        // Each card shows the ENTIRE email, scaled to the card width.
        // The iframe renders at a fixed design width; we measure the email's
        // real height and scale both down so the whole design is visible.
        DESIGN_WIDTH: 680,

        scaleThumbnails: function() {
            var self = this;

            $('.bcsend-template-thumb').each(function() {
                var iframe = this;

                var apply = function() {
                    var $viewport = $(iframe).closest('.bcsend-template-thumb-viewport');
                    if (!$viewport.length) {
                        return;
                    }

                    var doc = null;
                    try {
                        doc = iframe.contentDocument;
                    } catch (e) {
                        doc = null;
                    }
                    if (!doc || !doc.documentElement) {
                        return;
                    }

                    var scale = $viewport.width() / self.DESIGN_WIDTH;
                    var contentHeight = Math.max(
                        doc.documentElement.scrollHeight,
                        doc.body ? doc.body.scrollHeight : 0,
                        200
                    );

                    $(iframe).css({
                        width: self.DESIGN_WIDTH + 'px',
                        height: contentHeight + 'px',
                        transform: 'scale(' + scale + ')'
                    });
                    $viewport.css('height', Math.ceil(contentHeight * scale) + 'px');
                };

                if (iframe.contentDocument && 'complete' === iframe.contentDocument.readyState) {
                    apply();
                }
                $(iframe).off('load.bcsendThumb').on('load.bcsendThumb', apply);
            });
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
                var name = $(this).data('template-name') || '';

                if (htmlContent) {
                    self.openModal(htmlContent, name);
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
        openModal: function(html, name) {
            var $overlay = $('#bcsend-template-preview-modal');
            var $iframe = $('#bcsend-template-modal-iframe');
            $('.bcsend-template-modal-title').text(name || 'Template preview');
            $iframe[0].srcdoc = html;
            $overlay.css('display', 'flex');
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

            $(document).on('click', '.bcsend-set-default-template', function() {
                var $btn = $(this);
                var id = $btn.data('template-id');

                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_set_default_template', { template_id: id }, function(response) {
                    Bcsend.loading($btn, false);

                    if (response.success) {
                        Bcsend.notify('Default template updated.', 'success');
                        window.location.reload();
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Could not set the default template.';
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
