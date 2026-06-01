=== Beacon Campaign Sender ===
Contributors: Joe12345Campbell
Tags: email, newsletter, push notifications, ai, marketing
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted email, push notification, and social campaign management for WordPress via Brevo, Firebase, and Zernio.

== Description ==

**Run your entire audience — email, push, and social — from one WordPress dashboard, with AI drafting built in.**

Beacon Campaign Sender brings campaign management that usually takes three or four separate tools into a single plugin. Write once, let AI help you polish it in your own brand voice, and reach subscribers by email, mobile push, and social media — without copy-pasting between services or wiring up glue code.

**Key features**

- **Email campaigns through Brevo** — compose, preview, schedule, and send, with deliverability handled by Brevo's API.
- **Audience management & segmentation** — organize contacts, sync Brevo lists, and target the right people.
- **Mobile push notifications** — deliver to devices through Firebase Cloud Messaging.
- **Social publishing with Zernio** — post to 12+ platforms from the same composer.
- **AI-assisted drafting** — generate and refine campaign content with the latest Claude (Opus 4.8, Sonnet 4.6) and OpenAI (GPT-5.5) models.
- **Brand voice control** — keep every AI draft sounding like you.
- **Newsletter signup forms** — drop a form on any page with a shortcode, or build your own with custom HTML.
- **WordPress email routing (optional)** — route all of your site's `wp_mail()` traffic through Brevo, replacing a separate SMTP plugin.
- **Privacy-friendly** — subscriber and log data is stored locally and integrates with WordPress personal-data exporters and erasers.

One dashboard, every channel, no glue code — so you can spend your time on the message, not the plumbing.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it through the WordPress plugins screen.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open `Beacon Campaign Sender` in the WordPress admin.
4. Configure the integrations you plan to use.

== Frequently Asked Questions ==

= Does this plugin use external services? =

Yes. Beacon Campaign Sender can connect to external services depending on which features you enable.

= Which external services can Beacon Campaign Sender use? =

Beacon Campaign Sender can integrate with:

- Brevo for email delivery, contact lists, and campaign data
- OpenAI for AI content generation
- Anthropic for AI content generation
- Firebase Cloud Messaging for push notification delivery
- Zernio for social publishing and webhook status updates

= Does this plugin override `wp_mail()`? =

It can, but only when an administrator enables the `WordPress Email Routing` setting. When that setting is active, WordPress core emails and emails sent by other plugins can be routed through Brevo instead of the site’s default mail transport.

= Will my SMTP plugin conflict? =

No. Campaign emails are always sent directly to Brevo's API and do not pass through `wp_mail()`, so your SMTP plugin is never involved in campaign delivery. If you do not enable `WordPress Email Routing`, your SMTP plugin will continue to handle all other site email normally. If you do enable `WordPress Email Routing`, Beacon Campaign Sender will route `wp_mail()` traffic from WordPress core and other plugins through Brevo, in which case a separate SMTP plugin is no longer needed — please deactivate it to avoid conflicts.

= How do I add an email signup form to my site? =

Add the shortcode `[bcsend_subscribe_form]` to any page, post, or text/shortcode widget. The form collects an email address and consent, plus optional first and last name. New subscribers are added to the list(s) set in Beacon Campaign Sender > Settings > Default Subscriber Lists.

If enabled (the default), the form works on any page via the shortcode or your own custom HTML. You can turn the entire signup feature off under Beacon Campaign Sender > Settings > Signup Forms, which also stops the submit script and form CSS from loading.

Optional shortcode attributes:

- `style` — "card" (default) or "inline"
- `show_names` — "true" (default) or "false"
- `button_text` — custom submit button label
- `list_ids` — comma-separated Brevo list IDs that override the default list
- `class` — extra CSS class(es) added to the form wrapper for styling

= How do I change the look of the signup form? =

You have three options, from easiest to most flexible:

