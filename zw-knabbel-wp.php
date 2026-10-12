<?php
/**
 * Plugin Name: ZuidWest Knabbel
 * Plugin URI: https://github.com/oszuidwest/zw-knabbel-wp
 * Description: WordPress plugin om berichten naar de Babbel API te sturen voor het radionieuws. Gebruikt de WordPress AI Client voor AI-gegenereerde content.
 * Version: 0.8.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: classic-editor
 * Author: Streekomroep ZuidWest
 * Author URI: https://www.zuidwesttv.nl
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: zw-knabbel-wp
 * Domain Path: /languages
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Read version from plugin header (single source of truth).
$knabbel_plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
define( 'KNABBEL_VERSION', $knabbel_plugin_data['Version'] );
unset( $knabbel_plugin_data );
define( 'KNABBEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KNABBEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load composer autoloader.
require_once KNABBEL_PLUGIN_DIR . 'vendor/autoload.php';

// Load Action Scheduler (not PSR-4, needs explicit bootstrap).
require_once KNABBEL_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

// Direct includes.
require_once KNABBEL_PLUGIN_DIR . 'includes/story-status.php';
require_once KNABBEL_PLUGIN_DIR . 'includes/weekdays.php';
require_once KNABBEL_PLUGIN_DIR . 'includes/babbel-api.php';
require_once KNABBEL_PLUGIN_DIR . 'includes/ai-handler.php';
require_once KNABBEL_PLUGIN_DIR . 'includes/few-shot-selection.php';
require_once KNABBEL_PLUGIN_DIR . 'includes/few-shot-cache.php';

// Register plugin lifecycle hooks.
add_action( 'init', __NAMESPACE__ . '\\init' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Reports whether Debug Mode is enabled.
 *
 * @since 0.1.0
 * @return bool True when debug mode is enabled, false otherwise.
 */
function debug_enabled(): bool {
	return ! empty( get_plugin_settings()['debug_mode'] );
}

/**
 * Bounds context for recent-error storage.
 *
 * Debug logs retain the complete context, but persistent production data is
 * limited to known diagnostic fields and excludes response/request bodies.
 *
 * @since 0.5.0
 * @param array<string, mixed> $context Raw log context.
 * @return array<string, mixed> Safe context for persistent storage.
 *
 * @phpstan-param LogContext $context
 */
function prepare_log_context_for_storage( array $context ): array {
	$safe_context = array();

	// Textual diagnostic fields, bounded per field.
	$text_fields = array(
		'endpoint'   => 500,
		'story_id'   => 100,
		'error'      => 500,
		'context'    => 100,
		'api_error'  => 500,
		'json_error' => 500,
		'error_code' => 100,
	);
	foreach ( $text_fields as $key => $max_length ) {
		if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
			$value                = sanitize_textarea_field( (string) $context[ $key ] );
			$safe_context[ $key ] = wp_html_excerpt( $value, $max_length, '' );
		}
	}

	// Numeric diagnostic fields.
	foreach ( array( 'post_id', 'response_code' ) as $key ) {
		if ( isset( $context[ $key ] ) && is_numeric( $context[ $key ] ) ) {
			$safe_context[ $key ] = (int) $context[ $key ];
		}
	}

	// Bounded list of field names.
	if ( isset( $context['fields'] ) && is_array( $context['fields'] ) ) {
		$fields = array();
		foreach ( array_slice( $context['fields'], 0, 20 ) as $field ) {
			if ( is_scalar( $field ) ) {
				$fields[] = sanitize_key( (string) $field );
			}
		}
		$safe_context['fields'] = $fields;
	}

	return $safe_context;
}

/**
 * Sanitizes an error for bounded storage.
 *
 * This also normalizes legacy entries before they are written back, ensuring
 * previously stored response bodies do not remain in the rolling list.
 *
 * @since 0.5.0
 * @param array<string, mixed> $entry Raw error entry.
 * @return array<string, mixed>|null Sanitized entry, or null when invalid.
 *
 * @phpstan-return RecentError|null
 */
