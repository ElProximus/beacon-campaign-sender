<?php
/**
 * Campaign Queue view for Beacon Campaign Sender.
 *
 * Displays campaigns in list and calendar views with status filtering,
 * bulk actions, and pagination.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var array  $campaigns          Array of campaign objects for list view.
 * @var int    $total              Total campaign count.
 * @var int    $total_pages        Total number of pages.
 * @var string $status             Current status filter.
 * @var int    $paged              Current page number.
 * @var int    $cal_month          Calendar month number.
 * @var int    $cal_year           Calendar year.
 * @var array  $calendar_campaigns Campaigns indexed by day for calendar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'all'       => __( 'All', 'beacon-campaign-sender' ),
	'draft'     => __( 'Draft', 'beacon-campaign-sender' ),
	'approved'  => __( 'Approved', 'beacon-campaign-sender' ),
	'scheduled' => __( 'Scheduled', 'beacon-campaign-sender' ),
	'sending'   => __( 'Sending', 'beacon-campaign-sender' ),
	'sent'      => __( 'Sent', 'beacon-campaign-sender' ),
	'failed'    => __( 'Failed', 'beacon-campaign-sender' ),
	'cancelled' => __( 'Cancelled', 'beacon-campaign-sender' ),
);

$status_colors = array(
	'draft'     => '#9e9e9e',
	'approved'  => '#2196f3',
	'scheduled' => '#ff9800',
	'sending'   => '#9c27b0',
	'sent'      => '#4caf50',
	'failed'    => '#f44336',
	'cancelled' => '#607d8b',
);
?>
<div class="wrap bcsend-wrap bcsend-queue-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Scheduling', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'Campaign Queue', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Move between list and calendar views, keep scheduled work visible, and handle last-minute changes without digging through each campaign.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Create Campaign', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<!-- View Toggle + Filters -->
	<div class="bcsend-queue-toolbar">
		<div class="bcsend-view-toggle">
			<button type="button" class="button bcsend-view-btn bcsend-view-active" data-view="list" id="bcsend-view-list">
				<span class="dashicons dashicons-list-view"></span>
				<?php esc_html_e( 'List View', 'beacon-campaign-sender' ); ?>
			</button>
			<button type="button" class="button bcsend-view-btn" data-view="calendar" id="bcsend-view-calendar">
				<span class="dashicons dashicons-calendar-alt"></span>
				<?php esc_html_e( 'Calendar View', 'beacon-campaign-sender' ); ?>
			</button>
		</div>

		<div class="bcsend-queue-filters">
			<label for="bcsend-status-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'beacon-campaign-sender' ); ?></label>
			<select id="bcsend-status-filter">
				<?php foreach ( $status_labels as $status_val => $status_label ) : ?>
					<option value="<?php echo esc_attr( $status_val ); ?>" <?php selected( $status, $status_val ); ?>>
						<?php echo esc_html( $status_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="bcsend-apply-filter"><?php esc_html_e( 'Apply', 'beacon-campaign-sender' ); ?></button>
		</div>
	</div>

	<!-- List View -->
	<div id="bcsend-list-view">
		<form id="bcsend-bulk-form">
			<div class="bcsend-bulk-actions">
				<button type="button" class="button" id="bcsend-delete-selected" disabled>
					<?php esc_html_e( 'Delete Selected', 'beacon-campaign-sender' ); ?>
				</button>
				<span id="bcsend-bulk-status" class="bcsend-inline-status"></span>
			</div>

			<table class="widefat fixed striped bcsend-queue-table">
				<thead>
					<tr>
						<th class="check-column">
							<input type="checkbox" id="bcsend-select-all" />
						</th>
						<th><?php esc_html_e( 'Name', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Segment', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Scheduled (UTC)', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Open Rate', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Click Rate', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'beacon-campaign-sender' ); ?></th>
						</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $campaigns ) ) : ?>
						<?php foreach ( $campaigns as $campaign ) : ?>
							<?php
							$campaign_status = isset( $campaign->status ) ? $campaign->status : 'draft';
							$badge_color     = isset( $status_colors[ $campaign_status ] ) ? $status_colors[ $campaign_status ] : '#9e9e9e';
							$is_draft        = ( 'draft' === $campaign_status );
							?>
							<tr class="bcsend-queue-row" data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>">
								<td class="check-column">
									<?php if ( $is_draft ) : ?>
										<input type="checkbox"
												name="campaign_ids[]"
												value="<?php echo esc_attr( $campaign->id ); ?>"
												class="bcsend-campaign-checkbox" />
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer&campaign_id=' . (int) $campaign->id ) ); ?>">
										<?php echo esc_html( $campaign->name ); ?>
									</a>
								</td>
								<td><?php echo esc_html( mb_strimwidth( $campaign->subject, 0, 50, '...' ) ); ?></td>
								<td><?php echo esc_html( ! empty( $campaign->segment_name ) ? $campaign->segment_name : __( 'N/A', 'beacon-campaign-sender' ) ); ?></td>
								<td>
									<?php
									if ( ! empty( $campaign->scheduled_at ) ) {
										echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $campaign->scheduled_at ) ) );
									} else {
										echo esc_html__( 'Not scheduled', 'beacon-campaign-sender' );
									}
									?>
								</td>
								<td>
									<span class="bcsend-status-badge" style="background-color: <?php echo esc_attr( $badge_color ); ?>;">
										<?php echo esc_html( ucfirst( $campaign_status ) ); ?>
									</span>
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
								<td>
									<?php
									if ( 'sent' === $campaign_status && isset( $campaign->open_rate ) ) {
										echo esc_html( number_format( (float) $campaign->open_rate * 100, 1 ) . '%' );
									} else {
										echo esc_html__( '-', 'beacon-campaign-sender' );
									}
									?>
								</td>
								<td>
									<?php
									if ( 'sent' === $campaign_status && isset( $campaign->click_rate ) ) {
										echo esc_html( number_format( (float) $campaign->click_rate * 100, 1 ) . '%' );
									} else {
										echo esc_html__( '-', 'beacon-campaign-sender' );
									}
									?>
								</td>
								<td class="bcsend-queue-actions">
									<?php if ( in_array( $campaign_status, array( 'scheduled', 'approved', 'failed' ), true ) ) : ?>
										<button type="button"
												class="button button-small button-primary bcsend-send-now"
												data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>">
											<?php esc_html_e( 'Send Now', 'beacon-campaign-sender' ); ?>
										</button>
										<button type="button"
												class="button button-small bcsend-revert-to-draft"
												data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>">
											<?php esc_html_e( 'Revert to Draft', 'beacon-campaign-sender' ); ?>
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="9"><?php esc_html_e( 'No campaigns found.', 'beacon-campaign-sender' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</form>

		<!-- Pagination -->
		<?php if ( $total_pages > 1 ) : ?>
			<div class="bcsend-pagination">
				<?php if ( $paged > 1 ) : ?>
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'paged'  => $paged - 1,
								'status' => $status,
							),
							admin_url( 'admin.php?page=bcsend-queue' )
						)
					);
					?>
								"
						class="button">
						<?php esc_html_e( 'Previous', 'beacon-campaign-sender' ); ?>
					</a>
				<?php endif; ?>

				<span class="bcsend-page-indicator">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages */
						esc_html__( 'Page %1$d of %2$d', 'beacon-campaign-sender' ),
						(int) $paged,
						(int) $total_pages
					);
					?>
				</span>

				<?php if ( $paged < $total_pages ) : ?>
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'paged'  => $paged + 1,
								'status' => $status,
							),
							admin_url( 'admin.php?page=bcsend-queue' )
						)
					);
					?>
								"
						class="button">
						<?php esc_html_e( 'Next', 'beacon-campaign-sender' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Calendar View -->
	<div id="bcsend-calendar-view" style="display:none;">
		<?php
		$prev_month = $cal_month - 1;
		$prev_year  = $cal_year;
		if ( $prev_month < 1 ) {
			$prev_month = 12;
			--$prev_year;
		}

		$next_month = $cal_month + 1;
		$next_year  = $cal_year;
		if ( $next_month > 12 ) {
			$next_month = 1;
			++$next_year;
		}

		$month_name    = wp_date( 'F Y', mktime( 0, 0, 0, $cal_month, 1, $cal_year ) );
		$days_in_month = (int) gmdate( 't', mktime( 0, 0, 0, $cal_month, 1, $cal_year ) );
		$first_day_dow = (int) gmdate( 'w', mktime( 0, 0, 0, $cal_month, 1, $cal_year ) );
		?>

		<div class="bcsend-calendar-nav">
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'cal_month' => $prev_month,
						'cal_year'  => $prev_year,
					),
					admin_url( 'admin.php?page=bcsend-queue' )
				)
			);
			?>
			"
				class="button">
				<?php esc_html_e( 'Previous', 'beacon-campaign-sender' ); ?>
			</a>
			<h2 class="bcsend-calendar-title"><?php echo esc_html( $month_name ); ?></h2>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'cal_month' => $next_month,
						'cal_year'  => $next_year,
					),
					admin_url( 'admin.php?page=bcsend-queue' )
				)
			);
			?>
			"
				class="button">
				<?php esc_html_e( 'Next', 'beacon-campaign-sender' ); ?>
			</a>
		</div>

		<div class="bcsend-calendar-grid">
			<div class="bcsend-calendar-header"><?php esc_html_e( 'Sun', 'beacon-campaign-sender' ); ?></div>
			<div class="bcsend-calendar-header"><?php esc_html_e( 'Mon', 'beacon-campaign-sender' ); ?></div>
			<div class="bcsend-calendar-header"><?php esc_html_e( 'Tue', 'beacon-campaign-sender' ); ?></div>
			<div class="bcsend-calendar-header"><?php esc_html_e( 'Wed', 'beacon-campaign-sender' ); ?></div>
			<div class="bcsend-calendar-header"><?php esc_html_e( 'Thu', 'beacon-campaign-sender' ); ?></div>
			<div class="bcsend-calendar-header"><?php esc_html_e( 'Fri', 'beacon-campaign-sender' ); ?></div>
			<div class="bcsend-calendar-header"><?php esc_html_e( 'Sat', 'beacon-campaign-sender' ); ?></div>

			<?php
			// Empty cells before the first day of the month.
			for ( $i = 0; $i < $first_day_dow; $i++ ) {
				echo '<div class="bcsend-calendar-day bcsend-calendar-empty"></div>';
			}

			// Day cells.
			for ( $day = 1; $day <= $days_in_month; $day++ ) :
				$today_class = '';
				if ( (int) gmdate( 'j' ) === $day && (int) gmdate( 'n' ) === $cal_month && (int) gmdate( 'Y' ) === $cal_year ) {
					$today_class = ' bcsend-calendar-today';
				}
				?>
				<div class="bcsend-calendar-day<?php echo esc_attr( $today_class ); ?>">
					<span class="bcsend-calendar-day-number"><?php echo esc_html( $day ); ?></span>
					<?php if ( isset( $calendar_campaigns[ $day ] ) ) : ?>
						<div class="bcsend-calendar-events">
							<?php foreach ( $calendar_campaigns[ $day ] as $cal_campaign ) : ?>
								<?php $cal_badge_color = isset( $status_colors[ $cal_campaign->status ] ) ? $status_colors[ $cal_campaign->status ] : '#9e9e9e'; ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer&campaign_id=' . (int) $cal_campaign->id ) ); ?>"
									class="bcsend-calendar-event"
									style="border-left-color: <?php echo esc_attr( $cal_badge_color ); ?>;"
									title="<?php echo esc_attr( $cal_campaign->name ); ?>">
									<?php echo esc_html( mb_strimwidth( $cal_campaign->name, 0, 20, '...' ) ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endfor; ?>

			<?php
			// Trailing empty cells.
			$total_cells = $first_day_dow + $days_in_month;
			$remaining   = $total_cells % 7;
			if ( $remaining > 0 ) {
				for ( $i = $remaining; $i < 7; $i++ ) {
					echo '<div class="bcsend-calendar-day bcsend-calendar-empty"></div>';
				}
			}
			?>
		</div>
	</div>
</div>
