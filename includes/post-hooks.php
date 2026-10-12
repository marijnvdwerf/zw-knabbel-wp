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

const STORY_SYNC_RETRY_HOOK = 'knabbel_retry_story_sync';
// Delays in seconds before each automatic retry of a failed sync; the count bounds the retries.
const STORY_SYNC_RETRY_DELAYS = array( 300, 1800, 7200, 21600 );
// Seconds after which a sync lock counts as abandoned. A sync makes at most three Babbel
// calls, each with up to two logins and two requests of 30 seconds: 360 seconds.
const STORY_SYNC_LOCK_TTL      = 600;
const STORY_SYNC_DEFERRED_HOOK = 'knabbel_deferred_story_sync';

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

	add_action( STORY_SYNC_RETRY_HOOK, __NAMESPACE__ . '\\retry_story_sync', 10, 1 );
	add_action( STORY_SYNC_DEFERRED_HOOK, __NAMESPACE__ . '\\retry_story_sync', 10, 1 );
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
 * A sync that cannot get the post's lock in time queues itself instead.
 *
 * @since 0.5.0
 * @since 0.9.0 Removes the story when a post leaves publish, compares the post
 *              with what Babbel last confirmed and serializes syncs per post.
 *
 * @param int           $post_id     Post ID.
 * @param \WP_Post|null $post_before Post state before a save, when available.
 */
function sync_story_state( int $post_id, ?\WP_Post $post_before = null ): void {
	if ( ! with_story_sync_lock( $post_id, static fn () => reconcile_story_state( $post_id, $post_before ) ) ) {
		defer_story_sync( $post_id );
	}
}

/**
 * Reconciles story state while holding the post's sync lock.
 *
 * Which story should exist follows from the post's status and checkbox. What
 * the story should contain follows from the post and its stored window base,
 * never from what a single save changed, so syncs that run late or out of
 * order still send the post as it is. The previous post only picks a message.
 *
 * @since 0.9.0
 *
 * @param int           $post_id     Post ID.
 * @param \WP_Post|null $post_before Post state before a save, when available.
 * @return bool True when Babbel was called.
 */
function reconcile_story_state( int $post_id, ?\WP_Post $post_before ): bool {
	$post = get_post( $post_id );
	// Revisions and autosaves have post type 'revision', so this also excludes them.
	if ( ! $post || 'post' !== $post->post_type ) {
		return false;
	}

	$send_to_babbel = (bool) get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true );
	$state          = get_story_state( $post_id );
	$status         = $state['status'] ?? '';
	$story_id       = (string) ( $state['story_id'] ?? '' );
	$active_status  = in_array( $post->post_status, array( 'publish', 'future' ), true );
	$is_untrash     = null !== $post_before && 'trash' === $post_before->post_status && 'trash' !== $post->post_status;
	$is_rescheduled = null !== $post_before && 'publish' === $post_before->post_status && 'future' === $post->post_status;
	// Left publish/future for draft, pending or private; trash is handled separately. Without
	// the previous post (checkbox events, retries, deferred syncs) any inactive post counts.
	$is_deactivated = ! $active_status
		&& ( null === $post_before || in_array( $post_before->post_status, array( 'publish', 'future' ), true ) );
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
			return true;
		} elseif ( in_array( $status, array( StoryStatus::Scheduled->value, StoryStatus::Processing->value ), true ) ) {
			// Only these states can have a pending processing action.
			\as_unschedule_all_actions( 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' );
			delete_post_meta( $post_id, '_zw_knabbel_story_state' );
		}
		return false;
	}

	$should_restore = $is_untrash || $is_activated || ( null === $post_before && $active_status );
	if ( $story_id && StoryStatus::Deleted->value === $status && $should_restore ) {
		restore_and_sync_story( $post_id, $story_id, $post );
		return true;
	}

	if ( ! $active_status ) {
		return false;
	}

	$stable_statuses = array(
		StoryStatus::Sent->value,
		StoryStatus::Scheduled->value,
		StoryStatus::Processing->value,
		StoryStatus::Deleted->value,
	);
	if ( ! in_array( $status, $stable_statuses, true ) ) {
		schedule_story_processing( $post_id );
		return false;
	}

	if ( ! $story_id || StoryStatus::Sent->value !== $status ) {
		return false;
	}

	if ( $is_rescheduled ) {
		$message = __( 'Story updated (post rescheduled)', 'zw-knabbel-wp' );
	} elseif ( null !== $post_before && 'future' === $post_before->post_status && 'publish' === $post->post_status ) {
		$message = __( 'Story updated (post published)', 'zw-knabbel-wp' );
	} else {
		$message = __( 'Story updated in Babbel', 'zw-knabbel-wp' );
	}

	return push_story_changes( $post_id, $story_id, $post, $message );
}