function prepare_recent_error_for_storage( array $entry ): ?array {
	if ( ! isset( $entry['component'], $entry['message'] ) || ! is_scalar( $entry['component'] ) || ! is_scalar( $entry['message'] ) ) {
		return null;
	}

	$timestamp = isset( $entry['timestamp'] ) && is_scalar( $entry['timestamp'] )
		? wp_html_excerpt( sanitize_text_field( (string) $entry['timestamp'] ), 32, '' )
		: current_time( 'mysql' );
	$context   = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

	return array(
		'timestamp' => $timestamp,
		'component' => wp_html_excerpt( sanitize_text_field( (string) $entry['component'] ), 80, '' ),
		'message'   => wp_html_excerpt( sanitize_textarea_field( (string) $entry['message'] ), 500, '' ),
		'context'   => prepare_log_context_for_storage( $context ),
	);
}

/**
 * Normalizes a stored error history.
 *
 * @since 0.5.0
 * @param mixed $stored_errors Stored option value.
 * @return list<array<string, mixed>>
 *
 * @phpstan-return list<RecentError>
 */
function prepare_error_history( mixed $stored_errors ): array {
	$errors = array();
	if ( ! is_array( $stored_errors ) ) {
		return $errors;
	}

	foreach ( $stored_errors as $stored_error ) {
		if ( ! is_array( $stored_error ) ) {
			continue;
		}

		$prepared_error = prepare_recent_error_for_storage( $stored_error );
		if ( null !== $prepared_error ) {
			$errors[] = $prepared_error;
		}
	}

	return $errors;
}

/**
 * Adds an error using bounded optimistic retries.
 *
 * This is a diagnostic buffer rather than an audit log. A collision never
 * overwrites another request's entry, while the small retry limit keeps error
 * bursts from adding unbounded latency to the request that emitted the log.
 *
 * @since 0.5.0
 * @param array<string, mixed> $new_error Prepared error entry.
 *
 * @phpstan-param RecentError $new_error
 */
function append_recent_error( array $new_error ): void {
	global $wpdb;

	$option_name  = 'knabbel_recent_errors';
	$max_attempts = 3;

	for ( $attempt = 0; $attempt < $max_attempts; ++$attempt ) {
		// A direct read is required so the serialized value can be used as the
		// compare-and-swap token in the conditional update below.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored_value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $stored_value ) {
			if ( add_option( $option_name, array( $new_error ), '', false ) ) {
				return;
			}
		} else {
			$recent_errors   = prepare_error_history( maybe_unserialize( $stored_value ) );
			$recent_errors[] = $new_error;
			$recent_errors   = array_slice( $recent_errors, -10 );
			$new_value       = maybe_serialize( $recent_errors );

			if ( $new_value === $stored_value ) {
				return;
			}

			// Only update the row if no other request changed it after our read.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = 'off' WHERE option_name = %s AND option_value = %s",
					$new_value,
					$option_name,
					$stored_value
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( 1 === $updated ) {
				wp_cache_delete( $option_name, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				return;
			}
		}
	}
}

/**
 * Writes structured diagnostics to WordPress logging and recent errors.
 *
 * @since 0.1.0
 * @param string               $level     Log level: 'error', 'warning', 'info'.
 * @param string               $component Component name: 'BabbelApi', 'AiHandler', etc.
 * @param string               $message   Log message.
 * @param array<string, mixed> $context   Additional context data.
 *
 * @phpstan-param LogContext $context
 */
function log( string $level, string $component, string $message, array $context = array() ): void {
	// Store critical errors for admin display regardless of debug logging.
	if ( 'error' === $level ) {
		$new_error = prepare_recent_error_for_storage(
			array(
				'timestamp' => current_time( 'mysql' ),
				'component' => $component,
				'message'   => $message,
				'context'   => $context,
			)
		);
		if ( null !== $new_error ) {
			append_recent_error( $new_error );
		}
	}

	// Only write to the PHP error log when WordPress debug logging is enabled.
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	$log_entry = sprintf(
		'[Knabbel WP] [%s] [%s] %s%s',
		strtoupper( $level ),
		$component,
		$message,
		empty( $context ) ? '' : ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE )
	);

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional logging to WP_DEBUG_LOG.
	error_log( $log_entry );
}

