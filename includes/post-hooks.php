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
// Seconds after which a sync lock counts as abandoned. A sync makes at most two Babbel
// calls, each with up to two logins and two requests of 30 seconds: 240 seconds.
const STORY_SYNC_LOCK_TTL      = 600;
const STORY_SYNC_DEFERRED_HOOK = 'knabbel_deferred_story_sync';
const STORY_SYNC_DEFERRED_META = '_zw_knabbel_deferred_update';

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
	add_action( STORY_SYNC_DEFERRED_HOOK, __NAMESPACE__ . '\\sync_story_state', 10, 1 );
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
	if ( ! with_story_sync_lock( $post_id, static fn () => reconcile_story_state( $post_id, $post_before ) ) ) {
		defer_story_sync( $post_id, $post_before );
	}
}

/**
 * Reconciles story state while holding the post's sync lock.
 *
 * @since 0.9.0
 *
 * @param int           $post_id     Post ID.
 * @param \WP_Post|null $post_before Post state before a save, when available.
 */
function reconcile_story_state( int $post_id, ?\WP_Post $post_before ): void {
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
	// Left publish/future for draft, pending or private; trash is handled separately. Without
	// the previous post (checkbox events, deferred syncs) any inactive post counts.
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

	if ( ! $story_id || StoryStatus::Sent->value !== $status ) {
		return;
	}

	$change      = null === $post_before ? null : story_update_for_transition( $post, $post_before );
	$update_data = with_pending_story_update( $state, $post->post_title, $change['data'] ?? null );
	if ( null !== $update_data ) {
		push_story_update(
			$post_id,
			$story_id,
			$update_data,
			$change['message'] ?? __( 'Story updated in Babbel', 'zw-knabbel-wp' ),
			$change['context'] ?? 'pending_update'
		);
	}
}

/**
 * Returns the story update a post save calls for.
 *
 * @since 0.9.0
 *
 * @param \WP_Post $post        Current post.
 * @param \WP_Post $post_before Post before the save.
 * @return array{data: array<string, mixed>, message: string, context: string}|null The update, or null when the save changes nothing in Babbel.
 *
 * @phpstan-return array{data: StoryUpdateData, message: string, context: string}|null
 */
function story_update_for_transition( \WP_Post $post, \WP_Post $post_before ): ?array {
	if ( 'future' === $post->post_status && 'publish' === $post_before->post_status ) {
		// An embargo set after publication moves the window to the new publication date.
		return array(
			'data'    => build_full_story_update( $post->post_title, $post->post_date ),
			'message' => __( 'Story updated (post rescheduled)', 'zw-knabbel-wp' ),
			'context' => 'publish_to_future',
		);
	}

	if ( 'publish' === $post->post_status && 'future' === $post_before->post_status ) {
		return array(
			'data'    => build_full_story_update( $post->post_title, 'now' ),
			'message' => __( 'Story updated (post published)', 'zw-knabbel-wp' ),
			'context' => 'future_to_publish',
		);
	}

	$update_data = $post->post_status === $post_before->post_status ? build_story_update_from_changes( $post, $post_before ) : null;
	if ( null === $update_data ) {
		return null;
	}

	return array(
		'data'    => $update_data,
		'message' => __( 'Story updated in Babbel', 'zw-knabbel-wp' ),
		'context' => 'publish' === $post->post_status ? 'published_post_edit' : 'scheduled_post_edit',
	);
}

/**
 * Returns the fields of an earlier failed update.
 *
 * @since 0.9.0
 *
 * @param array<string, mixed> $state Story state data, validated because it is stored post meta.
 * @return array<string, mixed>|null Fields Babbel has not accepted yet, or null when none are pending.
 *
 * @phpstan-return StoryUpdateData|null
 */
function get_pending_story_update( array $state ): ?array {
	$stored = $state['pending_update'] ?? null;
	if ( ! is_array( $stored ) ) {
		return null;
	}

	$pending = array();
	foreach ( array( 'title', 'start_date', 'end_date' ) as $field ) {
		if ( isset( $stored[ $field ] ) && is_string( $stored[ $field ] ) ) {
			$pending[ $field ] = $stored[ $field ];
		}
	}
	if ( isset( $stored['weekdays'] ) && is_int( $stored['weekdays'] ) ) {
		$pending['weekdays'] = $stored['weekdays'];
	}

	return array() === $pending ? null : $pending;
}