/**
 * Returns the story fields a post calls for.
 *
 * A scheduled post's window starts from its post date. A published post's
 * window keeps the base it got when the plugin first saw it published (the
 * publication or the moment Radio News was enabled), until an editor moves
 * the post to another calendar day; a time-only change never moves it (#23).
 *
 * @since 0.9.0
 *
 * @param \WP_Post             $post        The post.
 * @param array<string, mixed> $state       Story state data.
 * @param array<string, mixed> $bookkeeping Receives the window base and post date to store.
 * @return array<string, mixed> Title, start and end date, and weekdays.
 *
 * @phpstan-param StoryState $state
 * @phpstan-param-out StoryStateUpdate $bookkeeping
 * @phpstan-return StoryUpdateData
 */
function desired_story_fields( \WP_Post $post, array $state, array &$bookkeeping ): array {
	$seen = $state['post_date_seen'] ?? null;
	if ( 'future' === $post->post_status ) {
		$base        = $post->post_date;
		$window_base = null;
	} elseif ( is_string( $seen ) && substr( $seen, 0, 10 ) !== substr( $post->post_date, 0, 10 ) ) {
		$base        = $post->post_date;
		$window_base = $base;
	} elseif ( is_string( $state['window_base'] ?? null ) ) {
		$base        = $state['window_base'];
		$window_base = $base;
	} else {
		$base        = current_time( 'mysql' );
		$window_base = $base;
	}

	$bookkeeping = array(
		'window_base'    => $window_base,
		'post_date_seen' => $post->post_date,
	);

	return build_full_story_update( $post->post_title, $base );
}

/**
 * Returns the story fields Babbel last confirmed.
 *
 * @since 0.9.0
 *
 * @param array<string, mixed> $state Story state data, validated because it is stored post meta.
 * @return array<string, mixed>|null The confirmed fields, or null when none are recorded.
 *
 * @phpstan-return StoryUpdateData|null
 */
function get_synced_story_fields( array $state ): ?array {
	$stored = $state['synced'] ?? null;
	if (
		! is_array( $stored )
		|| ! is_string( $stored['title'] ?? null )
		|| ! is_string( $stored['start_date'] ?? null )
		|| ! is_string( $stored['end_date'] ?? null )
		|| ! is_int( $stored['weekdays'] ?? null )
	) {
		return null;
	}

	return array(
		'title'      => $stored['title'],
		'start_date' => $stored['start_date'],
		'end_date'   => $stored['end_date'],
		'weekdays'   => $stored['weekdays'],
	);
}

/**
 * Reads a story's fields from a Babbel story response.
 *
 * @since 0.9.0
 *
 * @param mixed $story Decoded Babbel story.
 * @return array<string, mixed>|null Title, dates and weekdays, or null when the response lacks them.
 *
 * @phpstan-return StoryUpdateData|null
 */
function story_fields_from_babbel( mixed $story ): ?array {
	if ( ! is_array( $story ) ) {
		return null;
	}

	// Babbel v0.17 returns plain dates; older responses carried a time as well.
	return get_synced_story_fields(
		array(
			'synced' => array(
				'title'      => $story['title'] ?? null,
				'start_date' => is_string( $story['start_date'] ?? null ) ? substr( $story['start_date'], 0, 10 ) : null,
				'end_date'   => is_string( $story['end_date'] ?? null ) ? substr( $story['end_date'], 0, 10 ) : null,
				'weekdays'   => $story['weekdays'] ?? null,
			),
		)
	);
}

/**
 * Sends what differs between the post and what Babbel last confirmed.
 *
 * A story synced before this snapshot existed is read from Babbel once. Its
 * current window then becomes the base, so upgrading never moves a window.
 *
 * @since 0.9.0
 *
 * @param int      $post_id         Post ID.
 * @param string   $story_id        Babbel story ID.
 * @param \WP_Post $post            The post.
 * @param string   $success_message Translated message for the story state on success.
 * @return bool True when Babbel was called.
 */
