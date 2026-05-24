<?php
/**
 * Templates view for Beacon Campaign Sender.
 *
 * Displays saved email templates in a card grid with preview,
 * edit, duplicate, and delete actions.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 *
 * @var array $templates Array of template row objects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap bcsend-wrap bcsend-templates-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php esc_html_e( 'Reusable Design', 'beacon-campaign-sender' ); ?></span>
			<h1><?php esc_html_e( 'Email Templates', 'beacon-campaign-sender' ); ?></h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Keep proven layouts close at hand so new campaigns start from strong structure instead of a blank editor.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Open Composer', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<?php if ( ! empty( $templates ) ) : ?>
		<div class="bcsend-templates-grid">
			<?php foreach ( $templates as $template ) : ?>
				<?php
				$preview_text = '';
				if ( ! empty( $template->plain_text ) ) {
					$preview_text = mb_strimwidth( $template->plain_text, 0, 200, '...' );
				} elseif ( ! empty( $template->html_content ) ) {
					$preview_text = mb_strimwidth( wp_strip_all_tags( $template->html_content ), 0, 200, '...' );
				}
				?>
				<div class="bcsend-template-card" data-template-id="<?php echo esc_attr( $template->id ); ?>">
					<div class="bcsend-template-preview">
						<?php if ( ! empty( $template->html_content ) ) : ?>
							<iframe class="bcsend-template-thumb"
									srcdoc="<?php echo esc_attr( $template->html_content ); ?>"
									sandbox=""
									scrolling="no"
									tabindex="-1"></iframe>
						<?php else : ?>
							<div class="bcsend-template-text-preview">
								<?php echo esc_html( $preview_text ); ?>
							</div>
						<?php endif; ?>
					</div>

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
								data-template-html="<?php echo esc_attr( ! empty( $template->html_content ) ? $template->html_content : '' ); ?>">
							<?php esc_html_e( 'Preview', 'beacon-campaign-sender' ); ?>
						</button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-composer&template_id=' . (int) $template->id ) ); ?>"
							class="button button-small">
							<?php esc_html_e( 'Edit', 'beacon-campaign-sender' ); ?>
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
		<div class="bcsend-modal-content bcsend-modal-fullwidth">
			<button type="button" class="bcsend-modal-close" id="bcsend-close-template-modal">&times;</button>
			<iframe id="bcsend-template-modal-iframe" class="bcsend-modal-iframe"></iframe>
		</div>
	</div>
</div>
