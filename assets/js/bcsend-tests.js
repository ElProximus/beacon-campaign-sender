/**
 * Beacon Campaign Sender - System Tests Page JavaScript
 *
 * Provides System Tests page behavior for environment checks,
 * API connections, transactional email tests, push tests,
 * resend verification, and content generation.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

(function($) {
    'use strict';

    /**
     * Map data-test values to AJAX action names.
     */
    var testActions = {
        'verify_tables':      'bcsend_verify_tables',
        'brevo_connection':   'bcsend_test_brevo',
        'anthropic_connection': 'bcsend_test_anthropic',
        'openai_connection':  'bcsend_test_openai',
        'firebase_connection': 'bcsend_test_firebase',
        'send_test_email_smtp': 'bcsend_send_test_email',
        'send_test_email_default': 'bcsend_send_test_email_default',
        'send_test_push':     'bcsend_send_test_push',
        'generate_sample':    'bcsend_generate_sample',
        'content_library':    'bcsend_test_content_library',
        'verify_email_log':   'bcsend_verify_email_log',
        'test_resend':        'bcsend_test_resend'
    };

    var Tests = {

        /**
         * Initialize the test page.
         */
        init: function() {
            this.bindRunTests();
        },

        /**
         * Bind all .bcsend-run-test buttons via delegation using data-test attribute.
         */
        bindRunTests: function() {
            var self = this;

            $(document).on('click', '.bcsend-run-test', function() {
                var $btn = $(this);
                var testName = $btn.data('test');
                var action = testActions[testName];
                var $result = $('#bcsend-test-result-' + testName);

                if (!action) {
                    return;
                }

                // Collect extra data for specific tests.
                var extraData = {};
                if (testName === 'send_test_email_smtp' || testName === 'send_test_email_default') {
                    var testEmail = $.trim($('#bcsend-test-email-address').val());
                    if (!testEmail) {
                        testEmail = bcsendAdmin.adminEmail || '';
                    }
                    if (!testEmail) {
                        Bcsend.notify('Please enter an email address.', 'warning');
                        return;
                    }
                    extraData.to_email = testEmail;
                } else if (testName === 'send_test_push') {
                    var userId = $.trim($('#bcsend-test-push-user-id').val());
                    if (!userId) {
                        Bcsend.notify('Please enter a User ID.', 'warning');
                        return;
                    }
                    extraData.user_id = userId;
                } else if (testName === 'generate_sample') {
                    extraData.prompt = 'Generate a sample marketing campaign for our spring sale.';
                }

                Bcsend.loading($btn, true);
                $result.removeClass('is-success is-error').empty();

                Bcsend.ajax(action, extraData, function(response) {
                    Bcsend.loading($btn, false);

                    if (response.success) {
                        $result.addClass('is-success');
                        $result.html(self.formatResult(testName, response.data));
                    } else {
                        $result.addClass('is-error');
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Test failed.';
                        $result.html(Bcsend.escapeHtml(errMsg));
                    }
                });
            });
        },

        /**
         * Format test result for display.
         *
         * @param {string} testName The data-test value.
         * @param {Object} data     Response data from server.
         * @return {string} HTML string.
         */
        formatResult: function(testName, data) {
            if (testName === 'verify_tables' || testName === 'content_library') {
                // PHP returns { report: { ... } }.
                var report = data.report || data;
                var title = testName === 'content_library' ? 'Content Library Report:' : 'Environment Report:';
                var html = '<strong>' + title + '</strong><br>';
                if (typeof report === 'object') {
                    $.each(report, function(key, check) {
                        if (typeof check === 'object' && check.label !== undefined) {
                            var icon = check.result ? '<span style="color:#00a32a;">&#10004;</span>' : '<span style="color:#d63638;">&#10008;</span>';
                            html += icon + ' ' + Bcsend.escapeHtml(check.label) + '<br>';
                        } else {
                            html += Bcsend.escapeHtml(key) + ': ' + Bcsend.escapeHtml(String(check)) + '<br>';
                        }
                    });
                }
                return html;
            }

            if (testName === 'generate_sample') {
                // PHP returns { content: "raw AI JSON text" }.
                var content = data.content || '';
                var html2 = '<strong>Generated Sample Content:</strong><br><br>';
                html2 += '<div style="background:#f6f7f7;padding:8px;border-radius:3px;font-size:12px;white-space:pre-wrap;margin:4px 0;max-height:400px;overflow:auto;">' +
                    Bcsend.escapeHtml(content) + '</div>';
                return html2;
            }

            if (testName === 'send_test_push') {
                var msg = data.message || 'Test push sent.';
                if (data.sent !== undefined) {
                    msg += ' Sent: ' + data.sent + ', Failed: ' + (data.failed || 0);
                }
                return Bcsend.escapeHtml(msg);
            }

            if (testName === 'send_test_email_smtp' || testName === 'send_test_email_default') {
                var parts = [Bcsend.escapeHtml(data.message || 'Test passed.')];
                if (data.email_log_id) {
                    parts.push('Email log #' + Bcsend.escapeHtml(String(data.email_log_id)));
                }
                if (data.message_id) {
                    parts.push('Brevo message ID: ' + Bcsend.escapeHtml(data.message_id));
                }
                return parts.join('<br>');
            }

            if (testName === 'verify_email_log') {
                var html3 = '<strong>' + Bcsend.escapeHtml(data.message || '') + '</strong><br>';
                if (data.recent && data.recent.length) {
                    $.each(data.recent, function(i, entry) {
                        var icon = entry.status === 'sent'
                            ? '<span style="color:#00a32a;">&#10004;</span>'
                            : (entry.status === 'failed'
                                ? '<span style="color:#d63638;">&#10008;</span>'
                                : '<span style="color:#dba617;">&#9888;</span>');
                        html3 += icon + ' #' + entry.id + ' ' + Bcsend.escapeHtml(entry.status) +
                            ' &mdash; ' + Bcsend.escapeHtml(entry.subject || '(no subject)') +
                            ' &rarr; ' + Bcsend.escapeHtml(entry.to || '') +
                            (entry.brevo_message_id ? ' (ID: ' + Bcsend.escapeHtml(entry.brevo_message_id) + ')' : '') +
                            (entry.resent_from ? ' [resent from #' + entry.resent_from + ']' : '') +
                            '<br>';
                    });
                }
                return html3;
            }

            if (testName === 'test_resend') {
                var icon2 = data.provenance_ok
                    ? '<span style="color:#00a32a;">&#10004;</span> '
                    : '<span style="color:#dba617;">&#9888;</span> ';
                return icon2 + Bcsend.escapeHtml(data.message || 'Resend test complete.');
            }

            // Default: display message.
            return Bcsend.escapeHtml(data.message || 'Test passed.');
        }
    };

    $(document).ready(function() {
        Tests.init();
    });

})(jQuery);
