<?php
/**
 * Email log admin view.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Defensive defaults so the view works regardless of which controller path
// included it (list vs. detail). The caller sets the relevant subset.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
$view_mode   = isset( $view_mode ) ? $view_mode : 'list';
$email       = isset( $email ) ? $email : null;
$emails      = isset( $emails ) ? $emails : array();
$status      = isset( $status ) ? $status : 'all';
$search      = isset( $search ) ? $search : '';
$total       = isset( $total ) ? (int) $total : 0;
$total_pages = isset( $total_pages ) ? (int) $total_pages : 1;
$paged       = isset( $paged ) ? (int) $paged : 1;
// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

$can_manage         = current_user_can( 'manage_bcsend' );
$base_url           = admin_url( 'admin.php?page=bcsend-email-log' );
$full_email_logging = Bcsend_Email_Log::is_full_logging_enabled();

if ( 'detail' === $view_mode ) :
	?>
	<div class="wrap bcsend-wrap bcsend-email-log-wrap">
		<h1><?php esc_html_e( 'Beacon Campaign Sender Email Log', 'beacon-campaign-sender' ); ?></h1>
		<p><a href="<?php echo esc_url( $base_url ); ?>">&larr; <?php esc_html_e( 'Back to Email Log', 'beacon-campaign-sender' ); ?></a></p>

		<?php if ( ! $email ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Email log entry not found.', 'beacon-campaign-sender' ); ?></p></div>
		<?php else : ?>
			<?php
			$cc_list          = ! empty( $email->cc ) ? json_decode( $email->cc, true ) : array();
			$bcc_list         = ! empty( $email->bcc ) ? json_decode( $email->bcc, true ) : array();
			$attachment_paths = ! empty( $email->attachments ) ? json_decode( $email->attachments, true ) : array();
			$original_url     = ! empty( $email->resent_from_log_id )
				? add_query_arg(
					array(
						'page'     => 'bcsend-email-log',
						'action'   => 'detail',
						'email_id' => (int) $email->resent_from_log_id,
					),
					admin_url( 'admin.php' )
				)
				: '';
			?>
			<div class="bcsend-card">
				<div class="bcsend-card-header"><?php esc_html_e( 'Email Summary', 'beacon-campaign-sender' ); ?></div>
				<div class="bcsend-card-body">
					<table class="form-table" role="presentation">
						<tbody>
							<tr><th scope="row"><?php esc_html_e( 'Subject', 'beacon-campaign-sender' ); ?></th><td><?php echo esc_html( $email->subject ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'From', 'beacon-campaign-sender' ); ?></th><td><?php echo esc_html( trim( $email->from_name . ' <' . $email->from_email . '>' ) ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'To', 'beacon-campaign-sender' ); ?></th><td><?php echo esc_html( $email->to_email ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'CC', 'beacon-campaign-sender' ); ?></th><td><?php echo esc_html( $full_email_logging && ! empty( $cc_list ) ? wp_json_encode( $cc_list ) : '—' ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'BCC', 'beacon-campaign-sender' ); ?></th><td><?php echo esc_html( $full_email_logging && ! empty( $bcc_list ) ? wp_json_encode( $bcc_list ) : '—' ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th><td><span class="bcsend-badge <?php echo esc_attr( 'fallback_attempted' === $email->status ? 'bcsend-badge-fallback' : ( 'failed' === $email->status ? 'bcsend-badge-failed' : 'bcsend-badge-sent' ) ); ?>"><?php echo esc_html( $email->status ); ?></span></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Date', 'beacon-campaign-sender' ); ?></th><td><?php echo esc_html( $email->created_at ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Brevo Message ID', 'beacon-campaign-sender' ); ?></th><td><?php echo esc_html( ! empty( $email->brevo_message_id ) ? $email->brevo_message_id : '—' ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Error', 'beacon-campaign-sender' ); ?></th><td><?php echo esc_html( ! empty( $email->error_message ) ? $email->error_message : '—' ); ?></td></tr>
							<?php if ( ! empty( $original_url ) ) : ?>
								<tr><th scope="row"><?php esc_html_e( 'Resent From', 'beacon-campaign-sender' ); ?></th><td><a href="<?php echo esc_url( $original_url ); ?>"><?php echo esc_html( '#' . (int) $email->resent_from_log_id ); ?></a></td></tr>
							<?php endif; ?>
						</tbody>
					</table>

					<?php if ( $full_email_logging && ! empty( $attachment_paths ) && is_array( $attachment_paths ) ) : ?>
						<h2><?php esc_html_e( 'Attachments', 'beacon-campaign-sender' ); ?></h2>
						<ul>
							<?php foreach ( $attachment_paths as $attachment_path ) : ?>
								<li>
									<?php echo esc_html( $attachment_path ); ?>
									<?php if ( ! is_readable( $attachment_path ) ) : ?>
										<strong><?php esc_html_e( ' (missing file)', 'beacon-campaign-sender' ); ?></strong>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<h2><?php esc_html_e( 'Email Preview', 'beacon-campaign-sender' ); ?></h2>
					<?php if ( Bcsend_Email_Log::supports_resend( $email ) ) : ?>
						<?php if ( ! empty( $email->is_html ) ) : ?>
							<iframe class="bcsend-email-preview" sandbox="" srcdoc="<?php echo esc_attr( $email->body ); ?>"></iframe>
						<?php else : ?>
							<pre class="bcsend-email-plain"><?php echo esc_html( $email->body ); ?></pre>
						<?php endif; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'Email content was not retained because Email Log Privacy Mode is set to minimal.', 'beacon-campaign-sender' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="bcsend-card-footer">
					<?php if ( $can_manage && Bcsend_Email_Log::supports_resend( $email ) ) : ?>
						<button type="button" class="button button-primary bcsend-email-resend" data-id="<?php echo esc_attr( $email->id ); ?>"><?php esc_html_e( 'Resend This Email', 'beacon-campaign-sender' ); ?></button>
					<?php elseif ( $can_manage ) : ?>
						<button type="button" class="button" disabled><?php esc_html_e( 'Resend unavailable in minimal mode', 'beacon-campaign-sender' ); ?></button>
					<?php else : ?>
						<button type="button" class="button" disabled><?php esc_html_e( 'Resend Requires Manager Access', 'beacon-campaign-sender' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
<?php else : ?>
	<?php
	$status_options = array(
		'all'                => __( 'All Statuses', 'beacon-campaign-sender' ),
		'sent'               => __( 'Sent', 'beacon-campaign-sender' ),
		'failed'             => __( 'Failed', 'beacon-campaign-sender' ),
		'fallback_attempted' => __( 'Fallback Attempted', 'beacon-campaign-sender' ),
	);
	?>
	<div class="wrap bcsend-wrap bcsend-email-log-wrap">
		<h1><?php esc_html_e( 'Beacon Campaign Sender Email Log', 'beacon-campaign-sender' ); ?></h1>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="bcsend-email-log-filters">
			<input type="hidden" name="page" value="bcsend-email-log" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search recipient or subject', 'beacon-campaign-sender' ); ?>" />
			<select name="status">
				<?php foreach ( $status_options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'beacon-campaign-sender' ); ?></button>
			<span class="bcsend-log-count">
				<?php
				printf(
					esc_html__( '%s entries', 'beacon-campaign-sender' ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</span>
		</form>

		<table class="widefat fixed striped bcsend-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'To', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'beacon-campaign-sender' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $emails ) ) : ?>
					<?php foreach ( $emails as $row ) : ?>
						<?php
						$detail_url   = add_query_arg(
							array(
								'page'     => 'bcsend-email-log',
								'action'   => 'detail',
								'email_id' => (int) $row->id,
							),
							admin_url( 'admin.php' )
						);
						$status_class = 'fallback_attempted' === $row->status ? 'bcsend-badge-fallback' : ( 'failed' === $row->status ? 'bcsend-badge-failed' : 'bcsend-badge-sent' );
						?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><?php echo esc_html( mb_strimwidth( (string) $row->to_email, 0, 50, '...' ) ); ?></td>
							<td><?php echo esc_html( mb_strimwidth( (string) $row->subject, 0, 60, '...' ) ); ?></td>
							<td><span class="bcsend-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $row->status ); ?></span></td>
							<td class="bcsend-table-actions">
								<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'beacon-campaign-sender' ); ?></a>
								<?php if ( $can_manage && Bcsend_Email_Log::supports_resend( $row ) ) : ?>
									<button type="button" class="button button-small bcsend-email-resend" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Resend', 'beacon-campaign-sender' ); ?></button>
								<?php elseif ( $can_manage ) : ?>
									<button type="button" class="button button-small" disabled><?php esc_html_e( 'Resend unavailable', 'beacon-campaign-sender' ); ?></button>
								<?php else : ?>
									<button type="button" class="button button-small" disabled><?php esc_html_e( 'Resend', 'beacon-campaign-sender' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No email log entries found.', 'beacon-campaign-sender' ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="bcsend-pagination">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'   => 'bcsend-email-log',
								'paged'  => $paged - 1,
								'status' => $status,
								's'      => $search,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
											"><?php esc_html_e( 'Previous', 'beacon-campaign-sender' ); ?></a>
				<?php endif; ?>
				<span class="bcsend-page-indicator">
					<?php
					printf(
						esc_html__( 'Page %1$d of %2$d', 'beacon-campaign-sender' ),
						(int) $paged,
						(int) $total_pages
					);
					?>
				</span>
				<?php if ( $paged < $total_pages ) : ?>
					<a class="button" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'   => 'bcsend-email-log',
								'paged'  => $paged + 1,
								'status' => $status,
								's'      => $search,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
											"><?php esc_html_e( 'Next', 'beacon-campaign-sender' ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>
