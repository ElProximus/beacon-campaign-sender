/**
 * Beacon Campaign Sender - Campaign Queue Page JavaScript
 *
 * Handles list/calendar toggle views, campaign loading with status filters,
 * calendar month navigation, bulk operations, and pagination.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

(function($) {
    'use strict';

    var Queue = {

        /**
         * Current view mode: 'list' or 'calendar'.
         * @type {string}
         */
        viewMode: 'list',

        /**
         * Current list page.
         * @type {number}
         */
        page: 1,

        /**
         * Items per page for list view.
         * @type {number}
         */
        perPage: 20,

        /**
         * Current status filter value.
         * @type {string}
         */
        statusFilter: '',

        /**
         * Calendar current year.
         * @type {number}
         */
        calYear: new Date().getFullYear(),

        /**
         * Calendar current month (0-11).
         * @type {number}
         */
        calMonth: new Date().getMonth(),

        /**
         * Cached campaigns data for calendar rendering.
         * @type {Array}
         */
        campaigns: [],

        /**
         * Initialize the queue page.
         */
        init: function() {
            this.bindViewToggle();
            this.bindStatusFilter();
            this.bindBulkDelete();
            this.bindSendNow();
            this.bindRevertToDraft();
            this.bindCalendarNav();
        },

        /* ============================================================
           View Toggle
           ============================================================ */

        /**
         * Bind list/calendar toggle buttons.
         */
        bindViewToggle: function() {
            var self = this;

            $('#bcsend-view-list').on('click', function() {
                self.viewMode = 'list';
                $(this).addClass('bcsend-view-active');
                $('#bcsend-view-calendar').removeClass('bcsend-view-active');
                $('#bcsend-list-view').show();
                $('#bcsend-calendar-view').hide();
            });

            $('#bcsend-view-calendar').on('click', function() {
                self.viewMode = 'calendar';
                $(this).addClass('bcsend-view-active');
                $('#bcsend-view-list').removeClass('bcsend-view-active');
                $('#bcsend-list-view').hide();
                $('#bcsend-calendar-view').show();
            });
        },

        /* ============================================================
           Status Filter
           ============================================================ */

        /**
         * Bind status filter apply button.
         */
        bindStatusFilter: function() {
            $('#bcsend-apply-filter').on('click', function() {
                var status = $('#bcsend-status-filter').val();
                var url = new URL(window.location.href);
                url.searchParams.set('status', status);
                url.searchParams.delete('paged');
                window.location.href = url.toString();
            });
        },

        // List view and pagination are server-rendered; no AJAX loading needed.

        /* ============================================================
           Bulk Delete
           ============================================================ */

        /**
         * Bind bulk delete functionality.
         */
        bindBulkDelete: function() {
            var self = this;

            $('#bcsend-select-all').on('change', function() {
                var checked = $(this).prop('checked');
                $('.bcsend-campaign-checkbox').prop('checked', checked);
            });

            // Enable/disable delete button based on checkbox selection.
            $(document).on('change', '.bcsend-campaign-checkbox', function() {
                var anyChecked = $('.bcsend-campaign-checkbox:checked').length > 0;
                $('#bcsend-delete-selected').prop('disabled', !anyChecked);
            });

            $('#bcsend-delete-selected').on('click', function() {
                var $btn = $(this);
                var ids = [];

                $('.bcsend-campaign-checkbox:checked').each(function() {
                    ids.push($(this).val());
                });

                if (!ids.length) {
                    Bcsend.notify('No draft campaigns selected.', 'warning');
                    return;
                }

                Bcsend.loading($btn, true);
                $('#bcsend-bulk-status').text('Deleting...');
                var completed = 0;
                var total = ids.length;
                var errors = 0;

                $.each(ids, function(i, id) {
                    Bcsend.ajax('bcsend_delete_campaign', { id: id }, function(response) {
                        completed++;
                        if (!response.success) {
                            errors++;
                        }

                        if (completed >= total) {
                            Bcsend.loading($btn, false);
                            $('#bcsend-bulk-status').text('');
                            if (errors > 0) {
                                Bcsend.notify(errors + ' campaign(s) failed to delete.', 'error');
                            } else {
                                Bcsend.notify(total + ' campaign(s) deleted.', 'success');
                            }
                            // Reload the page to refresh server-rendered table.
                            window.location.reload();
                        }
                    });
                });
            });
        },

        /**
         * Bind send-now buttons on queue rows.
         */
        bindSendNow: function() {
            $(document).on('click', '.bcsend-send-now', function() {
                var $btn = $(this);
                var id = $btn.data('campaign-id');

                if ($btn.data('confirming')) {
                    Bcsend.loading($btn, true);

                    Bcsend.ajax('bcsend_send_now', { id: id }, function(response) {
                        Bcsend.loading($btn, false);

                        if (response.success) {
                            Bcsend.notify(response.data.message || 'Campaign sent.', 'success');
                            window.location.reload();
                        } else {
                            var errMsg = (response.data && response.data.message) ? response.data.message : 'Send failed.';
                            Bcsend.notify(errMsg, 'error');
                            $btn.text('Send Now').data('confirming', false);
                        }
                    });
                } else {
                    $btn.text('Confirm Send?').data('confirming', true);
                    setTimeout(function() {
                        if ($btn.data('confirming')) {
                            $btn.text('Send Now').data('confirming', false);
                        }
                    }, 3000);
                }
            });
        },

        /**
         * Bind revert-to-draft buttons on queue rows.
         */
        bindRevertToDraft: function() {
            $(document).on('click', '.bcsend-revert-to-draft', function() {
                var $btn = $(this);
                var id = $btn.data('campaign-id');

                if ($btn.data('confirming')) {
                    Bcsend.loading($btn, true);

                    Bcsend.ajax('bcsend_revert_to_draft', { id: id }, function(response) {
                        Bcsend.loading($btn, false);

                        if (response.success) {
                            Bcsend.notify(response.data.message || 'Reverted to draft.', 'success');
                            window.location.reload();
                        } else {
                            var errMsg = (response.data && response.data.message) ? response.data.message : 'Failed to revert.';
                            Bcsend.notify(errMsg, 'error');
                            $btn.text('Revert to Draft').data('confirming', false);
                        }
                    });
                } else {
                    $btn.text('Confirm?').data('confirming', true);
                    setTimeout(function() {
                        if ($btn.data('confirming')) {
                            $btn.text('Revert to Draft').data('confirming', false);
                        }
                    }, 3000);
                }
            });
        },

        // Calendar view is server-rendered; no AJAX calendar methods needed.

        /**
         * Placeholder to satisfy init call.
         */
        bindCalendarNav: function() {
            // Calendar navigation uses server-side links in the view.
        }
    };

    $(document).ready(function() {
        Queue.init();
    });

})(jQuery);
