<?php
/**
 * Admin metabox integration for the post editor.
 *
 * Provides the post editor metabox UI for sending posts to the Babbel API.
 * This file is only loaded in admin context.
 *
 * @package KnabbelWP
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin-only metabox hooks.
 *
 * @since 0.1.0
 */
function metabox_init(): void {
	add_action( 'post_submitbox_misc_actions', __NAMESPACE__ . '\\submitbox_render' );
	add_action( 'add_meta_boxes', __NAMESPACE__ . '\\metabox_add_status' );
	add_action( 'save_post', __NAMESPACE__ . '\\metabox_save' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\metabox_enqueue_assets' );
}

/**
 * Enqueues assets for post edit screens.
 *
 * @since 0.1.0
 *
 * @param string $hook The current admin page hook.
 */
function metabox_enqueue_assets( string $hook ): void {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	wp_enqueue_style(
		'zw-knabbel-wp-admin',
		KNABBEL_PLUGIN_URL . 'assets/admin.css',
		array(),
		KNABBEL_VERSION
	);
}

/**
 * Adds the per-post status metabox in Debug Mode.
 *
 * @since 0.1.0
 */
function metabox_add_status(): void {
	if ( ! debug_enabled() ) {
		return;
	}

	add_meta_box(
		'knabbel-status',
		__( 'Knabbel Status', 'zw-knabbel-wp' ),
		__NAMESPACE__ . '\\metabox_render_status',
		'post',
		'side',
		'default'
	);
}

/**
 * Displays the Radionieuws control in the Publish metabox.
 *
 * @since 0.6.0
 *
 * @param \WP_Post $post The current post object.
 */
function submitbox_render( \WP_Post $post ): void {
	if ( 'post' !== $post->post_type ) {
		return;
	}

	$send_to_babbel = (bool) get_post_meta( $post->ID, '_zw_knabbel_send_to_babbel', true );
	$state          = get_story_state( $post->ID );
	$sync_error     = get_story_sync_error( $state );

	wp_nonce_field( 'knabbel_metabox_nonce', 'knabbel_nonce' );
	?>
	<div class="misc-pub-section misc-pub-knabbel">
		<span class="dashicons dashicons-microphone" aria-hidden="true"></span>
		<label for="knabbel_send_to_babbel"><?php esc_html_e( 'Radio News', 'zw-knabbel-wp' ); ?>:</label>
		<input
			type="checkbox"
			id="knabbel_send_to_babbel"
			name="knabbel_send_to_babbel"
			value="1"
			<?php checked( $send_to_babbel ); ?>
		/>
		<label class="knabbel-submitbox-toggle" for="knabbel_send_to_babbel">
			<span class="knabbel-submitbox-toggle-enabled" aria-hidden="true"><?php esc_html_e( 'Yes', 'zw-knabbel-wp' ); ?></span>
			<span class="knabbel-submitbox-toggle-disabled" aria-hidden="true"><?php esc_html_e( 'No', 'zw-knabbel-wp' ); ?></span>
		</label>

		<?php if ( null !== $sync_error ) : ?>
			<div class="knabbel-sync-warning" role="alert">
				<strong><?php esc_html_e( 'Babbel sync warning', 'zw-knabbel-wp' ); ?></strong>
				<div><?php echo esc_html( $sync_error['message'] ); ?></div>
				<?php if ( ! empty( $state['story_id'] ) && 'create' !== $sync_error['operation'] ) : ?>
					<button type="submit" class="button button-small knabbel-sync-retry" name="knabbel_retry_sync" value="1">
						<?php esc_html_e( 'Save and sync again', 'zw-knabbel-wp' ); ?>
					</button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Displays status details in Debug Mode.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The current post object.
 */
function metabox_render_status( \WP_Post $post ): void {
		$state      = get_post_meta( $post->ID, '_zw_knabbel_story_state', true );
		$status     = is_array( $state ) && ! empty( $state['status'] ) ? $state['status'] : '';
		$story_id   = is_array( $state ) && ! empty( $state['story_id'] ) ? $state['story_id'] : '';
		$updated    = is_array( $state ) && ! empty( $state['status_changed_at'] ) ? $state['status_changed_at'] : '';
		$message    = is_array( $state ) && ! empty( $state['message'] ) ? $state['message'] : '';
		$sync_error = is_array( $state ) ? get_story_sync_error( $state ) : null;
		$scheduled  = \as_next_scheduled_action( 'knabbel_process_story', array( 'post_id' => $post->ID ), 'zw-knabbel-wp' );
	?>
		<ul class="knabbel-status-list">
			<li>
				<strong><?php esc_html_e( 'Status', 'zw-knabbel-wp' ); ?>:</strong>
				<?php
				$status_slug = $status ? sanitize_key( $status ) : 'none';
				$label       = $status ? ( StoryStatus::tryFrom( $status )?->label() ?? $status ) : __( '—', 'zw-knabbel-wp' );
				echo '<span class="knabbel-status-badge ' . esc_attr( $status_slug ) . '">' . esc_html( $label ) . '</span>';
				?>
			</li>
			<?php if ( ! empty( $updated ) ) : ?>
			<li class="knabbel-status-muted">
				<?php esc_html_e( 'Last status change:', 'zw-knabbel-wp' ); ?>
				<?php echo esc_html( format_stored_datetime( $updated ) ); ?>
			</li>
			<?php endif; ?>
			<?php if ( ! empty( $story_id ) ) : ?>
			<li>
				<strong><?php esc_html_e( 'Story ID', 'zw-knabbel-wp' ); ?>:</strong>
				<code><?php echo esc_html( $story_id ); ?></code>
			</li>
			<?php endif; ?>
			<?php if ( ! empty( $scheduled ) ) : ?>
			<li class="knabbel-status-muted">
				<?php esc_html_e( 'Scheduled:', 'zw-knabbel-wp' ); ?>
				<?php
				// Action Scheduler returns UTC timestamp.
				echo esc_html( format_stored_datetime( (int) $scheduled ) );
				?>
			</li>
			<?php endif; ?>
			<?php if ( null !== $sync_error ) : ?>
			<li>
				<strong><?php esc_html_e( 'Last sync error', 'zw-knabbel-wp' ); ?>:</strong>
				<div class="knabbel-sync-warning">
					<?php echo esc_html( $sync_error['message'] ); ?>
					<div class="knabbel-status-muted">
						<?php if ( ! empty( $sync_error['operation'] ) ) : ?>
							<?php esc_html_e( 'Operation', 'zw-knabbel-wp' ); ?>:
							<code><?php echo esc_html( $sync_error['operation'] ); ?></code>
						<?php endif; ?>
						<?php if ( ! empty( $sync_error['occurred_at'] ) ) : ?>
							<?php echo ' · ' . esc_html( format_stored_datetime( $sync_error['occurred_at'] ) ); ?>
						<?php endif; ?>
					</div>
				</div>
			</li>
			<?php endif; ?>
			<?php if ( ! empty( $message ) && null === $sync_error ) : ?>
			<li>
				<strong><?php esc_html_e( 'Message', 'zw-knabbel-wp' ); ?>:</strong>
				<div class="knabbel-pre"><?php echo esc_html( $message ); ?></div>
			</li>
			<?php endif; ?>
		</ul>
		<?php
}

/**
 * Saves the Radionieuws checkbox state.
 *
 * The post-meta and post-save hooks reconcile story state after WordPress has
 * persisted the checkbox and post status. "Save and sync again" submits the
 * whole form, so no edit is lost, and retries a failed sync after the save.
 *
 * @since 0.1.0
 * @since 0.9.0 Handles "Save and sync again".
 *
 * @param int $post_id The post ID being saved.
 */
function metabox_save( int $post_id ): void {
	// Skip revisions - they don't have our metabox.
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	// Only handle 'post' post type - our metabox is only registered there.
	if ( 'post' !== get_post_type( $post_id ) ) {
		return;
	}

	if (
		! isset( $_POST['knabbel_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['knabbel_nonce'] ) ), 'knabbel_metabox_nonce' )
	) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Save new checkbox state.
	$send_to_babbel = isset( $_POST['knabbel_send_to_babbel'] ) ? 1 : 0;
	update_post_meta( $post_id, '_zw_knabbel_send_to_babbel', $send_to_babbel );

	if ( isset( $_POST['knabbel_retry_sync'] ) ) {
		// After the regular sync of this save, which already sends any pending fields.
		add_action(
			'wp_after_insert_post',
			static function ( int $saved_post_id ) use ( $post_id ): void {
				if ( $saved_post_id === $post_id ) {
					retry_story_sync( $post_id );
				}
			},
			20
		);
	}
}