/**
 * Updates consolidated story state metadata.
 *
 * Stores all state in `_knabbel_story_state` with single database operation.
 * Triggers WordPress action hook for extensibility.
 *
 * @since 0.1.0
 * @since 0.9.0 A null value removes any key, not only `last_sync_error`.
 * @param int                  $post_id The post ID.
 * @param array<string, mixed> $updates Partial state updates to apply.
 * @return bool True on successful update, false on failure.
 *
 * @phpstan-param StoryStateUpdate $updates
 */
function update_story_state( int $post_id, array $updates = array() ): bool {
	$meta_value    = get_post_meta( $post_id, '_zw_knabbel_story_state', true );
	$current_state = is_array( $meta_value ) ? $meta_value : array();

	// Merge updates with current state. A sync-health-only update must not
	// change the lifecycle status timestamp.
	$new_state = array_merge(
		$current_state,
		$updates,
		array(
			'post_id' => $post_id,
		)
	);

	if ( array_key_exists( 'status', $updates ) ) {
		$new_state['status_changed_at'] = current_time( 'mysql' );
	}

	// Null is an update-only sentinel: remove the key instead of persisting it.
	foreach ( $updates as $key => $value ) {
		if ( null === $value ) {
			unset( $new_state[ $key ] );
		}
	}

	$success = update_post_meta( $post_id, '_zw_knabbel_story_state', $new_state );

	if ( $success ) {
		/**
		 * Fires when story state is changed.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $post_id       The post ID.
		 * @param array $new_state     The new story state array.
		 * @param array $current_state The previous story state array.
		 */
		do_action( 'knabbel_story_state_changed', $post_id, $new_state, $current_state );
	}

	return (bool) $success;
}

/**
 * Returns story state data for a post.
 *
 * @since 0.1.0
 * @param int $post_id The post ID.
 * @return array<string, mixed> Story state data, or an empty array.
 *
 * @phpstan-return StoryState
 */
function get_story_state( int $post_id ): array {
	$state = get_post_meta( $post_id, '_zw_knabbel_story_state', true );
	return is_array( $state ) ? $state : array();
}

/**
 * Builds a bounded error for persistent story state.
 *
 * @since 0.5.0
 * @param string $message   Error message to show to operators and editors.
 * @param string $operation Sync operation: create, update, restore, or delete.
 * @return array{message: string, occurred_at: string, operation: string} Sync error data.
 *
 * @phpstan-return LastSyncError
 */
function build_story_sync_error( string $message, string $operation ): array {
	$allowed_operations = array( 'create', 'update', 'restore', 'delete' );
	$operation          = sanitize_key( $operation );

	return array(
		'message'     => wp_html_excerpt( sanitize_textarea_field( $message ), 500, '' ),
		'occurred_at' => current_time( 'mysql' ),
		'operation'   => in_array( $operation, $allowed_operations, true ) ? $operation : 'update',
	);
}

/**
 * Returns a valid sync error from story state.
 *
 * @since 0.5.0
 * @since 0.8.0 Returns null for a blank message.
 * @param array<string, mixed> $state Story state data.
 * @return array{message: string, occurred_at: string, operation: string}|null Sync error, or null when absent, malformed, or blank.
 *
 * @phpstan-return LastSyncError|null
 */
function get_story_sync_error( array $state ): ?array {
	$error = $state['last_sync_error'] ?? null;
	if ( ! is_array( $error ) || ! isset( $error['message'], $error['occurred_at'], $error['operation'] ) ) {
		return null;
	}
	if ( ! is_string( $error['message'] ) || ! is_string( $error['occurred_at'] ) || ! is_string( $error['operation'] ) ) {
		return null;
	}
	if ( '' === trim( $error['message'] ) ) {
		return null;
	}

	return array(
		'message'     => $error['message'],
		'occurred_at' => $error['occurred_at'],
		'operation'   => $error['operation'],
	);
}

/**
 * Formats a stored datetime for admin display.
 *
 * Accepts a local-time MySQL string as written by current_time( 'mysql' ), or a
 * Unix timestamp. Falls back to the raw input when it cannot be parsed.
 *
 * @since 0.8.0
 * @param int|string $datetime Stored local-time string or Unix timestamp.
 * @return string Localized date and time in the site's display format.
 */