/**
 * Adds the fields of an earlier failed update to new update data.
 *
 * Pending fields go along with every later sync until Babbel accepts them, so
 * a later partial update can never report success while older changes are
 * still missing. Newer changes win, and the title is always the current one.
 *
 * @since 0.9.0
 *
 * @param array<string, mixed>      $state       Story state data.
 * @param string                    $title       Current post title.
 * @param array<string, mixed>|null $update_data Update data for this sync, or null when nothing changed.
 * @return array<string, mixed>|null Update data to send, or null when there is nothing to send.
 *
 * @phpstan-param StoryState $state
 * @phpstan-param StoryUpdateData|null $update_data
 * @phpstan-return StoryUpdateData|null
 */
function with_pending_story_update( array $state, string $title, ?array $update_data ): ?array {
	$pending = get_pending_story_update( $state );
	if ( null === $pending ) {
		return $update_data;
	}

	return array_merge( $pending, array( 'title' => $title ), $update_data ?? array() );
}

/**
 * Retries a failed synchronization from the current post and story state.
 *
 * Runs from Action Scheduler and from the editor's "Sync again" action. It
 * works from state rather than from the failed operation, so it never sends
 * what the post no longer wants. It never creates a story or generates text.
 * An update error without pending fields, recorded before they existed, gets
 * the full payload based on the post date.
 *
 * @since 0.9.0
 *
 * @param int $post_id Post ID.
 */
function retry_story_sync( int $post_id ): void {
	if ( ! with_story_sync_lock( $post_id, static fn () => run_story_sync_retry( $post_id ) ) ) {
		defer_story_sync( $post_id, null );
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
	$post     = get_post( $post_id );
	$state    = get_story_state( $post_id );
	$story_id = (string) ( $state['story_id'] ?? '' );
	$error    = get_story_sync_error( $state );
	if ( ! $post || 'post' !== $post->post_type || '' === $story_id || null === $error ) {
		return;
	}

	$status = $state['status'] ?? '';
	$wanted = (bool) get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true )
		&& in_array( $post->post_status, array( 'publish', 'future' ), true );

	if ( StoryStatus::Sent->value === $status && ! $wanted ) {
		push_story_delete( $post_id, $story_id, __( 'Story deleted from Babbel', 'zw-knabbel-wp' ), 'sync_retry' );
		return;
	}

	if ( StoryStatus::Deleted->value === $status && $wanted ) {
		restore_and_sync_story( $post_id, $story_id, $post->post_title );
		return;
	}

	$update_data = with_pending_story_update( $state, $post->post_title, null );
	if ( null === $update_data && in_array( $error['operation'], array( 'update', 'restore' ), true ) ) {
		// An error recorded before pending updates existed lists no fields: send the full payload.
		$update_data = build_full_story_update( $post->post_title, $post->post_date );
	}
	if ( StoryStatus::Sent->value === $status && null !== $update_data ) {
		push_story_update( $post_id, $story_id, $update_data, __( 'Story updated in Babbel', 'zw-knabbel-wp' ), 'sync_retry' );
		return;
	}

	// The failed operation is no longer wanted, so there is nothing left to sync.
	mark_story_synced( $post_id );
}

/**
 * Runs a callback while holding the post's sync lock.
 *
 * Syncs and retries of one post read the story state, talk to Babbel and
 * write the result. The lock serializes them across requests, so an older
 * result can never overwrite or clear what a newer sync recorded. The post
 * and its meta are read fresh once the lock is held, and changes that other
 * requests deferred are taken in first.
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
		absorb_deferred_story_updates( $post_id );
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
 * Sets a sync aside for later because another sync of the post is running.
 *
 * A save's own change is only known now, from the previous post, so it is
 * stored as its own meta row: adding a row never overwrites another
 * request's. A queued sync then takes it in under the lock.
 *
 * @since 0.9.0
 *
 * @param int           $post_id     Post ID.
 * @param \WP_Post|null $post_before Post state before a save, when available.
 */
