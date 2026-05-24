<?php
/**
 * Composer view for Beacon Campaign Sender.
 *
 * Single-screen campaign editor with split preview/code on the left
 * and all campaign fields on the right.
 *
 * @package Bcsend_Plugin
 * @since   2.0.0
 *
 * @var object|null $campaign    Existing campaign data or null for new.
 * @var array       $segments    Array of segment row objects.
 * @var string      $brand_voice Brand voice setting from plugin config.
 * @var int         $campaign_id Campaign ID if editing, 0 if new.
 * @var Bcsend_Environment $env      Environment instance.
 * @var object|null $template    Selected template row or null.
 * @var int         $template_id Template ID if starting from a template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_editing           = ( null !== $campaign );
$loaded_template_html = '';

if ( ! $is_editing && ! empty( $template ) && ! empty( $template->html_content ) ) {
	$loaded_template_html = (string) $template->html_content;
}

$social_platforms_meta = Bcsend_Social_Workflow::get_platform_metadata();
$social_posts_index    = array();
$settings_post_mode    = isset( $settings['zernio_post_mode'] ) && in_array( $settings['zernio_post_mode'], array( 'single', 'per_platform' ), true ) ? $settings['zernio_post_mode'] : 'single';
$social_post_mode      = $is_editing && ! empty( $campaign->social_post_mode ) && in_array( $campaign->social_post_mode, array( 'single', 'per_platform' ), true ) ? $campaign->social_post_mode : $settings_post_mode;
$shared_social_content = '';
$shared_social_media   = array();
$shared_link_mode      = 'none';
$shared_link_url       = '';

if ( ! empty( $social_posts ) ) {
	foreach ( $social_posts as $social_post ) {
		if ( empty( $social_post->platform ) ) {
			continue;
		}
		$social_posts_index[ $social_post->platform ] = $social_post;
		if ( empty( $shared_social_content ) && isset( $social_post->content ) ) {
			$shared_social_content = Bcsend_Social_Workflow::normalize_post_content( $social_post->content );
			$shared_link_mode      = ! empty( $social_post->link_mode ) ? $social_post->link_mode : 'none';
			$shared_link_url       = ! empty( $social_post->link_url ) ? $social_post->link_url : '';
			if ( ! empty( $social_post->media_items ) ) {
				$decoded_shared_media = json_decode( $social_post->media_items, true );
				$shared_social_media  = is_array( $decoded_shared_media ) ? $decoded_shared_media : array();
			}
		}
	}
}
?>
<div class="wrap bcsend-wrap bcsend-composer-wrap">
	<div class="bcsend-page-header">
		<div class="bcsend-page-title-group">
			<span class="bcsend-page-eyebrow"><?php echo $is_editing ? esc_html__( 'Campaign Editor', 'beacon-campaign-sender' ) : esc_html__( 'Campaign Builder', 'beacon-campaign-sender' ); ?></span>
			<h1>
				<?php
				if ( $is_editing ) {
					printf(
						/* translators: %s: campaign name */
						esc_html__( 'Edit Campaign: %s', 'beacon-campaign-sender' ),
						esc_html( $campaign->name )
					);
				} else {
					esc_html_e( 'Campaign Composer', 'beacon-campaign-sender' );
				}
				?>
			</h1>
			<p class="bcsend-page-lede"><?php esc_html_e( 'Draft the email, tune companion channels, and schedule the send from one workspace. The preview stays on the left; the campaign decisions stay on the right.', 'beacon-campaign-sender' ); ?></p>
		</div>
		<div class="bcsend-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bcsend-queue' ) ); ?>" class="button"><?php esc_html_e( 'View Queue', 'beacon-campaign-sender' ); ?></a>
		</div>
	</div>

	<?php if ( $is_editing ) : ?>
		<input type="hidden" id="bcsend-campaign-id" value="<?php echo esc_attr( $campaign->id ); ?>" />
	<?php endif; ?>
	<?php if ( ! empty( $template_id ) ) : ?>
		<input type="hidden" id="bcsend-template-id" value="<?php echo esc_attr( $template_id ); ?>" />
	<?php endif; ?>

	<div class="bcsend-panels">

		<!-- Left Panel: Email Preview + HTML Editor (split view) -->
		<div class="bcsend-panel-left" id="bcsend-email-panel" style="<?php echo ( $is_editing && isset( $campaign->send_email ) && ! $campaign->send_email ) ? 'display:none;' : ''; ?>">
			<div class="bcsend-preview-container">
				<div class="bcsend-preview-header">
					<span><?php esc_html_e( 'Email Preview', 'beacon-campaign-sender' ); ?></span>
					<span class="bcsend-sync-indicator" id="bcsend-sync-indicator"><?php esc_html_e( 'Synced', 'beacon-campaign-sender' ); ?></span>
				</div>
				<iframe id="bcsend-email-preview"
						class="bcsend-email-preview-iframe"
						<?php if ( $is_editing && ! empty( $campaign->html_content ) ) : ?>
							srcdoc="<?php echo esc_attr( $campaign->html_content ); ?>"
						<?php elseif ( ! empty( $loaded_template_html ) ) : ?>
							srcdoc="<?php echo esc_attr( $loaded_template_html ); ?>"
						<?php endif; ?>
				></iframe>
			</div>
			<div class="bcsend-code-container">
				<div class="bcsend-code-header">
					<span><?php esc_html_e( 'HTML Source', 'beacon-campaign-sender' ); ?></span>
					<div class="bcsend-code-header-actions">
						<button type="button" class="button button-small" id="bcsend-save-snippet-btn" title="<?php esc_attr_e( 'Save selection as snippet', 'beacon-campaign-sender' ); ?>">
							<?php esc_html_e( 'Save Snippet', 'beacon-campaign-sender' ); ?>
						</button>
						<button type="button" class="button button-small" id="bcsend-regenerate-html">
							<?php esc_html_e( 'Regenerate HTML', 'beacon-campaign-sender' ); ?>
						</button>
						<button type="button" class="button button-small" id="bcsend-apply-html">
							<?php esc_html_e( 'Apply', 'beacon-campaign-sender' ); ?>
						</button>
						<span id="bcsend-regen-html-status" class="bcsend-inline-status"></span>
					</div>
				</div>
				<textarea id="bcsend-html-editor"
							class="bcsend-code-editor"><?php echo $is_editing ? esc_textarea( $campaign->html_content ) : esc_textarea( $loaded_template_html ); ?></textarea>
			</div>
		</div>

		<!-- Right Panel: Campaign Fields -->
		<div class="bcsend-panel-right">

			<!-- Content Library -->
			<div class="bcsend-content-library" id="bcsend-content-library">
				<div class="bcsend-cl-header">
					<span><?php esc_html_e( 'Content Library', 'beacon-campaign-sender' ); ?></span>
					<button type="button" class="bcsend-cl-toggle" id="bcsend-cl-toggle" aria-expanded="true">
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
				</div>
				<div class="bcsend-cl-body" id="bcsend-cl-body">
					<div class="bcsend-cl-tabs">
						<?php if ( $env->is( 'woocommerce_active' ) ) : ?>
							<button type="button" class="bcsend-cl-tab is-active" data-tab="products">
								<?php esc_html_e( 'Products', 'beacon-campaign-sender' ); ?>
							</button>
						<?php endif; ?>
						<button type="button" class="bcsend-cl-tab<?php echo ! $env->is( 'woocommerce_active' ) ? ' is-active' : ''; ?>" data-tab="media">
							<?php esc_html_e( 'Media', 'beacon-campaign-sender' ); ?>
						</button>
						<button type="button" class="bcsend-cl-tab" data-tab="posts">
							<?php esc_html_e( 'Posts', 'beacon-campaign-sender' ); ?>
						</button>
						<button type="button" class="bcsend-cl-tab" data-tab="snippets">
							<?php esc_html_e( 'Snippets', 'beacon-campaign-sender' ); ?>
						</button>
					</div>

					<!-- Selected content (always visible) -->
					<div id="bcsend-cl-all-selected" class="bcsend-cl-all-selected"></div>

					<?php if ( $env->is( 'woocommerce_active' ) ) : ?>
					<!-- Products Tab -->
					<div class="bcsend-cl-panel is-active" data-panel="products">
						<div class="bcsend-cl-search-row">
							<input type="text" id="bcsend-cl-product-search" class="bcsend-cl-search" placeholder="<?php esc_attr_e( 'Search products...', 'beacon-campaign-sender' ); ?>" autocomplete="off" />
						</div>
						<div id="bcsend-cl-product-results" class="bcsend-cl-results"></div>
					</div>
					<?php endif; ?>

					<!-- Media Tab -->
					<div class="bcsend-cl-panel<?php echo ! $env->is( 'woocommerce_active' ) ? ' is-active' : ''; ?>" data-panel="media">
						<div class="bcsend-cl-media-actions">
							<button type="button" class="button" id="bcsend-cl-media-pick">
								<span class="dashicons dashicons-format-image"></span>
								<?php esc_html_e( 'Choose Images', 'beacon-campaign-sender' ); ?>
							</button>
						</div>
					</div>

					<!-- Posts Tab -->
					<div class="bcsend-cl-panel" data-panel="posts">
						<div class="bcsend-cl-search-row">
							<input type="text" id="bcsend-cl-post-search" class="bcsend-cl-search" placeholder="<?php esc_attr_e( 'Search posts...', 'beacon-campaign-sender' ); ?>" autocomplete="off" />
							<select id="bcsend-cl-post-type" class="bcsend-cl-type-select">
								<option value="post"><?php esc_html_e( 'Posts', 'beacon-campaign-sender' ); ?></option>
								<option value="page"><?php esc_html_e( 'Pages', 'beacon-campaign-sender' ); ?></option>
								<?php
								$custom_types = get_post_types(
									array(
										'public'   => true,
										'_builtin' => false,
									),
									'objects'
								);
								foreach ( $custom_types as $cpt ) :
									if ( 'product' === $cpt->name ) {
										continue; }
									?>
									<option value="<?php echo esc_attr( $cpt->name ); ?>"><?php echo esc_html( $cpt->label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div id="bcsend-cl-post-results" class="bcsend-cl-results"></div>
					</div>

					<!-- Snippets Tab -->
					<div class="bcsend-cl-panel" data-panel="snippets">
						<div id="bcsend-cl-snippet-list" class="bcsend-cl-results"></div>
						<p class="description"><?php esc_html_e( 'Select HTML in the editor above and click "Save Snippet" to create reusable blocks.', 'beacon-campaign-sender' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Campaign Prompt -->
			<div class="bcsend-field-group">
				<label for="bcsend-campaign-prompt"><?php esc_html_e( 'Campaign Prompt', 'beacon-campaign-sender' ); ?></label>
				<textarea id="bcsend-campaign-prompt"
							rows="3"
							class="large-text"
							placeholder="<?php esc_attr_e( 'Describe your campaign, then click Generate. Edit the prompt and regenerate as many times as you like.', 'beacon-campaign-sender' ); ?>"></textarea>
				<?php if ( ! empty( $brand_voice ) ) : ?>
					<p class="description bcsend-italic"><?php echo esc_html( 'Brand voice: ' . $brand_voice ); ?></p>
				<?php endif; ?>
				<div class="bcsend-generate-row">
					<button type="button" id="bcsend-generate-campaign" class="button button-primary">
						<?php esc_html_e( 'Generate', 'beacon-campaign-sender' ); ?>
					</button>
					<span id="bcsend-generate-status" class="bcsend-inline-status"></span>
				</div>
			</div>

			<div class="bcsend-field-group bcsend-email-section">
				<h3>
					<label for="bcsend-send-email" class="bcsend-push-toggle">
						<input type="checkbox"
								id="bcsend-send-email"
								<?php checked( ! $is_editing || ! isset( $campaign->send_email ) || $campaign->send_email ); ?>
						/>
						<?php esc_html_e( 'Include Email', 'beacon-campaign-sender' ); ?>
					</label>
				</h3>

				<div class="bcsend-email-fields" id="bcsend-email-fields" style="<?php echo ( $is_editing && isset( $campaign->send_email ) && ! $campaign->send_email ) ? 'display:none;' : 'display:block;'; ?>">
					<div class="bcsend-field-group">
						<label for="bcsend-subject"><?php esc_html_e( 'Subject Line', 'beacon-campaign-sender' ); ?></label>
						<input type="text"
								id="bcsend-subject"
								class="large-text"
								value="<?php echo $is_editing ? esc_attr( $campaign->subject ) : ''; ?>"
								placeholder="<?php esc_attr_e( 'Enter email subject line', 'beacon-campaign-sender' ); ?>" />
					</div>

					<div class="bcsend-field-group">
						<label for="bcsend-preview-text"><?php esc_html_e( 'Preview Text', 'beacon-campaign-sender' ); ?></label>
						<input type="text"
								id="bcsend-preview-text"
								class="large-text"
								value="<?php echo $is_editing ? esc_attr( $campaign->preview_text ) : ''; ?>"
								placeholder="<?php esc_attr_e( 'Text shown in email client before opening', 'beacon-campaign-sender' ); ?>" />
					</div>
				</div>
			</div>

			<textarea id="bcsend-plain-text" class="hidden"><?php echo $is_editing ? esc_textarea( $campaign->plain_text ) : ''; ?></textarea>

<!-- Push Notification -->
			<div class="bcsend-field-group bcsend-push-section">
				<h3>
					<label for="bcsend-send-push" class="bcsend-push-toggle">
						<input type="checkbox"
								id="bcsend-send-push"
								<?php checked( ! $is_editing || empty( $campaign ) || ! isset( $campaign->send_push ) || $campaign->send_push ); ?>
						/>
						<?php esc_html_e( 'Include Push Notification', 'beacon-campaign-sender' ); ?>
					</label>
				</h3>

				<div class="bcsend-push-fields" id="bcsend-push-fields">
					<div class="bcsend-push-preview-row">
						<div class="bcsend-phone-mockup">
							<div class="bcsend-phone-notch"></div>
							<div class="bcsend-phone-screen">
								<div class="bcsend-push-notification-preview">
									<div class="bcsend-push-app-icon"></div>
									<div class="bcsend-push-content">
										<span class="bcsend-push-preview-title" id="bcsend-push-preview-title">
											<?php echo $is_editing ? esc_html( $campaign->push_title ) : ''; ?>
										</span>
										<span class="bcsend-push-preview-message" id="bcsend-push-preview-message">
											<?php echo $is_editing ? esc_html( $campaign->push_message ) : ''; ?>
										</span>
									</div>
								</div>
							</div>
						</div>

						<div class="bcsend-push-inputs">
							<div class="bcsend-field-group">
								<label for="bcsend-push-title"><?php esc_html_e( 'Push Title', 'beacon-campaign-sender' ); ?></label>
								<input type="text"
										id="bcsend-push-title"
										class="large-text"
										maxlength="26"
										value="<?php echo $is_editing ? esc_attr( $campaign->push_title ) : ''; ?>"
										placeholder="<?php esc_attr_e( 'Max 26 characters', 'beacon-campaign-sender' ); ?>" />
								<span class="bcsend-char-counter">
									<span id="bcsend-push-title-count"><?php echo $is_editing ? esc_html( mb_strlen( $campaign->push_title ) ) : '0'; ?></span>/26
								</span>
							</div>

							<div class="bcsend-field-group">
								<label for="bcsend-push-message"><?php esc_html_e( 'Push Message', 'beacon-campaign-sender' ); ?></label>
								<textarea id="bcsend-push-message"
											class="large-text"
											maxlength="354"
											rows="3"><?php echo $is_editing ? esc_textarea( $campaign->push_message ) : ''; ?></textarea>
								<span class="bcsend-char-counter">
									<span id="bcsend-push-message-count"><?php echo $is_editing ? esc_html( mb_strlen( $campaign->push_message ) ) : '0'; ?></span>/354
								</span>
							</div>

							<button type="button" class="button" id="bcsend-regenerate-push">
								<?php esc_html_e( 'Regenerate Push', 'beacon-campaign-sender' ); ?>
							</button>
							<span id="bcsend-regen-push-status" class="bcsend-inline-status"></span>

							<?php
							$current_push_target_type = $is_editing && ! empty( $campaign->push_target_type ) ? $campaign->push_target_type : 'all_users';
							$current_push_target_data = array();
							if ( $is_editing && ! empty( $campaign->push_target_data ) ) {
								$decoded_target = json_decode( $campaign->push_target_data, true );
								if ( is_array( $decoded_target ) ) {
									$current_push_target_data = $decoded_target;
								}
							}
							$all_roles = array();
							foreach ( wp_roles()->roles as $slug => $info ) {
								$all_roles[ $slug ] = $info['name'];
							}
							?>
							<div class="bcsend-field-group">
								<label><?php esc_html_e( 'Push Audience', 'beacon-campaign-sender' ); ?></label>
								<div class="bcsend-push-audience">
									<label class="bcsend-radio-label">
										<input type="radio" name="bcsend-push-target-type" value="all_users" <?php checked( $current_push_target_type, 'all_users' ); ?> />
										<?php esc_html_e( 'All App Users', 'beacon-campaign-sender' ); ?>
									</label>
									<label class="bcsend-radio-label">
										<input type="radio" name="bcsend-push-target-type" value="by_role" <?php checked( $current_push_target_type, 'by_role' ); ?> />
										<?php esc_html_e( 'By Role', 'beacon-campaign-sender' ); ?>
									</label>
									<label class="bcsend-radio-label">
										<input type="radio" name="bcsend-push-target-type" value="specific_users" <?php checked( $current_push_target_type, 'specific_users' ); ?> />
										<?php esc_html_e( 'Specific Users', 'beacon-campaign-sender' ); ?>
									</label>
								</div>

								<div id="bcsend-push-role-fields" class="bcsend-push-target-fields" style="<?php echo 'by_role' === $current_push_target_type ? '' : 'display:none;'; ?>">
									<?php foreach ( $all_roles as $slug => $label ) : ?>
										<label class="bcsend-checkbox-label">
											<input type="checkbox" class="bcsend-push-role-checkbox" value="<?php echo esc_attr( $slug ); ?>"
												<?php checked( 'by_role' === $current_push_target_type && in_array( $slug, $current_push_target_data, true ) ); ?> />
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</div>

								<div id="bcsend-push-user-fields" class="bcsend-push-target-fields" style="<?php echo 'specific_users' === $current_push_target_type ? '' : 'display:none;'; ?>">
									<input type="text" id="bcsend-push-user-search" class="large-text" placeholder="<?php esc_attr_e( 'Search users by name or email...', 'beacon-campaign-sender' ); ?>" autocomplete="off" />
									<div id="bcsend-push-user-results" style="display:none;"></div>
									<div id="bcsend-push-selected-users" data-initial="<?php echo 'specific_users' === $current_push_target_type ? esc_attr( wp_json_encode( $current_push_target_data ) ) : '[]'; ?>"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Social Posting -->
			<div class="bcsend-field-group bcsend-social-section">
				<h3>
					<label for="bcsend-send-social" class="bcsend-push-toggle">
						<input type="checkbox"
								id="bcsend-send-social"
								<?php checked( $is_editing && ! empty( $campaign->send_social ) ); ?>
						/>
						<?php esc_html_e( 'Include Social Posts', 'beacon-campaign-sender' ); ?>
					</label>
				</h3>

				<div class="bcsend-social-fields" id="bcsend-social-fields" style="display:none;">
					<input type="hidden" id="bcsend-social-post-mode" value="<?php echo esc_attr( $social_post_mode ); ?>" />
					<p class="description">
						<?php echo 'single' === $social_post_mode ? esc_html__( 'Choose the social accounts for one shared Zernio post.', 'beacon-campaign-sender' ) : esc_html__( 'Choose the social platforms you want to publish alongside this campaign, then review copy, media, and links for each platform before scheduling.', 'beacon-campaign-sender' ); ?>
					</p>
					<p class="description" id="bcsend-social-media-summary">
						<?php esc_html_e( 'Content Library images are available as optional social media fallbacks, but each platform card can now keep its own media selection.', 'beacon-campaign-sender' ); ?>
					</p>

					<?php if ( empty( $social_accounts ) ) : ?>
						<p class="description"><?php esc_html_e( 'No Zernio accounts have been synced yet. Go to Settings > Social and sync your connected accounts first.', 'beacon-campaign-sender' ); ?></p>
					<?php else : ?>
						<div id="bcsend-social-shared-fields" class="bcsend-social-shared-fields" data-initial-media="<?php echo esc_attr( wp_json_encode( $shared_social_media ) ); ?>" style="<?php echo 'single' === $social_post_mode ? '' : 'display:none;'; ?>">
							<div class="bcsend-field-group">
								<label for="bcsend-social-content-shared"><?php esc_html_e( 'Social Copy', 'beacon-campaign-sender' ); ?></label>
								<textarea id="bcsend-social-content-shared"
										class="large-text bcsend-social-shared-textarea"
										rows="4"
										placeholder="<?php esc_attr_e( 'Write one post for all selected social accounts', 'beacon-campaign-sender' ); ?>"><?php echo esc_textarea( $shared_social_content ); ?></textarea>
								<span class="bcsend-char-counter">
									<span id="bcsend-social-shared-count"><?php echo esc_html( mb_strlen( $shared_social_content ) ); ?></span>/<span id="bcsend-social-shared-max">280</span>
								</span>
							</div>

							<div class="bcsend-field-group bcsend-social-link-group">
								<label for="bcsend-social-link-mode-shared"><?php esc_html_e( 'Link Handling', 'beacon-campaign-sender' ); ?></label>
								<select id="bcsend-social-link-mode-shared" class="large-text bcsend-social-link-mode" data-platform="shared">
									<option value="none" <?php selected( $shared_link_mode, 'none' ); ?>><?php esc_html_e( 'No link', 'beacon-campaign-sender' ); ?></option>
									<option value="product" <?php selected( $shared_link_mode, 'product' ); ?>><?php esc_html_e( 'Product URL', 'beacon-campaign-sender' ); ?></option>
									<option value="homepage" <?php selected( $shared_link_mode, 'homepage' ); ?>><?php esc_html_e( 'Homepage URL', 'beacon-campaign-sender' ); ?></option>
									<option value="custom" <?php selected( $shared_link_mode, 'custom' ); ?>><?php esc_html_e( 'Custom URL', 'beacon-campaign-sender' ); ?></option>
									<option value="link_in_bio" <?php selected( $shared_link_mode, 'link_in_bio' ); ?>><?php esc_html_e( 'Link in bio', 'beacon-campaign-sender' ); ?></option>
								</select>
								<input type="url"
										id="bcsend-social-link-url-shared"
										class="large-text bcsend-social-link-url"
										data-platform="shared"
										value="<?php echo esc_attr( $shared_link_url ); ?>"
										placeholder="<?php esc_attr_e( 'Resolved URL will appear here', 'beacon-campaign-sender' ); ?>" />
								<p class="description bcsend-social-link-help" data-platform="shared"></p>
							</div>

							<div class="bcsend-field-group bcsend-social-media-group">
								<label><?php esc_html_e( 'Media', 'beacon-campaign-sender' ); ?></label>
								<div class="bcsend-social-media-actions">
									<button type="button" class="button bcsend-social-media-pick" data-platform="shared">
										<?php esc_html_e( 'Choose Images', 'beacon-campaign-sender' ); ?>
									</button>
									<button type="button" class="button-link-delete bcsend-social-media-clear" data-platform="shared">
										<?php esc_html_e( 'Clear', 'beacon-campaign-sender' ); ?>
									</button>
								</div>
								<div class="bcsend-social-media-list" data-platform="shared"></div>
								<p class="description bcsend-social-media-help" data-platform="shared"></p>
							</div>
						</div>
						<?php foreach ( $social_platforms_meta as $platform_slug => $platform_meta ) : ?>
							<?php
							$platform_accounts = array_values(
								array_filter(
									$social_accounts,
									static function ( $account ) use ( $platform_slug ) {
										return isset( $account['platform'] ) && $platform_slug === $account['platform'];
									}
								)
							);
							$current_social    = isset( $social_posts_index[ $platform_slug ] ) ? $social_posts_index[ $platform_slug ] : null;
							$is_checked        = ! empty( $current_social );
							$current_media     = array();
							if ( $current_social && ! empty( $current_social->media_items ) ) {
								$decoded_media = json_decode( $current_social->media_items, true );
								$current_media = is_array( $decoded_media ) ? $decoded_media : array();
							}
							$current_link_mode  = $current_social && ! empty( $current_social->link_mode ) ? $current_social->link_mode : 'none';
							$current_link_url   = $current_social && ! empty( $current_social->link_url ) ? $current_social->link_url : '';
							$current_link_label = $current_social && ! empty( $current_social->link_label ) ? $current_social->link_label : '';
							$current_content    = $current_social && isset( $current_social->content ) ? Bcsend_Social_Workflow::normalize_post_content( $current_social->content ) : '';
							if ( empty( $platform_accounts ) && ! $is_checked ) {
								continue;
							}
							?>
							<div class="bcsend-social-platform-block"
								data-platform="<?php echo esc_attr( $platform_slug ); ?>"
								data-initial-media="<?php echo esc_attr( wp_json_encode( $current_media ) ); ?>"
								data-initial-link-label="<?php echo esc_attr( $current_link_label ); ?>">
								<label class="bcsend-social-platform-toggle">
									<input type="checkbox"
											class="bcsend-social-platform-enabled"
											data-platform="<?php echo esc_attr( $platform_slug ); ?>"
											<?php checked( $is_checked ); ?> />
									<?php echo esc_html( $platform_meta['label'] ); ?>
								</label>

								<div class="bcsend-social-platform-content" data-platform="<?php echo esc_attr( $platform_slug ); ?>" style="<?php echo $is_checked ? 'display:block;' : 'display:none;'; ?>">
									<div class="bcsend-field-group">
										<label for="bcsend-social-account-<?php echo esc_attr( $platform_slug ); ?>"><?php esc_html_e( 'Account', 'beacon-campaign-sender' ); ?></label>
										<select id="bcsend-social-account-<?php echo esc_attr( $platform_slug ); ?>"
												class="large-text bcsend-social-account-select"
												data-platform="<?php echo esc_attr( $platform_slug ); ?>">
											<option value=""><?php esc_html_e( 'Select account', 'beacon-campaign-sender' ); ?></option>
											<?php foreach ( $platform_accounts as $account ) : ?>
												<?php
												$account_id = '';
												if ( isset( $account['id'] ) ) {
													$account_id = (string) $account['id'];
												} elseif ( isset( $account['_id'] ) ) {
													$account_id = (string) $account['_id'];
												} elseif ( isset( $account['accountId'] ) ) {
													$account_id = (string) $account['accountId'];
												} elseif ( isset( $account['account_id'] ) ) {
													$account_id = (string) $account['account_id'];
												} elseif ( isset( $account['uuid'] ) ) {
													$account_id = (string) $account['uuid'];
												}

												$account_label = isset( $account['username'] ) ? $account['username'] : ( isset( $account['handle'] ) ? $account['handle'] : ( isset( $account['name'] ) ? $account['name'] : ( isset( $account['displayName'] ) ? $account['displayName'] : $account_id ) ) );
												?>
												<option value="<?php echo esc_attr( $account_id ); ?>"
													<?php selected( $current_social && isset( $current_social->account_id ) ? $current_social->account_id : '', $account_id ); ?>>
													<?php echo esc_html( $account_label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="bcsend-field-group">
										<label for="bcsend-social-content-<?php echo esc_attr( $platform_slug ); ?>">
											<?php echo esc_html( sprintf( __( '%s Copy', 'beacon-campaign-sender' ), $platform_meta['label'] ) ); ?>
										</label>
										<textarea id="bcsend-social-content-<?php echo esc_attr( $platform_slug ); ?>"
												class="large-text bcsend-social-textarea"
												data-platform="<?php echo esc_attr( $platform_slug ); ?>"
												data-max="<?php echo esc_attr( $platform_meta['max_chars'] ); ?>"
												rows="4"
												placeholder="<?php echo esc_attr( sprintf( __( 'Write %s copy', 'beacon-campaign-sender' ), $platform_meta['label'] ) ); ?>"><?php echo esc_textarea( $current_content ); ?></textarea>
										<span class="bcsend-char-counter">
											<span class="bcsend-social-char-count" data-platform="<?php echo esc_attr( $platform_slug ); ?>">
												<?php echo esc_html( mb_strlen( $current_content ) ); ?>
											</span>/<?php echo esc_html( $platform_meta['max_chars'] ); ?>
										</span>
									</div>

									<div class="bcsend-field-group bcsend-social-link-group">
										<label for="bcsend-social-link-mode-<?php echo esc_attr( $platform_slug ); ?>"><?php esc_html_e( 'Link Handling', 'beacon-campaign-sender' ); ?></label>
										<select id="bcsend-social-link-mode-<?php echo esc_attr( $platform_slug ); ?>"
												class="large-text bcsend-social-link-mode"
												data-platform="<?php echo esc_attr( $platform_slug ); ?>">
											<option value="none" <?php selected( $current_link_mode, 'none' ); ?>><?php esc_html_e( 'No link', 'beacon-campaign-sender' ); ?></option>
											<option value="product" <?php selected( $current_link_mode, 'product' ); ?>><?php esc_html_e( 'Product URL', 'beacon-campaign-sender' ); ?></option>
											<option value="homepage" <?php selected( $current_link_mode, 'homepage' ); ?>><?php esc_html_e( 'Homepage URL', 'beacon-campaign-sender' ); ?></option>
											<option value="custom" <?php selected( $current_link_mode, 'custom' ); ?>><?php esc_html_e( 'Custom URL', 'beacon-campaign-sender' ); ?></option>
											<option value="link_in_bio" <?php selected( $current_link_mode, 'link_in_bio' ); ?>><?php esc_html_e( 'Link in bio', 'beacon-campaign-sender' ); ?></option>
										</select>
										<input type="url"
												id="bcsend-social-link-url-<?php echo esc_attr( $platform_slug ); ?>"
												class="large-text bcsend-social-link-url"
												data-platform="<?php echo esc_attr( $platform_slug ); ?>"
												value="<?php echo esc_attr( $current_link_url ); ?>"
												placeholder="<?php esc_attr_e( 'Resolved URL will appear here', 'beacon-campaign-sender' ); ?>" />
										<p class="description bcsend-social-link-help" data-platform="<?php echo esc_attr( $platform_slug ); ?>"></p>
									</div>

									<div class="bcsend-field-group bcsend-social-media-group">
										<label><?php esc_html_e( 'Media', 'beacon-campaign-sender' ); ?></label>
										<div class="bcsend-social-media-actions">
											<button type="button"
													class="button bcsend-social-media-pick"
													data-platform="<?php echo esc_attr( $platform_slug ); ?>">
												<?php esc_html_e( 'Choose Platform-Specific Images', 'beacon-campaign-sender' ); ?>
											</button>
											<button type="button"
													class="button-link-delete bcsend-social-media-clear"
													data-platform="<?php echo esc_attr( $platform_slug ); ?>">
												<?php esc_html_e( 'Clear', 'beacon-campaign-sender' ); ?>
											</button>
										</div>
										<div class="bcsend-social-media-list" data-platform="<?php echo esc_attr( $platform_slug ); ?>"></div>
										<p class="description bcsend-social-media-help" data-platform="<?php echo esc_attr( $platform_slug ); ?>"></p>
									</div>

									<div class="bcsend-social-platform-status" data-platform="<?php echo esc_attr( $platform_slug ); ?>"></div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>

					<button type="button" class="button" id="bcsend-regenerate-social">
						<?php esc_html_e( 'Regenerate Social', 'beacon-campaign-sender' ); ?>
					</button>
					<span id="bcsend-regen-social-status" class="bcsend-inline-status"></span>
				</div>
			</div>

			<!-- Audience -->
			<div class="bcsend-field-group">
				<label for="bcsend-segment"><?php esc_html_e( 'Audience Segment', 'beacon-campaign-sender' ); ?></label>
				<select id="bcsend-segment" class="large-text">
					<option value=""><?php esc_html_e( 'Select an audience segment', 'beacon-campaign-sender' ); ?></option>
					<?php foreach ( $segments as $segment ) : ?>
						<option value="<?php echo esc_attr( $segment->id ); ?>"
							<?php
							if ( $is_editing && ! empty( $campaign->segment_id ) ) {
								selected( (int) $campaign->segment_id, (int) $segment->id );
							}
							?>
						>
							<?php
							echo esc_html(
								sprintf(
									'%s (%s)',
									$segment->name,
									number_format_i18n( (int) $segment->contact_count )
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Schedule -->
			<div class="bcsend-field-group bcsend-schedule-row">
				<label><?php esc_html_e( 'Schedule', 'beacon-campaign-sender' ); ?></label>
				<div class="bcsend-schedule-inputs">
					<input type="date"
							id="bcsend-schedule-date"
							value="
							<?php
							if ( $is_editing && ! empty( $campaign->scheduled_at ) ) {
								echo esc_attr( gmdate( 'Y-m-d', strtotime( $campaign->scheduled_at ) ) );
							}
							?>
							" />
					<input type="time"
							id="bcsend-schedule-time"
							value="
							<?php
							if ( $is_editing && ! empty( $campaign->scheduled_at ) ) {
								echo esc_attr( gmdate( 'H:i', strtotime( $campaign->scheduled_at ) ) );
							}
							?>
							" />
					<span class="bcsend-tz-label" id="bcsend-detected-tz"></span>
				</div>
			</div>

			<!-- Campaign Name -->
			<div class="bcsend-field-group">
				<label for="bcsend-campaign-name"><?php esc_html_e( 'Campaign Name', 'beacon-campaign-sender' ); ?></label>
				<input type="text"
						id="bcsend-campaign-name"
						class="large-text"
						value="<?php echo $is_editing ? esc_attr( $campaign->name ) : ''; ?>"
						placeholder="<?php esc_attr_e( 'Internal name for this campaign', 'beacon-campaign-sender' ); ?>" />
			</div>
		</div>
	</div>

	<!-- Sticky Bottom Bar -->
	<div class="bcsend-composer-bottom-bar">
		<div class="bcsend-bottom-bar-inner">
			<span id="bcsend-save-status" class="bcsend-inline-status"></span>
			<button type="button" class="button" id="bcsend-send-test-email" title="<?php esc_attr_e( 'Send a test email to yourself via Brevo', 'beacon-campaign-sender' ); ?>">
				<?php esc_html_e( 'Send Test Email', 'beacon-campaign-sender' ); ?>
			</button>
			<button type="button" class="button" id="bcsend-save-as-template" title="<?php esc_attr_e( 'Save the current email HTML as a reusable template', 'beacon-campaign-sender' ); ?>">
				<?php esc_html_e( 'Save as Template', 'beacon-campaign-sender' ); ?>
			</button>
			<button type="button" class="button button-secondary" id="bcsend-save-draft">
				<?php esc_html_e( 'Save Draft', 'beacon-campaign-sender' ); ?>
			</button>
			<button type="button" class="button button-primary" id="bcsend-approve-schedule">
				<?php esc_html_e( 'Approve & Schedule', 'beacon-campaign-sender' ); ?>
			</button>
		</div>
	</div>
</div>
