/**
 * Beacon Campaign Sender - Email Log JavaScript
 *
 * Handles resending stored transactional emails.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

(function($) {
    'use strict';

    $(document).on('click', '.bcsend-email-resend', function() {
        var $button = $(this);
        var emailId = $button.data('id');

        if (!emailId) {
            return;
        }

        if (!window.confirm('Resend this email?')) {
            return;
        }

        Bcsend.loading($button, true);

        Bcsend.ajax('bcsend_resend_email', {
            email_id: emailId
        }, function(response) {
            Bcsend.loading($button, false);

            if (response.success) {
                Bcsend.notify(response.data.message || 'Email resend submitted.', 'success');
            } else {
                Bcsend.notify((response.data && response.data.message) || 'Email resend failed.', 'error');
            }
        });
    });
})(jQuery);
