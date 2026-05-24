/**
 * Beacon Campaign Sender - Audiences Page JavaScript
 *
 * Handles Brevo list display, smart segment CRUD operations,
 * segment syncing, and dynamic form field management.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

(function($) {
    'use strict';

    var Audiences = {

        /**
         * Currently editing segment ID, or null for new.
         * @type {number|null}
         */
        editingSegmentId: null,

        /**
         * Initialize the audiences page.
         */
        init: function() {
            this.refreshSegmentCounts();
            this.bindQueryTypeChange();
            this.bindParamSearch();
            this.bindSegmentForm();
            this.bindSegmentActions();
            this.bindCreateToggle();
            this.bindSyncAll();
        },

        /**
         * Sync all segments, including importing Brevo lists as
         * brevo_list-type segments. Reloads on success so the
         * server-rendered segment table reflects newly imported lists.
         */
        bindSyncAll: function() {
            $('#bcsend-sync-brevo-lists').on('click', function() {
                var $btn = $(this);
                var $status = $('#bcsend-sync-all-status');

                if ($btn.data('syncing')) {
                    return;
                }
                $btn.data('syncing', true);
                Bcsend.loading($btn, true);
                $status.text('Syncing Brevo lists...');

                Bcsend.ajax('bcsend_sync_all_segments', {}, function(response) {
                    Bcsend.loading($btn, false);
                    $btn.data('syncing', false);
                    $status.text('');

                    if (response.success) {
                        Bcsend.notify(
                            (response.data && response.data.message) || 'Brevo lists synced.',
                            'success'
                        );
                        window.location.reload();
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Sync failed.';
                        Bcsend.notify(errMsg, 'error');
                    }
                });
            });
        },

        /* ============================================================
           Query Type Change
           ============================================================ */

        /**
         * Refresh contact counts for all segments on page load.
         * Fires a lightweight count request per segment row.
         */
        refreshSegmentCounts: function() {
            $('#bcsend-segments-body tr[data-segment-id]').each(function() {
                var $row = $(this);
                var id = $row.data('segment-id');
                var $countCell = $row.find('td:eq(2)');

                Bcsend.ajax('bcsend_get_segment_contacts', { id: id }, function(response) {
                    if (response.success && response.data.total !== undefined) {
                        $countCell.text(response.data.total.toLocaleString());
                    }
                });
            });
        },

        /**
         * Bind the query type dropdown to show/hide appropriate parameter fields.
         */
        bindQueryTypeChange: function() {
            $('#bcsend-segment-query-type').on('change', function() {
                var type = $(this).val();

                // Hide all param sections.
                $('.bcsend-param-category, .bcsend-param-product, .bcsend-param-days').hide();

                switch (type) {
                    case 'by_category':
                        $('.bcsend-param-category').show();
                        break;
                    case 'by_product':
                        $('.bcsend-param-product').show();
                        break;
                    case 'inactive':
                        $('.bcsend-param-days').show();
                        $('#bcsend-param-days-desc').text('Users inactive for this many days.');
                        break;
                    case 'new_members':
                        $('.bcsend-param-days').show();
                        $('#bcsend-param-days-desc').text('Users registered within this many days.');
                        break;
                    // all_customers, never_purchased, app_users have no extra params.
                }
            });
        },

        /* ============================================================
           Create Segment Toggle
           ============================================================ */

        /**
         * Bind the toggle button to show/hide the create segment form.
         */
        bindCreateToggle: function() {
            $('#bcsend-show-create-segment').on('click', function() {
                $('#bcsend-create-segment-form').slideToggle(200);
            });
        },

        /**
         * Search timer for category/product inputs.
         * @type {number|null}
         */
        paramSearchTimer: null,

        /**
         * Bind category and product search inputs in the segment form.
         */
        bindParamSearch: function() {
            var self = this;

            // Category search.
            $('#bcsend-param-category-search').on('input', function() {
                var term = $.trim($(this).val());
                var $results = $('#bcsend-param-category-results');

                if (self.paramSearchTimer) {
                    clearTimeout(self.paramSearchTimer);
                }

                if (term.length < 2) {
                    $results.empty().hide();
                    return;
                }

                self.paramSearchTimer = setTimeout(function() {
                    Bcsend.ajax('bcsend_search_categories', { search: term }, function(response) {
                        $results.empty();
                        if (response.success && response.data.categories && response.data.categories.length) {
                            $.each(response.data.categories, function(i, cat) {
                                $results.append(
                                    '<div class="bcsend-param-result-item" data-id="' + cat.id + '">' +
                                    Bcsend.escapeHtml(cat.name) + ' (' + cat.count + ')' +
                                    '</div>'
                                );
                            });
                            $results.show();
                        } else {
                            $results.append('<div class="bcsend-param-no-results">No categories found.</div>');
                            $results.show();
                        }
                    });
                }, 350);
            });

            $('#bcsend-param-category-results').on('click', '.bcsend-param-result-item', function() {
                var id = $(this).data('id');
                var name = $(this).text();
                $('#bcsend-param-category-id').val(id);
                $('#bcsend-param-category-search').val(name);
                $('#bcsend-param-category-results').empty().hide();
            });

            // Product search.
            $('#bcsend-param-product-search').on('input', function() {
                var term = $.trim($(this).val());
                var $results = $('#bcsend-param-product-results');

                if (self.paramSearchTimer) {
                    clearTimeout(self.paramSearchTimer);
                }

                if (term.length < 2) {
                    $results.empty().hide();
                    return;
                }

                self.paramSearchTimer = setTimeout(function() {
                    Bcsend.ajax('bcsend_search_products', { search: term }, function(response) {
                        $results.empty();
                        if (response.success && response.data.products && response.data.products.length) {
                            $.each(response.data.products, function(i, product) {
                                $results.append(
                                    '<div class="bcsend-param-result-item" data-id="' + product.id + '">' +
                                    Bcsend.escapeHtml(product.name) + ' — ' + Bcsend.escapeHtml(product.price) +
                                    '</div>'
                                );
                            });
                            $results.show();
                        } else {
                            $results.append('<div class="bcsend-param-no-results">No products found.</div>');
                            $results.show();
                        }
                    });
                }, 350);
            });

            $('#bcsend-param-product-results').on('click', '.bcsend-param-result-item', function() {
                var id = $(this).data('id');
                var name = $(this).text();
                $('#bcsend-param-product-id').val(id);
                $('#bcsend-param-product-search').val(name);
                $('#bcsend-param-product-results').empty().hide();
            });

            // Close dropdowns when clicking outside.
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.bcsend-param-category').length) {
                    $('#bcsend-param-category-results').empty().hide();
                }
                if (!$(e.target).closest('.bcsend-param-product').length) {
                    $('#bcsend-param-product-results').empty().hide();
                }
            });
        },

        /* ============================================================
           Segment Form
           ============================================================ */

        /**
         * Bind the segment create/update form submission.
         */
        bindSegmentForm: function() {
            var self = this;

            $('#bcsend-save-segment').on('click', function() {
                var name = $.trim($('#bcsend-segment-name').val());
                var queryType = $('#bcsend-segment-query-type').val();

                if (!name) {
                    Bcsend.notify('Segment name is required.', 'warning');
                    return;
                }

                if (!queryType) {
                    Bcsend.notify('Please select a query type.', 'warning');
                    return;
                }

                var queryParams = {};

                switch (queryType) {
                    case 'by_category':
                        queryParams.category_id = $.trim($('#bcsend-param-category-id').val());
                        if (!queryParams.category_id) {
                            Bcsend.notify('Category is required for this query type.', 'warning');
                            return;
                        }
                        break;
                    case 'by_product':
                        queryParams.product_id = $.trim($('#bcsend-param-product-id').val());
                        if (!queryParams.product_id) {
                            Bcsend.notify('Product is required for this query type.', 'warning');
                            return;
                        }
                        break;
                    case 'inactive':
                    case 'new_members':
                        queryParams.days = parseInt($('#bcsend-param-days-input').val(), 10) || 30;
                        break;
                }

                // PHP requires 'type' field with allowed values.
                var data = {
                    name: name,
                    type: 'wc_customers',
                    query_type: queryType,
                    query_params: JSON.stringify(queryParams)
                };

                var $btn = $(this);
                Bcsend.loading($btn, true);
                $('#bcsend-segment-form-status').text('Saving...');

                if (self.editingSegmentId) {
                    data.id = self.editingSegmentId;
                    Bcsend.ajax('bcsend_update_segment', data, function(response) {
                        Bcsend.loading($btn, false);
                        $('#bcsend-segment-form-status').text('');
                        if (response.success) {
                            Bcsend.notify(response.data.message || 'Segment updated.', 'success');
                            self.resetForm();
                            window.location.reload();
                        } else {
                            var errMsg = (response.data && response.data.message) ? response.data.message : 'Failed to update segment.';
                            Bcsend.notify(errMsg, 'error');
                        }
                    });
                } else {
                    Bcsend.ajax('bcsend_create_segment', data, function(response) {
                        Bcsend.loading($btn, false);
                        $('#bcsend-segment-form-status').text('');
                        if (response.success) {
                            Bcsend.notify(response.data.message || 'Segment created.', 'success');
                            self.resetForm();
                            window.location.reload();
                        } else {
                            var errMsg = (response.data && response.data.message) ? response.data.message : 'Failed to create segment.';
                            Bcsend.notify(errMsg, 'error');
                        }
                    });
                }
            });

            $('#bcsend-cancel-segment').on('click', function() {
                self.resetForm();
                $('#bcsend-create-segment-form').hide();
            });
        },

        /**
         * Reset the segment form to its initial state.
         */
        resetForm: function() {
            this.editingSegmentId = null;
            $('#bcsend-segment-name').val('');
            $('#bcsend-segment-query-type').val($('#bcsend-segment-query-type option:first').val());
            $('.bcsend-param-category, .bcsend-param-product, .bcsend-param-days').hide();
            $('#bcsend-param-category-id').val('');
            $('#bcsend-param-category-search').val('');
            $('#bcsend-param-product-id').val('');
            $('#bcsend-param-product-search').val('');
            $('#bcsend-param-days-input').val('30');
            $('#bcsend-edit-segment-id').val('');
            $('#bcsend-save-segment').text('Save Segment');
            $('#bcsend-segment-form-title').text('New Smart Segment');
        },

        /* ============================================================
           Segment Actions (Edit, Delete, Sync)
           ============================================================ */

        /**
         * Bind action buttons on segment table rows.
         */
        bindSegmentActions: function() {
            var self = this;

            // View Contacts — expand/collapse email list below the row.
            $(document).on('click', '.bcsend-view-contacts', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $row = $btn.closest('tr');
                var id = $btn.data('segment-id');
                var $existing = $row.next('.bcsend-contacts-row');

                // Toggle off if already open.
                if ($existing.length) {
                    $existing.remove();
                    return;
                }

                // Remove any other open contacts rows.
                $('.bcsend-contacts-row').remove();

                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_get_segment_contacts', { id: id }, function(response) {
                    Bcsend.loading($btn, false);

                    if (!response.success) {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to load contacts.', 'error');
                        return;
                    }

                    var emails = response.data.emails || [];
                    var total = response.data.total || 0;
                    var displayMax = 100;
                    var displayEmails = emails.slice(0, displayMax);

                    // Update the count cell in the table row.
                    $row.find('td:eq(2)').text(total.toLocaleString());

                    var html = '<tr class="bcsend-contacts-row"><td colspan="5"><div class="bcsend-contacts-panel">';
                    html += '<div class="bcsend-contacts-header">';
                    html += '<strong>' + total + ' contact' + (total !== 1 ? 's' : '') + '</strong>';
                    if (total > 0) {
                        html += ' <button type="button" class="button button-small bcsend-copy-emails" data-emails="' + Bcsend.escapeHtml(emails.join(', ')) + '">Copy All</button>';
                    }
                    html += '</div>';

                    if (total === 0) {
                        html += '<p class="bcsend-contacts-empty">No contacts match this segment. Try syncing first.</p>';
                    } else {
                        html += '<div class="bcsend-contacts-list">';
                        $.each(displayEmails, function(i, email) {
                            html += '<span class="bcsend-contact-email">' + Bcsend.escapeHtml(email) + '</span>';
                        });
                        if (total > displayMax) {
                            html += '<span class="bcsend-contacts-more">and ' + (total - displayMax) + ' more...</span>';
                        }
                        html += '</div>';
                    }

                    html += '</div></td></tr>';
                    $row.after(html);
                });
            });

            // Copy emails to clipboard.
            $(document).on('click', '.bcsend-copy-emails', function(e) {
                e.preventDefault();
                var emails = $(this).data('emails');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(emails).then(function() {
                        Bcsend.notify('Emails copied to clipboard.', 'success');
                    });
                }
            });

            $(document).on('click', '.bcsend-edit-segment', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var id = $btn.data('segment-id');
                var name = $btn.data('segment-name');
                var queryType = $btn.data('segment-query-type');
                var queryParams = $btn.data('segment-params');

                if (typeof queryParams === 'string') {
                    try {
                        queryParams = JSON.parse(queryParams);
                    } catch (e2) {
                        queryParams = {};
                    }
                }
                queryParams = queryParams || {};

                self.editingSegmentId = id;
                $('#bcsend-edit-segment-id').val(id);
                $('#bcsend-segment-name').val(name);
                $('#bcsend-segment-query-type').val(queryType).trigger('change');

                if (queryType === 'by_category' && queryParams.category_id) {
                    $('#bcsend-param-category-id').val(queryParams.category_id);
                } else if (queryType === 'by_product' && queryParams.product_id) {
                    $('#bcsend-param-product-id').val(queryParams.product_id);
                } else if ((queryType === 'inactive' || queryType === 'new_members') && queryParams.days) {
                    $('#bcsend-param-days-input').val(queryParams.days);
                }

                $('#bcsend-save-segment').text('Update Segment');
                $('#bcsend-segment-form-title').text('Edit Segment');
                $('#bcsend-create-segment-form').show();

                $('html, body').animate({ scrollTop: $('#bcsend-create-segment-form').offset().top - 50 }, 300);
            });

            $(document).on('click', '.bcsend-delete-segment', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $row = $btn.closest('tr');
                var id = $btn.data('segment-id');

                // Inline confirmation: change button text temporarily.
                if ($btn.data('confirming')) {
                    Bcsend.loading($btn, true);

                    Bcsend.ajax('bcsend_delete_segment', { id: id }, function(response) {
                        Bcsend.loading($btn, false);

                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                            });
                            Bcsend.notify(response.data.message || 'Segment deleted.', 'success');
                        } else {
                            var errMsg = (response.data && response.data.message) ? response.data.message : 'Failed to delete segment.';
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

            $(document).on('click', '.bcsend-sync-segment', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $row = $btn.closest('tr');
                var id = $btn.data('segment-id');
                var $status = $row.find('.bcsend-segment-action-status');

                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_sync_segment', { id: id }, function(response) {
                    Bcsend.loading($btn, false);

                    if (response.success) {
                        var count = response.data.contact_count || 0;
                        $row.find('td:eq(2)').text(count.toLocaleString());
                        if (response.data.last_synced) {
                            $row.find('td:eq(3)').text(new Date(response.data.last_synced + ' UTC').toLocaleString());
                        }
                        $status.text('Synced: ' + count + ' contacts');
                        Bcsend.notify('Segment synced: ' + count + ' contacts.', 'success');
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Sync failed.';
                        Bcsend.notify(errMsg, 'error');
                    }
                });
            });
        }
    };

    $(document).ready(function() {
        Audiences.init();
    });

})(jQuery);
