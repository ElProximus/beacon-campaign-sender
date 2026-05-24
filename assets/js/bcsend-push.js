/**
 * Beacon Campaign Sender - Push Notifications Page JavaScript
 *
 * Handles composer: character counters, phone preview, schedule toggle,
 * recipient mode toggle, user search, and AJAX submission.
 * Handles list: cancel and delete actions.
 *
 * @package Bcsend_Plugin
 * @since   2.2.0
 */

(function($) {
    'use strict';

    var selectedUsers = [];
    var searchTimer = null;

    /**
     * Update the phone mockup preview with current field values.
     */
    function updatePreview() {
        var title   = $.trim($('#bcsend-push-title').val()) || 'Push Title';
        var message = $.trim($('#bcsend-push-message').val()) || 'Your message preview will appear here.';

        $('#bcsend-push-preview-title').text(title);
        $('#bcsend-push-preview-message').text(message);
    }

    /**
     * Update character counter for an input.
     */
    function updateCounter($input, counterId) {
        var len = $input.val().length;
        $(counterId).text(len);
    }

    /**
     * Update the submit button label based on timing selection.
     */
    function updateSubmitLabel() {
        var timing = $('input[name="bcsend-push-timing"]:checked').val();
        var label  = timing === 'schedule' ? bcsendAdmin.strings.schedulePush || 'Schedule Push Notification' : bcsendAdmin.strings.sendPush || 'Send Push Notification';
        $('#bcsend-push-submit').text(label);
    }

    /**
     * Add a user chip to the selected users area.
     */
    function addUserChip(userId, displayName) {
        // Prevent duplicates.
        if (selectedUsers.indexOf(userId) !== -1) {
            return;
        }

        selectedUsers.push(userId);

        var $chip = $('<span class="bcsend-push-user-chip" data-user-id="' + userId + '" style="display: inline-flex; align-items: center; gap: 4px; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 3px; padding: 4px 8px; font-size: 13px;">' +
            Bcsend.escapeHtml(displayName) +
            ' <button type="button" class="bcsend-push-remove-user" style="background: none; border: none; cursor: pointer; color: #d63638; font-size: 16px; line-height: 1; padding: 0;">&times;</button>' +
            '</span>');

        $('#bcsend-push-selected-users').append($chip);
    }

    /**
     * Collect form data and submit the push notification.
     */
    function submitPush() {
        var $btn = $('#bcsend-push-submit');
        var timing = $('input[name="bcsend-push-timing"]:checked').val();
        var recipientType = $('input[name="bcsend-push-recipients"]:checked').val();

        var data = {
            title:       $.trim($('#bcsend-push-title').val()),
            message:     $.trim($('#bcsend-push-message').val()),
            link_url:    $.trim($('#bcsend-push-link-url').val()),
            target_type: recipientType
        };

        // Validation.
        if (!data.message) {
            Bcsend.notify('Message is required.', 'error');
            $('#bcsend-push-message').focus();
            return;
        }

        // Schedule.
        if (timing === 'schedule') {
            var schedDate = $('#bcsend-push-schedule-date').val();
            var schedTime = $('#bcsend-push-schedule-time').val();

            if (!schedDate || !schedTime) {
                Bcsend.notify('Please select a date and time for scheduling.', 'error');
                return;
            }

            data.is_scheduled = 1;
            data.scheduled_at = schedDate + 'T' + schedTime + ':00';
            data.tz_offset    = new Date().getTimezoneOffset();
        }

        // Target data.
        if (recipientType === 'by_role') {
            var roles = [];
            $('.bcsend-push-role-checkbox:checked').each(function() {
                roles.push($(this).val());
            });
            if (roles.length === 0) {
                Bcsend.notify('Please select at least one role.', 'error');
                return;
            }
            data.target_data = JSON.stringify(roles);
        } else if (recipientType === 'specific_users') {
            if (selectedUsers.length === 0) {
                Bcsend.notify('Please select at least one user.', 'error');
                return;
            }
            data.target_data = JSON.stringify(selectedUsers);
        }

        Bcsend.loading($btn, true);

        Bcsend.ajax('bcsend_push_submit', data, function(response) {
            Bcsend.loading($btn, false);

            if (response.success) {
                Bcsend.notify(response.data.message || 'Push notification sent!', 'success');
                // Redirect to list after brief delay.
                setTimeout(function() {
                    window.location.href = response.data.redirect || (bcsendAdmin.ajaxUrl.replace('admin-ajax.php', 'admin.php') + '?page=bcsend-push');
                }, 1000);
            } else {
                Bcsend.notify(response.data.message || 'Failed to send push notification.', 'error');
            }
        });
    }

    $(document).ready(function() {

        // ---- Composer: Character counters & preview ----
        $('#bcsend-push-title').on('input', function() {
            updateCounter($(this), '#bcsend-push-title-count');
            updatePreview();
        });

        $('#bcsend-push-message').on('input', function() {
            updateCounter($(this), '#bcsend-push-message-count');
            updatePreview();
        });

        // ---- Composer: Schedule toggle ----
        $('input[name="bcsend-push-timing"]').on('change', function() {
            if ($(this).val() === 'schedule') {
                $('#bcsend-push-schedule-fields').slideDown(200);
            } else {
                $('#bcsend-push-schedule-fields').slideUp(200);
            }
            updateSubmitLabel();
        });

        // ---- Composer: Recipient mode toggle ----
        $('input[name="bcsend-push-recipients"]').on('change', function() {
            var mode = $(this).val();
            $('#bcsend-push-role-fields')[mode === 'by_role' ? 'slideDown' : 'slideUp'](200);
            $('#bcsend-push-user-fields')[mode === 'specific_users' ? 'slideDown' : 'slideUp'](200);
        });

        // ---- Composer: User search ----
        $('#bcsend-push-user-search').on('input', function() {
            var term = $.trim($(this).val());
            var $results = $('#bcsend-push-user-results');

            if (searchTimer) {
                clearTimeout(searchTimer);
            }

            if (term.length < 2) {
                $results.empty().hide();
                return;
            }

            searchTimer = setTimeout(function() {
                Bcsend.ajax('bcsend_push_search_users', { search: term }, function(response) {
                    $results.empty();

                    if (response.success && response.data.users && response.data.users.length) {
                        $.each(response.data.users, function(i, user) {
                            var selected = selectedUsers.indexOf(user.id) !== -1;
                            $results.append(
                                '<div class="bcsend-push-user-result" data-id="' + user.id + '" data-name="' + Bcsend.escapeHtml(user.name) + '" style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f1;' + (selected ? ' opacity: 0.5;' : '') + '">' +
                                Bcsend.escapeHtml(user.name) + ' <small style="color: #646970;">' + Bcsend.escapeHtml(user.email) + '</small>' +
                                '</div>'
                            );
                        });
                        $results.show();
                    } else {
                        $results.append('<div style="padding: 8px 12px; color: #646970;">No users found.</div>');
                        $results.show();
                    }
                });
            }, 350);
        });

        // Click on user search result.
        $(document).on('click', '.bcsend-push-user-result', function() {
            var userId = parseInt($(this).data('id'), 10);
            var name   = $(this).data('name');
            addUserChip(userId, name);
            $('#bcsend-push-user-results').empty().hide();
            $('#bcsend-push-user-search').val('');
        });

        // Remove user chip.
        $(document).on('click', '.bcsend-push-remove-user', function() {
            var $chip  = $(this).closest('.bcsend-push-user-chip');
            var userId = parseInt($chip.data('user-id'), 10);
            selectedUsers = selectedUsers.filter(function(id) { return id !== userId; });
            $chip.remove();
        });

        // Close search results when clicking outside.
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#bcsend-push-user-search, #bcsend-push-user-results').length) {
                $('#bcsend-push-user-results').empty().hide();
            }
        });

        // ---- Composer: Submit ----
        $('#bcsend-push-submit').on('click', submitPush);

        // ---- List: Cancel push ----
        $(document).on('click', '.bcsend-push-cancel', function() {
            var $btn = $(this);
            var pushId = $btn.data('id');

            if (!confirm('Cancel this scheduled push notification?')) {
                return;
            }

            Bcsend.loading($btn, true);
            Bcsend.ajax('bcsend_push_cancel', { push_id: pushId }, function(response) {
                Bcsend.loading($btn, false);
                if (response.success) {
                    location.reload();
                } else {
                    Bcsend.notify(response.data.message || 'Failed to cancel.', 'error');
                }
            });
        });

        // ---- List: Delete push ----
        $(document).on('click', '.bcsend-push-delete', function() {
            var $btn = $(this);
            var pushId = $btn.data('id');

            if (!confirm('Delete this push notification? This cannot be undone.')) {
                return;
            }

            Bcsend.loading($btn, true);
            Bcsend.ajax('bcsend_push_delete', { push_id: pushId }, function(response) {
                Bcsend.loading($btn, false);
                if (response.success) {
                    location.reload();
                } else {
                    Bcsend.notify(response.data.message || 'Failed to delete.', 'error');
                }
            });
        });

    });

})(jQuery);