function format_stored_datetime( int|string $datetime ): string {
	$timestamp = is_int( $datetime ) || false !== filter_var( $datetime, FILTER_VALIDATE_INT )
		? (int) $datetime
		: strtotime( $datetime . ' ' . wp_timezone_string() );
	if ( false === $timestamp ) {
		return (string) $datetime;
	}

	$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	return false === $formatted ? (string) $datetime : $formatted;
}

/**
 * Derives story dates from a base date and configured offsets.
 *
 * For published posts, base_date should be 'now'.
 * For scheduled posts, base_date should be the scheduled publish time (post_date).
 *
 * @since 0.2.0
 * @param string $base_date Date string to calculate from (e.g., 'now' or '2025-01-15 10:00:00').
 * @return array{start_date: string, end_date: string, weekdays: int} Story dates and weekdays bitmask.
 */
function calculate_story_dates( string $base_date = 'now' ): array {
	$options      = get_plugin_settings();
	$start_offset = (int) $options['start_days_offset'];
	$end_offset   = (int) $options['end_days_offset'];

	$tz   = wp_timezone();
	$base = new \DateTimeImmutable( $base_date, $tz );

	return array(
		'start_date' => $base->modify( "+{$start_offset} day" )->format( 'Y-m-d' ),
		'end_date'   => $base->modify( "+{$end_offset} day" )->format( 'Y-m-d' ),
		'weekdays'   => settings_to_weekdays_bitmask( $options ),
	);
}

/**
 * Loads translations and registers plugin hooks.
 *
 * @since 0.1.0
 * @since 0.8.0 Runs legacy cleanup only when the installed version changes.
 */
