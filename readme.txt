=== Beacon Campaign Sender ===
Contributors: Joe12345Campbell
Tags: email, newsletter, push notifications, ai, marketing
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email, push notification, and social campaign management for WordPress with Brevo delivery, Firebase Cloud Messaging push notifications and Zernio social publishing,  with AI-assisted content generation. Can also route all WordPress email through Brevo when enabled.

== Description ==

Beacon Campaign Sender helps site administrators create campaigns for email, push notifications, and social publishing. It includes audience management, campaign scheduling, email delivery through Brevo, optional push delivery through Firebase or BuddyBoss App, Social Media publishing with Zernio over 12 platforms, and optional AI-assisted campaign drafting.

If the `WordPress Email Routing` setting is enabled by an administrator, Beacon Campaign Sender can route all mail traffic from WordPress core and other plugins through Brevo.

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

= What happens if Brevo email delivery fails? =

Beacon Campaign Sender attempts to send through Brevo first when WordPress Email Routing is enabled. Some failures can fall back to the normal WordPress mail path, while others are logged as failed deliveries depending on the error type.

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

When BuddyBoss App integration is available, Beacon Campaign Sender can read the BuddyBoss Firebase admin key path from BuddyBoss configuration to reuse the site’s existing push configuration.

Data accessed locally:
- BuddyBoss Firebase admin key file path
- BuddyBoss-managed Firebase service account JSON stored on the server

== Privacy ==

Beacon Campaign Sender stores subscriber sign-up records and email logs locally on your site. Depending on the enabled features, stored data can include email addresses, names, consent text, IP addresses, user agents, referrer metadata, message content, headers, attachments metadata, and delivery status information.

The plugin integrates with WordPress personal data exporters and erasers for locally stored subscriber and email log records.

== Changelog ==

= 1.0.1 =
- Keeps the Abilities Bridge integration card visible for discoverability and populates abilities only after the Bridge integration setting is enabled.
- Campaign composer Reply-To field now suggests addresses derived from the site's admin email and domain instead of hardcoded defaults.

= 1.0.0 =
- Initial public release on WordPress.org.