function push_story_changes( int $post_id, string $story_id, \WP_Post $post, string $success_message ): bool {
	$state  = get_story_state( $post_id );
	$synced = get_synced_story_fields( $state );
	if ( null === $synced ) {
		$fetched = babbel_get_story( $story_id );
		$synced  = $fetched['success'] ? story_fields_from_babbel( $fetched['story'] ?? null ) : null;
		if ( null === $synced ) {
			log(
				'error',
				'PostHooks',
				'Could not read the story from Babbel',
				array(
					'post_id'  => $post_id,
					'story_id' => $story_id,
					'error'    => $fetched['message'],
				)
			);
			update_story_state( $post_id, array( 'last_sync_error' => build_story_sync_error( $fetched['message'], 'update' ) ) );
			schedule_story_sync_retry( $post_id );
			return true;
		}

		$migrated = array(
			'synced'         => $synced,
			'post_date_seen' => $post->post_date,
		);
		if ( 'publish' === $post->post_status ) {
			$start_offset            = (int) get_plugin_settings()['start_days_offset'];
			$base                    = ( new \DateTimeImmutable( $synced['start_date'], wp_timezone() ) )->modify( "-{$start_offset} day" );
			$migrated['window_base'] = $base->format( 'Y-m-d H:i:s' );
		}
		update_story_state( $post_id, $migrated );
		$state = get_story_state( $post_id );
	}

	$bookkeeping = array();
	$desired     = desired_story_fields( $post, $state, $bookkeeping );
	if ( $desired === $synced ) {
		// Babbel already matches the post, so any earlier failure is moot.
		mark_story_synced( $post_id, $bookkeeping );
		return false;
	}

	// The window fields travel together, so Babbel never mixes two windows.
	$update_data = $desired['title'] !== $synced['title'] ? array( 'title' => $desired['title'] ) : array();
	if ( array_diff_key( $desired, array( 'title' => true ) ) !== array_diff_key( $synced, array( 'title' => true ) ) ) {
		$update_data = $desired;
	}

	push_story_update( $post_id, $story_id, $update_data, $success_message, $bookkeeping );
	return true;
}

/**
 * Retries a failed synchronization from the current post and story state.
 *
 * Runs from Action Scheduler, for a deferred sync and for "Save and sync
 * again". It works like a save without a previous post: it deletes,
 * restores or sends what differs from Babbel, never what the post no longer
 * wants. An error that is left without anything to send is cleared.
 *
 * @since 0.9.0
 *
 * @param int $post_id Post ID.
 */
function retry_story_sync( int $post_id ): void {
	if ( ! with_story_sync_lock( $post_id, static fn () => run_story_sync_retry( $post_id ) ) ) {
		defer_story_sync( $post_id );
	}
}

/**
 * Retries a failed synchronization while holding the post's sync lock.
 *
 * @since 0.9.0
 *
 * @param int $post_id Post ID.
 */
function run_story_sync_retry( int $post_id ): void {
	if ( reconcile_story_state( $post_id, null ) ) {
		return;
	}

	$error = get_story_sync_error( get_story_state( $post_id ) );
	if ( null !== $error && 'create' !== $error['operation'] ) {
		// Nothing was left to write, so the failed operation is no longer wanted.
		mark_story_synced( $post_id );
	}
}

/**
 * Runs a callback while holding the post's sync lock.
 *
 * Syncs and retries of one post read the story state, talk to Babbel and
 * write the result. The lock serializes them across requests, so an older
 * result can never overwrite or clear what a newer sync recorded. The post
 * and its meta are read fresh once the lock is held.
 *
 * @since 0.9.0
 *
 * @param int      $post_id  Post ID.
 * @param callable $callback Work to run.
 * @return bool True when the callback ran, false when the lock stayed busy.
 */
function with_story_sync_lock( int $post_id, callable $callback ): bool {
	static $held = array();
	if ( isset( $held[ $post_id ] ) ) {
		$callback();
		return true;
	}

	$token = acquire_story_sync_lock( $post_id );
	if ( null === $token ) {
		return false;
	}

	$held[ $post_id ] = true;
	try {
		clean_post_cache( $post_id );
		$callback();
	} finally {
		unset( $held[ $post_id ] );
		release_story_sync_lock( $post_id, $token );
	}

	return true;
}

/**
 * Takes the post's sync lock, waiting while another request holds it.
 *
 * The lock is an options row holding its expiry and an owner token. Inserting
 * it is atomic, so only one request can hold it. A row past its expiry
 * belongs to a request that died and is replaced.
 *
 * @since 0.9.0
 *
 * @param int $post_id Post ID.
 * @return string|null The owner token, or null when the lock stayed busy.
 */
