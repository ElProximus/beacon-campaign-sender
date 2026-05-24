<?php
/**
 * Logs view for Beacon Campaign Sender.
 *
 * Displays a filterable, paginated log table with expandable rows
 * to show full payload details.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var array  $logs        Array of log row objects.
 * @var int    $total       Total log entry count.
 * @var int    $total_pages Total number of pages.
 * @var string $type        Current type filter.
 * @var string $status      Current status filter.
 * @var int    $paged       Current page number.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$type_labels = array(
	'all'        => __( 'All Types', 'beacon-campaign-sender' ),
	'api_call'   => __( 'API Call', 'beacon-campaign-sender' ),
	'push'       => __( 'Push', 'beacon-campaign-sender' ),
	'generation' => __( 'Generation', 'beacon-campaign-sender' ),
	'error'      => __( 'Error', 'beacon-campaign-sender' ),
);

$status_labels = array(
	'all'     => __( 'All Statuses', 'beacon-campaign-sender' ),
	'success' => __( 'Success', 'beacon-campaign-sender' ),
	'error'   => __( 'Error', 'beacon-campaign-sender' ),
);

$type_colors = array(
	'api_call'   => '#2196f3',
	'push'       => '#9c27b0',
	'generation' => '#ff9800',
	'error'      => '#f44336',
);

$status_colors = array(
	'success' => '#4caf50',
	'error'   => '#f44336',
);
?>
<div class="wrap bcsend-wrap bcsend-logs-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Diagnostics', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'Beacon Campaign Sender Logs', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Filter operational activity quickly, inspect payloads when something looks off, and keep the log stream readable instead of overwhelming.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=beacon-campaign-sender' ) ); ?>" class="button"><?php esc_html_e( 'Back to Dashboard', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<!-- Filter Bar -->
	<div class="bcsend-logs-filter-bar">
		<label for="bcsend-log-type-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'beacon-campaign-sender' ); ?></label>
		<select id="bcsend-log-type-filter">
			<?php foreach ( $type_labels as $type_val => $type_label ) : ?>
				<option value="<?php echo esc_attr( $type_val ); ?>" <?php selected( $type, $type_val ); ?>>
					<?php echo esc_html( $type_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<label for="bcsend-log-status-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'beacon-campaign-sender' ); ?></label>
		<select id="bcsend-log-status-filter">
			<?php foreach ( $status_labels as $status_val => $status_label ) : ?>
				<option value="<?php echo esc_attr( $status_val ); ?>" <?php selected( $status, $status_val ); ?>>
					<?php echo esc_html( $status_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="button" class="button" id="bcsend-apply-log-filter">
			<?php esc_html_e( 'Apply', 'beacon-campaign-sender' ); ?>
		</button>

		<span class="bcsend-log-count">
			<?php
			printf(
				/* translators: %s: total log entries */
				esc_html__( '%s entries', 'beacon-campaign-sender' ),
				esc_html( number_format_i18n( $total ) )
			);
			?>
		</span>
	</div>

	<!-- Logs Table -->
	<table class="widefat fixed striped bcsend-logs-table">
		<thead>
			<tr>
				<th class="bcsend-col-timestamp"><?php esc_html_e( 'Timestamp', 'beacon-campaign-sender' ); ?></th>
				<th class="bcsend-col-type"><?php esc_html_e( 'Type', 'beacon-campaign-sender' ); ?></th>
				<th class="bcsend-col-status"><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th>
				<th class="bcsend-col-message"><?php esc_html_e( 'Message', 'beacon-campaign-sender' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $logs ) ) : ?>
				<?php foreach ( $logs as $index => $log ) : ?>
					<?php
					$log_type      = isset( $log->type ) ? $log->type : '';
					$log_status    = isset( $log->status ) ? $log->status : '';
					$log_message   = isset( $log->message ) ? $log->message : '';
					$log_payload   = isset( $log->payload ) ? $log->payload : '';
					$type_color    = isset( $type_colors[ $log_type ] ) ? $type_colors[ $log_type ] : '#9e9e9e';
					$status_color  = isset( $status_colors[ $log_status ] ) ? $status_colors[ $log_status ] : '#9e9e9e';
					$truncated_msg = mb_strimwidth( $log_message, 0, 120, '...' );
					$row_id        = 'bcsend-log-row-' . (int) $log->id;
					?>
					<tr class="bcsend-log-row" data-log-id="<?php echo esc_attr( $log->id ); ?>">
						<td class="bcsend-col-timestamp">
							<?php
							if ( ! empty( $log->created_at ) ) {
								echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $log->created_at ) ) );
							}
							?>
						</td>
						<td class="bcsend-col-type">
							<span class="bcsend-status-badge" style="background-color: <?php echo esc_attr( $type_color ); ?>;">
								<?php echo esc_html( isset( $type_labels[ $log_type ] ) ? $type_labels[ $log_type ] : $log_type ); ?>
							</span>
						</td>
						<td class="bcsend-col-status">
							<span class="bcsend-status-badge" style="background-color: <?php echo esc_attr( $status_color ); ?>;">
								<?php echo esc_html( ucfirst( $log_status ) ); ?>
							</span>
						</td>
						<td class="bcsend-col-message">
							<span class="bcsend-log-message-text"><?php echo esc_html( $truncated_msg ); ?></span>
						</td>
					</tr>
					<tr class="bcsend-log-detail-row" id="<?php echo esc_attr( $row_id ); ?>" style="display:none;">
						<td colspan="4">
							<div class="bcsend-log-detail">
								<strong><?php esc_html_e( 'Full Message:', 'beacon-campaign-sender' ); ?></strong>
								<p><?php echo esc_html( $log_message ); ?></p>
								<?php if ( ! empty( $log_payload ) ) : ?>
									<strong><?php esc_html_e( 'Payload:', 'beacon-campaign-sender' ); ?></strong>
									<pre class="bcsend-log-payload"><?php echo esc_html( $log_payload ); ?></pre>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="4"><?php esc_html_e( 'No log entries found.', 'beacon-campaign-sender' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Pagination -->
	<?php if ( $total_pages > 1 ) : ?>
		<div class="bcsend-pagination">
			<?php if ( $paged > 1 ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'paged'      => $paged - 1,
							'log_type'   => $type,
							'log_status' => $status,
						),
						admin_url( 'admin.php?page=bcsend-logs' )
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
							'paged'      => $paged + 1,
							'log_type'   => $type,
							'log_status' => $status,
						),
						admin_url( 'admin.php?page=bcsend-logs' )
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

	<!-- Clear Old Logs -->
	<div class="bcsend-logs-footer">
		<button type="button" class="button" id="bcsend-clear-old-logs">
			<?php esc_html_e( 'Clear Old Logs', 'beacon-campaign-sender' ); ?>
		</button>
		<span id="bcsend-clear-logs-status" class="bcsend-inline-status"></span>
	</div>
</div>
