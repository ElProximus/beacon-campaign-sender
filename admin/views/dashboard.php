<?php
/**
 * Dashboard view for Beacon Campaign Sender.
 *
 * Displays environment status, next scheduled campaign, recent campaigns,
 * segment overview, and quick action buttons.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var object|null $next_campaign    Next scheduled campaign or null.
 * @var array       $recent_campaigns Array of recent sent campaign objects.
 * @var array       $segment_stats    Segment statistics (count, last_sync).
 * @var array       $env_report       Environment report from Bcsend_Environment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap bcsend-wrap bcsend-dashboard-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Control Center', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'Beacon Campaign Sender Dashboard', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Track delivery readiness, keep an eye on scheduled work, and jump into the next campaign without hunting through the menu.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer' ) ); ?>" class="button button-primary"><?php esc_html_e( 'New Campaign', 'beacon-campaign-sender' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-queue' ) ); ?>" class="button"><?php esc_html_e( 'Open Queue', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<!-- Environment Status Bar -->
	<div class="bcsend-env-status-bar">
		<?php foreach ( $env_report as $check_key => $check ) : ?>
			<span class="bcsend-env-check <?php echo ! empty( $check['result'] ) ? 'bcsend-env-ok' : 'bcsend-env-warn'; ?>">
				<span class="dashicons <?php echo ! empty( $check['result'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
				<?php echo esc_html( $check['label'] ); ?>
			</span>
		<?php endforeach; ?>
	</div>

	<div class="bcsend-dashboard-grid">

		<!-- Next Scheduled Campaign -->
		<div class="bcsend-dashboard-card bcsend-card-next-campaign">
			<h2><?php esc_html_e( 'Next Scheduled Campaign', 'beacon-campaign-sender' ); ?></h2>
			<?php if ( $next_campaign ) : ?>
				<div class="bcsend-next-campaign-details">
					<table class="bcsend-detail-table">
						<tr>
							<th><?php esc_html_e( 'Name', 'beacon-campaign-sender' ); ?></th>
							<td><?php echo esc_html( $next_campaign->name ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Subject', 'beacon-campaign-sender' ); ?></th>
							<td><?php echo esc_html( $next_campaign->subject ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Segment', 'beacon-campaign-sender' ); ?></th>
							<td><?php echo esc_html( ! empty( $next_campaign->segment_name ) ? $next_campaign->segment_name : __( 'N/A', 'beacon-campaign-sender' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Scheduled', 'beacon-campaign-sender' ); ?></th>
							<td>
								<?php
								$scheduled_time = strtotime( $next_campaign->scheduled_at );
								echo esc_html( wp_date( 'M j, Y g:i A', $scheduled_time ) );
								?>
							</td>
						</tr>
					</table>
					<div class="bcsend-countdown" data-target="<?php echo esc_attr( gmdate( 'c', $scheduled_time ) ); ?>">
						<span class="bcsend-countdown-label"><?php esc_html_e( 'Sends in:', 'beacon-campaign-sender' ); ?></span>
						<span class="bcsend-countdown-value" id="bcsend-countdown-timer"><?php esc_html_e( 'Calculating...', 'beacon-campaign-sender' ); ?></span>
					</div>
				</div>
			<?php else : ?>
				<p class="bcsend-empty-state"><?php esc_html_e( 'No campaigns currently scheduled.', 'beacon-campaign-sender' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Segment Overview -->
		<div class="bcsend-dashboard-card bcsend-card-segments">
			<h2><?php esc_html_e( 'Smart Segments', 'beacon-campaign-sender' ); ?></h2>
			<div class="bcsend-stat-number"><?php echo esc_html( $segment_stats['count'] ); ?></div>
			<p class="bcsend-stat-label"><?php esc_html_e( 'Active Segments', 'beacon-campaign-sender' ); ?></p>
			<?php if ( ! empty( $segment_stats['last_sync'] ) ) : ?>
				<p class="bcsend-stat-meta">
					<?php
					printf(
						/* translators: %s: formatted date/time of last sync */
						esc_html__( 'Last synced: %s', 'beacon-campaign-sender' ),
						esc_html( wp_date( 'M j, Y g:i A', strtotime( $segment_stats['last_sync'] ) ) )
					);
					?>
				</p>
			<?php else : ?>
				<p class="bcsend-stat-meta"><?php esc_html_e( 'Never synced', 'beacon-campaign-sender' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Quick Actions -->
		<div class="bcsend-dashboard-card bcsend-card-actions">
			<h2><?php esc_html_e( 'Quick Actions', 'beacon-campaign-sender' ); ?></h2>
			<div class="bcsend-quick-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer' ) ); ?>" class="button button-primary button-hero">
					<span class="dashicons dashicons-edit"></span>
					<?php esc_html_e( 'New Campaign', 'beacon-campaign-sender' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-queue' ) ); ?>" class="button button-secondary button-hero">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'View Queue', 'beacon-campaign-sender' ); ?>
				</a>
			</div>
		</div>
	</div>

	<!-- Recent Campaigns Table -->
	<div class="bcsend-dashboard-card bcsend-card-recent-campaigns">
		<h2><?php esc_html_e( 'Recent Campaigns', 'beacon-campaign-sender' ); ?></h2>
		<?php if ( ! empty( $recent_campaigns ) ) : ?>
			<table class="widefat fixed striped bcsend-campaigns-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Sent', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Open Rate', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Click Rate', 'beacon-campaign-sender' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent_campaigns as $campaign ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer&campaign_id=' . (int) $campaign->id ) ); ?>">
									<?php echo esc_html( $campaign->name ); ?>
								</a>
								<?php
								$channel_statuses = array(
									'Email'  => isset( $campaign->email_status ) ? $campaign->email_status : '',
									'Push'   => isset( $campaign->push_status ) ? $campaign->push_status : '',
									'Social' => isset( $campaign->social_status ) ? $campaign->social_status : '',
								);
								?>
								<div class="bcsend-channel-status">
									<?php foreach ( $channel_statuses as $channel_label => $channel_status ) : ?>
										<?php if ( empty( $channel_status ) || 'skipped' === $channel_status ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<?php
										$color_class = in_array( $channel_status, array( 'sent', 'published' ), true )
											? 'bcsend-status-teal'
											: ( in_array( $channel_status, array( 'failed', 'partial' ), true ) ? 'bcsend-status-red' : 'bcsend-status-amber' );
										?>
										<span class="bcsend-channel-badge <?php echo esc_attr( $color_class ); ?>">
											<?php echo esc_html( $channel_label . ': ' . ucfirst( $channel_status ) ); ?>
										</span>
									<?php endforeach; ?>
								</div>
							</td>
							<td><?php echo esc_html( $campaign->subject ); ?></td>
							<td>
								<?php
								if ( ! empty( $campaign->scheduled_at ) ) {
									echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $campaign->scheduled_at ) ) );
								} else {
									echo esc_html__( 'N/A', 'beacon-campaign-sender' );
								}
								?>
							</td>
							<td>
								<?php
								if ( null !== $campaign->open_rate ) {
									echo esc_html( number_format( $campaign->open_rate * 100, 1 ) . '%' );
								} else {
									echo esc_html__( 'N/A', 'beacon-campaign-sender' );
								}
								?>
							</td>
							<td>
								<?php
								if ( null !== $campaign->click_rate ) {
									echo esc_html( number_format( $campaign->click_rate * 100, 1 ) . '%' );
								} else {
									echo esc_html__( 'N/A', 'beacon-campaign-sender' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="bcsend-empty-state"><?php esc_html_e( 'No campaigns sent yet.', 'beacon-campaign-sender' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- System Tests Link -->
	<p class="bcsend-dashboard-footer">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-tests' ) ); ?>" class="bcsend-link-subtle">
			<?php esc_html_e( 'Run System Tests', 'beacon-campaign-sender' ); ?>
		</a>
	</p>
</div>
