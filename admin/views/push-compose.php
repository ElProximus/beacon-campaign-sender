<?php
/**
 * Push notification composer view.
 *
 * @package Bcsend_Plugin
 * @since   2.2.0
 *
 * @var array  $roles     WordPress roles (slug => label).
 * @var array  $timezones Timezone identifiers.
 * @var string $wp_tz     Site timezone string.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url = add_query_arg( array( 'page' => 'bcsend-push' ), admin_url( 'admin.php' ) );
?>
<div class="wrap bcsend-wrap bcsend-push-compose-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Compose', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'New Push Notification', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Draft a notification, pick the audience, and either send now or schedule for later.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Back to Push Notifications', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<div id="bcsend-push-notification-bar" class="bcsend-notification" style="display: none;">
		<span class="bcsend-notification-message"></span>
	</div>

	<div class="bcsend-push-compose-panels" style="display: flex; gap: 30px; margin-top: 20px;">

		<!-- Left: Phone Mockup -->
		<div class="bcsend-push-compose-left" style="flex: 0 0 280px;">
			<div class="bcsend-phone-mockup">
				<div class="bcsend-phone-notch"></div>
				<div class="bcsend-phone-screen">
					<div class="bcsend-push-notification-preview">
						<div class="bcsend-push-app-icon"></div>
						<div class="bcsend-push-content">
							<span class="bcsend-push-preview-title" id="bcsend-push-preview-title"><?php esc_html_e( 'Push Title', 'beacon-campaign-sender' ); ?></span>
							<span class="bcsend-push-preview-message" id="bcsend-push-preview-message"><?php esc_html_e( 'Your message preview will appear here.', 'beacon-campaign-sender' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Right: Form Fields -->
		<div class="bcsend-push-compose-right" style="flex: 1; max-width: 600px;">

			<!-- Title -->
			<div class="bcsend-field-group" style="margin-bottom: 20px;">
				<label for="bcsend-push-title"><strong><?php esc_html_e( 'Title', 'beacon-campaign-sender' ); ?></strong></label>
				<input type="text" id="bcsend-push-title" maxlength="50" class="large-text" placeholder="<?php esc_attr_e( 'Notification title (optional)', 'beacon-campaign-sender' ); ?>" />
				<span class="bcsend-char-counter"><span id="bcsend-push-title-count">0</span>/50</span>
			</div>

			<!-- Message -->
			<div class="bcsend-field-group" style="margin-bottom: 20px;">
				<label for="bcsend-push-message"><strong><?php esc_html_e( 'Message', 'beacon-campaign-sender' ); ?></strong> <span class="required">*</span></label>
				<textarea id="bcsend-push-message" maxlength="400" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Notification message', 'beacon-campaign-sender' ); ?>"></textarea>
				<span class="bcsend-char-counter"><span id="bcsend-push-message-count">0</span>/400</span>
			</div>

			<!-- Deep Link URL -->
			<div class="bcsend-field-group" style="margin-bottom: 20px;">
				<label for="bcsend-push-link-url"><strong><?php esc_html_e( 'Deep Link URL', 'beacon-campaign-sender' ); ?></strong></label>
				<input type="url" id="bcsend-push-link-url" class="large-text" placeholder="https://" />
				<p class="description"><?php esc_html_e( 'Opens this URL when the notification is tapped.', 'beacon-campaign-sender' ); ?></p>
			</div>

			<hr style="margin: 25px 0;" />

			<!-- Send Timing -->
			<div class="bcsend-field-group" style="margin-bottom: 20px;">
				<label><strong><?php esc_html_e( 'Send Timing', 'beacon-campaign-sender' ); ?></strong></label>
				<div style="margin-top: 8px;">
					<label class="bcsend-radio-label" style="display: block; margin-bottom: 6px;">
						<input type="radio" name="bcsend-push-timing" value="now" checked /> <?php esc_html_e( 'Send Now', 'beacon-campaign-sender' ); ?>
					</label>
					<label class="bcsend-radio-label" style="display: block;">
						<input type="radio" name="bcsend-push-timing" value="schedule" /> <?php esc_html_e( 'Schedule', 'beacon-campaign-sender' ); ?>
					</label>
				</div>

				<div id="bcsend-push-schedule-fields" style="display: none; margin-top: 12px; padding: 15px; background: #f6f7f7; border-radius: 4px;">
					<div style="display: flex; gap: 10px; flex-wrap: wrap;">
						<input type="date" id="bcsend-push-schedule-date" style="width: 160px;" />
						<select id="bcsend-push-schedule-time" style="width: 120px;">
							<?php
							for ( $hour = 0; $hour < 24; $hour++ ) {
								for ( $minute = 0; $minute < 60; $minute += 15 ) {
									$val   = sprintf( '%02d:%02d', $hour, $minute );
									$label = gmdate( 'g:i A', strtotime( $val ) );
									printf( '<option value="%s">%s</option>', esc_attr( $val ), esc_html( $label ) );
								}
							}
							?>
						</select>
						<select id="bcsend-push-schedule-timezone" style="width: 220px;">
							<?php foreach ( $timezones as $tz ) : ?>
								<option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $tz, $wp_tz ); ?>>
									<?php echo esc_html( $tz ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<p class="description" style="margin-top: 8px;">
						<?php esc_html_e( 'Auto-expires 3 hours after the scheduled time if not sent.', 'beacon-campaign-sender' ); ?>
					</p>
				</div>
			</div>

			<hr style="margin: 25px 0;" />

			<!-- Recipients -->
			<div class="bcsend-field-group" style="margin-bottom: 20px;">
				<label><strong><?php esc_html_e( 'Recipients', 'beacon-campaign-sender' ); ?></strong></label>
				<div style="margin-top: 8px;">
					<label class="bcsend-radio-label" style="display: block; margin-bottom: 6px;">
						<input type="radio" name="bcsend-push-recipients" value="all_users" checked /> <?php esc_html_e( 'All App Users', 'beacon-campaign-sender' ); ?>
					</label>
					<label class="bcsend-radio-label" style="display: block; margin-bottom: 6px;">
						<input type="radio" name="bcsend-push-recipients" value="by_role" /> <?php esc_html_e( 'By Role', 'beacon-campaign-sender' ); ?>
					</label>
					<label class="bcsend-radio-label" style="display: block;">
						<input type="radio" name="bcsend-push-recipients" value="specific_users" /> <?php esc_html_e( 'Specific Users', 'beacon-campaign-sender' ); ?>
					</label>
				</div>

				<!-- By Role: checkboxes -->
				<div id="bcsend-push-role-fields" style="display: none; margin-top: 12px; padding: 15px; background: #f6f7f7; border-radius: 4px;">
					<?php foreach ( $roles as $slug => $label ) : ?>
						<label style="display: block; margin-bottom: 4px;">
							<input type="checkbox" class="bcsend-push-role-checkbox" value="<?php echo esc_attr( $slug ); ?>" />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>

				<!-- Specific Users: search -->
				<div id="bcsend-push-user-fields" style="display: none; margin-top: 12px; padding: 15px; background: #f6f7f7; border-radius: 4px;">
					<input type="text" id="bcsend-push-user-search" class="large-text" placeholder="<?php esc_attr_e( 'Search users by name or email...', 'beacon-campaign-sender' ); ?>" autocomplete="off" />
					<div id="bcsend-push-user-results" style="display: none; max-height: 200px; overflow-y: auto; border: 1px solid #c3c4c7; border-top: none; background: #fff;"></div>
					<div id="bcsend-push-selected-users" style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px;"></div>
				</div>
			</div>

			<hr style="margin: 25px 0;" />

			<!-- Submit -->
			<div class="bcsend-field-group">
				<button type="button" id="bcsend-push-submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Send Push Notification', 'beacon-campaign-sender' ); ?>
				</button>
				<a href="<?php echo esc_url( $list_url ); ?>" class="button button-hero" style="margin-left: 10px;">
					<?php esc_html_e( 'Cancel', 'beacon-campaign-sender' ); ?>
				</a>
			</div>

		</div>

	</div>

</div>
