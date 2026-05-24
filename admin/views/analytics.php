<?php
/**
 * Analytics view for Beacon Campaign Sender.
 *
 * Displays campaign performance stats, charts (via Chart.js),
 * and top campaigns table.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var int    $total_sent       Total campaigns sent.
 * @var float  $avg_open_rate    Average open rate.
 * @var float  $avg_click_rate   Average click rate.
 * @var int    $total_push       Total push notifications delivered.
 * @var array  $top_campaigns    Top campaigns by opens.
 * @var array  $daily_stats      Daily campaign stats for chart.
 * @var array  $audience_growth  Monthly audience growth for chart.
 * @var array  $push_per_campaign Push delivery counts per campaign.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap bcsend-wrap bcsend-analytics-wrap">
	<div class="bcsend-section-header" style="display:flex;align-items:center;gap:10px;">
		<h1 style="margin:0;"><?php esc_html_e( 'Campaign Analytics', 'beacon-campaign-sender' ); ?></h1>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bcsend-analytics&refresh=1' ), 'bcsend_refresh_analytics' ) ); ?>" class="button">
			<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:3px;"></span>
			<?php esc_html_e( 'Refresh', 'beacon-campaign-sender' ); ?>
		</a>
	</div>

	<!-- Stats Cards -->
	<div class="bcsend-stats-cards">
		<div class="bcsend-stat-card">
			<span class="bcsend-stat-card-value"><?php echo esc_html( number_format_i18n( $total_sent ) ); ?></span>
			<span class="bcsend-stat-card-label"><?php esc_html_e( 'Total Campaigns Sent', 'beacon-campaign-sender' ); ?></span>
		</div>
		<div class="bcsend-stat-card">
			<span class="bcsend-stat-card-value"><?php echo esc_html( number_format( $avg_open_rate * 100, 1 ) . '%' ); ?></span>
			<span class="bcsend-stat-card-label"><?php esc_html_e( 'Average Open Rate', 'beacon-campaign-sender' ); ?></span>
		</div>
		<div class="bcsend-stat-card">
			<span class="bcsend-stat-card-value"><?php echo esc_html( number_format( $avg_click_rate * 100, 1 ) . '%' ); ?></span>
			<span class="bcsend-stat-card-label"><?php esc_html_e( 'Average Click Rate', 'beacon-campaign-sender' ); ?></span>
		</div>
		<div class="bcsend-stat-card">
			<span class="bcsend-stat-card-value"><?php echo esc_html( number_format_i18n( $total_push ) ); ?></span>
			<span class="bcsend-stat-card-label"><?php esc_html_e( 'Total Push Delivered', 'beacon-campaign-sender' ); ?></span>
		</div>
	</div>

	<!-- Charts Row -->
	<div class="bcsend-charts-row">
		<!-- Email Performance Chart -->
		<div class="bcsend-chart-container">
			<h2><?php esc_html_e( 'Email Performance (Last 30 Days)', 'beacon-campaign-sender' ); ?></h2>
			<div class="bcsend-chart-wrapper">
				<canvas id="bcsend-email-performance-chart"></canvas>
			</div>
			<div class="bcsend-chart-loading" id="bcsend-email-chart-loading">
				<span class="spinner is-active" style="float:none;"></span>
				<?php esc_html_e( 'Loading chart data...', 'beacon-campaign-sender' ); ?>
			</div>
		</div>

		<!-- Audience Growth Chart -->
		<div class="bcsend-chart-container">
			<h2><?php esc_html_e( 'Audience Growth', 'beacon-campaign-sender' ); ?></h2>
			<div class="bcsend-chart-wrapper">
				<canvas id="bcsend-audience-growth-chart"></canvas>
			</div>
			<div class="bcsend-chart-loading" id="bcsend-audience-chart-loading">
				<span class="spinner is-active" style="float:none;"></span>
				<?php esc_html_e( 'Loading chart data...', 'beacon-campaign-sender' ); ?>
			</div>
		</div>
	</div>

	<!-- Push Stats Chart -->
	<div class="bcsend-chart-container bcsend-chart-full">
		<h2><?php esc_html_e( 'Push Notification Delivery', 'beacon-campaign-sender' ); ?></h2>
		<div class="bcsend-chart-wrapper">
			<canvas id="bcsend-push-stats-chart"></canvas>
		</div>
		<div class="bcsend-chart-loading" id="bcsend-push-chart-loading">
			<span class="spinner is-active" style="float:none;"></span>
			<?php esc_html_e( 'Loading chart data...', 'beacon-campaign-sender' ); ?>
		</div>
	</div>

	<!-- Top Campaigns Table -->
	<div class="bcsend-analytics-section">
		<h2><?php esc_html_e( 'Top Campaigns', 'beacon-campaign-sender' ); ?></h2>
		<?php if ( ! empty( $top_campaigns ) ) : ?>
			<table class="widefat fixed striped bcsend-top-campaigns-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Campaign Name', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Sent Date', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Opens', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'beacon-campaign-sender' ); ?></th>
						<th><?php esc_html_e( 'Unsubscribes', 'beacon-campaign-sender' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $top_campaigns as $tc ) : ?>
						<tr>
							<td><?php echo esc_html( $tc['name'] ); ?></td>
							<td>
								<?php
								if ( ! empty( $tc['sent_date'] ) ) {
									echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $tc['sent_date'] ) ) );
								} else {
									echo esc_html__( 'N/A', 'beacon-campaign-sender' );
								}
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $tc['opens'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $tc['clicks'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $tc['unsubscribes'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="bcsend-empty-state"><?php esc_html_e( 'No campaign data available yet.', 'beacon-campaign-sender' ); ?></p>
		<?php endif; ?>
	</div>

	<?php
	wp_add_inline_script(
		'bcsend-analytics',
		'var bcsendAnalyticsData = ' . wp_json_encode(
			array(
				'dailyStats'      => $daily_stats,
				'audienceGrowth'  => $audience_growth,
				'pushPerCampaign' => $push_per_campaign,
			)
		) . ';',
		'before'
	);
	?>
</div>
