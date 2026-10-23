<?php
/**
 * Block editor integration.
 *
 * Exposes the Radionieuws checkbox and the Babbel sync status to the block
 * editor. The classic editor keeps using the submit box in admin/metabox.php,
 * which stores the same meta key.
 *
 * @package KnabbelWP
 * @since   0.9.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the block editor hooks.
 *
 * Runs in all contexts: REST requests are not admin requests.
 *
 * @since 0.9.0
 */
function block_editor_init(): void {
	register_editor_meta();

	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_status_rest_field' );
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\block_editor_enqueue_assets' );
}

/**
 * Registers the Radionieuws meta key so the block editor can read and write it.
 *
 * The submit box stores 1 or 0 and the sanitize callback keeps REST writes in
 * that format. Writes reach sync_story_state() through the meta hooks in
 * post-hooks.php, exactly like a classic save does.
 *
 * @since 0.9.0
 */
function register_editor_meta(): void {
	register_post_meta(
		'post',
		'_zw_knabbel_send_to_babbel',
		array(
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'show_in_rest'      => array(
				// Protected key: only expose it to the editor, not to the public view context.
				'schema' => array( 'context' => array( 'edit' ) ),
			),
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_send_to_babbel',
			'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
		)
	);
}

/**
 * Normalizes the Radionieuws meta value to the 1 or 0 the submit box stores.
 *
 * Accepts the boolean sent by REST as well as '0' or '1' from WP-CLI.
 *
 * @since 0.9.0
 *
 * @param mixed $value Meta value.
 * @return int 1 when enabled, 0 otherwise.
 */
function sanitize_send_to_babbel( mixed $value ): int {
	return is_scalar( $value ) && rest_sanitize_boolean( $value ) ? 1 : 0;
}

/**
 * Registers the read-only Babbel sync status REST field.
 *
 * @since 0.9.0
 */
function register_status_rest_field(): void {
	register_rest_field(
		'post',
		'knabbel_status',
		array(
			'get_callback' => static fn( array $post ): ?array => get_editor_status( (int) $post['id'] ),
			'schema'       => array(
				'description' => __( 'Babbel sync status of the post.', 'zw-knabbel-wp' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'edit' ),
				'readonly'    => true,
				'properties'  => array(
					'status'        => array( 'type' => 'string' ),
					'status_label'  => array( 'type' => 'string' ),
					'error_message' => array( 'type' => array( 'string', 'null' ) ),
				),
			),
		)
	);
}

/**
 * Returns the sync status shown in the block editor.
 *
 * @since 0.9.0
 *
 * @param int $post_id Post ID.
 * @return array{status: string, status_label: string, error_message: string|null}|null Status, or null without a story or permission.
 */
function get_editor_status( int $post_id ): ?array {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return null;
	}

	$state = get_story_state( $post_id );
	$value = (string) ( $state['status'] ?? '' );
	if ( '' === $value ) {
		return null;
	}

	$sync_error = get_story_sync_error( $state );

	return array(
		'status'        => $value,
		'status_label'  => StoryStatus::tryFrom( $value )?->label() ?? $value,
		'error_message' => $sync_error['message'] ?? null,
	);
}

/**
 * Enqueues the Radionieuws panel row in the block editor for posts.
 *
 * @since 0.9.0
 */
function block_editor_enqueue_assets(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->base || 'post' !== $screen->post_type ) {
		return;
	}

	wp_enqueue_script(
		'zw-knabbel-wp-editor',
		KNABBEL_PLUGIN_URL . 'assets/editor.js',
		array( 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-plugins' ),
		KNABBEL_VERSION,
		true
	);

	wp_localize_script(
		'zw-knabbel-wp-editor',
		'knabbel_editor',
		array(
			'label'   => __( 'Radio News', 'zw-knabbel-wp' ),
			'warning' => __( 'Babbel sync warning', 'zw-knabbel-wp' ),
			'status'  => __( 'Status', 'zw-knabbel-wp' ),
		)
	);
}