function init(): void {
	load_plugin_textdomain( 'zw-knabbel-wp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Run one-time migrations only when the installed version changes.
	if ( KNABBEL_VERSION !== get_option( 'knabbel_version' ) ) {
		cleanup_legacy_data();
		update_option( 'knabbel_version', KNABBEL_VERSION, false );
	}

	// Register cron hook for async story processing (always, not just admin).
	add_action( 'knabbel_process_story', __NAMESPACE__ . '\\process_story_async', 10, 1 );

	// Drop the session of replaced credentials right away instead of at the next login.
	add_action( 'update_option_knabbel_settings', __NAMESPACE__ . '\\babbel_clear_session_cache', 10, 0 );

	// Register few-shot example sync hook and ensure it is scheduled.
	// Schedule check runs on every init because activation hooks do not fire on plugin updates.
	few_shot_register_hook();
	few_shot_schedule_sync();

	// Register global post hooks for REST API, CLI, and cron support.
	// This file contains only sync logic, no admin UI code.
	require_once KNABBEL_PLUGIN_DIR . 'includes/post-hooks.php';
	register_post_hooks();

	if ( is_admin() ) {
		admin_init();
	}
}

/**
 * Loads admin functions and registers their hooks.
 *
 * @since 0.1.0
 */
function admin_init(): void {
	// Load admin functions directly.
	require_once KNABBEL_PLUGIN_DIR . 'includes/admin/settings.php';
	require_once KNABBEL_PLUGIN_DIR . 'includes/admin/metabox.php';

	// Initialize admin functionality.
	settings_init();
	metabox_init();

	add_action( 'wp_ajax_knabbel_test_api', __NAMESPACE__ . '\\ajax_test_api' );
}

/**
 * Returns defaults for the knabbel_settings option.
 *
 * @since 0.6.0
 * @since 0.7.0 Removed the `few_shot_count` setting.
 * @return array<string, mixed> The default settings.
 */
function default_settings(): array {
	return array(
		'api_base_url'      => '',
		'api_username'      => '',
		'api_password'      => '',
		'ai_model'          => '',
		'speech_prompt'     => '',
		'debug_mode'        => false,
		// Story defaults.
		'start_days_offset' => 1,
		'end_days_offset'   => 2,
		'default_status'    => 'draft',
		'weekday_sunday'    => true,
		'weekday_monday'    => true,
		'weekday_tuesday'   => true,
		'weekday_wednesday' => true,
		'weekday_thursday'  => true,
		'weekday_friday'    => true,
		'weekday_saturday'  => true,
	);
}

/**
 * Returns stored settings merged over the defaults.
 *
 * Guarantees every default key exists, so read sites need no per-key fallbacks.
 *
 * @since 0.6.0
 * @return array<string, mixed> The plugin settings.
 */
function get_plugin_settings(): array {
	return wp_parse_args( (array) get_option( 'knabbel_settings', array() ), default_settings() );
}

/**
 * Initializes settings and scheduled work on activation.
 *
 * @since 0.1.0
 * @since 0.8.0 Leaves legacy cleanup to the version gate in init().
 */
function activate(): void {
		// Explicitly set autoload to true since these settings are needed on every admin page load.
		// WP 6.6+ changed the default autoload behavior from 'yes' to dynamic heuristics.
		add_option( 'knabbel_settings', default_settings(), '', true );

		// Schedule nightly few-shot example sync. Legacy cleanup runs from init()
		// via the version gate.
		few_shot_schedule_sync();
}

/**
 * Removes sessions and scheduled work on deactivation.
 *
 * @since 0.1.0
 */
function deactivate(): void {
	babbel_clear_session_cache();

	// Clear Action Scheduler jobs. Hook-only takes the bulk-cancel path; passing
	// args or a group forces an exact args match and would skip per-post actions.
	\as_unschedule_all_actions( 'knabbel_process_story' );
	\as_unschedule_all_actions( 'knabbel_retry_story_sync' );
	\as_unschedule_all_actions( 'knabbel_deferred_story_sync' );

	// Clear few-shot sync schedule and cached data.
	few_shot_unschedule_sync();
}

/**
 * Removes deprecated settings when the installed version changes.
 *
 * Safe to run multiple times. Keep through the migration window for settings
 * removed up to and including 0.7.0.
 *
 * @since 0.1.0
 * @since 0.7.0 Removes the obsolete `few_shot_count` setting.
 * @since 0.8.0 Runs when the installed version changes instead of on every request.
 */
function cleanup_legacy_data(): void {
	// Raw read on purpose: this rewrites the stored value, and reading through
	// get_plugin_settings() would bake the merged defaults into the database.
	$settings = get_option( 'knabbel_settings' );
	if ( ! is_array( $settings ) ) {
		return;
	}

	$clean_settings = array_diff_key( $settings, array_flip( array( 'title_prompt', 'openai_api_key', 'openai_model', 'few_shot_count' ) ) );
	if ( $clean_settings !== $settings ) {
		update_option( 'knabbel_settings', $clean_settings );
	}
}

/**
 * Handles an authorized Babbel connection test.
 *
 * @since 0.1.0
 */
function ajax_test_api(): void {
	check_ajax_referer( 'knabbel_test_api_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions', 'zw-knabbel-wp' ) );
	}

	$result = babbel_test_connection();

	if ( $result['success'] ) {
		wp_send_json_success( $result['message'] );
	} else {
		wp_send_json_error( $result['message'] );
	}
}

/**
 * Reports whether a post already has a story, live or gone from Babbel.
 *
 * @since 0.9.0
 * @param array<string, mixed> $state Story state data.
 * @return bool True when creating another story would replace the existing one.
 */
function story_exists_for_post( array $state ): bool {
	return in_array(
		$state['status'] ?? '',
		array( StoryStatus::Sent->value, StoryStatus::DeletedInBabbel->value, StoryStatus::MissingInBabbel->value ),
		true
	);
}

/**
 * Generates and sends a story through Action Scheduler.
 *
 * @since 0.1.0
 * @since 0.7.0 Excludes the current post from the AI few-shot examples.
 * @since 0.8.0 Accepts only a post ID and leaves already sent stories untouched.
 * @since 0.9.0 Skips posts that are no longer published, scheduled or enabled, also after generation, and
 *              records what Babbel confirmed.
 * @param int $post_id The WordPress post ID.
 */
