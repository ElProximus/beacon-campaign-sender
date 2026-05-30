<?php
/**
 * Settings page view for Beacon Campaign Sender.
 *
 * Renders a tabbed settings interface with sections for Brevo,
 * Push, Brand Voice, Base Template, AI, Abilities Bridge, and Logs.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var array $settings Decrypted settings array from Bcsend_Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'brevo';
$env        = Bcsend_Environment::get_instance();

$settings_tabs                       = array(
	'brevo'            => __( 'Brevo', 'beacon-campaign-sender' ),
	'push'             => __( 'Push', 'beacon-campaign-sender' ),
	'social'           => __( 'Social', 'beacon-campaign-sender' ),
	'brand-voice'      => __( 'Brand Voice', 'beacon-campaign-sender' ),
	'base-template'    => __( 'Base Template', 'beacon-campaign-sender' ),
	'ai'               => __( 'AI', 'beacon-campaign-sender' ),
	'abilities-bridge' => __( 'Abilities', 'beacon-campaign-sender' ),
	'logs'             => __( 'Logs', 'beacon-campaign-sender' ),
);
$zernio_accounts            = get_option( 'bcsend_zernio_accounts', array() );
$zernio_profiles            = get_option( 'bcsend_zernio_profiles', array() );
$zernio_profile_name        = get_option( 'bcsend_zernio_profile_name', '' );
$zernio_webhook_diagnostics = get_option( 'bcsend_zernio_webhook_diagnostics', array() );
?>
<div class="wrap bcsend-wrap bcsend-settings-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Configuration', 'beacon-campaign-sender' ); ?></span>
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Connections, sending defaults, AI behavior, and bridge settings all live here. Each tab is meant to answer one operational question quickly.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=beacon-campaign-sender' ) ); ?>" class="button"><?php esc_html_e( 'Back to Dashboard', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<?php settings_errors( 'bcsend_settings' ); ?>

	<nav class="nav-tab-wrapper bcsend-nav-tab-wrapper">
		<?php foreach ( $settings_tabs as $tab_slug => $tab_label ) : ?>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => 'bcsend-settings',
						'tab'  => $tab_slug,
					),
					admin_url( 'admin.php' )
				)
			);
			?>
						"
				class="nav-tab <?php echo ( $active_tab === $tab_slug ) ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="options.php" class="bcsend-settings-form">
		<?php settings_fields( Bcsend_Settings::SETTINGS_GROUP ); ?>

		<!-- Brevo Tab -->
		<div class="bcsend-tab-content" <?php echo ( 'brevo' !== $active_tab ) ? 'style="display:none;"' : 'style="display:block;"'; ?> id="bcsend-tab-brevo">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bcsend-brevo-api-key"><?php esc_html_e( 'API Key', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="password"
								id="bcsend-brevo-api-key"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[brevo_api_key]"
								value="<?php echo esc_attr( $settings['brevo_api_key'] ); ?>"
								class="regular-text"
								autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Enter your Brevo (Sendinblue) API key.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-brevo-sender-name"><?php esc_html_e( 'Sender Name', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="text"
								id="bcsend-brevo-sender-name"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[brevo_sender_name]"
								value="<?php echo esc_attr( $settings['brevo_sender_name'] ); ?>"
								class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-brevo-sender-email"><?php esc_html_e( 'Sender Email', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="email"
								id="bcsend-brevo-sender-email"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[brevo_sender_email]"
								value="<?php echo esc_attr( $settings['brevo_sender_email'] ); ?>"
								class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-reply-to-email"><?php esc_html_e( 'Default Reply-To Address', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="email"
								id="bcsend-reply-to-email"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[reply_to_email]"
								value="<?php echo esc_attr( $settings['reply_to_email'] ); ?>"
								class="regular-text" />
						<p class="description">
							<?php echo esc_html__( "This Reply-To applies only to campaigns and test emails sent by Beacon Campaign Sender. It does not change the Reply-To for other WordPress email, even when Beacon's WordPress Email Routing delivers that mail, each of those keeps its own Reply-To. Leave blank to reply to the Sender Email (From) above.", 'beacon-campaign-sender' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-default-subscriber-lists"><?php esc_html_e( 'Default Subscriber Lists', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="text"
								id="bcsend-default-subscriber-lists"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[default_subscriber_lists]"
								value="<?php echo esc_attr( implode( ', ', (array) $settings['default_subscriber_lists'] ) ); ?>"
								class="regular-text"
								placeholder="14" />
						<p class="description"><?php esc_html_e( 'Comma-separated Brevo list IDs used for new subscriber ingestion when a source does not provide an override.', 'beacon-campaign-sender' ); ?></p>
						<p class="description">
							<?php
							printf(
								/* translators: 1: subscribe form shortcode, 2: shortcode with list_ids override. */
								esc_html__( 'Add the signup form to any page or post with the shortcode %1$s. New subscribers are added to the list IDs above. To send a specific form to different lists instead, pass a list_ids attribute, for example %2$s.', 'beacon-campaign-sender' ),
								'<code>[bcsend_subscribe_form]</code>',
								'<code>[bcsend_subscribe_form list_ids="14,15"]</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-subscribe-terms-url"><?php esc_html_e( 'Subscribe Terms URL', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="url"
								id="bcsend-subscribe-terms-url"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[subscribe_terms_url]"
								value="<?php echo esc_attr( $settings['subscribe_terms_url'] ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( home_url( '/terms-of-service/' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional fine-print link shown below the public subscribe form. Defaults to /terms-of-service/ so the form is ready out of the box.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-subscribe-terms-text"><?php esc_html_e( 'Subscribe Fine Print', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="text"
								id="bcsend-subscribe-terms-text"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[subscribe_terms_text]"
								value="<?php echo esc_attr( $settings['subscribe_terms_text'] ); ?>"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'By signing up, you agree to our', 'beacon-campaign-sender' ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional lead-in text shown before the terms link. Leave blank to hide the fine print entirely.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-subscribe-terms-link-text"><?php esc_html_e( 'Terms Link Label', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="text"
								id="bcsend-subscribe-terms-link-text"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[subscribe_terms_link_text]"
								value="<?php echo esc_attr( $settings['subscribe_terms_link_text'] ); ?>"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'Terms of Service', 'beacon-campaign-sender' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-subscribe-custom-css"><?php esc_html_e( 'Subscribe Form CSS', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<textarea id="bcsend-subscribe-custom-css"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[subscribe_custom_css]"
								rows="8"
								class="large-text code"
								spellcheck="false"
								placeholder=".bcsend-subscribe-form button { background: #175cd3; }"><?php echo esc_textarea( $settings['subscribe_custom_css'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional CSS applied to every subscribe form on the site (shortcode or custom HTML), loaded after the form’s default styles so your rules win. Scope rules to .bcsend-subscribe-form (or a class you add via the shortcode’s class="" attribute) to avoid affecting the rest of the page.', 'beacon-campaign-sender' ); ?></p>
						<p class="description">
							<?php esc_html_e( 'Class hooks you can target:', 'beacon-campaign-sender' ); ?>
							<code>.bcsend-subscribe-form</code>, <code>.bcsend-subscribe-form-row</code>, <code>.bcsend-subscribe-form-consent</code>, <code>.bcsend-subscribe-form-fine-print</code>, <code>.bcsend-subscribe-form-message</code>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Embed in Custom HTML', 'beacon-campaign-sender' ); ?></th>
					<td>
						<p class="description"><?php esc_html_e( 'For most sites the [bcsend_subscribe_form] shortcode is easiest. To use your own markup, build any form with the class, REST URL, and field names below — the plugin’s script handles submission automatically on every front-end page.', 'beacon-campaign-sender' ); ?></p>
						<?php
						$bcsend_subscribe_rest_url = esc_url( rest_url( 'beacon-campaign-sender/v1/subscribe' ) );
						$bcsend_custom_html_snippet = sprintf(
							'<form class="bcsend-subscribe-form" data-bcsend-rest-url="%s">' . "\n" .
							'  <input type="email" name="email" required placeholder="Email address" />' . "\n" .
							'  <label><input type="checkbox" name="consent" value="1" required /> I agree to receive emails.</label>' . "\n" .
							'  <input type="text" name="first_name" placeholder="First name" />' . "\n" .
							'  <input type="text" name="last_name" placeholder="Last name" />' . "\n" .
							'  <input type="hidden" name="list_ids" value="" />' . "\n" .
							'  <input type="text" name="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;" />' . "\n" .
							'  <button type="submit">Sign me up</button>' . "\n" .
							'  <div class="bcsend-subscribe-form-message" aria-live="polite"></div>' . "\n" .
							'</form>',
							$bcsend_subscribe_rest_url
						);
						?>
						<textarea readonly rows="11" class="large-text code" spellcheck="false" onclick="this.select();"><?php echo esc_textarea( $bcsend_custom_html_snippet ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Required: the bcsend-subscribe-form class, the data-bcsend-rest-url attribute, an email field, and a consent checkbox. first_name, last_name, list_ids (defaults to the lists above when empty), the honeypot anti-spam field, and the message div are optional.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Test', 'beacon-campaign-sender' ); ?></th>
					<td>
						<button type="button" class="button bcsend-test-connection" data-service="brevo">
							<?php esc_html_e( 'Test Connection', 'beacon-campaign-sender' ); ?>
						</button>
						<span class="bcsend-test-result" id="bcsend-brevo-test-result"></span>
					</td>
				</tr>
			</table>

			<hr style="margin: 30px 0 20px;" />
			<h3><?php esc_html_e( 'WordPress Email Routing', 'beacon-campaign-sender' ); ?></h3>
			<p class="description" style="margin-bottom: 15px;">
				<?php esc_html_e( 'Route all WordPress system emails (password resets, WooCommerce orders, notifications, etc.) through Brevo using the API key above.', 'beacon-campaign-sender' ); ?>
			</p>
			<?php
			$email_routing_active = ! empty( $settings['smtp_routing_enabled'] ) && ! empty( $settings['brevo_api_key'] );
			?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Routing Status', 'beacon-campaign-sender' ); ?></th>
					<td>
						<?php if ( $email_routing_active ) : ?>
							<span class="bcsend-status-badge bcsend-status-success"><?php esc_html_e( 'Active', 'beacon-campaign-sender' ); ?></span>
							<p class="description"><?php esc_html_e( 'Beacon Campaign Sender is currently set to intercept global wp_mail() traffic and route it through Brevo.', 'beacon-campaign-sender' ); ?></p>
						<?php else : ?>
							<span class="bcsend-status-badge bcsend-status-inactive"><?php esc_html_e( 'Inactive', 'beacon-campaign-sender' ); ?></span>
							<p class="description"><?php esc_html_e( 'Global wp_mail() override is off. Beacon Campaign Sender will not reroute WordPress core or other plugin email until Email Routing is enabled and Brevo is configured.', 'beacon-campaign-sender' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-smtp-routing"><?php esc_html_e( 'Enable Email Routing', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox"
									id="bcsend-smtp-routing"
									name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[smtp_routing_enabled]"
									value="1"
									<?php checked( $settings['smtp_routing_enabled'], 1 ); ?> />
							<?php esc_html_e( 'Route all WordPress email through Brevo SMTP', 'beacon-campaign-sender' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, all emails sent by WordPress core and other plugins via wp_mail() will be delivered via Brevo. This replaces the need for a separate SMTP plugin.', 'beacon-campaign-sender' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Beacon Campaign Sender attempts Brevo delivery first. Some failure types can fall back to the site’s normal WordPress mail transport, while others are logged as delivery failures.', 'beacon-campaign-sender' ); ?>
						</p>
						<?php if ( Bcsend_Smtp::is_wp_mail_smtp_active() ) : ?>
							<p style="color: #d63638; margin-top: 8px;">
								<strong><?php esc_html_e( 'Warning:', 'beacon-campaign-sender' ); ?></strong>
								<?php esc_html_e( 'WP Mail SMTP is currently active. Both plugins will attempt to configure email delivery. Deactivate WP Mail SMTP before enabling this feature.', 'beacon-campaign-sender' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="bcsend-smtp-force-from-row" <?php echo empty( $settings['smtp_routing_enabled'] ) ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="bcsend-smtp-force-from"><?php esc_html_e( 'Force From Name & Email', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox"
									id="bcsend-smtp-force-from"
									name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[smtp_force_from]"
									value="1"
									<?php checked( $settings['smtp_force_from'], 1 ); ?> />
							<?php esc_html_e( 'Override From name and email on all WordPress emails', 'beacon-campaign-sender' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: 1: sender name, 2: sender email */
								esc_html__( 'All emails will be sent as "%1$s" <%2$s> using the Sender Name and Sender Email configured above.', 'beacon-campaign-sender' ),
								esc_html( $settings['brevo_sender_name'] ),
								esc_html( $settings['brevo_sender_email'] )
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Push Tab -->
		<div class="bcsend-tab-content" <?php echo ( 'push' !== $active_tab ) ? 'style="display:none;"' : 'style="display:block;"'; ?> id="bcsend-tab-push">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Push Configuration', 'beacon-campaign-sender' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio"
										name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[push_mode]"
										value="auto"
										<?php checked( $settings['push_mode'], 'auto' ); ?> />
								<?php esc_html_e( 'Auto-detect (recommended)', 'beacon-campaign-sender' ); ?>
							</label>
							<br />
							<label>
								<input type="radio"
										name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[push_mode]"
										value="manual"
										<?php checked( $settings['push_mode'], 'manual' ); ?> />
								<?php esc_html_e( 'Manual configuration', 'beacon-campaign-sender' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr class="bcsend-push-auto-row">
					<th scope="row"><?php esc_html_e( 'Detection Status', 'beacon-campaign-sender' ); ?></th>
					<td>
						<?php if ( $env->is( 'buddyboss_present' ) ) : ?>
							<span class="bcsend-status-badge bcsend-status-success"><?php esc_html_e( 'BuddyBoss App detected', 'beacon-campaign-sender' ); ?></span>
							<p class="description"><?php esc_html_e( 'Push notifications will use the BuddyBoss App Firebase configuration.', 'beacon-campaign-sender' ); ?></p>
						<?php else : ?>
							<span class="bcsend-status-badge bcsend-status-warning"><?php esc_html_e( 'BuddyBoss App not detected', 'beacon-campaign-sender' ); ?></span>
							<p class="description"><?php esc_html_e( 'Switch to manual mode and provide Firebase credentials.', 'beacon-campaign-sender' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="bcsend-push-manual-row">
					<th scope="row">
						<label for="bcsend-firebase-json"><?php esc_html_e( 'Firebase Service Account JSON', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<textarea id="bcsend-firebase-json"
									name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[firebase_service_account_json]"
									rows="10"
									class="large-text code"
									placeholder="<?php esc_attr_e( 'Paste your Firebase service account JSON here...', 'beacon-campaign-sender' ); ?>"><?php echo esc_textarea( $settings['firebase_service_account_json'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Paste the full JSON contents of your Firebase service account key file.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr class="bcsend-push-manual-row">
					<th scope="row">
						<label for="bcsend-firebase-project-id"><?php esc_html_e( 'Firebase Project ID', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="text"
								id="bcsend-firebase-project-id"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[firebase_project_id]"
								value="<?php echo esc_attr( $settings['firebase_project_id'] ); ?>"
								class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Test', 'beacon-campaign-sender' ); ?></th>
					<td>
						<button type="button" class="button bcsend-test-connection" data-service="firebase">
							<?php esc_html_e( 'Test Connection', 'beacon-campaign-sender' ); ?>
						</button>
						<span class="bcsend-test-result" id="bcsend-firebase-test-result"></span>
					</td>
				</tr>
			</table>
		</div>

		<!-- Social Tab -->
		<div class="bcsend-tab-content" <?php echo ( 'social' !== $active_tab ) ? 'style="display:none;"' : 'style="display:block;"'; ?> id="bcsend-tab-social">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bcsend-zernio-api-key"><?php esc_html_e( 'Zernio API Key', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="password"
								id="bcsend-zernio-api-key"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[zernio_api_key]"
								class="regular-text"
								placeholder="<?php echo ! empty( $settings['zernio_api_key'] ) ? esc_attr__( 'A value is saved. Enter a new key to replace it.', 'beacon-campaign-sender' ) : ''; ?>"
								autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Enter your Zernio API key (Bearer token).', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Test', 'beacon-campaign-sender' ); ?></th>
					<td>
						<button type="button" class="button bcsend-test-connection" data-service="zernio">
							<?php esc_html_e( 'Test Connection', 'beacon-campaign-sender' ); ?>
						</button>
						<span class="bcsend-test-result" id="bcsend-zernio-test-result"></span>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-zernio-profile"><?php esc_html_e( 'Active Profile', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<select id="bcsend-zernio-profile" name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[zernio_profile_id]" class="regular-text">
							<?php if ( ! empty( $zernio_profiles ) && is_array( $zernio_profiles ) ) : ?>
								<?php foreach ( $zernio_profiles as $profile ) : ?>
									<?php
									if ( ! is_array( $profile ) ) {
										continue;
									}

									$profile_id = '';
									if ( isset( $profile['id'] ) ) {
										$profile_id = (string) $profile['id'];
									} elseif ( isset( $profile['_id'] ) ) {
										$profile_id = (string) $profile['_id'];
									} elseif ( isset( $profile['profileId'] ) ) {
										$profile_id = (string) $profile['profileId'];
									} elseif ( isset( $profile['profile_id'] ) ) {
										$profile_id = (string) $profile['profile_id'];
									} elseif ( isset( $profile['uuid'] ) ) {
										$profile_id = (string) $profile['uuid'];
									}

									$profile_label = '';
									if ( isset( $profile['name'] ) ) {
										$profile_label = (string) $profile['name'];
									} elseif ( isset( $profile['displayName'] ) ) {
										$profile_label = (string) $profile['displayName'];
									} elseif ( isset( $profile['title'] ) ) {
										$profile_label = (string) $profile['title'];
									} elseif ( isset( $profile['label'] ) ) {
										$profile_label = (string) $profile['label'];
									}

									if ( '' === $profile_id ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $settings['zernio_profile_id'], $profile_id ); ?>>
										<?php echo esc_html( $profile_label ? $profile_label : $profile_id ); ?>
									</option>
								<?php endforeach; ?>
							<?php elseif ( ! empty( $settings['zernio_profile_id'] ) ) : ?>
								<option value="<?php echo esc_attr( $settings['zernio_profile_id'] ); ?>" selected="selected">
									<?php echo esc_html( $zernio_profile_name ? $zernio_profile_name : $settings['zernio_profile_id'] ); ?>
								</option>
							<?php else : ?>
								<option value=""><?php esc_html_e( 'Load profiles first', 'beacon-campaign-sender' ); ?></option>
							<?php endif; ?>
						</select>
						<button type="button" class="button" id="bcsend-zernio-fetch-profiles"><?php esc_html_e( 'Load Profiles', 'beacon-campaign-sender' ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connected Accounts', 'beacon-campaign-sender' ); ?></th>
					<td>
						<p>
							<button type="button" class="button" id="bcsend-zernio-sync-accounts"><?php esc_html_e( 'Sync Connected Accounts', 'beacon-campaign-sender' ); ?></button>
							<span id="bcsend-zernio-sync-status" class="bcsend-inline-status"></span>
						</p>
						<p class="description"><?php esc_html_e( 'Manage account connections in Zernio, then use sync here to pull the latest connected accounts for the selected profile.', 'beacon-campaign-sender' ); ?></p>
						<div id="bcsend-zernio-accounts-list">
							<?php if ( ! empty( $zernio_accounts ) ) : ?>
								<table class="widefat striped">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Platform', 'beacon-campaign-sender' ); ?></th>
											<th><?php esc_html_e( 'Username', 'beacon-campaign-sender' ); ?></th>
											<th><?php esc_html_e( 'Account ID', 'beacon-campaign-sender' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $zernio_accounts as $account ) : ?>
											<?php
											$account_id = '';
											if ( isset( $account['id'] ) ) {
												$account_id = (string) $account['id'];
											} elseif ( isset( $account['_id'] ) ) {
												$account_id = (string) $account['_id'];
											} elseif ( isset( $account['accountId'] ) ) {
												$account_id = (string) $account['accountId'];
											} elseif ( isset( $account['account_id'] ) ) {
												$account_id = (string) $account['account_id'];
											} elseif ( isset( $account['uuid'] ) ) {
												$account_id = (string) $account['uuid'];
											}
											?>
											<tr>
												<td><?php echo esc_html( isset( $account['platform'] ) ? $account['platform'] : '' ); ?></td>
												<td><?php echo esc_html( isset( $account['username'] ) ? $account['username'] : ( isset( $account['handle'] ) ? $account['handle'] : ( isset( $account['displayName'] ) ? $account['displayName'] : '' ) ) ); ?></td>
												<td><code><?php echo esc_html( $account_id ); ?></code></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No synced accounts yet.', 'beacon-campaign-sender' ); ?></p>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-zernio-post-mode"><?php esc_html_e( 'Zernio Post Mode', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<select id="bcsend-zernio-post-mode" name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[zernio_post_mode]" class="regular-text">
							<option value="single" <?php selected( isset( $settings['zernio_post_mode'] ) ? $settings['zernio_post_mode'] : 'single', 'single' ); ?>>
								<?php esc_html_e( 'Single post for selected accounts', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="per_platform" <?php selected( isset( $settings['zernio_post_mode'] ) ? $settings['zernio_post_mode'] : 'single', 'per_platform' ); ?>>
								<?php esc_html_e( 'Separate post per platform', 'beacon-campaign-sender' ); ?>
							</option>
						</select>
						<p class="description"><?php esc_html_e( 'Single post mode uses one Zernio post for all selected social accounts. Separate mode keeps platform-specific copy and media but uses more Zernio post quota.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook Sync', 'beacon-campaign-sender' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[zernio_webhook_enabled]"
									value="1"
									<?php checked( $settings['zernio_webhook_enabled'], 1 ); ?> />
							<?php esc_html_e( 'Enable Zernio webhook status syncing', 'beacon-campaign-sender' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Choose any private secret here. Beacon Campaign Sender will save it and use it to verify that incoming webhook requests really came from Zernio. You do not need to get a secret key from Zernio. Saving settings will automatically sync the webhook configuration for you.', 'beacon-campaign-sender' ); ?></p>
						<input type="password"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[zernio_webhook_secret]"
								class="regular-text"
								placeholder="<?php echo ! empty( $settings['zernio_webhook_secret'] ) ? esc_attr__( 'A value is saved. Enter a new secret to replace it.', 'beacon-campaign-sender' ) : ''; ?>"
								autocomplete="off" />
						<p class="description">
							<?php esc_html_e( 'Webhook endpoint:', 'beacon-campaign-sender' ); ?>
							<code><?php echo esc_html( rest_url( 'beacon-campaign-sender/v1/zernio/webhook' ) ); ?></code>
						</p>
						<p>
							<button type="button" class="button" id="bcsend-zernio-sync-webhook"><?php esc_html_e( 'Retry Webhook Sync', 'beacon-campaign-sender' ); ?></button>
							<span id="bcsend-zernio-webhook-status" class="bcsend-inline-status"></span>
						</p>
						<p class="description"><?php esc_html_e( 'Usually not needed. Save Changes syncs the webhook automatically. Use this only if you want to retry the sync manually.', 'beacon-campaign-sender' ); ?></p>
						<p>
							<button type="button" class="button" id="bcsend-zernio-test-webhook"><?php esc_html_e( 'Write Local Test Event', 'beacon-campaign-sender' ); ?></button>
							<button type="button" class="button" id="bcsend-zernio-clear-webhook"><?php esc_html_e( 'Clear Diagnostics', 'beacon-campaign-sender' ); ?></button>
						</p>
						<div class="bcsend-zernio-webhook-diagnostics">
							<h4><?php esc_html_e( 'Webhook Diagnostics', 'beacon-campaign-sender' ); ?></h4>
							<table class="widefat striped">
								<tbody>
									<tr>
										<th><?php esc_html_e( 'Last Received', 'beacon-campaign-sender' ); ?></th>
										<td id="bcsend-zernio-last-received"><?php echo ! empty( $zernio_webhook_diagnostics['last_received_at'] ) ? esc_html( $zernio_webhook_diagnostics['last_received_at'] ) : esc_html__( 'Never', 'beacon-campaign-sender' ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Last Status', 'beacon-campaign-sender' ); ?></th>
										<td id="bcsend-zernio-last-status"><?php echo ! empty( $zernio_webhook_diagnostics['last_status'] ) ? esc_html( $zernio_webhook_diagnostics['last_status'] ) : esc_html__( 'N/A', 'beacon-campaign-sender' ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Last Event', 'beacon-campaign-sender' ); ?></th>
										<td id="bcsend-zernio-last-event"><?php echo ! empty( $zernio_webhook_diagnostics['last_event'] ) ? esc_html( $zernio_webhook_diagnostics['last_event'] ) : esc_html__( 'N/A', 'beacon-campaign-sender' ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Signature Header', 'beacon-campaign-sender' ); ?></th>
										<td id="bcsend-zernio-last-signature"><?php echo ! empty( $zernio_webhook_diagnostics['last_signature_header'] ) ? esc_html( $zernio_webhook_diagnostics['last_signature_header'] ) : esc_html__( 'N/A', 'beacon-campaign-sender' ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Last Error', 'beacon-campaign-sender' ); ?></th>
										<td id="bcsend-zernio-last-error"><?php echo ! empty( $zernio_webhook_diagnostics['last_error'] ) ? esc_html( $zernio_webhook_diagnostics['last_error'] ) : esc_html__( 'None', 'beacon-campaign-sender' ); ?></td>
									</tr>
								</tbody>
							</table>
							<?php if ( ! empty( $zernio_webhook_diagnostics['last_payload'] ) ) : ?>
								<p class="description"><?php esc_html_e( 'Last payload excerpt', 'beacon-campaign-sender' ); ?></p>
								<textarea readonly rows="8" class="large-text code" id="bcsend-zernio-last-payload"><?php echo esc_textarea( $zernio_webhook_diagnostics['last_payload'] ); ?></textarea>
							<?php else : ?>
								<textarea readonly rows="8" class="large-text code" id="bcsend-zernio-last-payload" placeholder="<?php esc_attr_e( 'No webhook payload received yet.', 'beacon-campaign-sender' ); ?>"></textarea>
							<?php endif; ?>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<!-- Brand Voice Tab -->
		<div class="bcsend-tab-content" <?php echo ( 'brand-voice' !== $active_tab ) ? 'style="display:none;"' : 'style="display:block;"'; ?> id="bcsend-tab-brand-voice">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bcsend-brand-voice"><?php esc_html_e( 'Brand Voice', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<textarea id="bcsend-brand-voice"
									name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[brand_voice]"
									rows="12"
									class="large-text"
									placeholder="<?php esc_attr_e( 'Describe your brand tone, personality, writing style, and any specific language or phrases to use or avoid. This will be used by AI when generating campaign content.', 'beacon-campaign-sender' ); ?>"><?php echo esc_textarea( $settings['brand_voice'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'This description is included in every AI content generation request to maintain consistent brand messaging.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Base Template Tab -->
		<div class="bcsend-tab-content" <?php echo ( 'base-template' !== $active_tab ) ? 'style="display:none;"' : 'style="display:block;"'; ?> id="bcsend-tab-base-template">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bcsend-base-template"><?php esc_html_e( 'Base HTML Template', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<textarea id="bcsend-base-template"
									name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[base_template]"
									rows="20"
									class="large-text code"><?php echo esc_textarea( $settings['base_template'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'The HTML shell used as the foundation for all generated email campaigns.', 'beacon-campaign-sender' ); ?></p>
						<p class="bcsend-template-actions">
							<button type="button" class="button" id="bcsend-preview-template">
								<?php esc_html_e( 'Preview', 'beacon-campaign-sender' ); ?>
							</button>
							<button type="button" class="button" id="bcsend-reset-template">
								<?php esc_html_e( 'Reset to Default', 'beacon-campaign-sender' ); ?>
							</button>
						</p>
					</td>
				</tr>
			</table>
			<div id="bcsend-template-preview-overlay" class="bcsend-modal-overlay" style="display:none;">
				<div class="bcsend-modal-content">
					<button type="button" class="bcsend-modal-close" id="bcsend-close-template-preview">&times;</button>
					<iframe id="bcsend-template-preview-frame" class="bcsend-preview-iframe"></iframe>
				</div>
			</div>
		</div>

		<!-- AI Tab -->
		<div class="bcsend-tab-content" <?php echo ( 'ai' !== $active_tab ) ? 'style="display:none;"' : 'style="display:block;"'; ?> id="bcsend-tab-ai">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bcsend-ai-provider"><?php esc_html_e( 'AI Provider', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<select id="bcsend-ai-provider"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[ai_provider]">
							<option value="anthropic" <?php selected( $settings['ai_provider'], 'anthropic' ); ?>>
								<?php esc_html_e( 'Anthropic Claude', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="openai" <?php selected( $settings['ai_provider'], 'openai' ); ?>>
								<?php esc_html_e( 'OpenAI', 'beacon-campaign-sender' ); ?>
							</option>
						</select>
						<p class="description"><?php esc_html_e( 'Beacon Campaign Sender will use this provider for campaign generation, HTML regeneration, push copy regeneration, and sample generation.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-anthropic-api-key"><?php esc_html_e( 'Anthropic API Key', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="password"
								id="bcsend-anthropic-api-key"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[anthropic_api_key]"
								value="<?php echo esc_attr( $settings['anthropic_api_key'] ); ?>"
								class="regular-text"
								autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Your Anthropic API key for AI-powered content generation.', 'beacon-campaign-sender' ); ?></p>
						<p>
							<button type="button" class="button bcsend-test-connection" data-service="anthropic">
								<?php esc_html_e( 'Test Anthropic Connection', 'beacon-campaign-sender' ); ?>
							</button>
							<span class="bcsend-test-result" id="bcsend-anthropic-test-result"></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-anthropic-model"><?php esc_html_e( 'Model', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<select id="bcsend-anthropic-model"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[anthropic_model]">
							<option value="claude-opus-4-8" <?php selected( $settings['anthropic_model'], 'claude-opus-4-8' ); ?>>
								<?php esc_html_e( 'Claude Opus 4.8 (Most capable)', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="claude-opus-4-7" <?php selected( $settings['anthropic_model'], 'claude-opus-4-7' ); ?>>
								<?php esc_html_e( 'Claude Opus 4.7', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="claude-sonnet-4-6" <?php selected( $settings['anthropic_model'], 'claude-sonnet-4-6' ); ?>>
								<?php esc_html_e( 'Claude Sonnet 4.6 (Recommended)', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="claude-haiku-4-5-20251001" <?php selected( $settings['anthropic_model'], 'claude-haiku-4-5-20251001' ); ?>>
								<?php esc_html_e( 'Claude Haiku 4.5 (Faster, lower cost)', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="claude-opus-4-6" <?php selected( $settings['anthropic_model'], 'claude-opus-4-6' ); ?>>
								<?php esc_html_e( 'Claude Opus 4.6 (Legacy)', 'beacon-campaign-sender' ); ?>
							</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-openai-api-key"><?php esc_html_e( 'OpenAI API Key', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="password"
								id="bcsend-openai-api-key"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[openai_api_key]"
								value="<?php echo esc_attr( $settings['openai_api_key'] ); ?>"
								class="regular-text"
								autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Your OpenAI API key for AI-powered content generation.', 'beacon-campaign-sender' ); ?></p>
						<p>
							<button type="button" class="button bcsend-test-connection" data-service="openai">
								<?php esc_html_e( 'Test OpenAI Connection', 'beacon-campaign-sender' ); ?>
							</button>
							<span class="bcsend-test-result" id="bcsend-openai-test-result"></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-openai-model"><?php esc_html_e( 'OpenAI Model', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<select id="bcsend-openai-model"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[openai_model]">
							<option value="gpt-5.5" <?php selected( $settings['openai_model'], 'gpt-5.5' ); ?>>
								<?php esc_html_e( 'GPT-5.5 (Most capable)', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="gpt-5.4" <?php selected( $settings['openai_model'], 'gpt-5.4' ); ?>>
								<?php esc_html_e( 'GPT-5.4 (Recommended)', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="gpt-5.2" <?php selected( $settings['openai_model'], 'gpt-5.2' ); ?>>
								<?php esc_html_e( 'GPT-5.2', 'beacon-campaign-sender' ); ?>
							</option>
							<option value="gpt-5-mini" <?php selected( $settings['openai_model'], 'gpt-5-mini' ); ?>>
								<?php esc_html_e( 'GPT-5 mini', 'beacon-campaign-sender' ); ?>
							</option>
						</select>
					</td>
				</tr>
			</table>
		</div>

		<!-- Abilities Tab -->
		<div class="bcsend-tab-content" <?php echo ( 'abilities-bridge' !== $active_tab ) ? 'style="display:none;"' : 'style="display:block;"'; ?> id="bcsend-tab-abilities-bridge">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Register Abilities', 'beacon-campaign-sender' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[abilities_bridge_enabled]"
									value="1"
									<?php checked( $settings['abilities_bridge_enabled'], 1 ); ?> />
							<?php esc_html_e( 'Register Beacon Campaign Sender abilities with the WordPress Abilities API', 'beacon-campaign-sender' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, Beacon Campaign Sender registers abilities that any compatible plugin (Abilities Bridge, MCP clients, etc.) can discover and use.', 'beacon-campaign-sender' ); ?>
						</p>
						<?php if ( ! function_exists( 'wp_register_ability' ) ) : ?>
							<p class="bcsend-notice-inline" style="color: #d63638; margin-top: 8px;">
								<?php esc_html_e( 'The WordPress Abilities API is not available. Install and activate the Abilities API plugin.', 'beacon-campaign-sender' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Registered Abilities', 'beacon-campaign-sender' ); ?></th>
					<td>
						<?php
						$bcsend_ability_ids = class_exists( 'Bcsend_Abilities_Bridge' )
							? Bcsend_Abilities_Bridge::get_ability_manifest()
							: array();
						$api_exists         = function_exists( 'wp_get_ability' );
						?>
						<table class="widefat fixed striped bcsend-tools-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Ability', 'beacon-campaign-sender' ); ?></th>
									<th><?php esc_html_e( 'Description', 'beacon-campaign-sender' ); ?></th>
									<th><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $bcsend_ability_ids as $ability_id => $ability_meta ) : ?>
									<?php
									$is_registered = $api_exists && $settings['abilities_bridge_enabled'] && wp_get_ability( $ability_id );
									?>
									<tr>
										<td><code><?php echo esc_html( $ability_id ); ?></code></td>
										<td><?php echo esc_html( $ability_meta['settings_label'] ); ?></td>
										<td>
											<?php if ( $is_registered ) : ?>
												<span class="bcsend-status-badge bcsend-status-success"><?php esc_html_e( 'Registered', 'beacon-campaign-sender' ); ?></span>
											<?php elseif ( $settings['abilities_bridge_enabled'] ) : ?>
												<span class="bcsend-status-badge bcsend-status-warning"><?php esc_html_e( 'Pending', 'beacon-campaign-sender' ); ?></span>
											<?php else : ?>
												<span class="bcsend-status-badge bcsend-status-inactive"><?php esc_html_e( 'Disabled', 'beacon-campaign-sender' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</td>
				</tr>
			</table>
		</div>

		<!-- Logs Tab -->
		<div class="bcsend-tab-content" <?php echo ( 'logs' !== $active_tab ) ? 'style="display:none;"' : 'style="display:block;"'; ?> id="bcsend-tab-logs">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bcsend-log-retention"><?php esc_html_e( 'Log Retention (days)', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<input type="number"
								id="bcsend-log-retention"
								name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[log_retention_days]"
								value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>"
								min="1"
								max="365"
								class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of days to retain operational logs and email log entries. Campaign history is kept permanently regardless of this setting.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bcsend-email-log-detail-level"><?php esc_html_e( 'Email Log Privacy Mode', 'beacon-campaign-sender' ); ?></label>
					</th>
					<td>
						<select id="bcsend-email-log-detail-level" name="<?php echo esc_attr( Bcsend_Settings::OPTION_NAME ); ?>[email_log_detail_level]">
							<option value="minimal" <?php selected( $settings['email_log_detail_level'], 'minimal' ); ?>><?php esc_html_e( 'Minimal metadata only', 'beacon-campaign-sender' ); ?></option>
							<option value="full" <?php selected( $settings['email_log_detail_level'], 'full' ); ?>><?php esc_html_e( 'Full content and resend support', 'beacon-campaign-sender' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Minimal mode stores recipient, sender, subject, status, and delivery metadata only. Full mode also stores body, headers, CC/BCC, and attachments metadata so email previews and resend can work.', 'beacon-campaign-sender' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'beacon-campaign-sender' ) ); ?>
	</form>
</div>
