<?php
/**
 * Global post hooks for story synchronization.
 *
 * These hooks run in all contexts (admin, REST API, CLI, cron) to ensure
 * stories are synced regardless of how posts are modified.
 *
 * @package KnabbelWP
 * @since   0.2.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers post and metadata hooks.
 *
 * @since 0.2.0
 */
function register_post_hooks(): void {
	add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\handle_post_saved', 10, 4 );

	// Meta change hooks for REST API and CLI support.
	add_action( 'updated_post_meta', __NAMESPACE__ . '\\handle_checkbox_meta_changed', 10, 3 );
	add_action( 'added_post_meta', __NAMESPACE__ . '\\handle_checkbox_meta_changed', 10, 3 );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\\handle_checkbox_meta_changed', 10, 3 );
}

/**
 * Reconciles state after a checkbox change.
 *
 * WordPress only fires the added/updated/deleted metadata actions after the
 * requested change has succeeded, so reconciliation can read the current
 * checkbox value directly.
 *
 * @since 0.5.0
 *
 * @param int|int[] $meta_id  Meta ID, or deleted meta IDs.
 * @param int       $post_id  Post ID.
 * @param string    $meta_key Meta key.
 */
function handle_checkbox_meta_changed( $meta_id, $post_id, $meta_key ): void {
	if ( '_zw_knabbel_send_to_babbel' !== $meta_key ) {
		return;
	}

	sync_story_state( $post_id );
}

/**
 * Reconciles story state after a post save.
 *
 * The previous post state lets sync_story_state() distinguish status
 * transitions from ordinary edits.
 *
 * @since 0.2.0
 *
 * @param int           $post_id     Post ID.
 * @param \WP_Post      $post        Post object.
 * @param bool          $update      Whether this is an existing post being updated.
 * @param \WP_Post|null $post_before Post object before the update, or null for new posts.
 */
function handle_post_saved( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
	sync_story_state( $post_id, $post_before );
}

/**
 * Reconciles the WordPress post and Babbel story states.
 *
 * This is the single source of truth for deciding whether a story should be
 * created, restored, updated, deleted, or left unchanged. Hook callbacks only
 * trigger reconciliation after WordPress has persisted the relevant change.
 *
 * @since 0.5.0
 * @since 0.9.0 Removes the story when a post leaves publish, and moves its window when a published post is rescheduled.
 *
 * @param int           $post_id     Post ID.
 * @param \WP_Post|null $post_before Post state before a save, when available.
 */
function sync_story_state( int $post_id, ?\WP_Post $post_before = null ): void {
	$post = get_post( $post_id );
	// Revisions and autosaves have post type 'revision', so this also excludes them.
	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	$send_to_babbel = (bool) get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true );
	$state          = get_story_state( $post_id );
	$status         = $state['status'] ?? '';
	$story_id       = (string) ( $state['story_id'] ?? '' );
	$active_status  = in_array( $post->post_status, array( 'publish', 'future' ), true );
	$is_untrash     = null !== $post_before && 'trash' === $post_before->post_status && 'trash' !== $post->post_status;
	$is_rescheduled = null !== $post_before && 'publish' === $post_before->post_status && 'future' === $post->post_status;
	// Left publish/future for draft, pending or private; trash is handled separately.
	$is_deactivated = null !== $post_before
		&& in_array( $post_before->post_status, array( 'publish', 'future' ), true )
		&& ! $active_status;
	// Status changed into publish/future, except the publish→future demotion.
	$is_activated  = null !== $post_before
		&& $active_status
		&& $post_before->post_status !== $post->post_status
		&& ! $is_rescheduled;
	$should_remove = ! $send_to_babbel || 'trash' === $post->post_status || $is_deactivated;

	if ( $should_remove ) {
		if ( $story_id && in_array( $status, array( StoryStatus::Sent->value, StoryStatus::Error->value ), true ) ) {
			if ( ! $send_to_babbel ) {
				$message = __( 'Story deleted from Babbel', 'zw-knabbel-wp' );
				$context = 'checkbox_disabled';
			} elseif ( 'trash' === $post->post_status ) {
				$message = __( 'Story deleted (post trashed)', 'zw-knabbel-wp' );
				$context = 'post_trashed';
			} elseif ( 'future' === $post_before?->post_status ) {
				$message = __( 'Story deleted (post unscheduled)', 'zw-knabbel-wp' );
				$context = 'post_unscheduled';
			} else {
				$message = __( 'Story deleted (post unpublished)', 'zw-knabbel-wp' );
				$context = 'post_unpublished';
			}

			push_story_delete( $post_id, $story_id, $message, $context );
		} elseif ( in_array( $status, array( StoryStatus::Scheduled->value, StoryStatus::Processing->value ), true ) ) {
			// Only these states can have a pending processing action.
			\as_unschedule_all_actions( 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' );
			delete_post_meta( $post_id, '_zw_knabbel_story_state' );
		}
		return;
	}

	$should_restore = $is_untrash || $is_activated || ( null === $post_before && $active_status );
	if ( $story_id && StoryStatus::Deleted->value === $status && $should_restore ) {
		restore_and_sync_story( $post_id, $story_id, $post->post_title );
		return;
	}

	if ( ! $active_status ) {
		return;
	}

	$stable_statuses = array(
		StoryStatus::Sent->value,
		StoryStatus::Scheduled->value,
		StoryStatus::Processing->value,
		StoryStatus::Deleted->value,
	);
	if ( ! in_array( $status, $stable_statuses, true ) ) {
		schedule_story_processing( $post_id );
		return;
	}

	if ( ! $story_id || StoryStatus::Sent->value !== $status || null === $post_before ) {
		return;
	}

	// An embargo set after publication moves the window to the new publication date.
	if ( $is_rescheduled ) {
		push_story_update(
			$post_id,
			$story_id,
			build_full_story_update( $post->post_title, $post->post_date ),
			__( 'Story updated (post rescheduled)', 'zw-knabbel-wp' ),
			'publish_to_future'
		);
		return;
	}

	if ( 'publish' === $post->post_status && 'future' === $post_before->post_status ) {
		push_story_update(
			$post_id,
			$story_id,
			build_full_story_update( $post->post_title, 'now' ),
			__( 'Story updated (post published)', 'zw-knabbel-wp' ),
			'future_to_publish'
		);
		return;
	}

	if ( $post->post_status === $post_before->post_status ) {
		$update_data = build_story_update_from_changes( $post, $post_before );
		if ( $update_data ) {
			push_story_update(
				$post_id,
				$story_id,
				$update_data,
				__( 'Story updated in Babbel', 'zw-knabbel-wp' ),
				'publish' === $post->post_status ? 'published_post_edit' : 'scheduled_post_edit'
			);
		}
	}
}