function defer_story_sync( int $post_id, ?\WP_Post $post_before ): void {
	$post   = get_post( $post_id );
	$change = $post && null !== $post_before ? story_update_for_transition( $post, $post_before ) : null;
	if ( null !== $change ) {
		add_post_meta( $post_id, STORY_SYNC_DEFERRED_META, $change['data'] );
	}

	// A deferred sync that is running and defers again must queue its successor.
	if ( ! has_pending_story_action( STORY_SYNC_DEFERRED_HOOK, $post_id ) ) {
		\as_enqueue_async_action( STORY_SYNC_DEFERRED_HOOK, array( 'post_id' => $post_id ), 'zw-knabbel-wp' );
	}
	log( 'error', 'PostHooks', 'Another Babbel sync of this post is running; deferred this one', array( 'post_id' => $post_id ) );
}

/**
 * Takes deferred changes into the pending update.
 *
 * Runs under the post's sync lock. Rows are read and deleted one by one, so a
 * row another request adds meanwhile stays for the next sync.
 *
 * @since 0.9.0
 *
 * @param int $post_id Post ID.
 */
function absorb_deferred_story_updates( int $post_id ): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rows must be read with their IDs, in insertion order.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id",
			$post_id,
			STORY_SYNC_DEFERRED_META
		)
	);
	if ( array() === $rows ) {
		return;
	}

	$state   = get_story_state( $post_id );
	$pending = array();
	foreach ( $rows as $row ) {
		$fields  = get_pending_story_update( array( 'pending_update' => maybe_unserialize( $row->meta_value ) ) );
		$pending = array_merge( $pending, $fields ?? array() );
		delete_metadata_by_mid( 'post', (int) $row->meta_id );
	}

	$merged = array_merge( get_pending_story_update( $state ) ?? array(), $pending );
	if ( array() !== $merged ) {
		update_story_state( $post_id, array( 'pending_update' => $merged ) );
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
				'pending_update'      => null,
				'sync_retry_attempts' => null,
			)
		)
	);
}

/**
 * Restores a story and synchronizes its title.
 *
 * Uses PATCH to restore (deleted_at) then PUT to update the title,
 * matching the Babbel API contract where PATCH only accepts status/deleted_at.
 *
 * @since 0.3.0
 * @since 0.9.0 Schedules a retry on failure and keeps an unsent title as a pending update.
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
		schedule_story_sync_retry( $post_id );
		return $result;
	}

	// Sync the current post title via PUT (PATCH only accepts status/deleted_at).
	$title_result = babbel_update_story( $story_id, array( 'title' => $title ) );
	if ( $title_result['success'] ) {
		mark_story_synced(
			$post_id,
			array(
				'status'  => StoryStatus::Sent->value,
				'message' => __( 'Story restored in Babbel', 'zw-knabbel-wp' ),
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
				'pending_update'  => array( 'title' => $title ),
			)
		);
		schedule_story_sync_retry( $post_id );
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
 * @since 0.9.0 Keeps unsent fields as a pending update and schedules a retry on failure.
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
		mark_story_synced( $post_id, array( 'message' => $success_message ) );
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
		// Keep every field that did not reach Babbel, so a later sync sends it again.
		update_story_state(
			$post_id,
			array(
				'last_sync_error' => build_story_sync_error( $result['message'], 'update' ),
				'pending_update'  => $update_data,
			)
		);
		schedule_story_sync_retry( $post_id );
	}
}

/**
 * Deletes a story and updates its local state.
 *
 * @since 0.5.0
 * @since 0.9.0 Schedules a retry on failure.
 *
 * @param int    $post_id         Post ID.
 * @param string $story_id        Babbel story ID.
 * @param string $success_message Translated message for the story state on success.
 * @param string $log_context     Machine-readable context for failure logs.
 */
function push_story_delete( int $post_id, string $story_id, string $success_message, string $log_context ): void {
	$result = babbel_delete_story( $story_id );
	if ( $result['success'] ) {
		mark_story_synced(
			$post_id,
			array(
				'status'  => StoryStatus::Deleted->value,
				'message' => $success_message,
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