function acquire_story_sync_lock( int $post_id ): ?string {
	global $wpdb;

	$name  = 'knabbel_sync_lock_' . $post_id;
	$token = wp_generate_uuid4();
	/**
	 * Filters how long a sync waits for another sync of the same post before it defers itself.
	 *
	 * @since 0.9.0
	 *
	 * @param int $seconds Maximum wait in seconds.
	 */
	$deadline = microtime( true ) + (int) apply_filters( 'knabbel_sync_lock_wait', 10 );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic insert is the lock; the options cache must not answer for it.
	do {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST( SUBSTRING_INDEX( option_value, ':', 1 ) AS UNSIGNED ) < %d",
				$name,
				time()
			)
		);
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$name,
				( time() + STORY_SYNC_LOCK_TTL ) . ':' . $token
			)
		);
		if ( 1 === $inserted ) {
			return $token;
		}
		usleep( 250000 );
	} while ( microtime( true ) < $deadline );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return null;
}

/**
 * Releases the post's sync lock if this request still owns it.
 *
 * @since 0.9.0
 *
 * @param int    $post_id Post ID.
 * @param string $token   Owner token from acquire_story_sync_lock().
 */
function release_story_sync_lock( int $post_id, string $token ): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See acquire_story_sync_lock().
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND SUBSTRING_INDEX( option_value, ':', -1 ) = %s",
			'knabbel_sync_lock_' . $post_id,
			$token
		)
	);
}

/**
 * Queues a sync for later because another sync of the post is running.
 *
 * The queued sync works from the post and story state as they are when it
 * runs, so nothing about this save needs to be kept.
 *
 * @since 0.9.0
 *
 * @param int $post_id Post ID.
 */
function defer_story_sync( int $post_id ): void {
	log( 'error', 'PostHooks', 'Another Babbel sync of this post is running; deferred this one', array( 'post_id' => $post_id ) );
	// A deferred sync that is running and defers again must queue its successor.
	if (
		! has_pending_story_action( STORY_SYNC_DEFERRED_HOOK, $post_id )
		&& ! \as_enqueue_async_action( STORY_SYNC_DEFERRED_HOOK, array( 'post_id' => $post_id ), 'zw-knabbel-wp' )
	) {
		log( 'error', 'PostHooks', 'Could not queue a deferred Babbel sync; the next save of this post syncs it', array( 'post_id' => $post_id ) );
	}
}

/**
 * Queues the next bounded retry of a failed synchronization.
 *
 * @since 0.9.0
 *
 * @param int $post_id Post ID.
 */
function schedule_story_sync_retry( int $post_id ): void {
	// Only a pending retry counts: a retry that is running right now schedules its successor.
	if ( has_pending_story_action( STORY_SYNC_RETRY_HOOK, $post_id ) ) {
		return;
	}

	$attempts = (int) ( get_story_state( $post_id )['sync_retry_attempts'] ?? 0 );
	if ( $attempts >= count( STORY_SYNC_RETRY_DELAYS ) ) {
		log( 'error', 'PostHooks', 'Automatic Babbel sync retries exhausted', array( 'post_id' => $post_id ) );
		return;
	}

	$scheduled = \as_schedule_single_action(
		time() + STORY_SYNC_RETRY_DELAYS[ $attempts ],
		STORY_SYNC_RETRY_HOOK,
		array( 'post_id' => $post_id ),
		'zw-knabbel-wp'
	);
	if ( ! $scheduled ) {
		log( 'error', 'PostHooks', 'Could not schedule a Babbel sync retry', array( 'post_id' => $post_id ) );
		return;
	}
	update_story_state( $post_id, array( 'sync_retry_attempts' => $attempts + 1 ) );
}

/**
 * Reports whether a post has a pending action for a hook.
 *
 * Unlike as_has_scheduled_action(), a running action does not count.
 *
 * @since 0.9.0
 *
 * @param string $hook    Action hook.
 * @param int    $post_id Post ID.
 * @return bool True when an action waits to run.
 */
function has_pending_story_action( string $hook, int $post_id ): bool {
	$pending = \as_get_scheduled_actions(
		array(
			'hook'     => $hook,
			'args'     => array( 'post_id' => $post_id ),
			'group'    => 'zw-knabbel-wp',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		),
		'ids'
	);

	return array() !== $pending;
}

/**
 * Clears sync failure bookkeeping after Babbel matches the post.
 *
 * @since 0.9.0
 *
 * @param int                  $post_id Post ID.
 * @param array<string, mixed> $updates Further state updates to apply.
 *
 * @phpstan-param StoryStateUpdate $updates
 */