/**
 * Restores a story and synchronizes its title.
 *
 * Uses PATCH to restore (deleted_at) then PUT to update the title,
 * matching the Babbel API contract where PATCH only accepts status/deleted_at.
 *
 * @since 0.3.0
 *
 * @param int    $post_id  The post ID.
 * @param string $story_id The Babbel story ID.
 * @param string $title    The current post title to sync.
 * @return array{success: bool, message: string} Response with success status and message.
 */
function restore_and_sync_story( int $post_id, string $story_id, string $title ): array {
	$result = babbel_restore_story( $story_id );
	if ( ! $result['success'] ) {
		update_story_state(
			$post_id,
			array(
				'last_sync_error' => build_story_sync_error( $result['message'], 'restore' ),
			)
		);
		return $result;
	}

	// Sync the current post title via PUT (PATCH only accepts status/deleted_at).
	$title_result = babbel_update_story( $story_id, array( 'title' => $title ) );
	if ( $title_result['success'] ) {
		update_story_state(
			$post_id,
			array(
				'status'          => StoryStatus::Sent->value,
				'message'         => __( 'Story restored in Babbel', 'zw-knabbel-wp' ),
				'last_sync_error' => null,
			)
		);
	} else {
		// Keep 'sent' status - story is restored but title may be stale.
		log(
			'error',
			'PostHooks',
			'Story restored but title sync failed',
			array(
				'post_id'  => $post_id,
				'story_id' => $story_id,
				'error'    => $title_result['message'],
			)
		);
		update_story_state(
			$post_id,
			array(
				'status'          => StoryStatus::Sent->value,
				'message'         => __( 'Story restored in Babbel', 'zw-knabbel-wp' ),
				'last_sync_error' => build_story_sync_error( $title_result['message'], 'restore' ),
			)
		);
	}

	return $result;
}

/**
 * Sends an update and records the result.
 *
 * On failure, logs the error while preserving the lifecycle state: the story
 * still exists in Babbel even though this synchronization attempt failed.
 *
 * @since 0.5.0
 *
 * @param int                  $post_id         Post ID.
 * @param string               $story_id        Babbel story ID.
 * @param array<string, mixed> $update_data     Data to send to babbel_update_story().
 * @param string               $success_message Translated message for the story state on success.
 * @param string               $log_context     Untranslated code-path label for the failure log.
 *
 * @phpstan-param StoryUpdateData $update_data
 */