1. Add CSS in Beacon Campaign Sender > Settings > Subscribe Form CSS. It applies to every subscribe form on the site, whether added by the shortcode or built as custom HTML, and is loaded after the built-in styles so your rules win. Scope rules to `.bcsend-subscribe-form` (or a class you add with the shortcode's `class` attribute) so they do not affect the rest of the page.
2. Target the form's class hooks from your theme stylesheet: `.bcsend-subscribe-form`, `.bcsend-subscribe-form-row`, `.bcsend-subscribe-form-consent`, `.bcsend-subscribe-form-fine-print`, and `.bcsend-subscribe-form-message`.
3. Use the `[bcsend_subscribe_form style="inline" class="my-form"]` attributes to drop most default styling and target your own class.

= Can I use my own HTML for the signup form? =

Yes. The plugin's submit script loads on every front-end page and handles any form that uses the expected markup, so you can build the form however you like. The required pieces are:

- The wrapper element has the class `bcsend-subscribe-form`.
- The wrapper has a `data-bcsend-rest-url` attribute pointing at the subscribe REST endpoint (`/wp-json/beacon-campaign-sender/v1/subscribe`).
- An `email` input and a `consent` checkbox.

Optional fields are `first_name`, `last_name`, `list_ids` (a hidden input; falls back to the default lists when empty), a `honeypot` anti-spam field, and a `.bcsend-subscribe-form-message` element for status messages. A ready-to-copy snippet is shown under Beacon Campaign Sender > Settings > Embed in Custom HTML.

== Uses External Services ==

= Brevo =

Beacon Campaign Sender sends email delivery requests and contact/list operations to Brevo when Brevo features are enabled.

If the optional `WordPress Email Routing` feature is enabled, Beacon Campaign Sender can also route global `wp_mail()` traffic from WordPress core and other plugins through Brevo.

Data sent:
- sender and recipient email addresses
- campaign subject and message content
- contact attributes and list identifiers

Service provider: Brevo
Service URL: https://www.brevo.com/
Privacy policy: https://www.brevo.com/legal/privacypolicy/
Terms: https://www.brevo.com/legal/termsofuse/

= OpenAI =

Beacon Campaign Sender sends prompts and selected campaign context to OpenAI when the OpenAI content-generation option is enabled.

Data sent:
- prompts entered by administrators
- selected post, product, image, or campaign context included in the generation request

Service provider: OpenAI
Service URL: https://openai.com/
Privacy policy: https://openai.com/policies/privacy-policy/
Terms: https://openai.com/policies/terms-of-use/

= Anthropic =

Beacon Campaign Sender sends prompts and selected campaign context to Anthropic when the Anthropic content-generation option is enabled.

Data sent:
- prompts entered by administrators
- selected post, product, image, or campaign context included in the generation request

Service provider: Anthropic
Service URL: https://www.anthropic.com/
Privacy policy: https://www.anthropic.com/privacy
Terms: https://www.anthropic.com/legal/consumer-terms

= Firebase Cloud Messaging =

Beacon Campaign Sender sends push notification requests to Firebase Cloud Messaging when manual Firebase push delivery is enabled.

Data sent:
- push notification title and message
- recipient device tokens
- optional deep link URLs

Service provider: Google Firebase
Service URL: https://firebase.google.com/
Privacy policy: https://policies.google.com/privacy
Terms: https://policies.google.com/terms

= Zernio =

Beacon Campaign Sender sends social-post publishing requests and webhook configuration data to Zernio when social publishing is enabled.

Data sent:
- social post content
- selected social account identifiers
- scheduling timestamps
- webhook endpoint URL and webhook status configuration

Service provider: Zernio
Service URL: https://zernio.com/
Privacy policy: https://zernio.com/privacy-policy
Terms: https://zernio.com/tos

= BuddyBoss App =

One-click integration with the BuddyBoss App, no configuration needed.

== Privacy ==

Beacon Campaign Sender stores subscriber sign-up records and email logs locally on your site. Depending on the enabled features, stored data can include email addresses, names, consent text, IP addresses, user agents, referrer metadata, message content, headers, attachments metadata, and delivery status information.

The plugin integrates with WordPress personal data exporters and erasers for locally stored subscriber and email log records.

== Changelog ==

= 1.0.5 =
- Added a Settings > Access tab to grant campaign access (Dashboard, Composer, and Campaign Queue) to existing Author-or-higher users without making them administrators.
- Assigned non-admin users get a streamlined, mobile-friendly campaign workspace with the WordPress admin sidebar, toolbar, and footer hidden on those screens, plus quick links back to WP Admin, their profile, and log out.
- Made the Composer mobile-responsive so campaigns can be created and sent from a phone.
- Hardened API key handling: saved secrets (Brevo, Zernio, Anthropic, OpenAI, and the Firebase service account) are no longer printed back into the settings form and are only overwritten when you explicitly choose to replace them, ignoring browser autofill.
- Retired the separate Campaign Manager role in favor of the per-user Access settings.

= 1.0.4 =
- Added an "Enable signup forms" setting (on by default). When unchecked, the [bcsend_subscribe_form] shortcode renders nothing, the submit script and form CSS no longer load, and the subscribe endpoint rejects submissions.

= 1.0.3 =
- Documented the public subscribe form: added the [bcsend_subscribe_form] shortcode usage to the readme and the Settings screen.
- Added a "class" shortcode attribute for adding custom CSS classes to the form wrapper.
- Added a "Subscribe Form CSS" setting that is applied after the default styles for easy restyling.
- The subscribe submit handler now loads on all front-end pages, so custom HTML forms using the documented markup work without the shortcode. Added an "Embed in Custom HTML" reference snippet to Settings.
- Updated the selectable Anthropic models to the current lineup: added Claude Opus 4.8 and Opus 4.7, kept Sonnet 4.6 (recommended) and Haiku 4.5, and marked Opus 4.6 as legacy.
- Subscribe Form CSS now also loads on pages that use only custom HTML forms, not just shortcode pages.
- The subscribe form status message no longer clears custom classes on submit; only the is-success and is-error state classes are toggled.
- Added GPT-5.5 to the OpenAI model choices and removed GPT-4.1 mini.

= 1.0.2 =
- Reply-To moved from the campaign composer to plugin Settings, with optional per-campaign override available to automations.
- Added "Sync Brevo Lists" button to the Audiences screen and "Regenerate HTML" button to the campaign composer.
- Scheduled sends now create or update the Brevo campaign at send time; composer preview emails use the same direct Brevo path for accurate Reply-To handling.
- Shortened the readme short description to meet the WordPress.org 150-character limit.

= 1.0.1 =
- Keeps the Abilities Bridge integration card visible for discoverability and populates abilities only after the Bridge integration setting is enabled.
- Campaign composer Reply-To field now suggests addresses derived from the site's admin email and domain instead of hardcoded defaults.

= 1.0.0 =
- Initial public release on WordPress.org.