function process_story_async( int $post_id ): void {
	if ( ! $post_id ) {
		log( 'error', 'CronProcessor', 'Invalid post_id in process_story_async' );
		return;
	}

	log( 'info', 'CronProcessor', 'Starting async story processing', array( 'post_id' => $post_id ) );

	// The checks and state changes before generation run under the post's sync lock, on fresh state,
	// so they never overwrite an attempt another worker recorded; generation itself runs outside it.
	$post    = null;
	$prepare = static function () use ( $post_id, &$post ) {
		$post = prepare_story_processing( $post_id );
	};
	if ( ! with_story_sync_lock( $post_id, $prepare ) ) {
		log( 'info', 'CronProcessor', 'A sync of this post is running — processing again shortly', array( 'post_id' => $post_id ) );
		\as_schedule_single_action( time() + 60, 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' );
		return;
	}
	if ( null === $post ) {
		return;
	}

	$content = wp_strip_all_tags( $post->post_content );

	// Generate speech text.
	$speech_text = ai_generate_content( $content, $post_id );

	// The rest runs under the post's sync lock: a sync during the create could otherwise settle or
	// clear the attempt before its answer arrives. A sync that is running now is waited for.
	if ( ! with_story_sync_lock( $post_id, static fn () => send_generated_story( $post_id, $speech_text ) ) ) {
		log( 'info', 'CronProcessor', 'A sync of this post is running — processing again shortly', array( 'post_id' => $post_id ) );
		\as_schedule_single_action( time() + 60, 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' );
	}
}

/**
 * Checks whether a post still needs a story and marks it as processing.
 *
 * Runs while holding the post's sync lock, after a pending create attempt is
 * settled.
 *
 * @since 0.9.0
 *
 * @param int $post_id The WordPress post ID.
 * @return \WP_Post|null The post to generate a story for, or null when there is nothing to do.
 */
function prepare_story_processing( int $post_id ): ?\WP_Post {
	// Send-once safety: a post that has a story, even one gone from Babbel, never gets another.
	$existing_state = get_story_state( $post_id );
	// A create whose answer was lost may have made the story: settle that before anything else.
	if ( ! empty( $existing_state['pending_create'] ) ) {
		$adopted = resolve_pending_create( $post_id );
		if ( null === $adopted ) {
			return null;
		}
		if ( $adopted ) {
			sync_story_state( $post_id );
			return null;
		}
		$existing_state = get_story_state( $post_id );
	}
	if ( story_exists_for_post( $existing_state ) ) {
		log( 'info', 'CronProcessor', 'Story already sent — skipping', array( 'post_id' => $post_id ) );
		return null;
	}

		$post = get_post( $post_id );
	if ( ! $post ) {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => __( 'Post not found', 'zw-knabbel-wp' ) . ' (ID: ' . $post_id . ')',
			)
		);
		return null;
	}

	// Check if still enabled for this post.
	$send_to_babbel = get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true );
	if ( ! $send_to_babbel ) {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => __( 'Send to Babbel is disabled for this post', 'zw-knabbel-wp' ),
			)
		);
		return null;
	}

	// Only published and scheduled posts get a story; the post may have left publication since queueing.
	if ( ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
		log( 'info', 'CronProcessor', 'Post is no longer published or scheduled — skipping', array( 'post_id' => $post_id ) );
		delete_post_meta( $post_id, '_zw_knabbel_story_state' );
		return null;
	}

	// Update status to processing.
	update_story_state(
		$post_id,
		array(
			'status'  => \KnabbelWP\StoryStatus::Processing->value,
			'message' => __( 'Story is being processed...', 'zw-knabbel-wp' ),
		)
	);

	return $post;
}

/**
 * Sends generated speech text to Babbel as the post's story.
 *
 * Runs while holding the post's sync lock, so no sync settles or clears the
 * create attempt while its answer is outstanding.
 *
 * @since 0.9.0
 *
 * @param int         $post_id     The WordPress post ID.
 * @param string|null $speech_text The generated speech text, or null when generation failed.
 */
