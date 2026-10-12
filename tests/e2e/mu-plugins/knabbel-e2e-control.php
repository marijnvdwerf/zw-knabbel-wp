<?php
/**
 * Plugin Name: Knabbel E2E Control
 * Description: Deterministic editor and Action Scheduler controls for PHP and browser E2E tests.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() ) {
	return;
}

// The browser suite exercises the plugin's classic metabox integration.
add_filter(
	'use_block_editor_for_post_type',
	static fn( bool $use_block_editor, string $post_type ): bool => 'post' === $post_type ? false : $use_block_editor,
	10,
	2
);

// Lets the browser suite make Babbel story writes fail, as when Babbel is unreachable.
add_filter(
	'pre_http_request',
	static function ( false|array|WP_Error $preempt, array $args, string $url ): false|array|WP_Error {
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		if (
			get_option( 'knabbel_e2e_fail_babbel_writes' )
			&& str_contains( $url, '/api/v1/stories' )
			&& in_array( $method, array( 'PUT', 'PATCH', 'DELETE' ), true )
		) {
			return new WP_Error( 'http_request_failed', 'E2E: Babbel is unreachable' );
		}

		return $preempt;
	},
	1,
	3
);

/**
 * Runs due actions and fails on errors.
 *
 * Shared by the AJAX control endpoint below and tests/e2e/suite.php.
 *
 * @param string                    $hook  Action hook to run.
 * @param string                    $group Action Scheduler group to query.
 * @param array<string, mixed>|null $args  Optional exact action arguments.
 * @return int Number of actions processed.
 * @throws RuntimeException When an action does not complete successfully.
 */
function knabbel_e2e_run_due_actions( string $hook, string $group, ?array $args = null ): int {
	$query = array(
		'hook'         => $hook,
		'group'        => $group,
		'status'       => ActionScheduler_Store::STATUS_PENDING,
		'date'         => time(),
		'date_compare' => '<=',
		'per_page'     => -1,
	);

	if ( null !== $args ) {
		$query['args'] = $args;
	}

	$processed = 0;
	foreach ( as_get_scheduled_actions( $query, 'ids' ) as $action_id ) {
		ActionScheduler::runner()->process_action( $action_id, 'Knabbel E2E' );
		$status = ActionScheduler::store()->get_status( $action_id );

		if ( ActionScheduler_Store::STATUS_COMPLETE !== $status ) {
			$messages = array_map(
				static fn( ActionScheduler_LogEntry $entry ): string => $entry->get_message(),
				ActionScheduler::logger()->get_logs( $action_id )
			);
			$logs     = array() === $messages ? 'none' : implode( ' | ', $messages );
			throw new RuntimeException(
				sprintf( 'Action Scheduler action %d finished with status %s. Logs: %s', (int) $action_id, $status, $logs )
			);
		}

		++$processed;
	}

	return $processed;
}

add_action(
	'wp_ajax_knabbel_e2e_control',
	static function (): void {
		check_ajax_referer( 'knabbel_metabox_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : 'inspect';

		if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'A valid post ID is required.' ), 400 );
		}

		$processed = 0;
		if ( 'run' === $operation ) {
			try {
				$processed = knabbel_e2e_run_due_actions( 'knabbel_process_story', 'zw-knabbel-wp', array( 'post_id' => $post_id ) );
			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
			}
		} elseif ( 'fail_writes' === $operation || 'restore_writes' === $operation ) {
			update_option( 'knabbel_e2e_fail_babbel_writes', 'fail_writes' === $operation, false );
		} elseif ( 'inspect' !== $operation ) {
			wp_send_json_error( array( 'message' => 'Unsupported operation.' ), 400 );
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => 'knabbel_process_story',
				'group'    => 'zw-knabbel-wp',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'args'     => array( 'post_id' => $post_id ),
				'per_page' => -1,
			),
			'ids'
		);

		wp_send_json_success(
			array(
				'pending'   => count( $pending ),
				'processed' => $processed,
				'state'     => (object) KnabbelWP\get_story_state( $post_id ),
			)
		);
	}
);
