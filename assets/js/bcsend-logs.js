/**
 * Beacon Campaign Sender - Logs Page JavaScript
 *
 * Handles log filtering, AJAX loading with pagination,
 * expandable row detail display, and old log cleanup.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

(function($) {
    'use strict';

    var Logs = {

        /**
         * Current page number.
         * @type {number}
         */
        page: 1,

        /**
         * Items per page.
         * @type {number}
         */
        perPage: 30,

        /**
         * Current type filter.
         * @type {string}
         */
        typeFilter: '',

        /**
         * Current status filter.
         * @type {string}
         */
        statusFilter: '',

        /**
         * Initialize the logs page.
         */
        init: function() {
            this.bindFilters();
            this.bindExpandable();
            this.bindPagination();
            this.bindClearLogs();
            this.loadLogs();
        },

        /* ============================================================
           Filters
           ============================================================ */

        /**
         * Bind filter form controls.
         */
        bindFilters: function() {
            var self = this;

            $('#bcsend-log-filter-apply').on('click', function() {
                self.typeFilter = $('#bcsend-log-type-filter').val();
                self.statusFilter = $('#bcsend-log-status-filter').val();
                self.page = 1;
                self.loadLogs();
            });
        },

        /* ============================================================
           Load Logs
           ============================================================ */

        /**
         * Load logs via AJAX and render the table.
         */
        loadLogs: function() {
            var self = this;
            var $body = $('#bcsend-logs-table-body');
            var $wrap = $body.closest('.bcsend-card');
            $wrap.addClass('bcsend-loading');

            Bcsend.ajax('bcsend_get_logs', {
                page: self.page,
                per_page: self.perPage,
                type: self.typeFilter,
                status: self.statusFilter
            }, function(response) {
                $wrap.removeClass('bcsend-loading');
                $body.empty();

                if (response.success && response.data.logs && response.data.logs.length) {
                    $.each(response.data.logs, function(i, log) {
                        var rowId = 'bcsend-log-detail-' + log.id;
                        var payloadStr = '';

                        if (log.payload) {
                            try {
                                payloadStr = typeof log.payload === 'string'
                                    ? JSON.stringify(JSON.parse(log.payload), null, 2)
                                    : JSON.stringify(log.payload, null, 2);
                            } catch (e) {
                                payloadStr = String(log.payload);
                            }
                        }

                        var statusClass = '';
                        if (log.status === 'success' || log.status === 'ok') {
                            statusClass = 'bcsend-badge-sent';
                        } else if (log.status === 'error' || log.status === 'failed') {
                            statusClass = 'bcsend-badge-failed';
                        } else {
                            statusClass = 'bcsend-badge-draft';
                        }

                        $body.append(
                            '<tr class="bcsend-expandable-row" data-detail="' + rowId + '">' +
                            '<td><span class="bcsend-expand-icon"></span>' + Bcsend.escapeHtml(String(log.id)) + '</td>' +
                            '<td>' + Bcsend.escapeHtml(log.type || '') + '</td>' +
                            '<td><span class="bcsend-badge ' + statusClass + '">' + Bcsend.escapeHtml(log.status || 'unknown') + '</span></td>' +
                            '<td>' + Bcsend.escapeHtml(log.message || '') + '</td>' +
                            '<td>' + Bcsend.formatDate(log.created_at) + '</td>' +
                            '</tr>' +
                            '<tr class="bcsend-expandable-content" id="' + rowId + '">' +
                            '<td colspan="5"><pre>' + Bcsend.escapeHtml(payloadStr || 'No payload data.') + '</pre></td>' +
                            '</tr>'
                        );
                    });

                    self.updatePagination(response.data.total || 0);
                } else {
                    $body.append(
                        '<tr><td colspan="5" style="text-align:center;padding:30px;color:#8c8f94;">No logs found.</td></tr>'
                    );
                    self.updatePagination(0);
                }
            });
        },

        /* ============================================================
           Expandable Rows
           ============================================================ */

        /**
         * Bind click-to-expand on log table rows.
         */
        bindExpandable: function() {
            $(document).on('click', '.bcsend-expandable-row', function() {
                var detailId = $(this).data('detail');
                var $detail = $('#' + detailId);

                $(this).toggleClass('is-expanded');
                $detail.toggleClass('is-expanded');
            });
        },

        /* ============================================================
           Pagination
           ============================================================ */

        /**
         * Bind pagination buttons.
         */
        bindPagination: function() {
            var self = this;

            $('#bcsend-logs-page-prev').on('click', function() {
                if (self.page > 1) {
                    self.page--;
                    self.loadLogs();
                }
            });

            $('#bcsend-logs-page-next').on('click', function() {
                self.page++;
                self.loadLogs();
            });
        },

        /**
         * Update pagination controls.
         *
         * @param {number} total Total log entries.
         */
        updatePagination: function(total) {
            var totalPages = Math.ceil(total / this.perPage) || 1;

            $('#bcsend-logs-page-prev').prop('disabled', this.page <= 1);
            $('#bcsend-logs-page-next').prop('disabled', this.page >= totalPages);
            $('#bcsend-logs-pagination-info').text(Bcsend.paginationInfo(this.page, this.perPage, total));
        },

        /* ============================================================
           Clear Old Logs
           ============================================================ */

        /**
         * Bind the "Clear Old Logs" button.
         */
        bindClearLogs: function() {
            var self = this;

            $('#bcsend-clear-old-logs').on('click', function() {
                var $btn = $(this);

                if ($btn.data('confirming')) {
                    Bcsend.loading($btn, true);

                    Bcsend.ajax('bcsend_clear_old_logs', {}, function(response) {
                        Bcsend.loading($btn, false);
                        $btn.text('Clear Old Logs').data('confirming', false);

                        if (response.success) {
                            var count = response.data.deleted || 0;
                            Bcsend.notify(count + ' old log(s) cleared.', 'success');
                            self.page = 1;
                            self.loadLogs();
                        } else {
                            Bcsend.notify(response.data.message || 'Failed to clear logs.', 'error');
                        }
                    });
                } else {
                    $btn.text('Click again to confirm').data('confirming', true);
                    setTimeout(function() {
                        if ($btn.data('confirming')) {
                            $btn.text('Clear Old Logs').data('confirming', false);
                        }
                    }, 3000);
                }
            });
        }
    };

    $(document).ready(function() {
        Logs.init();
    });

})(jQuery);
