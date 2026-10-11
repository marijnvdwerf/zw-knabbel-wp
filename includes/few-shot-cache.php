<?php
/**
 * Few-shot example caching for AI prompt improvement.
 *
 * Syncs a recent candidate pool from Babbel; the pure selection and scoring
 * logic lives in few-shot-selection.php.
 *
 * @package KnabbelWP
 * @since   0.3.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KNABBEL_FEW_SHOT_MAX_AGE_MONTHS = 3;
// Quality gates for candidate speech texts.
const KNABBEL_FEW_SHOT_MIN_WORDS     = 20;
const KNABBEL_FEW_SHOT_MIN_SENTENCES = 2;

/**
 * Registers the nightly synchronization hook.
 *
 * @since 0.3.0
 */
function few_shot_register_hook(): void {
	add_action( 'knabbel_sync_few_shot_examples', __NAMESPACE__ . '\\sync_few_shot_examples' );
}

/**
 * Schedules the nightly example synchronization.
 *
 * @since 0.3.0
 */
function few_shot_schedule_sync(): void {
	if ( false === \as_has_scheduled_action( 'knabbel_sync_few_shot_examples', array(), 'zw-knabbel-wp' ) ) {
		\as_schedule_recurring_action(
			strtotime( 'tomorrow 03:00' ),
			DAY_IN_SECONDS,
			'knabbel_sync_few_shot_examples',
			array(),
			'zw-knabbel-wp'
		);
	}
}

/**
 * Cancels synchronization and clears cached examples.
 *
 * @since 0.3.0
 */
function few_shot_unschedule_sync(): void {
	// Hook-only takes the bulk-cancel path; passing args or a group forces an exact args match.
	\as_unschedule_all_actions( 'knabbel_sync_few_shot_examples' );
	delete_option( 'knabbel_few_shot_examples' );
}

/**
 * Returns the oldest allowed candidate creation timestamp.
 *
 * @since 0.7.0
 * @return int Unix timestamp.
 */
function few_shot_cutoff_timestamp(): int {
	return (int) strtotime( sprintf( '-%d months', KNABBEL_FEW_SHOT_MAX_AGE_MONTHS ) );
}

/**
 * Refreshes the recent few-shot candidate pool from Babbel.
 *
 * Final selection happens for each AI request so the current WordPress post can
 * be excluded without reducing the configured example count.
 *
 * @since 0.3.0
 * @since 0.7.0 Stores a recent candidate pool for per-request selection.
 */
function sync_few_shot_examples(): void {
	$stories = babbel_fetch_recent_stories( gmdate( DATE_ATOM, few_shot_cutoff_timestamp() ) );

	if ( is_wp_error( $stories ) ) {
		log(
			'error',
			'FewShotCache',
			'Failed to fetch stories for few-shot cache',
			array( 'error' => $stories->get_error_message() )
		);
		return;
	}

	// Keep enough diverse candidates to refill either side of the final mix
	// after excluding the current post.
	$pool = select_example_mix( build_few_shot_candidates( $stories ), ( KNABBEL_FEW_SHOT_COUNT * 2 ) + 2 );

	if ( empty( $pool ) ) {
		log( 'info', 'FewShotCache', 'No valid recent few-shot candidates found' );
		delete_option( 'knabbel_few_shot_examples' );
		return;
	}

	// Store the candidate pool without autoloading it. Selection is post-specific.
	update_option( 'knabbel_few_shot_examples', $pool, false );

	log(
		'info',
		'FewShotCache',
		'Few-shot candidate cache updated',
		array( 'candidates_cached' => count( $pool ) )
	);
}

/**
 * Matches recent Babbel stories with their WordPress sources.
 *
 * @since 0.3.0
 * @since 0.7.0 Added age, quality, duplicate, and provenance handling.
 * @param array<int, array<string, mixed>> $stories Stories from the Babbel API.
 * @return array<int, array<string, mixed>> Candidate examples.
 *
 * @phpstan-return list<FewShotCandidate>
 */
function build_few_shot_candidates( array $stories ): array {
	$candidates    = array();
	$seen_post_ids = array();
	$cutoff        = few_shot_cutoff_timestamp();

	// Warm the post cache in one query instead of one query per get_post() call below.
	$post_ids = array_filter( array_map( static fn ( array $story ): int => (int) ( $story['metadata']['wordpress_id'] ?? 0 ), $stories ) );
	if ( array() !== $post_ids ) {
		_prime_post_caches( array_values( $post_ids ), false, false );
	}

	foreach ( $stories as $story ) {
		// Re-check the cutoff; Babbel's filter drifts if its MySQL and server timezones differ.
		$created_at = $story['created_at'] ?? null;
		$created    = is_string( $created_at ) ? strtotime( $created_at ) : false;
		if ( false === $created || $created < $cutoff ) {
			continue;
		}

		$output          = normalize_few_shot_text( (string) ( $story['text'] ?? '' ) );
		$wp_post_id      = (int) ( $story['metadata']['wordpress_id'] ?? 0 );
		$original_speech = normalize_few_shot_text( (string) ( $story['metadata']['original_speech_text'] ?? '' ) );

		if ( '' === $output || $wp_post_id <= 0 || '' === $original_speech || isset( $seen_post_ids[ $wp_post_id ] ) ) {
			continue;
		}

		$output_word_count = few_shot_count_words( $output );
		$sentence_count    = few_shot_count_sentences( $output );
		if ( $output_word_count < KNABBEL_FEW_SHOT_MIN_WORDS || $sentence_count < KNABBEL_FEW_SHOT_MIN_SENTENCES ) {
			continue;
		}

		$post = get_post( $wp_post_id );
		if ( ! $post || '' === trim( $post->post_content ) ) {
			continue;
		}

		$input = normalize_few_shot_text( wp_strip_all_tags( $post->post_content ) );
		if ( '' === $input ) {
			continue;
		}

		$edit_score                   = calculate_edit_score( $original_speech, $output );
		$candidates[]                 = array(
			'post_id'             => $wp_post_id,
			'input'               => $input,
			'output'              => $output,
			'edit_score'          => $edit_score,
			'original_word_count' => few_shot_count_words( $original_speech ),
			'output_word_count'   => $output_word_count,
			'sentence_count'      => $sentence_count,
			'provenance'          => $edit_score < KNABBEL_FEW_SHOT_ACCEPTED_MAX_SCORE ? 'accepted' : 'edited',
		);
		$seen_post_ids[ $wp_post_id ] = true;
	}

	return $candidates;
}

/**
 * Returns the per-post example mix from the cached candidate pool.
 *
 * @since 0.7.0
 * @param int $excluded_post_id Current WordPress post ID, if any.
 * @return array<int, array<string, mixed>> Selected examples.
 *
 * @phpstan-return list<FewShotCandidate>
 */
function get_few_shot_examples( int $excluded_post_id = 0 ): array {
	$cached = get_option( 'knabbel_few_shot_examples', array() );
	if ( ! is_array( $cached ) ) {
		return array();
	}

	$candidates = array();
	foreach ( $cached as $candidate ) {
		if ( ! is_array( $candidate ) ) {
			continue;
		}

		$normalized = normalize_few_shot_candidate( $candidate );
		if ( null === $normalized || $normalized['post_id'] === $excluded_post_id ) {
			continue;
		}
		$candidates[] = $normalized;
	}

	return select_example_mix( $candidates, KNABBEL_FEW_SHOT_COUNT );
}
