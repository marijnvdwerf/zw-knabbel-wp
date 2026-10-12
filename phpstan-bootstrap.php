<?php
/**
 * PHPStan bootstrap definitions for static analysis.
 *
 * Defines constants and function stubs for static analysis only.
 * This file is NOT loaded at runtime and is excluded from release builds.
 *
 * @package KnabbelWP
 * @since   0.1.0
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'KNABBEL_PLUGIN_DIR', __DIR__ . '/' );
define( 'KNABBEL_PLUGIN_URL', 'https://example.com/wp-content/plugins/zw-knabbel-wp/' );

// Action Scheduler function stubs for static analysis.
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * Stubs a single scheduled action.
	 *
	 * @param int    $timestamp When to run the action.
	 * @param string $hook      The hook to trigger.
	 * @param array  $args      Arguments to pass to the hook.
	 * @param string $group     The group to assign this job to.
	 * @return int|false The action ID or false on failure.
	 */
	function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int|false {
		return 0;
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	/**
	 * Stubs a recurring scheduled action.
	 *
	 * @param int    $timestamp         When to first run the action.
	 * @param int    $interval_in_seconds How long to wait between runs.
	 * @param string $hook              The hook to trigger.
	 * @param array  $args              Arguments to pass to the hook.
	 * @param string $group             The group to assign this job to.
	 * @return int|false The action ID or false on failure.
	 */
	function as_schedule_recurring_action( int $timestamp, int $interval_in_seconds, string $hook, array $args = array(), string $group = '' ): int|false {
		return 0;
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	/**
	 * Stubs a scheduled-action lookup.
	 *
	 * @param string $hook  The hook to check.
	 * @param array  $args  Arguments to check against.
	 * @param string $group The group to check.
	 * @return bool|int False if no action found, action ID if found.
	 */
	function as_has_scheduled_action( string $hook, array $args = array(), string $group = '' ): bool|int {
		return false;
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	/**
	 * Stubs bulk action cancellation.
	 *
	 * @param string $hook  The hook to unschedule.
	 * @param array  $args  Arguments to match.
	 * @param string $group The group to match.
	 * @return void
	 */
	function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	}
}

if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	/**
	 * Stubs the next-action lookup.
	 *
	 * @param string $hook  The hook to check.
	 * @param array  $args  Arguments to check against.
	 * @param string $group The group to check.
	 * @return int|bool True if a running or async action exists, else the timestamp of the next pending action, or false if none found.
	 */
	function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int|bool {
		return false;
	}
}

if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
	/**
	 * Stubs the action query.
	 *
	 * @param array<string, mixed> $args          Query arguments.
	 * @param string               $return_format OBJECT, ARRAY_A or 'ids'.
	 * @return array<int, mixed> Matching actions.
	 */
	function as_get_scheduled_actions( array $args = array(), string $return_format = OBJECT ): array {
		return array();
	}
}

if ( ! class_exists( 'ActionScheduler_Store' ) ) {
	/**
	 * Stubs the Action Scheduler store's status constants.
	 */
	abstract class ActionScheduler_Store {
		public const STATUS_PENDING = 'pending';
	}
}