function send_generated_story( int $post_id, ?string $speech_text ): void {
	// Another worker may have made an attempt during generation whose answer was lost: settle it
	// first, so this one neither replaces its token nor makes a second story.
	if ( ! empty( get_story_state( $post_id )['pending_create'] ) ) {
		$adopted = resolve_pending_create( $post_id );
		if ( null === $adopted ) {
			return;
		}
		if ( $adopted ) {
			sync_story_state( $post_id );
			return;
		}
	}

	// Generation takes a while: another request may have given the post a story meanwhile, and
	// neither a new story nor an error state may replace it.
	clean_post_cache( $post_id );
	if ( story_exists_for_post( get_story_state( $post_id ) ) ) {
		log( 'info', 'CronProcessor', 'A story appeared during processing — skipping', array( 'post_id' => $post_id ) );
		return;
	}

	if ( null === $speech_text ) {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => __( 'Could not generate speech text', 'zw-knabbel-wp' ),
			)
		);
		return;
	}

	// Re-read the post, so a change made during generation is respected.
	$post = get_post( $post_id );
	if (
		! $post
		|| ! get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true )
		|| ! in_array( $post->post_status, array( 'publish', 'future' ), true )
	) {
		log( 'info', 'CronProcessor', 'Post left publication or Radio News during processing — skipping', array( 'post_id' => $post_id ) );
		delete_post_meta( $post_id, '_zw_knabbel_story_state' );
		return;
	}

	// Use the raw post title directly (not get_the_title() which applies filters and prefixes).
	$title = $post->post_title;

	// Prepare story data using configurable defaults.
	$options        = get_plugin_settings();
	$default_status = $options['default_status'];

	// Calculate dates based on post status:
	// - For scheduled posts (future): use the scheduled publish time (post_date).
	// - For published posts: use current time.
	$base_date = 'future' === $post->post_status ? $post->post_date : current_time( 'mysql' );
	$dates     = calculate_story_dates( $base_date );

	$story_data = array(
		'title'      => $title,
		'text'       => $speech_text,
		'start_date' => $dates['start_date'],
		'end_date'   => $dates['end_date'],
		'status'     => $default_status,
		'weekdays'   => $dates['weekdays'],
		'metadata'   => array(
			'wordpress_id'         => $post_id,
			'original_speech_text' => $speech_text,
		),
	);

	// A token in the metadata identifies this attempt, and stays recorded until the attempt is
	// settled: if its answer is lost, Babbel may have created the story anyway.
	// The attempt counts as outstanding before it is recorded, as recording it fires a hook that may save.
	$create_id                                   = wp_generate_uuid4();
	$story_data['metadata']['knabbel_create_id'] = $create_id;
	story_create_in_flight( $post_id, true );
	try {
		update_story_state(
			$post_id,
			array(
				'pending_create' => array(
					'id'          => $create_id,
					'since'       => time(),
					'window_base' => 'future' === $post->post_status ? null : $base_date,
					'speech_text' => $speech_text,
				),
			)
		);
		$result = babbel_create_story( $story_data );
	} finally {
		story_create_in_flight( $post_id, false );
	}

	if ( $result['success'] ) {
		update_story_state(
			$post_id,
			array(
				'status'                => \KnabbelWP\StoryStatus::Sent->value,
				'story_id'              => $result['story_id'],
				'message'               => __( 'Story created successfully', 'zw-knabbel-wp' ),
				'generated_speech_text' => $speech_text,
				'last_sync_error'       => null,
				// What Babbel confirmed, and when a published story went live.
				'synced'                => array(
					'title'      => $title,
					'start_date' => $dates['start_date'],
					'end_date'   => $dates['end_date'],
					'weekdays'   => $dates['weekdays'],
				),
				'window_base'           => 'future' === $post->post_status ? null : $base_date,
				'pending_create'        => null,
			)
		);
		// Saves during generation and creation found no story to update, so bring it in line now.
		sync_story_state( $post_id );
		return;
	}

	// An unanswered create may have made the story; a rejected one made nothing.
	$adopted = babbel_write_uncertain( $result ) ? resolve_pending_create( $post_id ) : false;
	if ( true === $adopted ) {
		sync_story_state( $post_id );
		return;
	}
	update_story_state(
		$post_id,
		array_merge(
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => $result['message'],
			),
			// A failed lookup keeps the attempt pending and its own error; it is looked up again first.
			null === $adopted ? array() : array(
				'last_sync_error' => build_story_sync_error( $result['message'], 'create' ),
				'pending_create'  => null,
			)
		)
	);
}
