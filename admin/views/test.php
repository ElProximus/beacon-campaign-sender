<?php
/**
 * System Tests view for Beacon Campaign Sender.
 *
 * Displays environment checks and provides buttons for testing
 * database tables, API connections, push notifications,
 * email sending, and content generation.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var array  $env_report  Environment report from Bcsend_Environment.
 * @var string $admin_email WordPress admin email address.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap bcsend-wrap bcsend-test-wrap">
	<h1><?php esc_html_e( 'Beacon Campaign Sender — System Tests', 'beacon-campaign-sender' ); ?></h1>

	<!-- Environment Report -->
	<div class="bcsend-test-section bcsend-env-report">
		<h2><?php esc_html_e( 'Environment Report', 'beacon-campaign-sender' ); ?></h2>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Check', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Details', 'beacon-campaign-sender' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $env_report as $check_key => $check ) : ?>
					<tr>
						<td><?php echo esc_html( $check['label'] ); ?></td>
						<td>
							<?php if ( ! empty( $check['result'] ) ) : ?>
								<span class="bcsend-status-indicator bcsend-status-green">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'OK', 'beacon-campaign-sender' ); ?>
								</span>
							<?php else : ?>
								<span class="bcsend-status-indicator bcsend-status-red">
									<span class="dashicons dashicons-dismiss"></span>
									<?php esc_html_e( 'Issue', 'beacon-campaign-sender' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $check['label'] ) ? $check['label'] : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Test Cards -->
	<div class="bcsend-test-cards">

		<!-- 1. Verify Database Tables -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Verify Database Tables', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Check that all required Beacon Campaign Sender database tables exist and are accessible.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="verify_tables">
				<?php esc_html_e( 'Verify Database Tables', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-verify_tables"></div>
		</div>

		<!-- 2. Test Brevo API Connection -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Test Brevo API Connection', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Verify that the Brevo API key is valid and can communicate with the service.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="brevo_connection">
				<?php esc_html_e( 'Test Brevo API Connection', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-brevo_connection"></div>
		</div>

		<!-- 3. Test Anthropic API Connection -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Test Anthropic API Connection', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Verify that the Anthropic API key is valid and Claude is responding.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="anthropic_connection">
				<?php esc_html_e( 'Test Anthropic API Connection', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-anthropic_connection"></div>
		</div>

		<!-- 4. Test OpenAI API Connection -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Test OpenAI API Connection', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Verify that the configured OpenAI API key and model are working.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="openai_connection">
				<?php esc_html_e( 'Test OpenAI API Connection', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-openai_connection"></div>
		</div>

		<!-- 5. Test Firebase Connection -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Test Firebase Connection', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Verify that Firebase credentials are valid and the push service is reachable.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="firebase_connection">
				<?php esc_html_e( 'Test Firebase Connection', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-firebase_connection"></div>
		</div>

		<!-- 6. Send Test Email -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Send Test Email', 'beacon-campaign-sender' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %s: admin email address */
					esc_html__( 'Send a test email to %s using either Beacon Campaign Sender SMTP routing or the default WordPress mail path.', 'beacon-campaign-sender' ),
					'<strong>' . esc_html( $admin_email ) . '</strong>'
				);
				?>
			</p>
			<input type="hidden" id="bcsend-test-email-address" value="<?php echo esc_attr( $admin_email ); ?>" />
			<div class="bcsend-test-actions">
				<button type="button" class="button button-primary bcsend-run-test" data-test="send_test_email_smtp">
					<?php esc_html_e( 'Test Beacon Campaign Sender SMTP', 'beacon-campaign-sender' ); ?>
				</button>
				<button type="button" class="button bcsend-run-test" data-test="send_test_email_default">
					<?php esc_html_e( 'Test WordPress Default Mail', 'beacon-campaign-sender' ); ?>
				</button>
			</div>
			<div class="bcsend-test-result-area" id="bcsend-test-result-send_test_email_smtp"></div>
			<div class="bcsend-test-result-area" id="bcsend-test-result-send_test_email_default"></div>
		</div>

		<!-- 7. Send Test Push -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Send Test Push Notification', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Send a test push notification to a specific user.', 'beacon-campaign-sender' ); ?></p>
			<div class="bcsend-test-input-row">
				<label for="bcsend-test-push-user-id"><?php esc_html_e( 'User ID:', 'beacon-campaign-sender' ); ?></label>
				<input type="number"
						id="bcsend-test-push-user-id"
						class="small-text"
						min="1"
						value="<?php echo esc_attr( get_current_user_id() ); ?>" />
			</div>
			<button type="button" class="button button-primary bcsend-run-test" data-test="send_test_push">
				<?php esc_html_e( 'Send Test Push', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-send_test_push"></div>
		</div>

		<!-- 8. Generate Sample Content -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Generate Sample Content', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Generate a complete sample campaign to verify AI content generation. Displays all six content fields.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="generate_sample">
				<?php esc_html_e( 'Generate Sample Content', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area bcsend-test-result-wide" id="bcsend-test-result-generate_sample"></div>
		</div>

		<!-- 9. Test Content Library -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Test Content Library', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Verify Content Library features: snippets CRUD, post search, product search, media library access, and style tag preservation.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="content_library">
				<?php esc_html_e( 'Test Content Library', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-content_library"></div>
		</div>

		<!-- 10. Verify Email Log -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Verify Email Log', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Check that recent emails were captured in the email log with status, body, and Brevo message ID.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="verify_email_log">
				<?php esc_html_e( 'Verify Email Log', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-verify_email_log"></div>
		</div>

		<!-- 11. Test Resend -->
		<div class="bcsend-test-card">
			<h3><?php esc_html_e( 'Test Email Resend', 'beacon-campaign-sender' ); ?></h3>
			<p><?php esc_html_e( 'Resend the most recent sent email and verify a new log entry is created with resend provenance.', 'beacon-campaign-sender' ); ?></p>
			<button type="button" class="button button-primary bcsend-run-test" data-test="test_resend">
				<?php esc_html_e( 'Test Resend', 'beacon-campaign-sender' ); ?>
			</button>
			<div class="bcsend-test-result-area" id="bcsend-test-result-test_resend"></div>
		</div>
	</div>
</div>
