<?php
/**
 * Uninstall script for Knabbel WP plugin.
 *
 * Cleans up temporary data when the plugin is deleted.
 * Settings and story data are preserved for potential reinstallation.
 *
 * @package KnabbelWP
 * @since   0.1.0
 */

// Exit if not called by WordPress uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clean up the session cache.
require_once __DIR__ . '/includes/babbel-api.php';
KnabbelWP\babbel_clear_session_cache();

// Clean up temporary options.
delete_option( 'knabbel_recent_errors' );
delete_option( 'knabbel_migration_status_changed_at' );
delete_option( 'knabbel_few_shot_examples' );
delete_option( 'knabbel_version' );

// Remove deferred sync changes and sync locks left by requests that died while holding them.
delete_post_meta_by_key( '_zw_knabbel_deferred_update' );
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of uncached lock rows.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'knabbel_sync_lock_' ) . '%' ) );

// Clean up any remaining Action Scheduler jobs.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	// Hook-only cancellation: a group or empty args would only match actions without per-post args.
	as_unschedule_all_actions( 'knabbel_process_story' );
	as_unschedule_all_actions( 'knabbel_retry_story_sync' );
	as_unschedule_all_actions( 'knabbel_deferred_story_sync' );
	as_unschedule_all_actions( 'knabbel_sync_few_shot_examples', array(), 'zw-knabbel-wp' );
}

// Note: The following data is intentionally preserved:
// - Option: knabbel_settings (user configuration)
// - Post meta: _zw_knabbel_story_state (story processing history)
// - Post meta: _zw_knabbel_send_to_babbel (user selections).