function mark_story_synced( int $post_id, array $updates = array() ): void {
	\as_unschedule_all_actions( STORY_SYNC_RETRY_HOOK, array( 'post_id' => $post_id ), 'zw-knabbel-wp' );
	update_story_state(
		$post_id,
		array_merge(
			$updates,
			array(
				'last_sync_error'     => null,
				'sync_retry_attempts' => null,
			)
		)
	);
}

/**
 * Restores a story and brings it in line with its post.
 *
 * Uses PATCH to restore (deleted_at), then sends what differs from the
 * restored story, because PATCH only accepts status or deleted_at.
 *
 * @since 0.3.0
 * @since 0.9.0 Sends what differs from the restored story instead of only the
 *              title, and schedules a retry on failure.
 *
 * @param int      $post_id  The post ID.
 * @param string   $story_id The Babbel story ID.
 * @param \WP_Post $post     The post to restore the story for.
 */
function restore_and_sync_story( int $post_id, string $story_id, \WP_Post $post ): void {
	$result = babbel_restore_story( $story_id );
	if ( ! $result['success'] ) {
		update_story_state(
			$post_id,
			array(
				'last_sync_error' => build_story_sync_error( $result['message'], 'restore' ),
			)
		);
		schedule_story_sync_retry( $post_id );
		return;
	}

	// PATCH returns the restored story; without it, the next step reads it from Babbel.
	update_story_state(
		$post_id,
		array(
			'status'  => StoryStatus::Sent->value,
			'message' => __( 'Story restored in Babbel', 'zw-knabbel-wp' ),
			'synced'  => story_fields_from_babbel( $result['story'] ?? null ),
		)
	);
	push_story_changes( $post_id, $story_id, $post, __( 'Story restored in Babbel', 'zw-knabbel-wp' ) );
}

/**
 * Sends an update and records the result.
 *
 * On failure, logs the error while preserving the lifecycle state: the story
 * still exists in Babbel even though this synchronization attempt failed.
 * The next sync compares again with what Babbel last confirmed, so nothing
 * that failed is forgotten.
 *
 * @since 0.5.0
 * @since 0.9.0 Records the confirmed fields and schedules a retry on failure.
 *
 * @param int                  $post_id         Post ID.
 * @param string               $story_id        Babbel story ID.
 * @param array<string, mixed> $update_data     Data to send to babbel_update_story().
 * @param string               $success_message Translated message for the story state on success.
 * @param array<string, mixed> $bookkeeping     Window base and post date to store either way.
 *
 * @phpstan-param StoryUpdateData $update_data
 * @phpstan-param StoryStateUpdate $bookkeeping
 */
function push_story_update( int $post_id, string $story_id, array $update_data, string $success_message, array $bookkeeping ): void {
	$result = babbel_update_story( $story_id, $update_data );
	if ( $result['success'] ) {
		$synced = array_merge( get_synced_story_fields( get_story_state( $post_id ) ) ?? array(), $update_data );
		mark_story_synced(
			$post_id,
			array_merge(
				$bookkeeping,
				array(
					'message' => $success_message,
					'synced'  => $synced,
				)
			)
		);
		return;
	}

	log(
		'error',
		'PostHooks',
		'Failed to update story in Babbel',
		array(
			'post_id'  => $post_id,
			'story_id' => $story_id,
			'fields'   => array_keys( $update_data ),
			'error'    => $result['message'],
		)
	);
	update_story_state(
		$post_id,
		array_merge(
			$bookkeeping,
			array( 'last_sync_error' => build_story_sync_error( $result['message'], 'update' ) )
		)
	);
	schedule_story_sync_retry( $post_id );
}

/**
 * Deletes a story and updates its local state.
 *
 * @since 0.5.0
 * @since 0.9.0 Schedules a retry on failure and drops the window base on success.
 *
 * @param int    $post_id         Post ID.
 * @param string $story_id        Babbel story ID.
 * @param string $success_message Translated message for the story state on success.
 * @param string $log_context     Machine-readable context for failure logs.
 */
function push_story_delete( int $post_id, string $story_id, string $success_message, string $log_context ): void {
	$result = babbel_delete_story( $story_id );
	if ( $result['success'] ) {
		// A later publication starts a new window, so the old base is dropped.
		mark_story_synced(
			$post_id,
			array(
				'status'      => StoryStatus::Deleted->value,
				'message'     => $success_message,
				'window_base' => null,
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
	schedule_story_sync_retry( $post_id );
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
