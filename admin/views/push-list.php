<?php
/**
 * Push notifications list view.
 *
 * @package Bcsend_Plugin
 * @since   2.2.0
 *
 * @var array  $pushes      Array of push notification objects.
 * @var int    $total        Total push notifications.
 * @var int    $total_pages  Total pages.
 * @var string $status       Current status filter.
 * @var int    $paged        Current page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$compose_url = add_query_arg(
	array(
		'page'   => 'bcsend-push',
		'action' => 'new',
	),
	admin_url( 'admin.php' )
);

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

$status_options = array( 'all', 'pending', 'scheduled', 'sending', 'sent', 'failed', 'expired', 'cancelled' );
?>
<div class="wrap bcsend-wrap bcsend-push-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Delivery', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'Push Notifications', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Send and schedule notifications to your app users, track delivery status, and review per-device outcomes.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( $compose_url ); ?>" class="button button-primary"><?php esc_html_e( 'New Push Notification', 'beacon-campaign-sender' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=beacon-campaign-sender' ) ); ?>" class="button"><?php esc_html_e( 'Back to Dashboard', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<div class="bcsend-push-toolbar">
		<form method="get" style="display: inline;">
			<input type="hidden" name="page" value="bcsend-push" />
			<select name="status">
				<?php foreach ( $status_options as $opt ) : ?>
					<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $status, $opt ); ?>>
						<?php echo esc_html( ucfirst( $opt ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'beacon-campaign-sender' ); ?></button>
		</form>
	</div>

	<?php if ( empty( $pushes ) ) : ?>
		<div class="bcsend-empty-state" style="text-align: center; padding: 40px 20px; color: #787c82;">
			<span class="dashicons dashicons-bell" style="font-size: 48px; width: 48px; height: 48px; margin-bottom: 10px;"></span>
			<h2><?php esc_html_e( 'No push notifications found.', 'beacon-campaign-sender' ); ?></h2>
			<p><a href="<?php echo esc_url( $compose_url ); ?>" class="button button-primary"><?php esc_html_e( 'Create Your First Push Notification', 'beacon-campaign-sender' ); ?></a></p>
		</div>
	<?php else : ?>

		<table class="widefat fixed striped bcsend-push-table">
			<thead>
				<tr>
					<th style="width: 20%;"><?php esc_html_e( 'Title', 'beacon-campaign-sender' ); ?></th>
					<th style="width: 25%;"><?php esc_html_e( 'Message', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Recipients', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Scheduled', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Delivered', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'beacon-campaign-sender' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $pushes as $push ) :
					$detail_url = add_query_arg(
						array(
							'page'    => 'bcsend-push',
							'action'  => 'detail',
							'push_id' => $push->id,
						),
						admin_url( 'admin.php' )
					);

					$color = isset( $status_colors[ $push->status ] ) ? $status_colors[ $push->status ] : '#787c82';

					// Recipient summary.
					$target_label = __( 'All App Users', 'beacon-campaign-sender' );
					if ( 'by_role' === $push->target_type && ! empty( $push->target_data ) ) {
						$roles_data   = json_decode( $push->target_data, true );
						$target_label = is_array( $roles_data ) ? implode( ', ', array_map( 'ucfirst', $roles_data ) ) : $push->target_type;
					} elseif ( 'specific_users' === $push->target_type && ! empty( $push->target_data ) ) {
						$users_data   = json_decode( $push->target_data, true );
						$count        = is_array( $users_data ) ? count( $users_data ) : 0;
						$target_label = sprintf( _n( '%d user', '%d users', $count, 'beacon-campaign-sender' ), $count );
					}

					// Delivered fraction.
					$delivered = '';
					if ( $push->total_tokens > 0 ) {
						$delivered = sprintf( '%d / %d', $push->sent_count, $push->total_tokens );
					}
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $detail_url ); ?>">
								<strong><?php echo esc_html( $push->title ?: '(' . __( 'No title', 'beacon-campaign-sender' ) . ')' ); ?></strong>
							</a>
						</td>
						<td><?php echo esc_html( mb_strimwidth( $push->message, 0, 80, '...' ) ); ?></td>
						<td><?php echo esc_html( $target_label ); ?></td>
						<td>
							<?php if ( $push->is_scheduled && $push->scheduled_at ) : ?>
								<?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $push->scheduled_at ) ) ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Immediate', 'beacon-campaign-sender' ); ?>
							<?php endif; ?>
						</td>
						<td>
							<span class="bcsend-status-badge" style="background: <?php echo esc_attr( $color ); ?>; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px;">
								<?php echo esc_html( ucfirst( $push->status ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $delivered ); ?></td>
						<td>
							<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'beacon-campaign-sender' ); ?></a>
							<?php if ( 'scheduled' === $push->status ) : ?>
								<button type="button" class="button button-small bcsend-push-cancel" data-id="<?php echo esc_attr( $push->id ); ?>">
									<?php esc_html_e( 'Cancel', 'beacon-campaign-sender' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( in_array( $push->status, array( 'sent', 'failed', 'expired', 'cancelled' ), true ) ) : ?>
								<button type="button" class="button button-small button-link-delete bcsend-push-delete" data-id="<?php echo esc_attr( $push->id ); ?>">
									<?php esc_html_e( 'Delete', 'beacon-campaign-sender' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="bcsend-pagination" style="margin-top: 15px;">
				<?php
				$base_url = add_query_arg(
					array(
						'page'   => 'bcsend-push',
						'status' => $status,
					),
					admin_url( 'admin.php' )
				);
				for ( $i = 1; $i <= $total_pages; $i++ ) :
					$page_url = add_query_arg( 'paged', $i, $base_url );
					if ( $i === $paged ) :
						?>
						<span class="button button-primary button-small"><?php echo esc_html( $i ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $page_url ); ?>" class="button button-small"><?php echo esc_html( $i ); ?></a>
						<?php
					endif;
				endfor;
				?>
			</div>
		<?php endif; ?>

	<?php endif; ?>

</div>
