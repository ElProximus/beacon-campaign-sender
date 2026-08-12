<?php
/**
 * Templates view for Beacon Campaign Sender.
 *
 * Card grid where every card shows the ENTIRE email scaled down. Clicking a
 * card's preview opens the composer with that template loaded. The default
 * template is pinned first with a badge; any template can be made the
 * default, and the composer preloads the default for new campaigns.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var array $templates Array of template row objects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bcsend_default_template_id = (int) get_option( 'bcsend_default_template_id', 0 );
?>
<div class="wrap bcsend-wrap bcsend-templates-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Reusable Design', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'Email Templates', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Click a design to start a campaign from it. The default template opens automatically in every new campaign.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Open Composer', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<?php if ( ! empty( $templates ) ) : ?>
		<div class="bcsend-templates-grid">
			<?php foreach ( $templates as $template ) : ?>
				<?php
				$is_default   = $bcsend_default_template_id === (int) $template->id;
				$composer_url = admin_url( 'admin.php?page=bcsend-composer&template_id=' . (int) $template->id );
				?>
				<div class="bcsend-template-card<?php echo $is_default ? ' is-default' : ''; ?>" data-template-id="<?php echo esc_attr( $template->id ); ?>">
					<?php if ( $is_default ) : ?>
						<span class="bcsend-default-badge"><?php esc_html_e( '★ Default', 'beacon-campaign-sender' ); ?></span>
					<?php endif; ?>

					<a class="bcsend-template-preview"
						href="<?php echo esc_url( $composer_url ); ?>"
						title="<?php esc_attr_e( 'Start a campaign from this template', 'beacon-campaign-sender' ); ?>">
						<?php if ( ! empty( $template->html_content ) ) : ?>
							<div class="bcsend-template-thumb-viewport">
								<iframe class="bcsend-template-thumb"
										srcdoc="<?php echo esc_attr( $template->html_content ); ?>"
										sandbox="allow-same-origin"
										scrolling="no"
										loading="lazy"
										tabindex="-1"></iframe>
							</div>
						<?php else : ?>
							<div class="bcsend-template-text-preview">
								<?php echo esc_html( mb_strimwidth( wp_strip_all_tags( (string) $template->plain_text ), 0, 200, '...' ) ); ?>
							</div>
						<?php endif; ?>
						<span class="bcsend-template-open-hint"><?php esc_html_e( 'Use this template →', 'beacon-campaign-sender' ); ?></span>
					</a>

					<div class="bcsend-template-info">
						<h3 class="bcsend-template-name"><?php echo esc_html( $template->name ); ?></h3>
						<span class="bcsend-template-date">
							<?php
							if ( ! empty( $template->created_at ) ) {
								echo esc_html( wp_date( 'M j, Y', strtotime( $template->created_at ) ) );
							}
							?>
						</span>
					</div>

					<div class="bcsend-template-actions">
						<button type="button"
								class="button button-small bcsend-preview-template-btn"
								data-template-id="<?php echo esc_attr( $template->id ); ?>"
								data-template-name="<?php echo esc_attr( $template->name ); ?>"
								data-template-html="<?php echo esc_attr( ! empty( $template->html_content ) ? $template->html_content : '' ); ?>">
							<?php esc_html_e( 'Preview', 'beacon-campaign-sender' ); ?>
						</button>
						<a href="<?php echo esc_url( $composer_url ); ?>" class="button button-small">
							<?php esc_html_e( 'Use', 'beacon-campaign-sender' ); ?>
						</a>
						<button type="button"
								class="button button-small bcsend-duplicate-template"
								data-template-id="<?php echo esc_attr( $template->id ); ?>">
							<?php esc_html_e( 'Duplicate', 'beacon-campaign-sender' ); ?>
						</button>
						<button type="button"
								class="button button-small bcsend-delete-template"
								data-template-id="<?php echo esc_attr( $template->id ); ?>">
							<?php esc_html_e( 'Delete', 'beacon-campaign-sender' ); ?>
						</button>
						<?php if ( ! $is_default ) : ?>
							<button type="button"
									class="button button-small bcsend-set-default-template"
									data-template-id="<?php echo esc_attr( $template->id ); ?>">
								<?php esc_html_e( 'Set as Default', 'beacon-campaign-sender' ); ?>
							</button>
						<?php endif; ?>
					</div>
					<span class="bcsend-template-action-status"></span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="bcsend-empty-state-box">
			<span class="dashicons dashicons-email-alt"></span>
			<p><?php esc_html_e( 'No templates saved yet. Save a campaign as a template from the Composer to get started.', 'beacon-campaign-sender' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Preview Modal -->
	<div id="bcsend-template-preview-modal" class="bcsend-modal-overlay" style="display:none;">
		<div class="bcsend-modal-content bcsend-template-modal">
			<div class="bcsend-template-modal-bar">
				<span class="bcsend-template-modal-title"></span>
				<button type="button" class="bcsend-modal-close" id="bcsend-close-template-modal" aria-label="<?php esc_attr_e( 'Close preview', 'beacon-campaign-sender' ); ?>">&times;</button>
			</div>
			<iframe id="bcsend-template-modal-iframe" class="bcsend-modal-iframe" sandbox="allow-same-origin"></iframe>
		</div>
	</div>
</div>
