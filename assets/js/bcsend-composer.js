/**
 * Beacon Campaign Sender - Composer Page JavaScript
 *
 * Single-screen campaign editor with inline HTML editing,
 * AI generation, and campaign scheduling.
 *
 * @package Bcsend_Plugin
 * @since   2.0.0
 */

(function($) {
    'use strict';

    var Composer = {

        campaignId: 0,
        htmlSynced: true,
        socialMediaByPlatform: {},
        socialMediaFrames: {},

        init: function() {
            this.campaignId = $('#bcsend-campaign-id').val() || this.getUrlParam('campaign_id');
            this.templateId = $('#bcsend-template-id').val() || this.getUrlParam('template_id');
            this.bindGenerate();
            this.bindFieldEvents();
            this.bindHtmlEditor();
            this.bindSchedule();
            this.bindActions();
            this.loadSegments();
            ContentLibrary.init();
            this.initSocialEditor();

            if (this.campaignId) {
                this.loadCampaign(this.campaignId);
            }
        },

        getUrlParam: function(name) {
            var url = new URL(window.location.href);
            return url.searchParams.get(name) || '';
        },

        initSocialEditor: function() {
            var self = this;
            this.socialMediaByPlatform = {};

            var sharedInitialMedia = $('#bcsend-social-shared-fields').attr('data-initial-media');
            try {
                self.socialMediaByPlatform.shared = self.normalizeMediaItems(sharedInitialMedia ? JSON.parse(sharedInitialMedia) : []);
            } catch (e) {
                self.socialMediaByPlatform.shared = [];
            }

            $('.bcsend-social-platform-block').each(function() {
                var platform = $(this).data('platform');
                var initialMedia = $(this).attr('data-initial-media');

                try {
                    self.socialMediaByPlatform[platform] = self.normalizeMediaItems(initialMedia ? JSON.parse(initialMedia) : []);
                } catch (e) {
                    self.socialMediaByPlatform[platform] = [];
                }

                self.updateSocialLinkUI(platform);
                self.renderPlatformMedia(platform);
                self.updateSocialPlatformStatus(platform);
            });

            self.updateSocialLinkUI('shared');
            self.renderPlatformMedia('shared');
            self.updateSharedSocialStatus();
            self.applySocialPostModeUI();
        },

        getSocialPostMode: function() {
            var mode = $('#bcsend-social-post-mode').val() || (bcsendAdmin && bcsendAdmin.socialConfig ? bcsendAdmin.socialConfig.postMode : 'single') || 'single';
            return mode === 'per_platform' ? 'per_platform' : 'single';
        },

        isSingleSocialMode: function() {
            return this.getSocialPostMode() === 'single';
        },

        getSupportedSocialRules: function() {
            return (bcsendAdmin && bcsendAdmin.socialConfig && bcsendAdmin.socialConfig.platforms) ? bcsendAdmin.socialConfig.platforms : {};
        },

        getPlatformRule: function(platform) {
            if (platform === 'shared') {
                return { requiresMedia: false, maxChars: this.getStrictestSelectedSocialLimit(), label: 'Social post' };
            }
            return this.getSupportedSocialRules()[platform] || { requiresMedia: false, maxChars: 500, label: platform };
        },

        getSupportedSocialLinkModes: function() {
            return (bcsendAdmin && bcsendAdmin.socialConfig && Array.isArray(bcsendAdmin.socialConfig.linkModes)) ? bcsendAdmin.socialConfig.linkModes : ['none', 'product', 'homepage', 'custom', 'link_in_bio'];
        },

        getPlatformMedia: function(platform) {
            if (!this.socialMediaByPlatform[platform]) {
                this.socialMediaByPlatform[platform] = [];
            }
            this.socialMediaByPlatform[platform] = this.normalizeMediaItems(this.socialMediaByPlatform[platform]);
            return this.socialMediaByPlatform[platform];
        },

        setPlatformMedia: function(platform, items) {
            this.socialMediaByPlatform[platform] = this.normalizeMediaItems(items);
            this.renderPlatformMedia(platform);
            this.updateSocialPlatformStatus(platform);
        },

        getContentLibraryImages: function() {
            return this.normalizeMediaItems(Array.isArray(ContentLibrary.selectedImages) ? ContentLibrary.selectedImages : []);
        },

        extractEmailMediaItems: function(html) {
            var items = [];
            var wrapper;

            if (!html || typeof html !== 'string') {
                return items;
            }

            wrapper = document.createElement('div');
            wrapper.innerHTML = html;

            Array.prototype.forEach.call(wrapper.querySelectorAll('img[src]'), function(img) {
                var src = (img.getAttribute('src') || '').trim();
                if (!src || src.indexOf('data:') === 0) {
                    return;
                }

                items.push({
                    id: 0,
                    type: 'image',
                    url: src,
                    alt: img.getAttribute('alt') || '',
                    title: img.getAttribute('title') || img.getAttribute('alt') || '',
                    thumb: src
                });
            });

            return this.normalizeMediaItems(items);
        },

        syncEmailImagesToContentLibrary: function(html) {
            var extracted = this.extractEmailMediaItems(html);

            if (!extracted.length) {
                return;
            }

            ContentLibrary.selectedImages = this.mergeMediaItems(ContentLibrary.selectedImages, extracted);
            ContentLibrary.renderSelectedImages();
        },

        getResolvedProductUrl: function() {
            var products = Array.isArray(ContentLibrary.selectedProducts) ? ContentLibrary.selectedProducts : [];
            return products.length && products[0].permalink ? products[0].permalink : '';
        },

        getResolvedHomepageUrl: function() {
            return (bcsendAdmin && bcsendAdmin.siteUrl) ? bcsendAdmin.siteUrl : (window.location.origin + '/');
        },

        getResolvedSocialLinkUrl: function(platform) {
            var mode = $('#bcsend-social-link-mode-' + platform).val() || 'none';
            var currentValue = $.trim($('#bcsend-social-link-url-' + platform).val() || '');

            if (mode === 'custom') {
                return currentValue;
            }
            if (mode === 'product') {
                return this.getResolvedProductUrl() || currentValue;
            }
            if (mode === 'homepage') {
                return this.getResolvedHomepageUrl() || currentValue;
            }
            return '';
        },

        getSelectedSocialRules: function() {
            var self = this;
            return this.getSelectedSocialPlatforms().map(function(platform) {
                return self.getPlatformRule(platform);
            });
        },

        getStrictestSelectedSocialLimit: function() {
            var limit = 500;
            var hasSelection = false;
            this.getSelectedSocialRules().forEach(function(rule) {
                var max = parseInt(rule.maxChars, 10) || 0;
                if (!max) {
                    return;
                }
                limit = hasSelection ? Math.min(limit, max) : max;
                hasSelection = true;
            });
            return hasSelection ? limit : 280;
        },

        selectedSocialRequiresMedia: function() {
            var required = false;
            this.getSelectedSocialRules().forEach(function(rule) {
                if (rule.requiresMedia) {
                    required = true;
                }
            });
            return required;
        },

        getSocialLinkSeparator: function() {
            return (bcsendAdmin && bcsendAdmin.socialConfig && typeof bcsendAdmin.socialConfig.linkSeparator === 'string') ? bcsendAdmin.socialConfig.linkSeparator : '\n\n';
        },

        getLinkInBioText: function() {
            return (bcsendAdmin && bcsendAdmin.socialConfig && typeof bcsendAdmin.socialConfig.linkInBioText === 'string') ? bcsendAdmin.socialConfig.linkInBioText : 'Link in bio';
        },

        normalizeSocialContentInput: function(content) {
            content = String(content || '');
            content = content.replace(/\r\n|\r/g, '\n');
            content = content.replace(/\n{3,}/g, '\n\n');
            return $.trim(content);
        },

        extractSocialText: function(value) {
            if (typeof value === 'string') {
                return value === '[object Object]' ? '' : value;
            }

            if (!value || typeof value !== 'object') {
                return '';
            }

            if (typeof value.text === 'string') {
                return value.text;
            }

            if (typeof value.caption === 'string') {
                return value.caption;
            }

            if (typeof value.content === 'string') {
                return value.content;
            }

            if (Array.isArray(value.parts)) {
                return value.parts.map(function(part) {
                    if (typeof part === 'string') {
                        return part;
                    }

                    if (part && typeof part === 'object') {
                        if (typeof part.text === 'string') {
                            return part.text;
                        }

                        if (typeof part.content === 'string') {
                            return part.content;
                        }
                    }

                    return '';
                }).join('\n');
            }

            return '';
        },

        normalizeSocialPayload: function(entry) {
            var payload = (entry && typeof entry === 'object' && !Array.isArray(entry)) ? $.extend({}, entry) : { content: entry };

            payload.content = this.normalizeSocialContentInput(this.extractSocialText(payload.content));
            payload.link_mode = typeof payload.link_mode === 'string' ? payload.link_mode : 'none';
            payload.link_url = typeof payload.link_url === 'string' ? payload.link_url : '';
            payload.media_items = this.normalizeMediaItems(payload.media_items);
            payload.media_suggestions = Array.isArray(payload.media_suggestions) ? payload.media_suggestions : [];

            return payload;
        },

        normalizeMediaItem: function(item) {
            if (!item) {
                return null;
            }

            if (typeof item === 'string') {
                return {
                    id: 0,
                    type: 'image',
                    url: item,
                    alt: '',
                    title: '',
                    thumb: item
                };
            }

            if (typeof item !== 'object') {
                return null;
            }

            var url = item.url || item.source_url || item.src || '';
            var thumb = item.thumb || item.thumbnail || item.thumb_url || url;

            if (!url) {
                return null;
            }

            return {
                id: parseInt(item.id, 10) || 0,
                type: item.type || 'image',
                url: String(url),
                alt: item.alt || item.alt_text || '',
                title: item.title || item.name || '',
                thumb: String(thumb || url)
            };
        },

        normalizeMediaItems: function(items) {
            var self = this;

            if (!Array.isArray(items)) {
                return [];
            }

            return items.map(function(item) {
                return self.normalizeMediaItem(item);
            }).filter(function(item) {
                return item && item.url;
            });
        },

        mergeMediaItems: function(existingItems, newItems) {
            var merged = [];
            var seen = {};

            this.normalizeMediaItems(existingItems).concat(this.normalizeMediaItems(newItems)).forEach(function(item) {
                var key = item.id ? ('id:' + item.id) : ('url:' + item.url);
                if (seen[key]) {
                    return;
                }
                seen[key] = true;
                merged.push(item);
            });

            return merged;
        },

        getPlatformMediaFrame: function(platform) {
            var self = this;

            if (this.socialMediaFrames[platform]) {
                return this.socialMediaFrames[platform];
            }

            var mediaFrame = wp.media({
                title: 'Choose Platform Images',
                library: { type: 'image' },
                multiple: 'add',
                button: { text: 'Use Images' }
            });

            mediaFrame.on('open', function() {
                var selection = mediaFrame.state().get('selection');
                selection.reset();

                self.getPlatformMedia(platform).forEach(function(item) {
                    if (!item.id) {
                        return;
                    }

                    var attachment = wp.media.attachment(item.id);
                    if (attachment) {
                        attachment.fetch();
                        selection.add(attachment);
                    }
                });
            });

            mediaFrame.on('select', function() {
                var items = [];
                mediaFrame.state().get('selection').each(function(item) {
                    var attachment = item.toJSON();
                    items.push({
                        id: attachment.id || 0,
                        type: 'image',
                        url: attachment.url,
                        alt: attachment.alt || attachment.title || '',
                        title: attachment.title || '',
                        thumb: (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url
                    });
                });

                self.setPlatformMedia(platform, self.mergeMediaItems(self.getPlatformMedia(platform), items));
                Bcsend.notify('Platform images updated for ' + self.getPlatformRule(platform).label + '.', 'success');
            });

            this.socialMediaFrames[platform] = mediaFrame;
            return mediaFrame;
        },

        appendSocialLinkText: function(content, text) {
            content = this.normalizeSocialContentInput(content);
            text = $.trim(String(text || ''));

            if (!text) {
                return content;
            }

            if (content.indexOf(text) === -1) {
                content = $.trim(content + this.getSocialLinkSeparator() + text);
            }

            return content;
        },

        resolveSuggestedMediaItems: function(payload) {
            var items = [];
            var suggestions = Array.isArray(payload.media_suggestions) ? payload.media_suggestions : [];
            var seen = {};

            if (Array.isArray(payload.media_items) && payload.media_items.length) {
                return this.normalizeMediaItems(payload.media_items);
            }

            suggestions.forEach(function(token) {
                if (token === 'selected_media') {
                    (ContentLibrary.selectedImages || []).forEach(function(img) {
                        if (img.url && !seen[img.url]) {
                            seen[img.url] = true;
                            items.push({
                                id: img.id || 0,
                                type: 'image',
                                url: img.url,
                                alt: img.alt || img.title || '',
                                title: img.title || '',
                                thumb: img.thumb || img.url
                            });
                        }
                    });
                }

                if (token === 'product_image') {
                    (ContentLibrary.selectedProducts || []).forEach(function(product) {
                        if (product.image && !seen[product.image]) {
                            seen[product.image] = true;
                            items.push({
                                id: 0,
                                type: 'image',
                                url: product.image,
                                alt: product.name || '',
                                title: product.name || '',
                                thumb: product.image
                            });
                        }
                    });
                }

                if (token === 'featured_image') {
                    (ContentLibrary.selectedPosts || []).forEach(function(post) {
                        if (post.image && !seen[post.image]) {
                            seen[post.image] = true;
                            items.push({
                                id: 0,
                                type: 'image',
                                url: post.image,
                                alt: post.title || '',
                                title: post.title || '',
                                thumb: post.image
                            });
                        }
                    });
                }
            });

            return this.normalizeMediaItems(items);
        },

        getEffectiveSocialContent: function(platform) {
            var content = this.normalizeSocialContentInput($('#bcsend-social-content-' + platform).val() || '');
            if (platform === 'shared') {
                content = this.normalizeSocialContentInput($('#bcsend-social-content-shared').val() || '');
            }
            var mode = $('#bcsend-social-link-mode-' + platform).val() || 'none';
            var url = this.getResolvedSocialLinkUrl(platform);

            if (mode === 'link_in_bio') {
                return this.appendSocialLinkText(content, this.getLinkInBioText());
            }

            return this.appendSocialLinkText(content, url);
        },

        getEffectivePlatformMedia: function(platform) {
            var media = this.getPlatformMedia(platform);
            if (media.length) {
                return media;
            }
            return this.getContentLibraryImages();
        },

        updateSocialLinkUI: function(platform) {
            var mode = $('#bcsend-social-link-mode-' + platform).val() || 'none';
            var $input = $('#bcsend-social-link-url-' + platform);
            var $help = $('.bcsend-social-link-help[data-platform="' + platform + '"]');
            var resolved = '';
            var helpText = '';

            if (mode === 'custom') {
                $input.prop('readonly', false).prop('disabled', false).attr('placeholder', 'Enter a custom URL');
                helpText = 'Custom URL will be appended when the post is sent.';
            } else if (mode === 'product') {
                resolved = this.getResolvedProductUrl();
                $input.val(resolved);
                $input.prop('readonly', true).prop('disabled', false);
                helpText = resolved ? 'Using the first selected product URL.' : 'Select a product in Content Library to resolve this URL.';
            } else if (mode === 'homepage') {
                resolved = this.getResolvedHomepageUrl();
                $input.val(resolved);
                $input.prop('readonly', true).prop('disabled', false);
                helpText = 'Using the site homepage URL.';
            } else if (mode === 'link_in_bio') {
                $input.val('');
                $input.prop('readonly', true).prop('disabled', false);
                helpText = 'This post will append "' + this.getLinkInBioText() + '" instead of a URL.';
            } else {
                $input.val('');
                $input.prop('readonly', true).prop('disabled', false);
                helpText = 'No link will be appended to this post.';
            }

            $help.text(helpText);
        },

        renderPlatformMedia: function(platform) {
            var self = this;
            var items = this.getPlatformMedia(platform);
            var $list = $('.bcsend-social-media-list[data-platform="' + platform + '"]');
            var $help = $('.bcsend-social-media-help[data-platform="' + platform + '"]');
            var fallbackCount = this.getContentLibraryImages().length;

            if (!$list.length) {
                return;
            }

            $list.empty();
            $.each(items, function(_, item) {
                var thumb = item.thumb || item.url || '';
                if (!thumb) {
                    return;
                }

                $list.append(
                    '<div class="bcsend-social-media-item" data-platform="' + platform + '" data-url="' + Bcsend.escapeHtml(item.url || '') + '">' +
                    '<img src="' + Bcsend.escapeHtml(thumb) + '" alt="" />' +
                    '<button type="button" class="bcsend-social-media-remove" data-platform="' + platform + '" data-url="' + Bcsend.escapeHtml(item.url || '') + '">&times;</button>' +
                    '</div>'
                );
            });

            if (items.length && platform === 'shared') {
                $help.text(items.length === 1 ? '1 shared image selected.' : items.length + ' shared images selected.');
            } else if (items.length) {
                $help.text(items.length === 1 ? '1 platform-specific image selected.' : items.length + ' platform-specific images selected.');
            } else if (fallbackCount) {
                $help.text(fallbackCount === 1 ? 'No platform-specific media selected. 1 Content Library image will be used as fallback.' : 'No platform-specific media selected. ' + fallbackCount + ' Content Library images will be used as fallback.');
            } else {
                $help.text('No media selected for this platform yet.');
            }

            $list.off('click', '.bcsend-social-media-remove').on('click', '.bcsend-social-media-remove', function() {
                var url = $(this).data('url');
                self.setPlatformMedia(platform, self.getPlatformMedia(platform).filter(function(item) {
                    return item.url !== url;
                }));
            });
        },

        validateSocialPlatform: function(platform, strict) {
            var rule = this.getPlatformRule(platform);
            var accountId = $('#bcsend-social-account-' + platform).val() || '';
            var content = $.trim($('#bcsend-social-content-' + platform).val() || '');
            var effectiveContent = this.getEffectiveSocialContent(platform);
            var mode = $('#bcsend-social-link-mode-' + platform).val() || 'none';
            var url = this.getResolvedSocialLinkUrl(platform);
            var effectiveMedia = this.getEffectivePlatformMedia(platform);
            var messages = [];

            if (!accountId) {
                messages.push('Choose an account for ' + rule.label + '.');
            }

            if (!content) {
                messages.push('Add social copy for ' + rule.label + '.');
            }

            if (rule.maxChars && effectiveContent.length > rule.maxChars) {
                messages.push(rule.label + ' copy is too long once link text is included.');
            }

            if (rule.requiresMedia && !effectiveMedia.length) {
                messages.push(rule.label + ' requires at least one image or video.');
            }

            if ((mode === 'product' || mode === 'homepage' || mode === 'custom') && !url) {
                messages.push(rule.label + ' needs a resolved URL for the selected link mode.');
            }

            return {
                valid: messages.length === 0,
                messages: messages,
                severity: strict ? 'error' : (messages.length ? 'warning' : 'ok')
            };
        },

        updateSocialPlatformStatus: function(platform) {
            var $status = $('.bcsend-social-platform-status[data-platform="' + platform + '"]');
            var validation = this.validateSocialPlatform(platform, false);

            if (!$status.length) {
                return;
            }

            $status.removeClass('is-error is-warning is-ok');

            if (!$('.bcsend-social-platform-enabled[data-platform="' + platform + '"]').is(':checked')) {
                $status.addClass('is-ok').text('Platform disabled.');
                return;
            }

            if (validation.messages.length) {
                $status.addClass('is-warning').text(validation.messages.join(' '));
            } else {
                $status.addClass('is-ok').text('Ready for scheduling.');
            }
        },

        updateSharedSocialStatus: function() {
            var limit = this.getStrictestSelectedSocialLimit();
            var contentLength = this.getEffectiveSocialContent('shared').length;
            var $count = $('#bcsend-social-shared-count');
            var $max = $('#bcsend-social-shared-max');

            $count.text(contentLength);
            $max.text(limit);
            $count.closest('.bcsend-char-counter')
                .toggleClass('warning', contentLength > limit)
                .toggleClass('is-near-limit', contentLength > (limit * 0.9));

            this.renderPlatformMedia('shared');
        },

        applySocialPostModeUI: function() {
            var single = this.isSingleSocialMode();
            $('#bcsend-social-shared-fields').toggle(single);
            $('.bcsend-social-platform-content .bcsend-field-group:not(:first-child)').toggle(!single);
            $('.bcsend-social-platform-status').toggle(!single);
            this.updateSharedSocialStatus();
        },

        refreshAllSocialPlatforms: function() {
            var self = this;
            self.applySocialPostModeUI();
            $('.bcsend-social-platform-block').each(function() {
                var platform = $(this).data('platform');
                self.updateSocialLinkUI(platform);
                self.renderPlatformMedia(platform);
                self.updateSocialPlatformStatus(platform);
            });
            self.updateSharedSocialStatus();
        },

        /* ============================================================
           Generate Campaign (conversational — runs on every click)
           ============================================================ */

        bindGenerate: function() {
            var self = this;
            var $generateBtn = $('#bcsend-generate-campaign');
            var $prompt = $('#bcsend-campaign-prompt');

            $generateBtn.on('click', function() {
                var $btn = $(this);
                var prompt = $.trim($prompt.val());

                var clProducts = ContentLibrary.selectedProducts || [];
                var clImages = ContentLibrary.selectedImages || [];
                var clPosts = ContentLibrary.selectedPosts || [];
                if (!prompt && !clProducts.length && !clImages.length && !clPosts.length) {
                    Bcsend.notify('Enter a prompt or select content to include.', 'warning');
                    return;
                }

                var data = {
                    prompt: prompt,
                    social_post_mode: self.getSocialPostMode()
                };

                if (self.templateId) {
                    data.template_id = self.templateId;
                }

                if (clProducts.length) {
                    data.product_ids = clProducts.map(function(p) { return p.id; });
                }

                if (clImages.length) {
                    data.image_urls = clImages.map(function(img) { return img.url; });
                }

                if (clPosts.length) {
                    data.post_ids = clPosts.map(function(p) { return p.id; });
                }

                // Include current HTML so the AI can make targeted edits.
                var currentHtml = $.trim($('#bcsend-html-editor').val());
                if ($('#bcsend-send-email').is(':checked') && currentHtml) {
                    data.current_html = currentHtml;
                }

                data.channels = [];
                if ($('#bcsend-send-email').is(':checked')) {
                    data.channels.push('email');
                }
                if ($('#bcsend-send-push').is(':checked')) {
                    data.channels.push('push');
                }
                if ($('#bcsend-send-social').is(':checked')) {
                    data.channels.push('social');
                }

                if (!data.channels.length) {
                    Bcsend.notify('Select at least one channel to generate: email, push, or social.', 'warning');
                    return;
                }

                data.social_platforms = self.getSelectedSocialPlatforms();

                if ($('#bcsend-send-social').is(':checked') && !data.social_platforms.length) {
                    Bcsend.notify('Select at least one social platform to generate social posts.', 'warning');
                    return;
                }

                Bcsend.loading($btn, true);
                $('#bcsend-generate-status').text('Generating...');

                Bcsend.ajax('bcsend_generate_campaign', data, function(response) {
                    Bcsend.loading($btn, false);
                    $('#bcsend-generate-status').text('');

                    if (response.success && response.data) {
                        var generated = {};
                        if (response.data.content) {
                            try {
                                generated = JSON.parse(response.data.content);
                            } catch (e) {
                                generated = { plain_text: response.data.content };
                            }
                        } else {
                            generated = response.data;
                        }
                        self.populateFields(generated);
                        if ($('#bcsend-send-social').is(':checked') && self.getSelectedSocialPlatforms().length && (!generated.social || !Object.keys(generated.social).length)) {
                            Bcsend.notify('Campaign generation returned no social copy for the selected platforms.', 'warning');
                        }
                        Bcsend.notify('Campaign content generated.', 'success');

                        // Auto-save draft after generation.
                        self.autoSaveDraft();
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Generation failed.';
                        Bcsend.notify(errMsg, 'error');
                    }
                });
            });
        },

        autoSaveDraft: function() {
            var self = this;

            // Auto-generate a campaign name if empty.
            if (!$.trim($('#bcsend-campaign-name').val())) {
                var subject = $.trim($('#bcsend-subject').val());
                if (subject) {
                    $('#bcsend-campaign-name').val(subject);
                } else {
                    $('#bcsend-campaign-name').val('Draft - ' + new Date().toLocaleDateString());
                }
            }

            var data = this.collectData();
            $('#bcsend-save-status').text('Saving...');

            Bcsend.ajax('bcsend_save_draft', data, function(response) {
                $('#bcsend-save-status').text('');
                if (response.success) {
                    if (response.data.id) { self.campaignId = response.data.id; }
                    $('#bcsend-save-status').text('Draft saved').delay(2000).queue(function(next) { $(this).text(''); next(); });
                    if (response.data.warnings && response.data.warnings.length) {
                        Bcsend.notify(response.data.warnings.join(' '), 'warning');
                    }
                }
            });
        },

        populateFields: function(data) {
            if (data.subject) { $('#bcsend-subject').val(data.subject); }
            if (data.preview_text) { $('#bcsend-preview-text').val(data.preview_text); }
            if (data.plain_text) { $('#bcsend-plain-text').val(data.plain_text); }
            if (data.push_title) { $('#bcsend-push-title').val(data.push_title).trigger('input'); }
            if (data.push_message) { $('#bcsend-push-message').val(data.push_message).trigger('input'); }
            if (data.name) { $('#bcsend-campaign-name').val(data.name); }

            if (data.html_content) {
                this.updateEmailPreview(data.html_content);
                $('#bcsend-html-editor').val(data.html_content);
                this.syncEmailImagesToContentLibrary(data.html_content);
            }

            if (data.campaign_id || data.id) {
                this.campaignId = data.campaign_id || data.id;
            }

            if (data.social) {
                var self = this;
                $('#bcsend-send-social').prop('checked', true).trigger('change');
                if (self.isSingleSocialMode()) {
                    var sharedPayload = null;
                    $.each(data.social, function(platform, entry) {
                        $('.bcsend-social-platform-enabled[data-platform="' + platform + '"]').prop('checked', true).trigger('change');
                        if (!sharedPayload) {
                            sharedPayload = self.normalizeSocialPayload(entry);
                        }
                    });
                    if (sharedPayload) {
                        $('#bcsend-social-content-shared').val(sharedPayload.content || '').trigger('input');
                        $('#bcsend-social-link-mode-shared').val(sharedPayload.link_mode || 'none').trigger('change');
                        if (sharedPayload.link_mode === 'custom') {
                            $('#bcsend-social-link-url-shared').val(sharedPayload.link_url || '');
                        }
                        var sharedSuggestedMedia = self.resolveSuggestedMediaItems(sharedPayload);
                        if (sharedSuggestedMedia.length) {
                            self.socialMediaByPlatform.shared = sharedSuggestedMedia;
                            self.renderPlatformMedia('shared');
                        }
                    }
                } else {
                    $.each(data.social, function(platform, entry) {
                        var payload = self.normalizeSocialPayload(entry);
                        $('.bcsend-social-platform-enabled[data-platform="' + platform + '"]').prop('checked', true).trigger('change');
                        $('#bcsend-social-content-' + platform).val(payload.content || '').trigger('input');
                        $('#bcsend-social-link-mode-' + platform).val(payload.link_mode || 'none').trigger('change');
                        if (payload.link_mode === 'custom') {
                            $('#bcsend-social-link-url-' + platform).val(payload.link_url || '');
                        }
                        var suggestedMedia = self.resolveSuggestedMediaItems(payload);
                        if (suggestedMedia.length) {
                            self.socialMediaByPlatform[platform] = suggestedMedia;
                            self.renderPlatformMedia(platform);
                        }
                    });
                }
            }

            this.htmlSynced = true;
            this.updateSyncIndicator();
            this.refreshAllSocialPlatforms();
            this.updateSocialMediaSummary();
        },

        /* ============================================================
           Field Events
           ============================================================ */

        bindFieldEvents: function() {
            var self = this;

            $('#bcsend-send-email').on('change', function() {
                var enabled = $(this).is(':checked');
                $('#bcsend-email-fields').toggle(enabled);
                $('#bcsend-email-panel').toggle(enabled);
                $('#bcsend-send-test-email, #bcsend-save-as-template').toggle(enabled);
            }).trigger('change');

            // Push toggle.
            $('#bcsend-send-push').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#bcsend-push-fields').show();
                } else {
                    $('#bcsend-push-fields').hide();
                }
            }).trigger('change');

            // Push audience: radio toggle shows role/user fields.
            $('input[name="bcsend-push-target-type"]').on('change', function() {
                var type = $('input[name="bcsend-push-target-type"]:checked').val() || 'all_users';
                $('#bcsend-push-role-fields').toggle(type === 'by_role');
                $('#bcsend-push-user-fields').toggle(type === 'specific_users');
            }).trigger('change');

            // Push audience: specific-users search + selection chips.
            var pushUserSearchTimer = null;
            $('#bcsend-push-user-search').on('input', function() {
                var term = $.trim($(this).val());
                if (pushUserSearchTimer) { clearTimeout(pushUserSearchTimer); }
                var $results = $('#bcsend-push-user-results');
                if (term.length < 2) { $results.hide().empty(); return; }
                pushUserSearchTimer = setTimeout(function() {
                    Bcsend.ajax('bcsend_push_search_users', { search: term }, function(response) {
                        $results.empty().show();
                        var users = response.success && response.data && response.data.users ? response.data.users : [];
                        if (!users.length) { $results.html('<div class="bcsend-cl-no-results">No matches</div>'); return; }
                        users.forEach(function(u) {
                            $results.append(
                                '<div class="bcsend-push-user-result" data-user-id="' + u.id + '" data-user-name="' + Bcsend.escapeHtml(u.name) + '">' +
                                Bcsend.escapeHtml(u.name) + ' <small>' + Bcsend.escapeHtml(u.email) + '</small>' +
                                '</div>'
                            );
                        });
                    });
                }, 250);
            });

            $('#bcsend-push-user-results').on('click', '.bcsend-push-user-result', function() {
                var id = $(this).data('user-id');
                var name = $(this).data('user-name');
                if ($('#bcsend-push-selected-users .bcsend-push-selected-user[data-user-id="' + id + '"]').length) { return; }
                $('#bcsend-push-selected-users').append(
                    '<span class="bcsend-push-selected-user" data-user-id="' + id + '">' +
                    Bcsend.escapeHtml(name) +
                    ' <button type="button" class="bcsend-push-selected-remove" aria-label="Remove">&times;</button>' +
                    '</span>'
                );
                $('#bcsend-push-user-search').val('');
                $('#bcsend-push-user-results').hide().empty();
            });

            $('#bcsend-push-selected-users').on('click', '.bcsend-push-selected-remove', function() {
                $(this).closest('.bcsend-push-selected-user').remove();
            });

            // Restore initial specific-users chips from data-initial on page load.
            (function restoreInitialPushUsers() {
                var $container = $('#bcsend-push-selected-users');
                var raw = $container.attr('data-initial') || '[]';
                var ids = [];
                try { ids = JSON.parse(raw); } catch (e) { ids = []; }
                if (!ids.length) { return; }
                Bcsend.ajax('bcsend_push_search_users', { ids: ids }, function(response) {
                    var users = response.success && response.data && response.data.users ? response.data.users : [];
                    users.forEach(function(u) {
                        $container.append(
                            '<span class="bcsend-push-selected-user" data-user-id="' + u.id + '">' +
                            Bcsend.escapeHtml(u.name) +
                            ' <button type="button" class="bcsend-push-selected-remove" aria-label="Remove">&times;</button>' +
                            '</span>'
                        );
                    });
                });
            })();

            $('#bcsend-send-social').on('change', function() {
                $('#bcsend-social-fields').toggle($(this).is(':checked'));
                self.refreshAllSocialPlatforms();
                self.updateSocialMediaSummary();
            }).trigger('change');

            // Push title character counter.
            $('#bcsend-push-title').on('input', function() {
                var len = $(this).val().length;
                var $counter = $('#bcsend-push-title-count');
                $counter.text(len);
                $counter.closest('.bcsend-char-counter').toggleClass('warning', len > 26);
                self.updatePhoneMockup();
            });

            // Push message character counter.
            $('#bcsend-push-message').on('input', function() {
                var len = $(this).val().length;
                var $counter = $('#bcsend-push-message-count');
                $counter.text(len);
                $counter.closest('.bcsend-char-counter').toggleClass('warning', len > 354);
                self.updatePhoneMockup();
            });

            // Regenerate push.
            $('#bcsend-regenerate-push').on('click', function() {
                var $btn = $(this);
                var context = $.trim($('#bcsend-subject').val()) + ' ' + $.trim($('#bcsend-plain-text').val());

                if (!$.trim(context)) {
                    Bcsend.notify('No content context for push regeneration.', 'warning');
                    return;
                }

                Bcsend.loading($btn, true);
                $('#bcsend-regen-push-status').text('Regenerating...');

                Bcsend.ajax('bcsend_regenerate_push', { context_text: context }, function(response) {
                    Bcsend.loading($btn, false);
                    $('#bcsend-regen-push-status').text('');

                    if (response.success && response.data) {
                        if (response.data.push_title) {
                            $('#bcsend-push-title').val(response.data.push_title).trigger('input');
                        }
                        if (response.data.push_message) {
                            $('#bcsend-push-message').val(response.data.push_message).trigger('input');
                        }
                        Bcsend.notify('Push content regenerated.', 'success');
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Failed to regenerate.';
                        Bcsend.notify(errMsg, 'error');
                    }
                });
            });

            $('.bcsend-social-platform-enabled').on('change', function() {
                var platform = $(this).data('platform');
                $('.bcsend-social-platform-content[data-platform="' + platform + '"]').toggle($(this).is(':checked'));
                self.updateSocialPlatformStatus(platform);
                self.applySocialPostModeUI();
                self.updateSharedSocialStatus();
            }).trigger('change');

            $('#bcsend-social-content-shared').on('input', function() {
                self.updateSharedSocialStatus();
            }).trigger('input');

            $('.bcsend-social-textarea').on('input', function() {
                var platform = $(this).data('platform');
                var max = parseInt($(this).data('max'), 10) || 0;
                var len = self.getEffectiveSocialContent(platform).length;
                var $counter = $('.bcsend-social-char-count[data-platform="' + platform + '"]');
                $counter.text(len);
                $counter.closest('.bcsend-char-counter')
                    .toggleClass('warning', max && len > max)
                    .toggleClass('is-near-limit', max && len > (max * 0.9));
                self.updateSocialPlatformStatus(platform);
            }).trigger('input');

            $('.bcsend-social-link-mode').on('change', function() {
                var platform = $(this).data('platform');
                self.updateSocialLinkUI(platform);
                if (platform === 'shared') {
                    $('#bcsend-social-content-shared').trigger('input');
                    self.updateSharedSocialStatus();
                } else {
                    $('#bcsend-social-content-' + platform).trigger('input');
                    self.updateSocialPlatformStatus(platform);
                }
            }).trigger('change');

            $('.bcsend-social-link-url').on('input', function() {
                var platform = $(this).data('platform');
                if (platform === 'shared') {
                    $('#bcsend-social-content-shared').trigger('input');
                    self.updateSharedSocialStatus();
                } else {
                    $('#bcsend-social-content-' + platform).trigger('input');
                    self.updateSocialPlatformStatus(platform);
                }
            });

            $('.bcsend-social-media-clear').on('click', function() {
                var platform = $(this).data('platform');
                self.setPlatformMedia(platform, []);
            });

            $('.bcsend-social-media-pick').on('click', function() {
                var platform = $(this).data('platform');

                if (typeof wp === 'undefined' || !wp.media) {
                    Bcsend.notify('The WordPress media library is not available on this page right now.', 'error');
                    return;
                }

                self.getPlatformMediaFrame(platform).open();
            });

            $('#bcsend-regenerate-social').on('click', function() {
                var $btn = $(this);
                var platforms = self.getSelectedSocialPlatforms();
                var context = $.trim($('#bcsend-subject').val()) + "\n" + $.trim($('#bcsend-plain-text').val()) + "\n" + $.trim($('#bcsend-campaign-prompt').val());

                if (!platforms.length) {
                    Bcsend.notify('Select at least one social platform first.', 'warning');
                    return;
                }

                if (!$.trim(context)) {
                    Bcsend.notify('No context available for social regeneration.', 'warning');
                    return;
                }

                Bcsend.loading($btn, true);
                $('#bcsend-regen-social-status').text('Regenerating...');

                Bcsend.ajax('bcsend_regenerate_social', {
                    campaign_id: self.campaignId || '',
                    context_text: context,
                    social_platforms: platforms,
                    prompt: $.trim($('#bcsend-campaign-prompt').val()),
                    social_post_mode: self.getSocialPostMode()
                }, function(response) {
                    Bcsend.loading($btn, false);
                    $('#bcsend-regen-social-status').text('');

                    if (!response.success || !response.data) {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to regenerate social copy.', 'error');
                        return;
                    }

                    if (!response.data.social || !Object.keys(response.data.social).length) {
                        Bcsend.notify('Social regeneration returned no platform copy. Please try again after confirming the platform is selected and the prompt has enough context.', 'warning');
                        return;
                    }

                    if (self.isSingleSocialMode()) {
                        var firstPayload = null;
                        $.each(response.data.social || {}, function(_, entry) {
                            if (!firstPayload) {
                                firstPayload = self.normalizeSocialPayload(entry);
                            }
                        });
                        if (firstPayload) {
                            $('#bcsend-social-content-shared').val(firstPayload.content || '').trigger('input');
                            $('#bcsend-social-link-mode-shared').val(firstPayload.link_mode || 'none').trigger('change');
                            if (firstPayload.link_mode === 'custom') {
                                $('#bcsend-social-link-url-shared').val(firstPayload.link_url || '').trigger('input');
                            }
                            var sharedSuggestedMedia = self.resolveSuggestedMediaItems(firstPayload);
                            if (sharedSuggestedMedia.length) {
                                self.setPlatformMedia('shared', sharedSuggestedMedia);
                            }
                        }
                    } else {
                        $.each(response.data.social || {}, function(platform, entry) {
                            var payload = self.normalizeSocialPayload(entry);
                            $('#bcsend-social-content-' + platform).val(payload.content || '').trigger('input');
                            $('#bcsend-social-link-mode-' + platform).val(payload.link_mode || 'none').trigger('change');

                            if (payload.link_mode === 'custom') {
                                $('#bcsend-social-link-url-' + platform).val(payload.link_url || '').trigger('input');
                            }

                            var suggestedMedia = self.resolveSuggestedMediaItems(payload);
                            if (suggestedMedia.length) {
                                self.setPlatformMedia(platform, suggestedMedia);
                            }
                        });
                    }

                    Bcsend.notify('Social copy regenerated.', 'success');
                });
            });
        },

        updateEmailPreview: function(html) {
            var $iframe = $('#bcsend-email-preview');
            if ($iframe.length) {
                $iframe[0].srcdoc = html;

                // Auto-resize iframe to content height once loaded.
                $iframe.off('load.bcsend').on('load.bcsend', function() {
                    try {
                        var doc = this.contentDocument || this.contentWindow.document;
                        var height = doc.documentElement.scrollHeight || doc.body.scrollHeight;
                        if (height > 100) {
                            this.style.height = height + 'px';
                        }
                    } catch (e) {
                        // Cross-origin or empty — keep min-height.
                    }
                });
            }
        },

        updatePhoneMockup: function() {
            $('#bcsend-push-preview-title').text($('#bcsend-push-title').val() || '');
            $('#bcsend-push-preview-message').text($('#bcsend-push-message').val() || '');
        },

        getSelectedSocialPlatforms: function() {
            var platforms = [];
            $('.bcsend-social-platform-enabled:checked').each(function() {
                platforms.push($(this).data('platform'));
            });
            return platforms;
        },

        updateSyncIndicator: function() {
            var $indicator = $('#bcsend-sync-indicator');
            if (this.htmlSynced) {
                $indicator.removeClass('is-unsynced').addClass('is-synced').text('Synced');
            } else {
                $indicator.removeClass('is-synced').addClass('is-unsynced').text('Modified');
            }
        },

        /* ============================================================
           HTML Editor (inline split view)
           ============================================================ */

        bindHtmlEditor: function() {
            var self = this;

            // Apply button syncs code → preview.
            $('#bcsend-apply-html').on('click', function() {
                var html = $('#bcsend-html-editor').val();
                self.updateEmailPreview(html);
                self.htmlSynced = true;
                self.updateSyncIndicator();
            });

            // Track edits in the code editor.
            $('#bcsend-html-editor').on('input', function() {
                self.htmlSynced = false;
                self.updateSyncIndicator();
            });
        },

        /* ============================================================
           Audience / Schedule
           ============================================================ */

        loadSegments: function() {
            Bcsend.ajax('bcsend_get_audience_options', {}, function(response) {
                var $select = $('#bcsend-segment');
                if (!$select.length) { return; }
                $select.find('option:not(:first)').remove();

                if (response.success && response.data.segments) {
                    $.each(response.data.segments, function(i, seg) {
                        var label = Bcsend.escapeHtml(seg.name);
                        if (seg.contact_count !== undefined) {
                            label += ' (' + seg.contact_count + ')';
                        }
                        $select.append('<option value="' + Bcsend.escapeHtml(String(seg.id)) + '">' + label + '</option>');
                    });
                }

            });
        },

        bindSchedule: function() {
            var now = new Date();
            var today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            $('#bcsend-schedule-date').attr('min', today);

            // Show the browser's detected timezone next to the schedule inputs.
            try {
                var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                if (tz) {
                    $('#bcsend-detected-tz').text(tz);
                }
            } catch (e) {
                // Intl not supported — leave blank.
            }
        },

        /* ============================================================
           Validation
           ============================================================ */

        validate: function() {
            var errors = [];
            var sendEmail = $('#bcsend-send-email').is(':checked');
            var sendPush = $('#bcsend-send-push').is(':checked');
            var sendSocial = $('#bcsend-send-social').is(':checked');

            if (!$.trim($('#bcsend-campaign-name').val())) { errors.push('Campaign name is required.'); }
            if (!sendEmail && !sendPush && !sendSocial) { errors.push('Select at least one delivery channel: email, push, or social.'); }
            if (sendEmail && !$.trim($('#bcsend-subject').val())) { errors.push('Subject line is required.'); }

            var pushTitle = $('#bcsend-push-title').val();
            if (pushTitle && pushTitle.length > 26) { errors.push('Push title must be 26 characters or fewer.'); }

            var pushMessage = $('#bcsend-push-message').val();
            if (pushMessage && pushMessage.length > 354) { errors.push('Push message must be 354 characters or fewer.'); }

            if (sendSocial) {
                if (!this.getSelectedSocialPlatforms().length) {
                    errors.push('Select at least one social platform.');
                }

                if (this.isSingleSocialMode()) {
                    var sharedContent = $.trim($('#bcsend-social-content-shared').val() || '');
                    var sharedEffective = this.getEffectiveSocialContent('shared');
                    var sharedLimit = this.getStrictestSelectedSocialLimit();
                    var sharedMode = $('#bcsend-social-link-mode-shared').val() || 'none';
                    var sharedUrl = this.getResolvedSocialLinkUrl('shared');
                    var sharedMedia = this.getEffectivePlatformMedia('shared');

                    if (!sharedContent) { errors.push('Add social copy.'); }
                    if (sharedEffective.length > sharedLimit) { errors.push('Social copy exceeds the strictest selected platform limit.'); }
                    if (this.selectedSocialRequiresMedia() && !sharedMedia.length) { errors.push('Instagram requires at least one image or video.'); }
                    if ((sharedMode === 'product' || sharedMode === 'homepage' || sharedMode === 'custom') && !sharedUrl) {
                        errors.push('Social post needs a resolved URL for the selected link mode.');
                    }
                    $('.bcsend-social-platform-enabled:checked').each(function() {
                        var platform = $(this).data('platform');
                        if (!$('#bcsend-social-account-' + platform).val()) {
                            errors.push('Choose an account for ' + Composer.getPlatformRule(platform).label + '.');
                        }
                    });
                } else {
                    $('.bcsend-social-platform-enabled:checked').each(function() {
                        var platform = $(this).data('platform');
                        var validation = Composer.validateSocialPlatform(platform, true);
                        if (validation.messages.length) {
                            errors = errors.concat(validation.messages);
                        }
                    });
                }
            }

            if ((sendEmail || sendPush) && !$('#bcsend-segment').val()) { errors.push('Please select an audience segment.'); }
            if (!$('#bcsend-schedule-date').val() || !$('#bcsend-schedule-time').val()) { errors.push('Please set a schedule date and time.'); }

            if (errors.length) {
                Bcsend.notify(errors.join(' '), 'error');
                return false;
            }
            return true;
        },

        /* ============================================================
           Collect Data / Actions
           ============================================================ */

        collectData: function() {
            var self = this;
            var scheduleDate = $('#bcsend-schedule-date').val();
            var scheduleTime = $('#bcsend-schedule-time').val();
            var scheduledAt = '';
            if (scheduleDate && scheduleTime) {
                scheduledAt = scheduleDate + ' ' + scheduleTime + ':00';
            }

            var segmentVal = $('#bcsend-segment').val() || '';
            var pushTargetType = $('input[name="bcsend-push-target-type"]:checked').val() || 'all_users';
            var pushTargetData = [];
            if (pushTargetType === 'by_role') {
                $('.bcsend-push-role-checkbox:checked').each(function() {
                    pushTargetData.push($(this).val());
                });
            } else if (pushTargetType === 'specific_users') {
                $('#bcsend-push-selected-users .bcsend-push-selected-user').each(function() {
                    pushTargetData.push(parseInt($(this).data('user-id'), 10));
                });
            }
            var tzOffsetMin = new Date().getTimezoneOffset();
            var socialPosts = {};
            var socialAccountIds = {};
            var socialMediaItems = {};
            var socialLinkModes = {};
            var socialLinkUrls = {};
            var socialLinkLabels = {};
            var socialPlatforms = [];

            // Build content library JSON from ContentLibrary selections.
            var clData = {
                products: (ContentLibrary.selectedProducts || []).map(function(p) {
                    return { id: p.id, name: p.name, price: p.price, image: p.image || '', permalink: p.permalink || '' };
                }),
                images: (ContentLibrary.selectedImages || []).map(function(img) {
                    return { id: img.id, url: img.url, title: img.title || '', thumb: img.thumb || '', alt: img.alt || '' };
                }),
                posts: (ContentLibrary.selectedPosts || []).map(function(p) {
                    return { id: p.id, title: p.title, image: p.image || '', permalink: p.permalink || '', post_type: p.post_type || 'post', date: p.date || '', excerpt: p.excerpt || '' };
                })
            };

            $('.bcsend-social-platform-enabled:checked').each(function() {
                var platform = $(this).data('platform');
                socialPlatforms.push(platform);
                socialPosts[platform] = self.isSingleSocialMode() ? ($('#bcsend-social-content-shared').val() || '') : ($('#bcsend-social-content-' + platform).val() || '');
                socialAccountIds[platform] = $('#bcsend-social-account-' + platform).val() || '';
                socialMediaItems[platform] = self.isSingleSocialMode() ? self.getPlatformMedia('shared') : self.getPlatformMedia(platform);
                socialLinkModes[platform] = self.isSingleSocialMode() ? ($('#bcsend-social-link-mode-shared').val() || 'none') : ($('#bcsend-social-link-mode-' + platform).val() || 'none');
                socialLinkUrls[platform] = self.isSingleSocialMode() ? self.getResolvedSocialLinkUrl('shared') : self.getResolvedSocialLinkUrl(platform);
                socialLinkLabels[platform] = $('.bcsend-social-platform-block[data-platform="' + platform + '"]').attr('data-initial-link-label') || '';
            });

            return {
                id:               this.campaignId || '',
                name:             $('#bcsend-campaign-name').val(),
                subject:          $('#bcsend-subject').val(),
                preview_text:     $('#bcsend-preview-text').val(),
                html_content:     $('#bcsend-html-editor').val(),
                plain_text:       $('#bcsend-plain-text').val(),
                reply_to:         $('#bcsend-reply-to').val(),
                push_title:       $('#bcsend-push-title').val(),
                push_message:     $('#bcsend-push-message').val(),
                send_email:       $('#bcsend-send-email').is(':checked') ? 1 : 0,
                send_push:        $('#bcsend-send-push').is(':checked') ? 1 : 0,
                send_social:      $('#bcsend-send-social').is(':checked') ? 1 : 0,
                social_posts:     JSON.stringify(socialPosts),
                social_account_ids: JSON.stringify(socialAccountIds),
                social_media_items: JSON.stringify(socialMediaItems),
                social_link_modes: JSON.stringify(socialLinkModes),
                social_link_urls: JSON.stringify(socialLinkUrls),
                social_link_labels: JSON.stringify(socialLinkLabels),
                social_platforms: JSON.stringify(socialPlatforms),
                social_post_mode: this.getSocialPostMode(),
                segment_id:       segmentVal,
                push_target_type: pushTargetType,
                push_target_data: JSON.stringify(pushTargetData),
                scheduled_at:     scheduledAt,
                social_scheduled_at: scheduledAt,
                tz_offset:        tzOffsetMin,
                content_library:  JSON.stringify(clData)
            };
        },

        bindActions: function() {
            var self = this;

            // Save Draft.
            $('#bcsend-save-draft').on('click', function() {
                var $btn = $(this);
                var data = self.collectData();
                Bcsend.loading($btn, true);
                $('#bcsend-save-status').text('Saving...');

                Bcsend.ajax('bcsend_save_draft', data, function(response) {
                    Bcsend.loading($btn, false);
                    $('#bcsend-save-status').text('');

                    if (response.success) {
                        if (response.data.id) { self.campaignId = response.data.id; }
                        Bcsend.notify(response.data.message || 'Draft saved.', 'success');
                        if (response.data.warnings && response.data.warnings.length) {
                            Bcsend.notify(response.data.warnings.join(' '), 'warning');
                        }
                    } else {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to save draft.', 'error');
                    }
                });
            });

            // Send Test Email.
            $('#bcsend-send-test-email').on('click', function() {
                var $btn = $(this);
                var htmlContent = $('#bcsend-html-editor').val() || '';

                if (!htmlContent) {
                    Bcsend.notify('Generate campaign content first.', 'warning');
                    return;
                }

                Bcsend.loading($btn, true);
                Bcsend.ajax('bcsend_send_test_email', {
                    to_email: bcsendAdmin.adminEmail || '',
                    subject: '[TEST] ' + ($('#bcsend-subject').val() || 'Beacon Campaign Sender Test'),
                    html_content: htmlContent
                }, function(response) {
                    Bcsend.loading($btn, false);
                    if (response.success) {
                        Bcsend.notify(response.data.message || 'Test email sent!', 'success');
                    } else {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to send test email.', 'error');
                    }
                });
            });

            // Save as Template.
            $('#bcsend-save-as-template').on('click', function() {
                var $btn = $(this);
                var htmlContent = $.trim($('#bcsend-html-editor').val());

                if (!htmlContent) {
                    Bcsend.notify('Generate campaign content first.', 'warning');
                    return;
                }

                var name = prompt('Template name:');
                if (!name) { return; }

                Bcsend.loading($btn, true);
                Bcsend.ajax('bcsend_save_template', {
                    name: name,
                    html_content: htmlContent,
                    plain_text: $.trim($('#bcsend-plain-text').val())
                }, function(response) {
                    Bcsend.loading($btn, false);
                    if (response.success) {
                        Bcsend.notify(response.data.message || 'Template saved.', 'success');
                    } else {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to save template.', 'error');
                    }
                });
            });

            // Approve & Schedule.
            $('#bcsend-approve-schedule').on('click', function() {
                var $btn = $(this);
                if (!self.validate()) { return; }

                var data = self.collectData();
                Bcsend.loading($btn, true);

                Bcsend.ajax('bcsend_approve_schedule', data, function(response) {
                    Bcsend.loading($btn, false);

                    if (response.success) {
                        Bcsend.notify(response.data.message || 'Campaign approved and scheduled.', 'success');
                        setTimeout(function() {
                            window.location.href = bcsendAdmin.queueUrl || (window.location.origin + window.location.pathname + '?page=bcsend-queue');
                        }, 1000);
                    } else {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to approve campaign.', 'error');
                    }
                });
            });
        },

        /* ============================================================
           Load Existing Campaign
           ============================================================ */

        loadCampaign: function(id) {
            var self = this;

            Bcsend.ajax('bcsend_get_campaign', { id: id }, function(response) {
                if (response.success && response.data && response.data.campaign) {
                    var data = response.data.campaign;

                    if (data.social_post_mode) {
                        $('#bcsend-social-post-mode').val(data.social_post_mode === 'per_platform' ? 'per_platform' : 'single');
                    }

                    self.populateFields(data);

                    if (data.segment_id) {
                        $('#bcsend-segment').val(data.segment_id);
                    }

                    if (data.push_target_type) {
                        $('input[name="bcsend-push-target-type"][value="' + data.push_target_type + '"]').prop('checked', true).trigger('change');
                        var targetData = [];
                        try {
                            targetData = typeof data.push_target_data === 'string' ? JSON.parse(data.push_target_data) : (data.push_target_data || []);
                        } catch (e) {
                            targetData = [];
                        }
                        if (data.push_target_type === 'by_role' && targetData.length) {
                            targetData.forEach(function(roleSlug) {
                                $('.bcsend-push-role-checkbox[value="' + roleSlug + '"]').prop('checked', true);
                            });
                        }
                        // specific_users: the server-rendered initial data is picked up by the init handler below.
                    }

                    if (data.scheduled_at) {
                        var utcDate = new Date(data.scheduled_at + ' UTC');
                        if (!isNaN(utcDate.getTime())) {
                            var y = utcDate.getFullYear();
                            var m = String(utcDate.getMonth() + 1).padStart(2, '0');
                            var d = String(utcDate.getDate()).padStart(2, '0');
                            var hh = String(utcDate.getHours()).padStart(2, '0');
                            var mm = String(utcDate.getMinutes()).padStart(2, '0');
                            $('#bcsend-schedule-date').val(y + '-' + m + '-' + d);
                            $('#bcsend-schedule-time').val(hh + ':' + mm);
                        }
                    }

                    if (data.name) { $('#bcsend-campaign-name').val(data.name); }
                    if (data.reply_to) { $('#bcsend-reply-to').val(data.reply_to); }

                    if (data.send_email !== undefined) {
                        $('#bcsend-send-email').prop('checked', parseInt(data.send_email, 10) !== 0).trigger('change');
                    }

                    if (data.send_push !== undefined) {
                        $('#bcsend-send-push').prop('checked', parseInt(data.send_push, 10) !== 0).trigger('change');
                    }

                    if (data.send_social !== undefined) {
                        $('#bcsend-send-social').prop('checked', parseInt(data.send_social, 10) !== 0).trigger('change');
                    }

                    if (data.social_posts && data.social_posts.length) {
                        $.each(data.social_posts, function(_, post) {
                            $('.bcsend-social-platform-enabled[data-platform="' + post.platform + '"]').prop('checked', true).trigger('change');
                            $('#bcsend-social-account-' + post.platform).val(post.account_id || '');
                            if (self.isSingleSocialMode()) {
                                if (!$('#bcsend-social-content-shared').val()) {
                                    $('#bcsend-social-content-shared').val(self.normalizeSocialContentInput(self.extractSocialText(post.content || ''))).trigger('input');
                                    $('#bcsend-social-link-mode-shared').val(post.link_mode || 'none').trigger('change');
                                    if ((post.link_mode || 'none') === 'custom') {
                                        $('#bcsend-social-link-url-shared').val(post.link_url || '').trigger('input');
                                    } else if (post.link_url) {
                                        $('#bcsend-social-link-url-shared').val(post.link_url);
                                    }
                                }
                            } else {
                                $('#bcsend-social-content-' + post.platform).val(self.normalizeSocialContentInput(self.extractSocialText(post.content || ''))).trigger('input');
                                $('#bcsend-social-link-mode-' + post.platform).val(post.link_mode || 'none').trigger('change');
                                if ((post.link_mode || 'none') === 'custom') {
                                    $('#bcsend-social-link-url-' + post.platform).val(post.link_url || '').trigger('input');
                                } else if (post.link_url) {
                                    $('#bcsend-social-link-url-' + post.platform).val(post.link_url);
                                }
                            }

                            try {
                                self.socialMediaByPlatform[self.isSingleSocialMode() ? 'shared' : post.platform] = self.normalizeMediaItems(
                                    Array.isArray(post.media_items)
                                        ? post.media_items
                                        : (post.media_items ? JSON.parse(post.media_items) : [])
                                );
                            } catch (e) {
                                self.socialMediaByPlatform[self.isSingleSocialMode() ? 'shared' : post.platform] = [];
                            }

                            self.renderPlatformMedia(self.isSingleSocialMode() ? 'shared' : post.platform);
                            self.updateSocialPlatformStatus(post.platform);
                        });
                    }

                    // Restore Content Library selections.
                    if (data.content_library) {
                        try {
                            var cl = typeof data.content_library === 'string' ? JSON.parse(data.content_library) : data.content_library;
                            if (cl.products && cl.products.length) {
                                ContentLibrary.selectedProducts = cl.products;
                            }
                            if (cl.images && cl.images.length) {
                                ContentLibrary.selectedImages = self.normalizeMediaItems(cl.images);
                            }
                            if (cl.posts && cl.posts.length) {
                                ContentLibrary.selectedPosts = cl.posts;
                            }
                            ContentLibrary.renderAllSelected();
                        } catch (e) {
                            // Invalid JSON — ignore.
                        }
                    }

                    self.refreshAllSocialPlatforms();
                } else {
                    Bcsend.notify('Failed to load campaign.', 'error');
                }
            });
        }
        ,

        updateSocialMediaSummary: function() {
            var $summary = $('#bcsend-social-media-summary');
            if (!$summary.length) {
                return;
            }

            var count = (ContentLibrary.selectedImages || []).length;
            if (!$('#bcsend-send-social').is(':checked')) {
                $summary.hide();
                return;
            }

            $summary.show();
            if (this.isSingleSocialMode()) {
                $summary.text(count
                    ? (count === 1 ? '1 Content Library image is available as fallback for the shared social post.' : count + ' Content Library images are available as fallback for the shared social post.')
                    : 'No Content Library fallback images selected. Choose shared media below or add images in Content Library.'
                );
                this.refreshAllSocialPlatforms();
                return;
            }

            if (!count) {
                $summary.text('No Content Library fallback images selected. Add platform-specific media below or choose images in Content Library.');
                this.refreshAllSocialPlatforms();
                return;
            }

            $summary.text(count === 1
                ? '1 Content Library image is available as fallback for enabled social posts with no platform-specific media.'
                : count + ' Content Library images are available as fallback for enabled social posts with no platform-specific media.'
            );
            this.refreshAllSocialPlatforms();
        }
    };

    /* ================================================================
       Content Library Module
       ================================================================ */

    var ContentLibrary = {

        selectedProducts: [],
        selectedPosts: [],
        selectedImages: [],
        searchTimers: {},

        init: function() {
            this.bindTabs();
            this.bindToggle();
            this.bindProductTab();
            this.bindMediaTab();
            this.bindPostTab();
            this.bindSnippetTab();
            this.loadSnippets();
        },

        /* --- Helpers --- */

        insertAtCursor: function(html) {
            var $editor = $('#bcsend-html-editor');
            var el = $editor[0];
            var val = el.value;
            var start = el.selectionStart;
            var end = el.selectionEnd;
            el.value = val.substring(0, start) + html + val.substring(end);
            el.selectionStart = el.selectionEnd = start + html.length;
            $editor.trigger('input');
            Composer.updateEmailPreview(el.value);
            Composer.htmlSynced = true;
            Composer.updateSyncIndicator();
            Bcsend.notify('Content inserted.', 'success');
        },

        escHtml: function(str) {
            return Bcsend.escapeHtml(str || '');
        },

        /* --- Unified selected content display --- */

        renderAllSelected: function() {
            var self = this;
            var $container = $('#bcsend-cl-all-selected');
            $container.empty();

            var hasAny = this.selectedProducts.length || this.selectedImages.length || this.selectedPosts.length;
            if (!hasAny) {
                $container.hide();
                return;
            }

            $container.show();

            // Products
            $.each(this.selectedProducts, function(i, product) {
                var thumb = product.image
                    ? '<img src="' + self.escHtml(product.image) + '" alt="" />'
                    : '<span class="dashicons dashicons-cart"></span>';
                $container.append(
                    '<div class="bcsend-cl-sel-item" data-type="product" data-id="' + product.id + '">' +
                    '<div class="bcsend-cl-sel-thumb">' + thumb + '</div>' +
                    '<div class="bcsend-cl-sel-info">' +
                    '<span class="bcsend-cl-sel-name">' + self.escHtml(product.name) + '</span>' +
                    '<span class="bcsend-cl-sel-meta">$' + self.escHtml(product.price) + '</span>' +
                    '</div>' +
                    '<button type="button" class="bcsend-cl-sel-remove">&times;</button>' +
                    '</div>'
                );
            });

            // Images
            $.each(this.selectedImages, function(i, img) {
                $container.append(
                    '<div class="bcsend-cl-sel-item" data-type="image" data-id="' + img.id + '">' +
                    '<div class="bcsend-cl-sel-thumb"><img src="' + self.escHtml(img.thumb) + '" alt="" /></div>' +
                    '<div class="bcsend-cl-sel-info">' +
                    '<span class="bcsend-cl-sel-name">' + self.escHtml(img.title || 'Image') + '</span>' +
                    '<span class="bcsend-cl-sel-meta">Image</span>' +
                    '</div>' +
                    '<button type="button" class="bcsend-cl-sel-remove">&times;</button>' +
                    '</div>'
                );
            });

            // Posts
            $.each(this.selectedPosts, function(i, post) {
                var thumb = post.image
                    ? '<img src="' + self.escHtml(post.image) + '" alt="" />'
                    : '<span class="dashicons dashicons-admin-post"></span>';
                $container.append(
                    '<div class="bcsend-cl-sel-item" data-type="post" data-id="' + post.id + '">' +
                    '<div class="bcsend-cl-sel-thumb">' + thumb + '</div>' +
                    '<div class="bcsend-cl-sel-info">' +
                    '<span class="bcsend-cl-sel-name">' + self.escHtml(post.title) + '</span>' +
                    '<span class="bcsend-cl-sel-meta">' + self.escHtml(post.post_type) + '</span>' +
                    '</div>' +
                    '<button type="button" class="bcsend-cl-sel-remove">&times;</button>' +
                    '</div>'
                );
            });

            // Remove handler
            $container.off('click', '.bcsend-cl-sel-remove').on('click', '.bcsend-cl-sel-remove', function() {
                var $item = $(this).closest('.bcsend-cl-sel-item');
                var type = $item.data('type');
                var id = $item.data('id');

                if (type === 'product') {
                    self.selectedProducts = self.selectedProducts.filter(function(p) { return p.id !== id; });
                    $('.bcsend-cl-result-item[data-id="' + id + '"]').removeClass('is-selected');
                } else if (type === 'image') {
                    self.selectedImages = self.selectedImages.filter(function(img) { return img.id !== id; });
                } else if (type === 'post') {
                    self.selectedPosts = self.selectedPosts.filter(function(p) { return p.id !== id; });
                    $('.bcsend-cl-result-item[data-id="' + id + '"]').removeClass('is-selected');
                }

                self.renderAllSelected();
                Composer.updateSocialMediaSummary();
            });
        },

        /* --- Tabs / Toggle --- */

        bindTabs: function() {
            var self = this;
            $('.bcsend-cl-tabs').on('click', '.bcsend-cl-tab', function() {
                var tab = $(this).data('tab');
                $(this).addClass('is-active').siblings().removeClass('is-active');
                $(this).closest('.bcsend-cl-body').find('.bcsend-cl-panel').removeClass('is-active');
                $(this).closest('.bcsend-cl-body').find('.bcsend-cl-panel[data-panel="' + tab + '"]').addClass('is-active');

                if (tab === 'snippets') {
                    self.loadSnippets();
                }
            });
        },

        bindToggle: function() {
            $('#bcsend-cl-toggle').on('click', function() {
                var $body = $('#bcsend-cl-body');
                var expanded = $(this).attr('aria-expanded') === 'true';
                $body.slideToggle(200);
                $(this).attr('aria-expanded', !expanded);
                $(this).find('.dashicons')
                    .toggleClass('dashicons-arrow-down-alt2', expanded)
                    .toggleClass('dashicons-arrow-up-alt2', !expanded);
            });
        },

        /* ==============================================================
           Products Tab (multi-select)
           ============================================================== */

        bindProductTab: function() {
            var self = this;
            var $input = $('#bcsend-cl-product-search');
            var $results = $('#bcsend-cl-product-results');

            if (!$input.length) { return; }

            $input.on('input', function() {
                var term = $.trim($(this).val());
                if (self.searchTimers.product) { clearTimeout(self.searchTimers.product); }
                if (term.length < 2) { $results.empty(); return; }

                self.searchTimers.product = setTimeout(function() {
                    Bcsend.ajax('bcsend_search_products', { search: term }, function(response) {
                        $results.empty();
                        if (response.success && response.data.products && response.data.products.length) {
                            $.each(response.data.products, function(i, product) {
                                var already = self.selectedProducts.some(function(p) { return p.id === product.id; });
                                var imgHtml = product.image ? '<img src="' + self.escHtml(product.image) + '" alt="">' : '<span class="dashicons dashicons-cart"></span>';
                                $results.append(
                                    '<div class="bcsend-cl-result-item' + (already ? ' is-selected' : '') + '" data-id="' + product.id + '">' +
                                    '<div class="bcsend-cl-result-thumb">' + imgHtml + '</div>' +
                                    '<div class="bcsend-cl-result-info">' +
                                    '<div class="bcsend-cl-result-title">' + self.escHtml(product.name) + '</div>' +
                                    '<div class="bcsend-cl-result-meta">' + self.escHtml(product.price) + '</div>' +
                                    '</div>' +
                                    '<span class="bcsend-cl-check dashicons dashicons-yes-alt"></span>' +
                                    '</div>'
                                );
                                $results.find('.bcsend-cl-result-item[data-id="' + product.id + '"]').data('product', product);
                            });
                        } else {
                            $results.html('<div class="bcsend-cl-no-results">' + bcsendAdmin.strings.noResults + '</div>');
                        }
                    });
                }, 350);
            });

            $results.on('click', '.bcsend-cl-result-item', function() {
                var product = $(this).data('product');
                var idx = self.selectedProducts.findIndex(function(p) { return p.id === product.id; });
                if (idx > -1) {
                    self.selectedProducts.splice(idx, 1);
                    $(this).removeClass('is-selected');
                } else {
                    self.selectedProducts.push(product);
                    $(this).addClass('is-selected');
                }
                self.renderSelectedProducts();
            });
        },

        renderSelectedProducts: function() {
            this.renderAllSelected();
        },

        /* ==============================================================
           Media Tab (multi-select, passed to AI on Generate)
           ============================================================== */

        bindMediaTab: function() {
            var self = this;
            var mediaFrame;

            $('#bcsend-cl-media-pick').on('click', function() {
                // Always create a fresh frame so previous selections don't carry over.
                mediaFrame = wp.media({
                    title: 'Choose Images',
                    library: { type: 'image' },
                    multiple: true,
                    button: { text: 'Add Images' }
                });

                mediaFrame.on('select', function() {
                    mediaFrame.state().get('selection').each(function(item) {
                        var attachment = item.toJSON();
                        var already = self.selectedImages.some(function(img) { return img.id === attachment.id; });
                        if (!already) {
                            self.selectedImages.push({
                                id: attachment.id,
                                url: attachment.url,
                                alt: attachment.alt || attachment.title || '',
                                title: attachment.title || '',
                                thumb: (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url
                            });
                        }
                    });
                    self.renderSelectedImages();
                    Composer.updateSocialMediaSummary();
                });

                mediaFrame.open();
            });

        },

        renderSelectedImages: function() {
            this.renderAllSelected();
            Composer.updateSocialMediaSummary();
        },

        /* ==============================================================
           Posts Tab (multi-select)
           ============================================================== */

        bindPostTab: function() {
            var self = this;
            var $input = $('#bcsend-cl-post-search');
            var $results = $('#bcsend-cl-post-results');

            if (!$input.length) { return; }

            $input.on('input', function() {
                var term = $.trim($(this).val());
                if (self.searchTimers.post) { clearTimeout(self.searchTimers.post); }
                if (term.length < 2) { $results.empty(); return; }

                self.searchTimers.post = setTimeout(function() {
                    var postType = $('#bcsend-cl-post-type').val() || 'post';
                    Bcsend.ajax('bcsend_search_posts', { search: term, post_type: postType }, function(response) {
                        $results.empty();
                        if (response.success && response.data.posts && response.data.posts.length) {
                            $.each(response.data.posts, function(i, post) {
                                var already = self.selectedPosts.some(function(p) { return p.id === post.id; });
                                var imgHtml = post.image ? '<img src="' + self.escHtml(post.image) + '" alt="">' : '<span class="dashicons dashicons-admin-post"></span>';
                                $results.append(
                                    '<div class="bcsend-cl-result-item' + (already ? ' is-selected' : '') + '" data-id="' + post.id + '">' +
                                    '<div class="bcsend-cl-result-thumb">' + imgHtml + '</div>' +
                                    '<div class="bcsend-cl-result-info">' +
                                    '<div class="bcsend-cl-result-title">' + self.escHtml(post.title) + '</div>' +
                                    '<div class="bcsend-cl-result-meta">' + self.escHtml(post.date) + ' &middot; ' + self.escHtml(post.post_type) + '</div>' +
                                    '</div>' +
                                    '<span class="bcsend-cl-check dashicons dashicons-yes-alt"></span>' +
                                    '</div>'
                                );
                                $results.find('.bcsend-cl-result-item[data-id="' + post.id + '"]').data('post', post);
                            });
                        } else {
                            $results.html('<div class="bcsend-cl-no-results">' + bcsendAdmin.strings.noResults + '</div>');
                        }
                    });
                }, 350);
            });

            $results.on('click', '.bcsend-cl-result-item', function() {
                var post = $(this).data('post');
                var idx = self.selectedPosts.findIndex(function(p) { return p.id === post.id; });
                if (idx > -1) {
                    self.selectedPosts.splice(idx, 1);
                    $(this).removeClass('is-selected');
                } else {
                    self.selectedPosts.push(post);
                    $(this).addClass('is-selected');
                }
                self.renderSelectedPosts();
            });
        },

        renderSelectedPosts: function() {
            this.renderAllSelected();
        },

        /* ==============================================================
           Snippets Tab
           ============================================================== */

        bindSnippetTab: function() {
            var self = this;

            // Save Snippet button.
            $('#bcsend-save-snippet-btn').on('click', function() {
                var $editor = $('#bcsend-html-editor')[0];
                var selected = $editor.value.substring($editor.selectionStart, $editor.selectionEnd);
                var content = $.trim(selected) || $.trim($editor.value);

                if (!content) {
                    Bcsend.notify('No HTML content to save.', 'warning');
                    return;
                }

                var name = prompt('Snippet name:');
                if (!name) { return; }

                var category = prompt('Category (optional):', 'general') || 'general';

                Bcsend.ajax('bcsend_save_snippet', {
                    name: name,
                    category: category,
                    html_content: content
                }, function(response) {
                    if (response.success) {
                        Bcsend.notify(response.data.message || 'Snippet saved.', 'success');
                        self.loadSnippets();
                    } else {
                        Bcsend.notify((response.data && response.data.message) || 'Failed to save.', 'error');
                    }
                });
            });

            // Delegate insert and delete.
            $('#bcsend-cl-snippet-list').on('click', '.bcsend-cl-snippet-insert', function() {
                var html = $(this).closest('.bcsend-cl-snippet-item').data('html');
                self.insertAtCursor(html);
            });

            $('#bcsend-cl-snippet-list').on('click', '.bcsend-cl-snippet-delete', function() {
                var $item = $(this).closest('.bcsend-cl-snippet-item');
                var id = $item.data('id');
                if (!confirm('Delete this snippet?')) { return; }

                Bcsend.ajax('bcsend_delete_snippet', { id: id }, function(response) {
                    if (response.success) {
                        $item.fadeOut(200, function() { $(this).remove(); });
                    }
                });
            });
        },

        loadSnippets: function() {
            var self = this;
            var $list = $('#bcsend-cl-snippet-list');
            if (!$list.length) { return; }

            Bcsend.ajax('bcsend_get_snippets', {}, function(response) {
                $list.empty();
                if (response.success && response.data.snippets && response.data.snippets.length) {
                    $.each(response.data.snippets, function(i, snippet) {
                        var $item = $(
                            '<div class="bcsend-cl-snippet-item" data-id="' + snippet.id + '">' +
                            '<div class="bcsend-cl-snippet-info">' +
                            '<strong>' + self.escHtml(snippet.name) + '</strong>' +
                            '<span class="bcsend-cl-snippet-cat">' + self.escHtml(snippet.category) + '</span>' +
                            '</div>' +
                            '<div class="bcsend-cl-snippet-actions">' +
                            '<button type="button" class="button button-small bcsend-cl-snippet-insert">Insert</button>' +
                            '<button type="button" class="button button-small bcsend-cl-snippet-delete" title="Delete">&times;</button>' +
                            '</div>' +
                            '</div>'
                        );
                        $item.data('html', snippet.html_content);
                        $list.append($item);
                    });
                } else {
                    $list.html('<div class="bcsend-cl-no-results">No snippets saved yet.</div>');
                }
            });
        }
    };

    $(document).ready(function() {
        Composer.init();
    });

})(jQuery);
