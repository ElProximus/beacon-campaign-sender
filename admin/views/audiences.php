<?php
/**
 * Audiences view for Beacon Campaign Sender.
 *
 * Displays Brevo contact lists (loaded via AJAX) and smart segments
 * (from DB) with inline segment creation form.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var array          $segments Array of segment row objects.
 * @var Bcsend_Environment $env      Environment instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$query_types = array(
	'all_customers'   => __( 'All Customers', 'beacon-campaign-sender' ),
	'by_category'     => __( 'By Category', 'beacon-campaign-sender' ),
	'by_product'      => __( 'By Product', 'beacon-campaign-sender' ),
	'never_purchased' => __( 'Never Purchased', 'beacon-campaign-sender' ),
	'inactive'        => __( 'Inactive', 'beacon-campaign-sender' ),
	'new_members'     => __( 'New Members', 'beacon-campaign-sender' ),
	'app_users'       => __( 'App Users', 'beacon-campaign-sender' ),
);
?>
<div class="wrap bcsend-wrap bcsend-audiences-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Audience Builder', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'Audiences', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Keep reusable segments organized, check sync freshness, and adjust targeting rules without losing sight of contact counts.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Use in Composer', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<!-- Segments Section -->
	<div class="bcsend-audiences-section">
		<div class="bcsend-section-header">
			<h2><?php esc_html_e( 'Smart Segments', 'beacon-campaign-sender' ); ?></h2>
			<div class="bcsend-section-header-actions">
				<button type="button" class="button" id="bcsend-sync-brevo-lists">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Sync Brevo Lists', 'beacon-campaign-sender' ); ?>
				</button>
				<span id="bcsend-sync-all-status" class="bcsend-inline-status"></span>
			</div>
		</div>

		<table class="widefat fixed striped bcsend-segments-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Query Type', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Contacts', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Last Synced', 'beacon-campaign-sender' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'beacon-campaign-sender' ); ?></th>
				</tr>
			</thead>
			<tbody id="bcsend-segments-body">
				<?php if ( ! empty( $segments ) ) : ?>
					<?php foreach ( $segments as $segment ) : ?>
						<tr data-segment-id="<?php echo esc_attr( $segment->id ); ?>" data-segment-type="<?php echo esc_attr( $segment->type ); ?>">
							<td><?php echo esc_html( $segment->name ); ?></td>
							<td>
								<span class="bcsend-query-type-badge">
									<?php
									if ( 'brevo_list' === $segment->type ) {
										esc_html_e( 'Brevo List', 'beacon-campaign-sender' );
									} else {
										$type_key = isset( $segment->query_type ) ? $segment->query_type : '';
										echo esc_html( isset( $query_types[ $type_key ] ) ? $query_types[ $type_key ] : $type_key );
									}
									?>
								</span>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) $segment->contact_count ) ); ?></td>
							<td>
								<?php
								if ( ! empty( $segment->last_synced ) ) {
									echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $segment->last_synced ) ) );
								} else {
									esc_html_e( 'Never', 'beacon-campaign-sender' );
								}
								?>
							</td>
							<td class="bcsend-segment-actions">
								<?php if ( 'brevo_list' !== $segment->type ) : ?>
								<button type="button"
										class="button button-small bcsend-view-contacts"
										data-segment-id="<?php echo esc_attr( $segment->id ); ?>">
									<?php esc_html_e( 'View Contacts', 'beacon-campaign-sender' ); ?>
								</button>
								<?php endif; ?>
								<button type="button"
										class="button button-small bcsend-sync-segment"
										data-segment-id="<?php echo esc_attr( $segment->id ); ?>">
									<?php esc_html_e( 'Sync Now', 'beacon-campaign-sender' ); ?>
								</button>
								<?php if ( 'brevo_list' !== $segment->type ) : ?>
								<button type="button"
										class="button button-small bcsend-edit-segment"
										data-segment-id="<?php echo esc_attr( $segment->id ); ?>"
										data-segment-name="<?php echo esc_attr( $segment->name ); ?>"
										data-segment-query-type="<?php echo esc_attr( $segment->query_type ); ?>"
										data-segment-params="<?php echo esc_attr( isset( $segment->query_params ) ? $segment->query_params : '' ); ?>">
									<?php esc_html_e( 'Edit', 'beacon-campaign-sender' ); ?>
								</button>
								<?php endif; ?>
								<button type="button"
										class="button button-small bcsend-delete-segment"
										data-segment-id="<?php echo esc_attr( $segment->id ); ?>">
									<?php esc_html_e( 'Delete', 'beacon-campaign-sender' ); ?>
								</button>
								<span class="bcsend-segment-action-status"></span>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr id="bcsend-no-segments-row">
						<td colspan="5"><?php esc_html_e( 'No smart segments created yet.', 'beacon-campaign-sender' ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<!-- Create New Segment -->
		<div class="bcsend-create-segment-toggle">
			<button type="button" class="button button-primary" id="bcsend-show-create-segment">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( 'Create New Smart Segment', 'beacon-campaign-sender' ); ?>
			</button>
		</div>

		<div id="bcsend-create-segment-form" class="bcsend-inline-form" style="display:none;">
			<h3 id="bcsend-segment-form-title"><?php esc_html_e( 'New Smart Segment', 'beacon-campaign-sender' ); ?></h3>
			<input type="hidden" id="bcsend-edit-segment-id" value="" />

			<div class="bcsend-field-group">
				<label for="bcsend-segment-name"><?php esc_html_e( 'Segment Name', 'beacon-campaign-sender' ); ?></label>
				<input type="text"
						id="bcsend-segment-name"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. VIP Customers', 'beacon-campaign-sender' ); ?>" />
			</div>

			<div class="bcsend-field-group">
				<label for="bcsend-segment-query-type"><?php esc_html_e( 'Query Type', 'beacon-campaign-sender' ); ?></label>
				<select id="bcsend-segment-query-type" class="regular-text">
					<?php foreach ( $query_types as $type_val => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_val ); ?>">
							<?php echo esc_html( $type_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Dynamic Parameters -->
			<div id="bcsend-segment-params" class="bcsend-segment-params">
				<div class="bcsend-param-category" style="display:none;">
					<label for="bcsend-param-category-search"><?php esc_html_e( 'Category', 'beacon-campaign-sender' ); ?></label>
					<input type="text"
							id="bcsend-param-category-search"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Search categories...', 'beacon-campaign-sender' ); ?>" />
					<div id="bcsend-param-category-results" class="bcsend-param-results"></div>
					<input type="hidden" id="bcsend-param-category-id" value="" />
				</div>

				<div class="bcsend-param-product" style="display:none;">
					<label for="bcsend-param-product-search"><?php esc_html_e( 'Product', 'beacon-campaign-sender' ); ?></label>
					<input type="text"
							id="bcsend-param-product-search"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Search products...', 'beacon-campaign-sender' ); ?>" />
					<div id="bcsend-param-product-results" class="bcsend-param-results"></div>
					<input type="hidden" id="bcsend-param-product-id" value="" />
				</div>

				<div class="bcsend-param-days" style="display:none;">
					<label for="bcsend-param-days-input"><?php esc_html_e( 'Days', 'beacon-campaign-sender' ); ?></label>
					<input type="number"
							id="bcsend-param-days-input"
							class="small-text"
							min="1"
							max="365"
							value="30"
							placeholder="30" />
					<p class="description" id="bcsend-param-days-desc"></p>
				</div>
			</div>

			<div class="bcsend-form-actions">
				<button type="button" class="button button-primary" id="bcsend-save-segment">
					<?php esc_html_e( 'Save Segment', 'beacon-campaign-sender' ); ?>
				</button>
				<button type="button" class="button" id="bcsend-cancel-segment">
					<?php esc_html_e( 'Cancel', 'beacon-campaign-sender' ); ?>
				</button>
				<span id="bcsend-segment-form-status" class="bcsend-inline-status"></span>
			</div>
		</div>
	</div>
</div>
