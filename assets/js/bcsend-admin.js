/**
 * Beacon Campaign Sender - Shared Admin JavaScript
 *
 * Provides the Bcsend global namespace, AJAX helper, notification system,
 * loading states, utility functions, and tab switching.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

(function($) {
    'use strict';

    window.Bcsend = window.Bcsend || {};

    /**
     * Perform an AJAX request to the WordPress admin-ajax endpoint.
     *
     * @param {string}   action   The AJAX action name.
     * @param {Object}   data     Additional data to send.
     * @param {Function} callback Callback receiving the response object.
     * @return {jqXHR}
     */
    Bcsend.ajax = function(action, data, callback) {
        data = data || {};
        data.action = action;
        data.nonce = bcsendAdmin.nonce;
        return $.post(bcsendAdmin.ajaxUrl, data, function(response) {
            if (callback) {
                callback(response);
            }
        }).fail(function() {
            if (callback) {
                callback({ success: false, data: { message: bcsendAdmin.strings.error } });
            }
        });
    };

    /**
     * Show an inline notification at the top of .bcsend-wrap.
     *
     * @param {string} message The notification message.
	 * @param {string} type    One of: success, error, info, warning.
	 */
    Bcsend.notify = function(message, type) {
        type = ['success', 'error', 'info', 'warning'].indexOf(type) !== -1 ? type : 'info';
        var $wrap = $('.bcsend-wrap').first();
        if (!$wrap.length) {
            return;
        }

        var $note = $('<div>').addClass('bcsend-notification bcsend-notification-' + type);
        var $msg = $('<span>').text(message == null ? '' : String(message));
        var $close = $('<button type="button" class="bcsend-notification-close">').text('\u00d7');
        $note.append($msg).append($close);

        $wrap.prepend($note);

        setTimeout(function() {
            $note.addClass('is-visible');
        }, 10);

        var dismissTimer = setTimeout(function() {
            $note.removeClass('is-visible');
            setTimeout(function() {
                $note.remove();
            }, 300);
        }, 5000);

        $note.on('click', '.bcsend-notification-close', function() {
            clearTimeout(dismissTimer);
            $note.removeClass('is-visible');
            setTimeout(function() {
                $note.remove();
            }, 300);
        });
    };

    /**
     * Toggle loading state on a button or element.
     *
     * @param {jQuery}  $el  The element to toggle.
     * @param {boolean} show True to enable loading, false to disable.
     */
    Bcsend.loading = function($el, show) {
        if (show) {
            $el.addClass('bcsend-is-loading').prop('disabled', true);
        } else {
            $el.removeClass('bcsend-is-loading').prop('disabled', false);
        }
    };

    /**
     * Escape an HTML string for safe insertion.
     *
     * @param {string} str The string to escape.
     * @return {string}
     */
    Bcsend.escapeHtml = function(str) {
        if (!str) {
            return '';
        }
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    };

    /**
     * Format a date string for display.
     *
     * @param {string} dateStr ISO date string or MySQL datetime.
     * @return {string}
     */
    Bcsend.formatDate = function(dateStr) {
        if (!dateStr) {
            return '\u2014';
        }
        var d = new Date(dateStr.replace(/-/g, '/'));
        if (isNaN(d.getTime())) {
            return '\u2014';
        }
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    };

    /**
     * Generate a status badge HTML string.
     *
     * @param {string} status The status key.
     * @return {string}
     */
    Bcsend.statusBadge = function(status) {
        return '<span class="bcsend-badge bcsend-badge-' + Bcsend.escapeHtml(status) + '">' + Bcsend.escapeHtml(status) + '</span>';
    };

    /**
     * Format a number with locale-aware separators.
     *
     * @param {number} num The number to format.
     * @return {string}
     */
    Bcsend.formatNumber = function(num) {
        if (num === null || num === undefined) {
            return '0';
        }
        return Number(num).toLocaleString();
    };

    /**
     * Generate a pagination info string.
     *
     * @param {number} page    Current page.
     * @param {number} perPage Items per page.
     * @param {number} total   Total items.
     * @return {string}
     */
    Bcsend.paginationInfo = function(page, perPage, total) {
        var start = ((page - 1) * perPage) + 1;
        var end = Math.min(page * perPage, total);
        if (total === 0) {
            return 'No items';
        }
        return start + '\u2013' + end + ' of ' + total;
    };

    /**
     * Initialize tab switching within .bcsend-tabs containers.
     */
    $(document).ready(function() {
        $('.bcsend-nav-tab').on('click', function(e) {
            e.preventDefault();
            var target = $(this).data('tab');
            var $wrapper = $(this).closest('.bcsend-wrap');

            $(this).closest('.bcsend-tabs').find('.bcsend-nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            $wrapper.find('.bcsend-tab-content').removeClass('is-active').hide();
            $wrapper.find('#bcsend-tab-' + target).addClass('is-active').show();

            if (window.history && window.history.replaceState) {
                var url = new URL(window.location);
                url.hash = target;
                window.history.replaceState(null, '', url.toString());
            }
        });
    });

})(jQuery);