function push_story_update( int $post_id, string $story_id, array $update_data, string $success_message, string $log_context ): void {
	$result = babbel_update_story( $story_id, $update_data );
	if ( $result['success'] ) {
		update_story_state(
			$post_id,
			array(
				'message'         => $success_message,
				'last_sync_error' => null,
			)
		);
	} else {
		log(
			'error',
			'PostHooks',
			'Failed to update story in Babbel',
			array(
				'post_id'  => $post_id,
				'story_id' => $story_id,
				'context'  => $log_context,
				'fields'   => array_keys( $update_data ),
				'error'    => $result['message'],
			)
		);
		update_story_state(
			$post_id,
			array(
				'last_sync_error' => build_story_sync_error( $result['message'], 'update' ),
			)
		);
	}
}

/**
 * Deletes a story and updates its local state.
 *
 * @since 0.5.0
 *
 * @param int    $post_id         Post ID.
 * @param string $story_id        Babbel story ID.
 * @param string $success_message Translated message for the story state on success.
 * @param string $log_context     Machine-readable context for failure logs.
 */
function push_story_delete( int $post_id, string $story_id, string $success_message, string $log_context ): void {
	$result = babbel_delete_story( $story_id );
	if ( $result['success'] ) {
		update_story_state(
			$post_id,
			array(
				'status'          => StoryStatus::Deleted->value,
				'message'         => $success_message,
				'last_sync_error' => null,
			)
		);
		return;
	}

	log(
		'error',
		'PostHooks',
		'Failed to delete story in Babbel',
		array(
			'post_id'  => $post_id,
			'story_id' => $story_id,
			'context'  => $log_context,
			'error'    => $result['message'],
		)
	);

	update_story_state(
		$post_id,
		array(
			'last_sync_error' => build_story_sync_error( $result['message'], 'delete' ),
		)
	);
}

/**
 * Returns data changed between post versions.
 *
 * Only the calendar day (Y-m-d) of the post date is compared: story dates are
 * day-granular, so a time-only edit can never change the Babbel payload.
 *
 * @since 0.5.0
 *
 * @param \WP_Post $post        Current post object.
 * @param \WP_Post $post_before Post object before the update.
 * @return array<string, mixed>|null Update data for babbel_update_story(), or null if nothing
 *                                   changed. Always includes 'title'; start/end dates and
 *                                   weekdays only when the date changed.
 *
 * @phpstan-return StoryUpdateData|null
 */
function build_story_update_from_changes( \WP_Post $post, \WP_Post $post_before ): ?array {
	$title_changed = $post_before->post_title !== $post->post_title;
	$date_changed  = substr( $post_before->post_date, 0, 10 ) !== substr( $post->post_date, 0, 10 );

	if ( ! $date_changed && ! $title_changed ) {
		return null;
	}

	if ( $date_changed ) {
		return build_full_story_update( $post->post_title, $post->post_date );
	}

	return array( 'title' => $post->post_title );
}

/**
 * Builds a payload from a title and base date.
 *
 * @since 0.5.0
 *
 * @param string $title     The post title.
 * @param string $base_date Base date for calculate_story_dates() (e.g. 'now' or a post date).
 * @return array<string, mixed> Update data for babbel_update_story().
 *
 * @phpstan-return StoryUpdateData
 */
function build_full_story_update( string $title, string $base_date ): array {
	$dates = calculate_story_dates( $base_date );

	return array(
		'title'      => $title,
		'start_date' => $dates['start_date'],
		'end_date'   => $dates['end_date'],
		'weekdays'   => $dates['weekdays'],
	);
}

/**
 * Queues deduplicated story processing.
 *
 * @since 0.2.0
 *
 * @param int $post_id The post ID to process.
 */
function schedule_story_processing( int $post_id ): void {
	// De-dupe scheduling: skip if an event is already queued for this post.
	if ( \as_has_scheduled_action( 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' ) ) {
		update_story_state(
			$post_id,
			array(
				'status'  => StoryStatus::Scheduled->value,
				'message' => __( 'Processing already scheduled', 'zw-knabbel-wp' ),
			)
		);
		return;
	}

	// Schedule async processing via Action Scheduler.
	$scheduled = \as_schedule_single_action(
		time(),
		'knabbel_process_story',
		array( 'post_id' => $post_id ),
		'zw-knabbel-wp'
	);

	if ( $scheduled ) {
		update_story_state(
			$post_id,
			array(
				'status'  => StoryStatus::Scheduled->value,
				'message' => __( 'Processing scheduled', 'zw-knabbel-wp' ),
			)
		);
	} else {
		update_story_state(
			$post_id,
			array(
				'status'  => StoryStatus::Error->value,
				'message' => __( 'Could not schedule action', 'zw-knabbel-wp' ),
			)
		);
	}
}
