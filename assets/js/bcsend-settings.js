/**
 * Beacon Campaign Sender - Settings Page JavaScript
 *
 * Handles tab persistence, API connection testing,
 * Firebase JSON validation, and base template management.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

(function($) {
    'use strict';

    var Settings = {

        /**
         * Initialize settings page functionality.
         */
        init: function() {
            this.restoreTab();
            this.bindTestButtons();
            this.bindZernioControls();
            this.bindFirebaseValidation();
            this.bindTemplatePreview();
            this.bindTemplateReset();
            this.bindModelSelector();
            this.bindSmtpToggle();
            this.bindSecretReplacements();
        },

        /**
         * Restore the active tab from URL hash on page load.
         */
        restoreTab: function() {
            var hash = window.location.hash.replace('#', '');
            if (hash) {
                var $tab = $('.bcsend-nav-tab[data-tab="' + hash + '"]');
                if ($tab.length) {
                    $tab.trigger('click');
                }
            }
        },

        /**
         * Bind click handlers for API test connection buttons.
         */
        bindTestButtons: function() {
            var actionMap = {
                brevo: 'bcsend_test_brevo',
                zernio: 'bcsend_test_zernio',
                anthropic: 'bcsend_test_anthropic',
                openai: 'bcsend_test_openai',
                firebase: 'bcsend_test_firebase'
            };

            $(document).on('click', '.bcsend-test-connection', function() {
                var $btn = $(this);
                var service = $btn.data('service');
                var action = actionMap[service];
                var $result = $('#bcsend-' + service + '-test-result');

                if (!action || !$result.length) {
                    return;
                }

                Settings.runTest($btn, $result, action);
            });
        },

        bindZernioControls: function() {
            var self = this;

            $('#bcsend-zernio-profile').on('change', function() {
                var $select = $(this);
                var profileId = $select.val() || '';
                var profileName = $.trim($select.find('option:selected').text() || '');

                Bcsend.ajax('bcsend_zernio_set_profile', {
                    profile_id: profileId,
                    profile_name: profileName
                }, function() {});
            });

            $('#bcsend-zernio-fetch-profiles').on('click', function() {
                var $btn = $(this);
                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_zernio_fetch_profiles', {}, function(response) {
                    Bcsend.loading($btn, false);

                    if (!response.success) {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to load profiles.', 'error');
                        return;
                    }

                    self.renderProfiles(response.data.profiles || []);
                    Bcsend.notify('Profiles loaded.', 'success');
                });
            });

            $('#bcsend-zernio-sync-accounts').on('click', function() {
                var $btn = $(this);
                var $selectedProfile = $('#bcsend-zernio-profile option:selected');
                Bcsend.loading($btn, true);
                $('#bcsend-zernio-sync-status').text('Syncing...');

                Bcsend.ajax('bcsend_zernio_sync_accounts', {
                    profile_id: $('#bcsend-zernio-profile').val() || '',
                    profile_name: $.trim($selectedProfile.text() || '')
                }, function(response) {
                    Bcsend.loading($btn, false);
                    $('#bcsend-zernio-sync-status').text('');

                    if (!response.success) {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to sync accounts.', 'error');
                        return;
                    }

                    self.renderAccounts(response.data.accounts || []);
                    Bcsend.notify('Accounts synced.', 'success');
                });
            });

            $('#bcsend-zernio-sync-webhook').on('click', function() {
                var $btn = $(this);
                Bcsend.loading($btn, true);
                $('#bcsend-zernio-webhook-status').text('Syncing...');

                Bcsend.ajax('bcsend_zernio_sync_webhook', {}, function(response) {
                    Bcsend.loading($btn, false);
                    $('#bcsend-zernio-webhook-status').text('');

                    if (!response.success) {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to sync webhook.', 'error');
                        return;
                    }

                    Bcsend.notify(response.data.message || 'Webhook synced.', 'success');
                });
            });

            $('#bcsend-zernio-clear-webhook').on('click', function() {
                var $btn = $(this);
                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_zernio_clear_webhook_diagnostics', {}, function(response) {
                    Bcsend.loading($btn, false);

                    if (!response.success) {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to clear diagnostics.', 'error');
                        return;
                    }

                    $('#bcsend-zernio-last-received').text('Never');
                    $('#bcsend-zernio-last-status').text('N/A');
                    $('#bcsend-zernio-last-event').text('N/A');
                    $('#bcsend-zernio-last-signature').text('N/A');
                    $('#bcsend-zernio-last-error').text('None');
                    $('#bcsend-zernio-last-payload').val('');
                    Bcsend.notify(response.data.message || 'Diagnostics cleared.', 'success');
                });
            });

            $('#bcsend-zernio-test-webhook').on('click', function() {
                var $btn = $(this);
                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_zernio_test_webhook_diagnostics', {}, function(response) {
                    Bcsend.loading($btn, false);

                    if (!response.success) {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to write test diagnostic.', 'error');
                        return;
                    }

                    location.reload();
                });
            });

        },

        renderProfiles: function(profiles) {
            var $select = $('#bcsend-zernio-profile');
            if (!$select.length) {
                return;
            }

            var currentValue = $select.val() || '';

            if (profiles && !Array.isArray(profiles)) {
                if (Array.isArray(profiles.profiles)) {
                    profiles = profiles.profiles;
                } else if (Array.isArray(profiles.items)) {
                    profiles = profiles.items;
                } else if (Array.isArray(profiles.data)) {
                    profiles = profiles.data;
                } else if (profiles.data && Array.isArray(profiles.data.profiles)) {
                    profiles = profiles.data.profiles;
                } else {
                    profiles = Object.values(profiles);
                }
            }

            $select.empty();

            if (!profiles.length) {
                $select.append('<option value="">No profiles found</option>');
                return;
            }

            $.each(profiles, function(_, profile) {
                if (!profile || typeof profile !== 'object') {
                    return;
                }

                var id = String(
                    profile.id ||
                    profile._id ||
                    profile.profileId ||
                    profile.profile_id ||
                    profile.uuid ||
                    ''
                );
                var name = profile.name || profile.displayName || profile.title || profile.label || id;

                if (!id) {
                    return;
                }

                $select.append('<option value="' + Bcsend.escapeHtml(id) + '">' + Bcsend.escapeHtml(name) + '</option>');
            });

            if (!$select.children().length) {
                $select.append('<option value="">No profiles found</option>');
                return;
            }

            if (currentValue && $select.find('option[value="' + currentValue + '"]').length) {
                $select.val(currentValue);
            }

            $select.trigger('change');
        },

        renderAccounts: function(accounts) {
            var $container = $('#bcsend-zernio-accounts-list');
            if (!$container.length) {
                return;
            }

            if (accounts && !Array.isArray(accounts)) {
                if (Array.isArray(accounts.accounts)) {
                    accounts = accounts.accounts;
                } else if (Array.isArray(accounts.items)) {
                    accounts = accounts.items;
                } else if (Array.isArray(accounts.data)) {
                    accounts = accounts.data;
                } else if (accounts.data && Array.isArray(accounts.data.accounts)) {
                    accounts = accounts.data.accounts;
                } else {
                    accounts = Object.values(accounts);
                }
            }

            if (!Array.isArray(accounts) || !accounts.length) {
                $container.html('<p class="description">No synced accounts yet.</p>');
                return;
            }

            var html = '<table class="widefat striped"><thead><tr><th>Platform</th><th>Username</th><th>Account ID</th></tr></thead><tbody>';
            $.each(accounts, function(_, account) {
                if (!account || typeof account !== 'object') {
                    return;
                }

                var platform = account.platform || account.type || account.network || '';
                var username = account.username || account.handle || account.name || account.displayName || account.label || '';
                var accountId = String(
                    account.id ||
                    account._id ||
                    account.accountId ||
                    account.account_id ||
                    (account.account && (account.account.id || account.account.accountId || account.account.uuid)) ||
                    account.uuid ||
                    ''
                );

                if (!platform && !username && !accountId) {
                    return;
                }

                html += '<tr>' +
                    '<td>' + Bcsend.escapeHtml(String(platform)) + '</td>' +
                    '<td>' + Bcsend.escapeHtml(String(username)) + '</td>' +
                    '<td><code>' + Bcsend.escapeHtml(accountId) + '</code></td>' +
                    '</tr>';
            });
            html += '</tbody></table>';

            if (html.indexOf('<tr>') === -1) {
                $container.html('<p class="description">No synced accounts yet.</p>');
                return;
            }

            $container.html(html);
        },

        /**
         * Execute a test connection AJAX call and display result inline.
         *
         * @param {jQuery} $btn    The button element.
         * @param {jQuery} $result The result display element.
         * @param {string} action  The AJAX action name.
         */
        runTest: function($btn, $result, action) {
            Bcsend.loading($btn, true);
            $result.removeClass('is-success is-error').hide().empty();

            Bcsend.ajax(action, {}, function(response) {
                Bcsend.loading($btn, false);
                if (response.success) {
                    $result
                        .addClass('is-success')
                        .html(Bcsend.escapeHtml(response.data.message || bcsendAdmin.strings.testSuccess))
                        .show();
                } else {
                    $result
                        .addClass('is-error')
                        .html(Bcsend.escapeHtml(response.data.message || bcsendAdmin.strings.testFailed))
                        .show();
                }
            });
        },

        /**
         * Bind real-time validation for Firebase service account JSON textarea.
         */
        bindFirebaseValidation: function() {
            var $textarea = $('#bcsend-firebase-json');
            if (!$textarea.length) {
                return;
            }

            var $hint = $textarea.closest('.bcsend-form-group').find('.bcsend-form-hint');

            $textarea.on('blur', function() {
                var val = $.trim($textarea.val());
                if (!val) {
                    $hint.text('Paste your Firebase service account JSON here.').css('color', '');
                    return;
                }

                try {
                    var parsed = JSON.parse(val);
                    if (parsed.project_id && parsed.private_key && parsed.client_email) {
                        $hint.text('Valid JSON with required fields detected (project_id, private_key, client_email).').css('color', '#00a32a');
                    } else {
                        var missing = [];
                        if (!parsed.project_id) { missing.push('project_id'); }
                        if (!parsed.private_key) { missing.push('private_key'); }
                        if (!parsed.client_email) { missing.push('client_email'); }
                        $hint.text('Valid JSON but missing required fields: ' + missing.join(', ')).css('color', '#dba617');
                    }
                } catch (e) {
                    $hint.text('Invalid JSON format. Please check your input.').css('color', '#d63638');
                }
            });
        },

        /**
         * Bind the base template preview button.
         */
        bindTemplatePreview: function() {
            $('#bcsend-preview-template').on('click', function(e) {
                e.preventDefault();
                var $textarea = $('#bcsend-base-template');
                var html = $textarea.val();
                if (!html) {
                    Bcsend.notify('No template content to preview.', 'warning');
                    return;
                }

                var win = window.open('', '_blank', 'width=700,height=600');
                if (win) {
                    win.document.open();
                    win.document.write(html);
                    win.document.close();
                }
            });
        },

        /**
         * Bind the reset-to-default button for the base template.
         */
        bindTemplateReset: function() {
            $('#bcsend-reset-template').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_get_default_template', {}, function(response) {
                    Bcsend.loading($btn, false);
                    if (response.success && response.data.template) {
                        $('#bcsend-base-template').val(response.data.template);
                        Bcsend.notify('Template reset to default.', 'success');
                    } else {
                        Bcsend.notify(response.data.message || 'Failed to load default template.', 'error');
                    }
                });
            });
        },

        /**
         * Show/hide the Force From row based on the SMTP routing checkbox.
         */
        bindSmtpToggle: function() {
            var $checkbox = $('#bcsend-smtp-routing');
            var $row = $('.bcsend-smtp-force-from-row');

            if (!$checkbox.length) {
                return;
            }

            $checkbox.on('change', function() {
                if ($(this).is(':checked')) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        },

        /**
         * Keep saved secret fields disabled until the user explicitly replaces them.
         */
        bindSecretReplacements: function() {
            $('.bcsend-settings-form input[type="checkbox"][name*="[replace_"]').each(function() {
                var $toggle = $(this);
                var $field = $toggle.closest('td').find('input[type="password"], textarea').last();

                if (!$field.length) {
                    return;
                }

                var syncField = function() {
                    var isReplacing = $toggle.is(':checked');
                    $field.prop('disabled', !isReplacing);
                    if (!isReplacing) {
                        $field.val('');
                    }
                };

                syncField();
                $toggle.on('change', syncField);
            });
        },

        /**
         * Handle model selector changes for display purposes.
         */
        bindModelSelector: function() {
            $('#bcsend-anthropic-model').on('change', function() {
                var selected = $(this).val();
                var $info = $(this).closest('.bcsend-form-group').find('.bcsend-model-info');
                if ($info.length) {
                    $info.text('Selected model: ' + selected);
                }
            });
        }
    };

    $(document).ready(function() {
        Settings.init();
    });

})(jQuery);
