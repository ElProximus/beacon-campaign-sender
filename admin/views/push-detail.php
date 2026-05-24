<?php
/**
 * Push notification detail view with per-device history.
 *
 * @package Bcsend_Plugin
 * @since   2.2.0
 *
 * @var object $push    Push notification object.
 * @var array  $history {items, total} Per-device delivery history.
 * @var int    $paged   Current history page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url = add_query_arg( array( 'page' => 'bcsend-push' ), admin_url( 'admin.php' ) );

$status_colors = array(
	'pending'    => '#2271b1',
	'scheduled'  => '#dba617',
	'processing' => '#d63638',
	'sending'    => '#d63638',
	'sent'       => '#00a32a',
	'failed'     => '#d63638',
	'expired'    => '#787c82',
	'cancelled'  => '#787c82',
);

$color = isset( $status_colors[ $push->status ] ) ? $status_colors[ $push->status ] : '#787c82';

$sender = $push->sent_by ? get_userdata( $push->sent_by ) : null;
?>
<div class="wrap bcsend-wrap bcsend-push-detail-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Push Notification', 'beacon-campaign-sender' ); ?></span>
			<h1><?php echo esc_html( $push->title ?: __( '(No title)', 'beacon-campaign-sender' ) ); ?></h1>
			<p class="bcsend-page-lede"><?php echo esc_html( mb_strimwidth( (string) $push->message, 0, 200, '...' ) ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Back to Push Notifications', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<!-- Summary Card -->
	<div class="bcsend-push-summary" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin: 20px 0; border-radius: 4px;">
		<div style="display: flex; justify-content: space-between; align-items: flex-start;">
			<div>
				<h2 style="margin: 0 0 5px;">
					<?php echo esc_html( $push->title ?: '(' . __( 'No title', 'beacon-campaign-sender' ) . ')' ); ?>
				</h2>
				<p style="margin: 0 0 10px; color: #50575e; font-size: 14px;"><?php echo esc_html( $push->message ); ?></p>
				<?php if ( ! empty( $push->link_url ) ) : ?>
					<p style="margin: 0 0 10px;">
						<strong><?php esc_html_e( 'Deep Link:', 'beacon-campaign-sender' ); ?></strong>
						<a href="<?php echo esc_url( $push->link_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $push->link_url ); ?></a>
					</p>
				<?php endif; ?>
			</div>
			<span class="bcsend-status-badge" style="background: <?php echo esc_attr( $color ); ?>; color: #fff; padding: 4px 12px; border-radius: 3px; font-size: 13px; white-space: nowrap;">
				<?php echo esc_html( ucfirst( $push->status ) ); ?>
			</span>
		</div>

		<div style="display: flex; gap: 30px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f1; font-size: 13px; color: #646970;">
			<div>
				<strong><?php esc_html_e( 'Sent By', 'beacon-campaign-sender' ); ?>:</strong>
				<?php echo $sender ? esc_html( $sender->display_name ) : esc_html__( 'Unknown', 'beacon-campaign-sender' ); ?>
			</div>
			<div>
				<strong><?php esc_html_e( 'Created', 'beacon-campaign-sender' ); ?>:</strong>
				<?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $push->created_at ) ) ); ?>
			</div>
			<?php if ( $push->is_scheduled && $push->scheduled_at ) : ?>
				<div>
					<strong><?php esc_html_e( 'Scheduled', 'beacon-campaign-sender' ); ?>:</strong>
					<?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $push->scheduled_at ) ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $push->sent_at ) : ?>
				<div>
					<strong><?php esc_html_e( 'Sent At', 'beacon-campaign-sender' ); ?>:</strong>
					<?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $push->sent_at ) ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $push->total_tokens > 0 ) : ?>
				<div>
					<strong><?php esc_html_e( 'Delivered', 'beacon-campaign-sender' ); ?>:</strong>
					<?php
					printf(
						'%d / %d (%s%%)',
						(int) $push->sent_count,
						(int) $push->total_tokens,
						number_format( ( $push->sent_count / $push->total_tokens ) * 100, 1 )
					);
					?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- Per-Device Delivery History -->
	<h2><?php esc_html_e( 'Delivery History', 'beacon-campaign-sender' ); ?></h2>

	<?php if ( empty( $history['items'] ) ) : ?>
		<p class="description"><?php esc_html_e( 'No delivery records yet.', 'beacon-campaign-sender' ); ?></p>
	<?php else : ?>

		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'User', 'beacon-campaign-sender' ); ?></th>
					<th style="width: 80px;"><?php esc_html_e( 'Platform', 'beacon-campaign-sender' ); ?></th>
					<th style="width: 80px;"><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Error', 'beacon-campaign-sender' ); ?></th>
					<th style="width: 160px;"><?php esc_html_e( 'Time', 'beacon-campaign-sender' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history['items'] as $record ) : ?>
					<tr>
						<td>
							<?php if ( ! empty( $record->display_name ) ) : ?>
								<?php echo esc_html( $record->display_name ); ?>
								<br><small style="color: #646970;"><?php echo esc_html( $record->user_email ); ?></small>
							<?php else : ?>
								<?php echo esc_html( sprintf( __( 'User #%d', 'beacon-campaign-sender' ), $record->user_id ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( strtoupper( $record->platform ?: '?' ) ); ?></td>
						<td>
							<?php if ( 1 === (int) $record->status ) : ?>
								<span style="color: #00a32a;" title="<?php esc_attr_e( 'Delivered', 'beacon-campaign-sender' ); ?>">&#10003;</span>
							<?php else : ?>
								<span style="color: #d63638;" title="<?php esc_attr_e( 'Failed', 'beacon-campaign-sender' ); ?>">&#10007;</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $record->error_message ) ) : ?>
								<code style="font-size: 12px;"><?php echo esc_html( $record->error_message ); ?></code>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wp_date( 'M j, g:i A', strtotime( $record->created_at ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $history['total'] > 50 ) : ?>
			<p class="description" style="margin-top: 10px;">
				<?php
				printf(
					esc_html__( 'Showing %1$d of %2$d delivery records.', 'beacon-campaign-sender' ),
					(int) count( $history['items'] ),
					(int) $history['total']
				);
				?>
			</p>
		<?php endif; ?>

	<?php endif; ?>

</div>
