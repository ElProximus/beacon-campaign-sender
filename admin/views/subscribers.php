<?php
/**
 * Subscribers admin view.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 *
 * @var array $rows
 * @var int   $total
 * @var int   $total_pages
 * @var array $sources
 * @var string $status_value
 * @var string $source_value
 * @var int $paged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap bcsend-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Audience Ops', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'Subscribers', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Review subscriber ingest attempts, watch pending retries, and inspect the exact Brevo payload snapshot stored for each row.', 'beacon-campaign-sender' ); ?></p>
		</div>
	</div>

	<?php settings_errors( 'bcsend_settings' ); ?>

	<form method="get" class="bcsend-logs-filter-bar">
		<input type="hidden" name="page" value="bcsend-subscribers" />
		<select name="subscriber_status">
			<option value="all" <?php selected( $status_value, 'all' ); ?>><?php esc_html_e( 'All Statuses', 'beacon-campaign-sender' ); ?></option>
			<option value="pending" <?php selected( $status_value, 'pending' ); ?>><?php esc_html_e( 'Pending', 'beacon-campaign-sender' ); ?></option>
			<option value="pending_retry" <?php selected( $status_value, 'pending_retry' ); ?>><?php esc_html_e( 'Pending Retry', 'beacon-campaign-sender' ); ?></option>
			<option value="confirmed" <?php selected( $status_value, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'beacon-campaign-sender' ); ?></option>
			<option value="failed" <?php selected( $status_value, 'failed' ); ?>><?php esc_html_e( 'Failed', 'beacon-campaign-sender' ); ?></option>
		</select>
		<select name="subscriber_source">
			<option value="all" <?php selected( $source_value, 'all' ); ?>><?php esc_html_e( 'All Sources', 'beacon-campaign-sender' ); ?></option>
			<?php foreach ( $sources as $source ) : ?>
				<option value="<?php echo esc_attr( $source ); ?>" <?php selected( $source_value, $source ); ?>><?php echo esc_html( $source ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Apply', 'beacon-campaign-sender' ); ?></button>
		<span class="bcsend-log-count">
			<?php
			printf(
				/* translators: %s: total subscriber rows */
				esc_html__( '%s rows', 'beacon-campaign-sender' ),
				esc_html( number_format_i18n( $total ) )
			);
			?>
		</span>
	</form>

	<table class="widefat fixed striped bcsend-tools-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Email', 'beacon-campaign-sender' ); ?></th>
				<th><?php esc_html_e( 'Source', 'beacon-campaign-sender' ); ?></th>
				<th><?php esc_html_e( 'Status', 'beacon-campaign-sender' ); ?></th>
				<th><?php esc_html_e( 'Submitted', 'beacon-campaign-sender' ); ?></th>
				<th><?php esc_html_e( 'Lists', 'beacon-campaign-sender' ); ?></th>
				<th><?php esc_html_e( 'Retries', 'beacon-campaign-sender' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'beacon-campaign-sender' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $rows ) ) : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$list_ids = ! empty( $row['list_ids_json'] ) ? json_decode( $row['list_ids_json'], true ) : array();
					$list_ids = is_array( $list_ids ) ? array_map( 'intval', $list_ids ) : array();
					$raw_open = ! empty( $row['brevo_response_json'] );
					?>
					<tr>
						<td><?php echo esc_html( $row['email'] ); ?></td>
						<td><code><?php echo esc_html( $row['source'] ); ?></code></td>
						<td><span class="bcsend-status-badge"><?php echo esc_html( $row['status'] ); ?></span></td>
						<td><?php echo esc_html( $row['submitted_at'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', $list_ids ) ); ?></td>
						<td><?php echo esc_html( (string) $row['retry_count'] ); ?></td>
						<td>
							<?php if ( in_array( $row['status'], array( 'pending_retry', 'failed' ), true ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bcsend-subscribers&subscriber_action=retry&subscriber_id=' . (int) $row['id'] ), 'bcsend_retry_subscriber_' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Retry', 'beacon-campaign-sender' ); ?></a>
							<?php endif; ?>
							<?php if ( $raw_open ) : ?>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'View Raw', 'beacon-campaign-sender' ); ?></summary>
									<pre style="max-width:520px;white-space:pre-wrap;"><?php echo esc_html( $row['brevo_response_json'] ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No subscriber rows found.', 'beacon-campaign-sender' ); ?></td>
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
							'page'              => 'bcsend-subscribers',
							'subscriber_status' => $status_value,
							'subscriber_source' => $source_value,
							'paged'             => $paged - 1,
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
					/* translators: 1: current page, 2: total pages */
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
							'page'              => 'bcsend-subscribers',
							'subscriber_status' => $status_value,
							'subscriber_source' => $source_value,
							'paged'             => $paged + 1,
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
