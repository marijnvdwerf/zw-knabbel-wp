<?php
/**
 * End-to-end tests for the WordPress-to-Babbel synchronization flow.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

use KnabbelWP\StoryStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this suite through WP-CLI.' );
}

/**
 * Runs stateful scenarios against isolated databases.
 */
final class Knabbel_E2E_Suite {
	private const BABBEL_BASE_URL         = 'http://babbel:8080/api/v1';
	private const STORY_HOOK              = 'knabbel_process_story';
	private const SYNC_RETRY_HOOK         = 'knabbel_retry_story_sync';
	private const DEFERRED_SYNC_HOOK      = 'knabbel_deferred_story_sync';
	private const FEW_SHOT_HOOK           = 'knabbel_sync_few_shot_examples';
	private const ACTION_GROUP            = 'zw-knabbel-wp';
	private const SEND_TO_BABBEL_META_KEY = '_zw_knabbel_send_to_babbel';
	// Keep in sync with the AI Client MU plugin and editor-flow.spec.ts.
	private const GENERATED_TEXT = 'Deterministische E2E-radiospreektekst.';

	/**
	 * Cookies for the independent Babbel verification client.
	 *
	 * @var array<int, WP_Http_Cookie>
	 */
	private array $babbel_cookies = array();

	/**
	 * Number of assertions completed by the suite.
	 *
	 * @var int
	 */
	private int $assertion_count = 0;

	/**
	 * Number of scenarios completed successfully.
	 *
	 * @var int
	 */
	private int $case_count = 0;

	/**
	 * HTTP methods whose Babbel story requests currently fail.
	 *
	 * @var array<int, string>
	 */
	private array $failing_babbel_methods = array();

	/**
	 * HTTP methods whose Babbel story answers are currently lost.
	 *
	 * @var array<int, string>
	 */
	private array $lost_babbel_methods = array();

	/**
	 * WordPress ID of the shared published fixture.
	 *
	 * @var int
	 */
	private int $published_post_id = 0;

	/**
	 * Babbel ID of the shared published fixture.
	 *
	 * @var string
	 */
	private string $published_story_id = '';

	/**
	 * Whether Babbel restores and updates in one request, once probed.
	 *
	 * @var bool|null
	 */
	private ?bool $atomic_restore = null;

	/**
	 * Executes all scenarios in dependency order.
	 */
	public function run(): void {
		$this->run_case( 'E2E-001', 'recurring queue, credential snapshots and Babbel authentication', $this->test_queue_and_authentication( ... ) );
		$this->run_case( 'E2E-002', 'published post creates exactly one complete Babbel story', $this->test_published_story_creation( ... ) );
		$this->run_case( 'E2E-003', 'edits synchronize and recover from an authentication failure', $this->test_update_and_error_recovery( ... ) );
		$this->run_case( 'E2E-004', 'checkbox disable soft-deletes and re-enable restores', $this->test_checkbox_delete_and_restore( ... ) );
		$this->run_case( 'E2E-005', 'scheduled post dates update when scheduled and published', $this->test_scheduled_story_lifecycle( ... ) );
		$this->run_case( 'E2E-006', 'unscheduling cancels pending processing without creating a story', $this->test_pending_schedule_cancellation( ... ) );
		$this->run_case( 'E2E-007', 'trash deletes and untrash keeps the story deleted until publication', $this->test_trash_and_restore( ... ) );
		$this->run_case( 'E2E-008', 'AI provider failure retries without creating a Babbel story', $this->test_ai_failure( ... ) );
		$this->run_case( 'E2E-009', 'Babbel create failure is visible and preserves diagnostics safely', $this->test_babbel_create_failure( ... ) );
		$this->run_case( 'E2E-010', 'few-shot queue learns editor changes', $this->test_few_shot_sync( ... ) );
		$this->run_case( 'E2E-011', 'enabling existing published and future posts schedules one story', $this->test_enable_existing_posts( ... ) );
		$this->run_case( 'E2E-012', 'checkbox and trash cancel pending work', $this->test_pending_work_cancellation( ... ) );
		$this->run_case( 'E2E-013', 'leaving future deletes a sent story', $this->test_sent_story_unscheduling( ... ) );
		$this->run_case( 'E2E-014', 'meta deletion and rapid toggles preserve lifecycle invariants', $this->test_meta_deletion_and_rapid_toggles( ... ) );
		$this->run_case( 'E2E-015', 'processing state prevents duplicate scheduling', $this->test_in_flight_state_guards( ... ) );
		$this->run_case( 'E2E-016', 'untrash honors a disabled checkbox', $this->test_untrash_with_checkbox_disabled( ... ) );
		$this->run_case( 'E2E-017', 'delete and restore failures remain retryable', $this->test_delete_and_restore_failures( ... ) );
		$this->run_case( 'E2E-018', 'stored Unix timestamps retain their meaning after serialization', $this->test_stored_datetime_formatting( ... ) );
		$this->run_case( 'E2E-019', 'a story deleted in Babbel reports a readable 410 on update', $this->test_story_deleted_in_babbel( ... ) );
		$this->run_case( 'E2E-020', 'rescheduling moves the window and unpublishing deletes the story', $this->test_published_status_transitions( ... ) );
		$this->run_case( 'E2E-021', 'syncs compare with what Babbel confirmed, run one at a time and retry', $this->test_failed_sync_retry( ... ) );
		// The two-step restore and its rollback, as with Babbel versions that cannot restore with fields.
		$this->run_case(
			'E2E-022',
			'a restored story gets the current window and a failed restore deletes it again',
			fn () => $this->with_two_step_restore( $this->test_restore_window( ... ) )
		);
		$this->run_case( 'E2E-023', 'an embargoed story is never selectable for a bulletin before its window', $this->test_embargo_never_selectable( ... ) );
		$this->run_case( 'E2E-024', 'deactivation clears sessions, caches and scheduled actions', $this->test_deactivation_cleanup( ... ) );

		WP_CLI::success( sprintf( '%d E2E scenarios passed with %d assertions.', $this->case_count, $this->assertion_count ) );
	}

	/**
	 * Runs one named case with useful failure context.
	 *
	 * @param string   $id       Stable scenario ID.
	 * @param string   $title    Scenario title.
	 * @param callable $callback Scenario callback.
	 */
	private function run_case( string $id, string $title, callable $callback ): void {
		WP_CLI::log( sprintf( '[%s] %s', $id, $title ) );

		try {
			$callback();
			++$this->case_count;
			WP_CLI::log( sprintf( '[%s] PASS', $id ) );
		} catch ( Throwable $throwable ) {
			WP_CLI::error( sprintf( '[%s] FAIL: %s', $id, $throwable->getMessage() ) );
		}
	}

	/**
	 * Sets deterministic credentials and story defaults.
	 *
	 * @param string $password Babbel password.
	 */
	private function configure_plugin( string $password = 'admin' ): void {
		$settings = get_option( 'knabbel_settings', array() );

		$settings = array_merge(
			$settings,
			array(
				'api_base_url'      => self::BABBEL_BASE_URL,
				'api_username'      => 'admin',
				'api_password'      => $password,
				'ai_model'          => 'openai/gpt-4.1',
				'start_days_offset' => 1,
				'end_days_offset'   => 2,
				'default_status'    => 'draft',
				'weekday_sunday'    => 1,
				'weekday_monday'    => 1,
				'weekday_tuesday'   => 1,
				'weekday_wednesday' => 1,
				'weekday_thursday'  => 1,
				'weekday_friday'    => 1,
				'weekday_saturday'  => 1,
			)
		);

		update_option( 'knabbel_settings', $settings );
	}

	/**
	 * Verifies queue uniqueness and authentication.
	 */
	private function test_queue_and_authentication(): void {
		KnabbelWP\few_shot_schedule_sync();
		$this->assert_same(
			1,
			$this->action_count( self::FEW_SHOT_HOOK, ActionScheduler_Store::STATUS_PENDING ),
			'The recurring few-shot action must be unique.'
		);

		$this->configure_plugin();
		$result = KnabbelWP\babbel_test_connection();
		$this->assert_same( true, $result['success'], 'Valid Babbel credentials must connect.' );
		$this->assert_same( true, str_contains( $result['message'], 'admin' ), 'Connection result must identify the authenticated user.' );

		$this->assert_same( true, ! empty( get_transient( KnabbelWP\KNABBEL_SESSION_TRANSIENT )['cookies'] ), 'Successful login must cache session cookies.' );
		$this->assert_session_credential_snapshots();

		$invalid_cookie = new WP_Http_Cookie(
			array(
				'name'   => 'babbel_session',
				'value'  => 'invalid-e2e-session',
				'path'   => '/',
				'domain' => 'babbel',
			)
		);

		$session            = get_transient( KnabbelWP\KNABBEL_SESSION_TRANSIENT );
		$session['cookies'] = array( $invalid_cookie );
		set_transient( KnabbelWP\KNABBEL_SESSION_TRANSIENT, $session, MINUTE_IN_SECONDS );

		$result = KnabbelWP\babbel_test_connection();
		$this->assert_same( true, $result['success'], 'A 401 response must clear the cache, authenticate again and retry once.' );
		$this->assert_same(
			true,
			'invalid-e2e-session' !== ( get_transient( KnabbelWP\KNABBEL_SESSION_TRANSIENT )['cookies'][0]->value ?? null ),
			'The invalid session cookie must be replaced.'
		);
	}

	/**
	 * Verifies that authentication reads and caches against one credential snapshot.
	 */
	private function assert_session_credential_snapshots(): void {
		$settings                         = get_option( 'knabbel_settings', array() );
		$changed_settings                 = $settings;
		$changed_settings['api_username'] = 'changeduser';
		KnabbelWP\babbel_clear_session_cache();

		$settings_reads  = 0;
		$settings_filter = static function () use ( &$settings_reads, $settings, $changed_settings ): array {
			return 0 === $settings_reads++ ? $settings : $changed_settings;
		};

		add_filter( 'option_knabbel_settings', $settings_filter );
		try {
			$cookies = KnabbelWP\babbel_get_session_cookies();
		} finally {
			remove_filter( 'option_knabbel_settings', $settings_filter );
		}

		$this->assert_same( true, is_array( $cookies ), 'Authentication with the captured credential snapshot must succeed.' );
		$this->assert_same( 1, $settings_reads, 'Authentication must read configured credentials exactly once.' );
		$this->assert_same(
			$cookies[0]->value ?? null,
			KnabbelWP\babbel_get_session_cookies()[0]->value ?? null,
			'The session must be cached for the captured credentials.'
		);

		// Filters bypass the settings hook, so only the fingerprint can reject the session.
		foreach ( array( 'api_username', 'api_password' ) as $field ) {
			$changed_filter = static fn ( array $value ): array => array_merge( $value, array( $field => 'e2e-changed' ) );
			add_filter( 'option_knabbel_settings', $changed_filter );
			try {
				$changed_cookies = KnabbelWP\babbel_get_session_cookies();
			} finally {
				remove_filter( 'option_knabbel_settings', $changed_filter );
			}

			$this->assert_same( true, is_wp_error( $changed_cookies ), 'A session must never be reused when ' . $field . ' changes.' );
		}

		$this->assert_same(
			$cookies[0]->value ?? null,
			KnabbelWP\babbel_get_session_cookies()[0]->value ?? null,
			'A rejected login must keep the valid session.'
		);
	}

	/**
	 * Verifies the complete publish flow.
	 */
	private function test_published_story_creation(): void {
		$title                   = 'E2E gepubliceerd – één';
		$content                 = 'Dit artikel bevat voldoende woorden om de volledige publicatieketen betrouwbaar te testen.';
		$accepted_example_input  = 'Direct geaccepteerd voorbeeldartikel voor de native AI Client-gespreksgeschiedenis.';
		$accepted_example_output = 'Direct geaccepteerde radiospreektekst.';
		$example_input           = 'Voorbeeldartikel voor de native AI Client-gespreksgeschiedenis.';
		$example_output          = 'Voorbeeld van gecorrigeerde radiospreektekst.';
		$post_id                 = $this->create_enabled_draft( $title, $content );

		update_option(
			'knabbel_few_shot_examples',
			array(
				array(
					'post_id'             => $post_id,
					'input'               => 'Dit huidige artikel mag nooit zijn eigen voorbeeld worden.',
					'output'              => 'Deze uitvoer moet buiten de AI-aanvraag blijven.',
					'edit_score'          => 0.0,
					'original_word_count' => 7,
					'output_word_count'   => 7,
					'sentence_count'      => 1,
					'provenance'          => 'accepted',
				),
				array(
					'post_id'             => $post_id + 1001,
					'input'               => $accepted_example_input,
					'output'              => $accepted_example_output,
					'edit_score'          => 0.0,
					'original_word_count' => 5,
					'output_word_count'   => 4,
					'sentence_count'      => 1,
					'provenance'          => 'accepted',
				),
				array(
					'post_id'             => $post_id + 1000,
					'input'               => $example_input,
					'output'              => $example_output,
					'edit_score'          => 25.0,
					'original_word_count' => 6,
					'output_word_count'   => 5,
					'sentence_count'      => 1,
					'provenance'          => 'edited',
				),
			),
			false
		);

		$this->assert_same( 0, $this->story_action_count( $post_id ), 'Enabling a draft must not schedule processing.' );

		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$this->assert_story_status( $post_id, StoryStatus::Scheduled, 'Publishing must mark the story scheduled.' );

		$this->update_post( $post_id, array( 'post_title' => $title ) );
		$this->assert_same( 1, $this->story_action_count( $post_id ), 'Repeated saves must not duplicate the pending action.' );

		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$state       = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'The worker must mark a created story sent.' );
		$this->assert_same( self::GENERATED_TEXT, $state['generated_speech_text'] ?? null, 'The generated speech text must be persisted.' );
		$ai_request = get_option( 'knabbel_e2e_ai_last_request', array() );
		$this->assert_same( true, is_array( $ai_request ), 'The native AI provider request must be observable.' );
		$this->assert_same( 1000, $ai_request['max_output_tokens'] ?? null, 'The native AI request must retain the output token limit.' );
		$this->assert_same( false, array_key_exists( 'temperature', $ai_request ), 'The native AI request must omit optional temperature.' );
		$this->assert_same( 'gpt-4.1', $ai_request['model'] ?? null, 'The native AI request must use the configured model preference.' );
		$request_messages = $ai_request['input'] ?? array();
		$this->assert_same( true, is_array( $request_messages ), 'The native AI request input must contain structured messages.' );
		$this->assert_same( 'user', $request_messages[0]['role'] ?? null, 'The accepted example must start with a user message.' );
		$this->assert_same(
			sprintf( KnabbelWP\AI_EXAMPLE_PROMPT, 'VOORBEELD DAT DE REDACTIE DIRECT HEEFT GEACCEPTEERD' )
				. "\n\n" . sprintf( KnabbelWP\AI_ARTICLE_PROMPT, $accepted_example_input ),
			$request_messages[0]['content'][0]['text'] ?? null,
			'The accepted example label must precede its input.'
		);
		$this->assert_same( 'assistant', $request_messages[1]['role'] ?? null, 'The accepted example output must follow as an assistant message.' );
		$this->assert_same(
			$accepted_example_output,
			$request_messages[1]['content'][0]['text'] ?? null,
			'The accepted example output must immediately follow its input.'
		);
		$this->assert_same( 'user', $request_messages[2]['role'] ?? null, 'The edited example must start with a user message.' );
		$this->assert_same(
			sprintf( KnabbelWP\AI_EXAMPLE_PROMPT, 'VOORBEELD DAT DE REDACTIE HEEFT AANGEPAST' )
				. "\n\n" . sprintf( KnabbelWP\AI_ARTICLE_PROMPT, $example_input ),
			$request_messages[2]['content'][0]['text'] ?? null,
			'The edited example label must precede its input.'
		);
		$this->assert_same( 'assistant', $request_messages[3]['role'] ?? null, 'The edited example output must follow as an assistant message.' );
		$this->assert_same(
			$example_output,
			$request_messages[3]['content'][0]['text'] ?? null,
			'The edited example output must immediately follow its input.'
		);
		$request_input = (string) wp_json_encode( $request_messages );
		$this->assert_same( true, str_contains( $request_input, $example_input ), 'The native AI request must include the few-shot user example.' );
		$this->assert_same( true, str_contains( $request_input, $example_output ), 'The native AI request must include the few-shot model example.' );
		$this->assert_same(
			true,
			! str_contains( $request_input, 'Dit huidige artikel mag nooit zijn eigen voorbeeld worden.' ),
			'The native AI request must exclude the current WordPress post from few-shot examples.'
		);
		$this->assert_same(
			true,
			! str_contains( $request_input, 'Deze uitvoer moet buiten de AI-aanvraag blijven.' ),
			'The native AI request must exclude the current WordPress post output from few-shot examples.'
		);
		delete_option( 'knabbel_few_shot_examples' );

		$story = $this->get_babbel_story( (string) $state['story_id'] );
		$this->assert_same( $title, $story['title'] ?? null, 'Babbel must receive the raw WordPress title.' );
		$this->assert_same( self::GENERATED_TEXT, $story['text'] ?? null, 'Babbel must receive the generated speech text.' );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'Published story dates must be based on the processing date.' );
		$this->assert_same( 127, $story['weekdays'] ?? null, 'All configured weekdays must produce bitmask 127.' );
		$this->assert_same( 'draft', $story['status'] ?? null, 'Babbel must receive the configured default status.' );
		$this->assert_same( $post_id, (int) ( $story['metadata']['wordpress_id'] ?? 0 ), 'Babbel metadata must link to the WordPress post.' );
		$this->assert_same(
			self::GENERATED_TEXT,
			$story['metadata']['original_speech_text'] ?? null,
			'Babbel metadata must retain the original generated text.'
		);

		KnabbelWP\process_story_async( $post_id );
		$this->assert_same( 1, $this->count_babbel_stories_by_title( $title ), 'Re-running the worker must not create a duplicate story.' );

		$this->published_post_id  = $post_id;
		$this->published_story_id = (string) $state['story_id'];
	}

	/**
	 * Verifies updates and authentication recovery.
	 */
	private function test_update_and_error_recovery(): void {
		$original_story = $this->get_babbel_story( $this->published_story_id );
		$edited_text    = 'Dit is de door de redactie aangepaste Babbel-speechtekst die behouden moet blijven.';
		$this->babbel_request(
			'PUT',
			'/stories/' . $this->published_story_id,
			array(
				'text'   => $edited_text,
				'status' => $original_story['status'] ?? 'draft',
			)
		);

		$new_content = 'De inhoud verandert, maar bestaand Babbel-speechmateriaal blijft bewust en aantoonbaar ongewijzigd.';
		$this->update_post( $this->published_post_id, array( 'post_content' => $new_content ) );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( $edited_text, $story['text'] ?? null, 'Content-only edits must not overwrite edited Babbel speech text.' );

		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel gesynchroniseerd' ) );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel gesynchroniseerd', $story['title'] ?? null, 'Title edits must synchronize immediately.' );

		$this->configure_plugin( 'definitely-wrong-password' );
		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel tijdens fout' ) );
		$this->assert_sync_failure_preserved(
			$this->published_post_id,
			$this->published_story_id,
			StoryStatus::Sent,
			'update',
			'API connection failed: Login failed: API error: HTTP 401 - Invalid username or password'
		);
		$connection = KnabbelWP\babbel_test_connection();
		$this->assert_same( false, $connection['success'], 'Connection test must fail with wrong credentials.' );
		$this->assert_same(
			'Login failed: API error: HTTP 401 - Invalid username or password',
			$connection['message'],
			'Connection test must report the login problem once.'
		);
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel gesynchroniseerd', $story['title'] ?? null, 'Failed update must leave remote data unchanged.' );

		$recent_errors = get_option( 'knabbel_recent_errors', array() );
		$this->assert_same( true, ! empty( $recent_errors ), 'A synchronization failure must be visible in recent errors.' );
		$error_json = wp_json_encode( $recent_errors );
		$this->assert_same( true, is_string( $error_json ), 'Persistent diagnostics must be JSON-encodable.' );
		$this->assert_same(
			true,
			is_string( $error_json ) && ! str_contains( $error_json, 'definitely-wrong-password' ),
			'Persistent diagnostics must never contain the Babbel password.'
		);

		$this->configure_plugin();
		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel hersteld' ) );
		$state = KnabbelWP\get_story_state( $this->published_post_id );
		$this->assert_same( true, ! isset( $state['last_sync_error'] ), 'A successful retry must clear the previous sync error.' );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel hersteld', $story['title'] ?? null, 'Remote title must synchronize after credential recovery.' );
	}

	/**
	 * Verifies checkbox-driven deletion and restoration.
	 */
	private function test_checkbox_delete_and_restore(): void {
		update_post_meta( $this->published_post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $this->published_post_id, StoryStatus::Deleted, 'Disabling the checkbox must mark the story deleted.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $this->published_story_id );

		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel na verwijderen' ) );
		update_post_meta( $this->published_post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$state = KnabbelWP\get_story_state( $this->published_post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Re-enabling must restore the story.' );
		$this->assert_same( $this->published_story_id, (string) ( $state['story_id'] ?? '' ), 'Restore must reuse the original story ID.' );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel na verwijderen', $story['title'] ?? null, 'Restore must synchronize the current title.' );
	}

	/**
	 * Verifies scheduled-story date changes.
	 */
	private function test_scheduled_story_lifecycle(): void {
		$post_id = $this->create_enabled_draft( 'E2E gepland', 'Een gepland artikel doorloopt dezelfde betrouwbare asynchrone integratieketen.' );

		$first_date = $this->future_post_date( 10 );
		$this->schedule_post( $post_id, $first_date );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );

		$state    = KnabbelWP\get_story_state( $post_id );
		$story_id = (string) ( $state['story_id'] ?? '' );
		$story    = $this->get_babbel_story( $story_id );
		$dates    = KnabbelWP\calculate_story_dates( $first_date );
		$this->assert_same( $dates['start_date'], $this->date_only( $story['start_date'] ?? '' ), 'Scheduled start date must use post_date.' );
		$this->assert_same( $dates['end_date'], $this->date_only( $story['end_date'] ?? '' ), 'Scheduled end date must use post_date.' );

		$second_date = $this->future_post_date( 12 );
		$this->update_post(
			$post_id,
			array(
				'post_title'    => 'E2E opnieuw gepland',
				'post_date'     => $second_date,
				'post_date_gmt' => get_gmt_from_date( $second_date ),
			)
		);
		$story = $this->get_babbel_story( $story_id );
		$dates = KnabbelWP\calculate_story_dates( $second_date );
		$this->assert_same( 'E2E opnieuw gepland', $story['title'] ?? null, 'Rescheduling must synchronize the title.' );
		$this->assert_same( $dates['start_date'], $this->date_only( $story['start_date'] ?? '' ), 'Rescheduling must recalculate story dates.' );

		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->update_post(
			$post_id,
			array(
				'post_status'   => 'publish',
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
			)
		);
		$story       = $this->get_babbel_story( $story_id );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'Publishing a scheduled post must recalculate from the processing date.' );
	}

	/**
	 * Verifies cancellation after reverting to draft.
	 */
	private function test_pending_schedule_cancellation(): void {
		$title   = 'E2E planning geannuleerd';
		$post_id = $this->create_enabled_draft( $title, 'Deze geplande verwerking wordt geannuleerd voordat een externe story ontstaat.' );
		$this->schedule_post( $post_id, $this->future_post_date( 15 ) );
		$this->assert_same( 1, $this->story_action_count( $post_id ), 'Future post must have one pending action before cancellation.' );

		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_same( 0, $this->story_action_count( $post_id ), 'Returning to draft must cancel pending work.' );
		$this->assert_same( array(), KnabbelWP\get_story_state( $post_id ), 'Cancellation before processing must clear local story state.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'Canceled work must never create a Babbel story.' );
	}

	/**
	 * Verifies trash and untrash synchronization.
	 */
	private function test_trash_and_restore(): void {
		list( $post_id, $story_id ) = $this->create_sent_story( 'E2E prullenbak', 'Een gepubliceerd artikel wordt verwijderd en daarna veilig teruggezet.' );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Published fixture must reach sent state.' );
		$this->set_stale_story_window( $story_id );

		wp_trash_post( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Trashing must mark the remote story deleted.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
		$trashed = $this->babbel_get_json(
			'/stories?' . http_build_query(
				array(
					'trashed' => 'with',
					'filter'  => array( 'id' => $story_id ),
				)
			),
			'The deleted story must be listed with trashed stories.'
		);
		$this->assert_same( 0, $trashed['data'][0]['weekdays'] ?? null, 'A deleted story must be parked on no weekdays.' );

		wp_untrash_post( $post_id );
		$this->assert_same( 'draft', get_post_status( $post_id ), 'Precondition: WordPress untrashes a post as draft.' );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Untrashing to draft must not restore the story.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$state       = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Publishing the untrashed post must restore the story.' );
		$this->assert_same( $story_id, (string) ( $state['story_id'] ?? '' ), 'Restoring must retain the story ID.' );
		$story = $this->get_babbel_story( $story_id );
		$this->assert_same( 'E2E prullenbak', $story['title'] ?? null, 'The restored story must remain readable.' );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'The restored story must get the window of its new publication.' );
	}

	/**
	 * Verifies retry behavior without remote side effects.
	 */
	private function test_ai_failure(): void {
		$title = 'E2E AI-providerfout';
		update_option( 'knabbel_e2e_ai_call_count', 0, false );
		add_filter( 'wp_supports_ai', '__return_false' );
		try {
			$this->assert_same(
				null,
				KnabbelWP\ai_generate_content( 'Unsupported AI must fail without a provider request.', 0 ),
				'Unsupported text generation must fail immediately.'
			);
			$this->assert_same( 0, (int) get_option( 'knabbel_e2e_ai_call_count', 0 ), 'Unsupported text generation must not call the provider.' );
		} finally {
			remove_filter( 'wp_supports_ai', '__return_false' );
		}

		update_option( 'knabbel_e2e_ai_mode', 'error', false );
		update_option( 'knabbel_e2e_ai_call_count', 0, false );

		$post_id = $this->create_enabled_draft( $title, 'De AI-provider faalt deterministisch zodat foutafhandeling en retries aantoonbaar blijven.' );
		$this->publish_and_process( $post_id );

		$this->assert_story_status( $post_id, StoryStatus::Error, 'AI provider exhaustion must mark processing as error.' );
		$this->assert_same( 3, (int) get_option( 'knabbel_e2e_ai_call_count', 0 ), 'The AI provider must be attempted exactly three times.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'AI provider failure must not create a Babbel story.' );

		update_option( 'knabbel_e2e_ai_mode', 'success', false );
	}

	/**
	 * Verifies diagnostics after a login failure.
	 */
	private function test_babbel_create_failure(): void {
		$title = 'E2E Babbel fout';
		$this->configure_plugin( 'wrong-create-password' );

		$post_id = $this->create_enabled_draft( $title, 'Babbel weigert authenticatie en WordPress bewaart een begrensde foutstatus.' );
		$this->publish_and_process( $post_id );

		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Error->value, $state['status'] ?? null, 'Babbel create failure must mark lifecycle error.' );
		$this->assert_same( 'create', $state['last_sync_error']['operation'] ?? null, 'Babbel create failure must identify the operation.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'Rejected authentication must not create a story.' );
		$error_json = wp_json_encode( get_option( 'knabbel_recent_errors', array() ) );
		$this->assert_same( true, is_string( $error_json ), 'Recent errors must be JSON-encodable.' );
		$this->assert_same(
			true,
			is_string( $error_json ) && ! str_contains( $error_json, 'wrong-create-password' ),
			'Recent errors must exclude failed credentials.'
		);

		$this->configure_plugin();
	}

	/**
	 * Verifies learning from an editor change.
	 */
	private function test_few_shot_sync(): void {
		$edited_text = 'Dit is een aantoonbaar door de redactie aangepaste radiospreektekst voor het nieuws. '
			. 'De tweede zin maakt de tekst geschikt als recent voorbeeld.';
		$this->babbel_request(
			'PUT',
			'/stories/' . $this->published_story_id,
			array(
				'text'   => $edited_text,
				'status' => 'active',
			)
		);

		as_enqueue_async_action( self::FEW_SHOT_HOOK, array(), self::ACTION_GROUP );
		$this->run_action_scheduler( self::FEW_SHOT_HOOK );

		$examples = get_option( 'knabbel_few_shot_examples', array() );
		$this->assert_same( 1, count( $examples ), 'Few-shot sync must cache the available candidate.' );
		$this->assert_same( $edited_text, $examples[0]['output'] ?? null, 'Few-shot output must use editor-corrected Babbel text.' );
		$this->assert_same( 'edited', $examples[0]['provenance'] ?? null, 'Few-shot sync must mark editor-corrected stories as edited.' );
		$this->assert_same( true, (float) ( $examples[0]['edit_score'] ?? 0 ) > 0, 'Edited few-shot examples must have a positive edit score.' );
		$this->assert_same(
			wp_strip_all_tags( get_post_field( 'post_content', $this->published_post_id ) ),
			$examples[0]['input'] ?? null,
			'Few-shot input must use current WordPress content.'
		);
	}

	/**
	 * Verifies metadata hooks for existing posts.
	 */
	private function test_enable_existing_posts(): void {
		$published_title = 'E2E bestaand gepubliceerd';
		$published_id    = $this->create_draft(
			$published_title,
			'Een bestaand gepubliceerd bericht krijgt radionieuws pas na publicatie ingeschakeld.'
		);
		$this->update_post( $published_id, array( 'post_status' => 'publish' ) );
		update_post_meta( $published_id, self::SEND_TO_BABBEL_META_KEY, 0 );

		$this->assert_same( array(), KnabbelWP\get_story_state( $published_id ), 'Publishing while disabled must not create story state.' );
		$this->assert_same( 0, $this->story_action_count( $published_id ), 'Publishing while disabled must not queue processing.' );

		update_post_meta( $published_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_story_status( $published_id, StoryStatus::Scheduled, 'Enabling an existing published post must schedule processing.' );
		$this->assert_same( 1, $this->story_action_count( $published_id ), 'Published enable must queue exactly one action.' );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $published_id ) );
		$this->assert_story_status( $published_id, StoryStatus::Sent, 'The enabled published post must reach sent state.' );
		$this->assert_same( 1, $this->count_babbel_stories_by_title( $published_title ), 'Published enable must create exactly one story.' );

		$future_title = 'E2E bestaand gepland';
		$future_id    = $this->create_draft(
			$future_title,
			'Een bestaand gepland bericht krijgt radionieuws pas na het plannen ingeschakeld.'
		);
		$future_date  = $this->future_post_date( 18 );
		$this->schedule_post( $future_id, $future_date );

		$this->assert_same( array(), KnabbelWP\get_story_state( $future_id ), 'Scheduling while disabled must not create story state.' );
		$this->assert_same( 0, $this->story_action_count( $future_id ), 'Scheduling while disabled must not queue processing.' );

		add_post_meta( $future_id, self::SEND_TO_BABBEL_META_KEY, 1, true );
		$this->assert_story_status( $future_id, StoryStatus::Scheduled, 'Adding enabled meta to an existing future post must schedule processing.' );
		$this->assert_same( 1, $this->story_action_count( $future_id ), 'Future enable must queue exactly one action.' );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $future_id ) );

		$state = KnabbelWP\get_story_state( $future_id );
		$story = $this->get_babbel_story( (string) ( $state['story_id'] ?? '' ) );
		$dates = KnabbelWP\calculate_story_dates( $future_date );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'The enabled future post must reach sent state.' );
		$this->assert_same( $dates['start_date'], $this->date_only( $story['start_date'] ?? '' ), 'Future enable must retain scheduled dates.' );
		$this->assert_same( 1, $this->count_babbel_stories_by_title( $future_title ), 'Future enable must create exactly one story.' );
	}

	/**
	 * Verifies cancellation in eligible states.
	 */
	private function test_pending_work_cancellation(): void {
		foreach ( array( 'checkbox', 'trash' ) as $cancel_via ) {
			$title   = 'E2E annulering ' . $cancel_via;
			$post_id = $this->create_enabled_draft(
				$title,
				'Een nog niet uitgevoerde storyactie moet zonder externe bijwerking worden geannuleerd.'
			);
			$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
			$this->assert_same( 1, $this->story_action_count( $post_id ), 'Precondition: one story action must be pending.' );

			if ( 'trash' === $cancel_via ) {
				wp_trash_post( $post_id );
			} else {
				update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
			}

			$this->assert_same( 0, $this->story_action_count( $post_id ), $cancel_via . ' must cancel pending processing.' );
			$this->assert_same( array(), KnabbelWP\get_story_state( $post_id ), $cancel_via . ' must clear transient story state.' );
			$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), $cancel_via . ' must not create a remote story.' );
		}
	}

	/**
	 * Verifies deletion after leaving future status.
	 */
	private function test_sent_story_unscheduling(): void {
		$title   = 'E2E verzonden planning geannuleerd';
		$post_id = $this->create_enabled_draft(
			$title,
			'Een reeds verzonden geplande story wordt verwijderd wanneer de planning vervalt.'
		);
		$this->schedule_post( $post_id, $this->future_post_date( 20 ) );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );

		$state    = KnabbelWP\get_story_state( $post_id );
		$story_id = (string) ( $state['story_id'] ?? '' );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Precondition: scheduled story must be sent.' );

		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Returning a sent future post to draft must mark the story deleted.' );
		$this->assert_same( 0, $this->story_action_count( $post_id ), 'Returning to draft must leave no pending action.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
	}

	/**
	 * Verifies rapid metadata changes.
	 */
	private function test_meta_deletion_and_rapid_toggles(): void {
		$delete_title = 'E2E checkboxmeta verwijderd';

		list( $delete_id, $story_id ) = $this->create_sent_story(
			$delete_title,
			'Het volledig verwijderen van de checkboxmeta moet hetzelfde werken als uitschakelen.'
		);

		delete_post_meta( $delete_id, self::SEND_TO_BABBEL_META_KEY );
		$this->assert_story_status( $delete_id, StoryStatus::Deleted, 'Deleting enabled checkbox meta must delete the story.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		add_post_meta( $delete_id, self::SEND_TO_BABBEL_META_KEY, 1, true );
		$state = KnabbelWP\get_story_state( $delete_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Re-adding checkbox meta must restore the story.' );
		$this->assert_same( $story_id, (string) ( $state['story_id'] ?? '' ), 'Meta restore must reuse the original story ID.' );
		$this->assert_babbel_response_code( 200, 'GET', '/stories/' . $story_id );

		$toggle_title = 'E2E snelle checkboxwissel';
		$toggle_id    = $this->create_draft(
			$toggle_title,
			'Snel in- en uitschakelen mag nooit dubbele storyacties of externe stories veroorzaken.'
		);
		$this->update_post( $toggle_id, array( 'post_status' => 'publish' ) );

		update_post_meta( $toggle_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_same( 1, $this->story_action_count( $toggle_id ), 'First enable must queue one action.' );
		update_post_meta( $toggle_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_same( 0, $this->story_action_count( $toggle_id ), 'Disable must cancel the queued action.' );
		$this->assert_same( array(), KnabbelWP\get_story_state( $toggle_id ), 'Disable must clear the queued lifecycle state.' );
		update_post_meta( $toggle_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		KnabbelWP\sync_story_state( $toggle_id );
		$this->assert_same( 1, $this->story_action_count( $toggle_id ), 'A duplicate enable event must retain exactly one action.' );

		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $toggle_id ) );
		$this->assert_same( 1, $this->count_babbel_stories_by_title( $toggle_title ), 'Rapid toggles must create exactly one story.' );
	}

	/**
	 * Verifies duplicate-work prevention.
	 */
	private function test_in_flight_state_guards(): void {
		$processing_id = $this->create_draft(
			'E2E guard processing',
			'Een bestaande processing-state mag bij het inschakelen geen nieuwe actie krijgen.'
		);
		$this->update_post( $processing_id, array( 'post_status' => 'publish' ) );
		KnabbelWP\update_story_state( $processing_id, array( 'status' => StoryStatus::Processing->value ) );

		update_post_meta( $processing_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_same( 0, $this->story_action_count( $processing_id ), 'Enabling processing state must not queue another action.' );
		$this->assert_story_status( $processing_id, StoryStatus::Processing, 'Enabling processing state must preserve its lifecycle status.' );
	}

	/**
	 * Verifies disabled-story restoration behavior.
	 */
	private function test_untrash_with_checkbox_disabled(): void {
		list( $post_id, $story_id ) = $this->create_sent_story(
			'E2E prullenbak checkbox uit',
			'Een story blijft verwijderd als radionieuws in de prullenbak is uitgeschakeld.'
		);

		wp_trash_post( $post_id );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		wp_untrash_post( $post_id );

		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Deleted->value, $state['status'] ?? null, 'Untrash with checkbox disabled must preserve deleted state.' );
		$this->assert_same( $story_id, (string) ( $state['story_id'] ?? '' ), 'Disabled untrash must retain the deleted story ID.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
	}

	/**
	 * Verifies retryable failure state.
	 */
	private function test_delete_and_restore_failures(): void {
		list( $post_id, $story_id ) = $this->create_sent_story(
			'E2E verwijder- en herstelfout',
			'Mislukte synchronisatieovergangen moeten zonder verlies van lifecycle-state opnieuw uitvoerbaar blijven.'
		);

		$this->configure_plugin( 'wrong-delete-password' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Sent, 'delete' );
		$this->assert_babbel_response_code( 200, 'GET', '/stories/' . $story_id );

		$this->configure_plugin();
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'A retried delete must reach deleted state.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		$this->configure_plugin( 'wrong-restore-password' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Deleted, 'restore' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		$this->configure_plugin();
		$retry_title = 'E2E verwijder- en herstelfout hersteld';
		$this->update_post( $post_id, array( 'post_title' => $retry_title ) );
		// Reset the checkbox edge so the next enable retries the failed restore.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'A retried restore must reach sent state.' );
		$this->assert_same( true, ! isset( $state['last_sync_error'] ), 'Successful restore retry must clear its previous error.' );
		$story = $this->get_babbel_story( $story_id );
		$this->assert_same( $retry_title, $story['title'] ?? null, 'Successful restore retry must synchronize the current title.' );
	}

	/**
	 * Verifies integer timestamps survive WordPress option serialization.
	 */
	private function test_stored_datetime_formatting(): void {
		$timestamp  = 1_700_000_000;
		$serialized = maybe_unserialize( maybe_serialize( (string) $timestamp ) );

		$this->assert_same(
			KnabbelWP\format_stored_datetime( $timestamp ),
			KnabbelWP\format_stored_datetime( $serialized ),
			'Integer and serialized-string timestamps must render identically.'
		);
	}

	/**
	 * Verifies an update to a story deleted outside WordPress.
	 */
	private function test_story_deleted_in_babbel(): void {
		list( $post_id, $story_id ) = $this->create_sent_story(
			'E2E buiten WordPress verwijderd',
			'Een redacteur verwijdert het verhaal in Babbel terwijl het bericht in WordPress gepubliceerd blijft.'
		);
		$this->assert_babbel_response_code( 204, 'DELETE', '/stories/' . $story_id );

		$this->update_post( $post_id, array( 'post_title' => 'E2E bewerking na verwijdering' ) );
		$this->assert_sync_failure_preserved(
			$post_id,
			$story_id,
			StoryStatus::Sent,
			'update',
			'API error: HTTP 410 - Story with id ' . $story_id . ' has been deleted'
		);
	}

	/**
	 * Verifies transitions out of the publish status.
	 */
	private function test_published_status_transitions(): void {
		list( $post_id, $story_id ) = $this->create_sent_story(
			'E2E gepubliceerd en opnieuw ingepland',
			'Een per ongeluk gepubliceerd artikel krijgt alsnog een embargo tot een latere datum.'
		);
		$embargo_date               = $this->future_post_date( 10 );
		$this->schedule_post( $post_id, $embargo_date );
		$this->assert_same( 'future', get_post_status( $post_id ), 'Precondition: a future date must reschedule the published post.' );
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Rescheduling must keep the story sent.' );
		$this->assert_same( true, ! isset( $state['last_sync_error'] ), 'Rescheduling must synchronize without errors.' );
		$this->assert_story_window( $story_id, $embargo_date, 'Rescheduling a published post must move the window to the new date.' );

		// A time-only change on the same day leaves the window as it is.
		$later_same_day = substr( $embargo_date, 0, 10 ) . ' 15:30:00';
		$requests       = $this->count_babbel_requests(
			fn () => $this->update_post(
				$post_id,
				array(
					'post_date'     => $later_same_day,
					'post_date_gmt' => get_gmt_from_date( $later_same_day ),
				)
			)
		);
		$this->assert_same( 0, $requests, 'A time-only change must not send a Babbel request.' );
		$this->assert_story_window( $story_id, $embargo_date, 'A time-only change must keep the rescheduled window.' );

		foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
			list( $post_id, $story_id ) = $this->create_sent_story(
				'E2E gepubliceerd naar ' . $status,
				'Een gepubliceerd artikel gaat offline en mag niet meer in bulletins terechtkomen.'
			);
			$this->update_post( $post_id, array( 'post_status' => $status ) );
			$this->assert_story_status( $post_id, StoryStatus::Deleted, 'publish -> ' . $status . ' must delete the story.' );
			$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
		}

		$title   = 'E2E gepubliceerd naar concept met wachtrij';
		$post_id = $this->create_enabled_draft( $title, 'Een gepubliceerd artikel gaat terug naar concept voordat de story is gemaakt.' );
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$this->assert_same( 1, $this->story_action_count( $post_id ), 'Precondition: one story action must be pending.' );
		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_same( 0, $this->story_action_count( $post_id ), 'Unpublishing must cancel pending processing.' );
		$this->run_action_scheduler( self::STORY_HOOK, 0, array( 'post_id' => $post_id ) );
		$this->assert_same( array(), KnabbelWP\get_story_state( $post_id ), 'Unpublishing before processing must clear local story state.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'Unpublishing before processing must not create a story.' );

		// A worker that already started must not create a story for a post that is no longer published.
		KnabbelWP\process_story_async( $post_id );
		$this->assert_same( array(), KnabbelWP\get_story_state( $post_id ), 'Processing a draft must leave no story state.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'Processing a draft must not create a story.' );

		$title   = 'E2E gedepubliceerd tijdens verwerking';
		$post_id = $this->create_enabled_draft( $title, 'Een artikel gaat terug naar concept terwijl de radiotekst wordt gegenereerd.' );
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$unpublish = function ( $preempt, array $args, string $url ) use ( $post_id ) {
			if ( 'https://api.openai.com/v1/responses' === $url && 'publish' === get_post_status( $post_id ) ) {
				$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $unpublish, 5, 3 );
		try {
			$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		} finally {
			remove_filter( 'pre_http_request', $unpublish, 5 );
		}
		$this->assert_same( 'draft', get_post_status( $post_id ), 'Precondition: the post must be unpublished during generation.' );
		$this->assert_same( array(), KnabbelWP\get_story_state( $post_id ), 'Unpublishing during generation must leave no story state.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'Unpublishing during generation must not create a story.' );
	}

	/**
	 * Verifies the snapshot comparison, the sync lock and bounded retries.
	 */
	private function test_failed_sync_retry(): void {
		$title                      = 'E2E mislukte update';
		list( $post_id, $story_id ) = $this->create_sent_story( $title, 'Babbel is even onbereikbaar terwijl de redactie de publicatiedatum aanpast.' );
		$ai_calls                   = (int) get_option( 'knabbel_e2e_ai_call_count', 0 );
		$this->assert_same(
			true,
			is_string( KnabbelWP\get_story_state( $post_id )['window_base'] ?? null ),
			'Creation must record when the story went live.'
		);
		// As if the story went live a month ago, so moving the post to an earlier day still moves the window.
		KnabbelWP\update_story_state( $post_id, array( 'window_base' => $this->past_post_date( 30 ) ) );
		$original = $this->get_babbel_story( $story_id );
		$this->assert_same(
			array( $this->date_only( $original['start_date'] ?? '' ), $this->date_only( $original['end_date'] ?? '' ) ),
			array( KnabbelWP\get_story_state( $post_id )['synced']['start_date'] ?? null, KnabbelWP\get_story_state( $post_id )['synced']['end_date'] ?? null ),
			'Creation must record the window Babbel confirmed.'
		);

		// A failed date change keeps the error and one retry; Babbel and the snapshot stay unchanged.
		$first_date = $this->past_post_date( 3 );
		$this->set_babbel_writes_failing( true );
		$this->set_post_date( $post_id, $first_date );
		$this->set_babbel_writes_failing( false );
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Sent, 'update' );
		$this->assert_same( true, $state['remote_unverified'] ?? null, 'A write that may have reached Babbel must be marked unverified.' );
		$this->assert_same( 1, $this->sync_retry_count( $post_id ), 'A failed update must schedule exactly one retry.' );
		$this->assert_same( 1, $state['sync_retry_attempts'] ?? null, 'The first retry must be counted.' );
		$next_retry = as_next_scheduled_action( self::SYNC_RETRY_HOOK, array( 'post_id' => $post_id ), self::ACTION_GROUP );
		$this->assert_same( true, is_int( $next_retry ) && abs( $next_retry - ( time() + 300 ) ) <= 10, 'The first retry must wait five minutes.' );
		$this->assert_same(
			$original['start_date'] ?? null,
			$this->get_babbel_story( $story_id )['start_date'] ?? null,
			'A failed update must leave Babbel unchanged.'
		);

		// A plain save after recovery sends the window, because Babbel never confirmed it.
		$this->update_post( $post_id, array() );
		$this->assert_story_window( $story_id, $first_date, 'A plain save must send the unconfirmed window.' );
		$this->assert_synced( $post_id, 'A plain save that sends everything must clear the failure.' );

		// A title-only change after a failed date change sends the dates as well.
		$second_date = $this->past_post_date( 4 );
		$this->set_babbel_writes_failing( true );
		$this->set_post_date( $post_id, $second_date );
		$this->set_babbel_writes_failing( false );
		$this->update_post( $post_id, array( 'post_title' => $title . ' hersteld' ) );
		$this->assert_same( $title . ' hersteld', $this->get_babbel_story( $story_id )['title'] ?? null, 'The title change must synchronize.' );
		$this->assert_story_window( $story_id, $second_date, 'A title-only success must also send the unconfirmed dates.' );
		$this->assert_synced( $post_id, 'A title-only success that sends the dates must clear the failure.' );

		// A time-only change on a healthy post sends nothing.
		$later_same_day = substr( $second_date, 0, 10 ) . ' 16:45:00';
		$this->assert_same(
			0,
			$this->count_babbel_requests( fn () => $this->set_post_date( $post_id, $later_same_day ) ),
			'A time-only change on a healthy post must not send a request.'
		);

		// The scheduled retry sends what Babbel has not confirmed.
		$third_date = $this->past_post_date( 5 );
		$this->set_babbel_writes_failing( true );
		$this->set_post_date( $post_id, $third_date );
		$this->set_babbel_writes_failing( false );
		$this->run_pending_sync_retry( $post_id );
		$this->assert_story_window( $story_id, $third_date, 'The retry must send the unconfirmed dates.' );
		$this->assert_synced( $post_id, 'A successful retry must clear the failure.' );

		// A sync that runs late with an old previous post sends the post as it is now.
		$stale_before              = clone get_post( $post_id );
		$stale_before->post_status = 'future';
		$this->assert_same(
			0,
			$this->count_babbel_requests( fn () => KnabbelWP\sync_story_state( $post_id, $stale_before ) ),
			'A late publication callback must not replace a newer date with a now window.'
		);
		$this->assert_story_window( $story_id, $third_date, 'A late callback must keep the newest date.' );

		// A sync holds the post's lock while it talks to Babbel and releases it afterwards.
		$lock_held = null;
		$observe   = function ( $preempt, array $args, string $url ) use ( &$lock_held, $post_id ) {
			if ( str_starts_with( $url, self::BABBEL_BASE_URL . '/stories' ) && 'PUT' === ( $args['method'] ?? '' ) ) {
				$lock_held = $this->sync_lock_exists( $post_id );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $observe, 1, 3 );
		try {
			$this->update_post( $post_id, array( 'post_title' => $title . ' vergrendeld' ) );
		} finally {
			remove_filter( 'pre_http_request', $observe, 1 );
		}
		$this->assert_same( true, $lock_held, 'A sync must hold the post lock while it writes to Babbel.' );
		$this->assert_same( false, $this->sync_lock_exists( $post_id ), 'A sync must release the post lock.' );

		// A lock left by a request that died is taken over.
		$this->insert_sync_lock( $post_id, time() - 1 );
		$this->update_post( $post_id, array( 'post_title' => $title . ' overgenomen' ) );
		$this->assert_same( $title . ' overgenomen', $this->get_babbel_story( $story_id )['title'] ?? null, 'An expired lock must not block a sync.' );
		$this->assert_same( false, $this->sync_lock_exists( $post_id ), 'Taking over an expired lock must release it afterwards.' );

		// A save that finds a live lock queues a sync instead of syncing unlocked.
		$this->insert_sync_lock( $post_id, time() + 60 );
		add_filter( 'knabbel_sync_lock_wait', '__return_zero' );
		try {
			$requests = $this->count_babbel_requests(
				fn () => $this->update_post( $post_id, array( 'post_title' => $title . ' uitgesteld' ) )
			);
		} finally {
			remove_filter( 'knabbel_sync_lock_wait', '__return_zero' );
		}
		$this->assert_same( 0, $requests, 'A sync must not talk to Babbel without the lock.' );
		$this->assert_same( true, $this->sync_lock_exists( $post_id ), 'A live lock must not be taken over.' );
		$this->assert_same( 1, $this->deferred_sync_count( $post_id ), 'A deferred sync must be queued.' );
		$this->assert_recent_error_logged( 'Another Babbel sync of this post is running; deferred this one', 'Deferring a sync must be logged.' );

		// A deferred sync that finds the lock busy again queues its successor.
		add_filter( 'knabbel_sync_lock_wait', '__return_zero' );
		try {
			$this->run_action_scheduler( self::DEFERRED_SYNC_HOOK, 1, array( 'post_id' => $post_id ) );
		} finally {
			remove_filter( 'knabbel_sync_lock_wait', '__return_zero' );
		}
		$this->assert_same( 1, $this->deferred_sync_count( $post_id ), 'A deferred sync that defers again must queue its successor.' );

		// Once the lock is free, the deferred sync sends the post as it is then.
		$this->delete_sync_lock( $post_id );
		$this->run_action_scheduler( self::DEFERRED_SYNC_HOOK, 1, array( 'post_id' => $post_id ) );
		$this->assert_same( $title . ' uitgesteld', $this->get_babbel_story( $story_id )['title'] ?? null, 'The deferred sync must send the change.' );
		$this->assert_synced( $post_id, 'A deferred sync must leave nothing to sync.' );

		// A deferred unpublish removes the story as the save would have.
		$this->insert_sync_lock( $post_id, time() + 60 );
		add_filter( 'knabbel_sync_lock_wait', '__return_zero' );
		try {
			$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		} finally {
			remove_filter( 'knabbel_sync_lock_wait', '__return_zero' );
			$this->delete_sync_lock( $post_id );
		}
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Nothing may happen while the lock is busy.' );
		$this->run_action_scheduler( self::DEFERRED_SYNC_HOOK, 1, array( 'post_id' => $post_id ) );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'A deferred unpublish must delete the story.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Precondition: publishing again must restore the story.' );

		// A deferred sync that cannot be queued is logged, and the next save sends the change.
		$this->insert_sync_lock( $post_id, time() + 60 );
		add_filter( 'knabbel_sync_lock_wait', '__return_zero' );
		add_filter( 'pre_as_enqueue_async_action', '__return_zero' );
		try {
			$this->update_post( $post_id, array( 'post_title' => $title . ' niet ingepland' ) );
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', '__return_zero' );
			remove_filter( 'knabbel_sync_lock_wait', '__return_zero' );
			$this->delete_sync_lock( $post_id );
		}
		$this->assert_recent_error_logged(
			'Could not queue a deferred Babbel sync; the next save of this post syncs it',
			'A failed deferral must be logged.'
		);
		$this->update_post( $post_id, array() );
		$this->assert_same( $title . ' niet ingepland', $this->get_babbel_story( $story_id )['title'] ?? null, 'The next save must sync the change.' );

		// Releasing never removes a lock that another request took over.
		$token = KnabbelWP\acquire_story_sync_lock( $post_id );
		$this->delete_sync_lock( $post_id );
		$this->insert_sync_lock( $post_id, time() + 60 );
		KnabbelWP\release_story_sync_lock( $post_id, (string) $token );
		$this->assert_same( true, $this->sync_lock_exists( $post_id ), 'Release must only remove the caller\'s own lock.' );
		$this->delete_sync_lock( $post_id );

		// A sync reads the post fresh once it holds the lock, not a copy cached while it waited.
		global $wpdb;
		$stale_post = get_post( $post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Changes the stored post behind the cache on purpose.
		$wpdb->update( $wpdb->posts, array( 'post_title' => $title . ' hersteld' ), array( 'ID' => $post_id ) );
		wp_cache_set( $post_id, $stale_post, 'posts' );
		KnabbelWP\sync_story_state( $post_id, $stale_post );
		$this->assert_same(
			$title . ' hersteld',
			$this->get_babbel_story( $story_id )['title'] ?? null,
			'A sync must send the post as stored, not a stale cached copy.'
		);

		// The story went live again after republishing; age it so earlier dates still move the window.
		KnabbelWP\update_story_state( $post_id, array( 'window_base' => $this->past_post_date( 30 ) ) );

		// A second failure while a retry is pending neither schedules nor counts another retry.
		$this->set_babbel_writes_failing( true );
		$this->set_post_date( $post_id, $this->past_post_date( 7 ) );
		$this->set_post_date( $post_id, $this->past_post_date( 8 ) );
		$this->set_babbel_writes_failing( false );
		$this->assert_same(
			array( 1, 1 ),
			array( $this->sync_retry_count( $post_id ), KnabbelWP\get_story_state( $post_id )['sync_retry_attempts'] ?? null ),
			'A pending retry must not be duplicated or counted twice.'
		);
		$this->update_post( $post_id, array() );
		$this->assert_story_window( $story_id, $this->past_post_date( 8 ), 'The newest date must win.' );
		$this->assert_synced( $post_id, 'Precondition: the story must be in sync.' );

		// A retry that cannot be scheduled is not counted.
		$this->set_babbel_writes_failing( true );
		add_filter( 'pre_as_schedule_single_action', '__return_zero' );
		try {
			$this->set_post_date( $post_id, $this->past_post_date( 9 ) );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', '__return_zero' );
			$this->set_babbel_writes_failing( false );
		}
		$this->assert_same(
			array( 0, null ),
			array( $this->sync_retry_count( $post_id ), KnabbelWP\get_story_state( $post_id )['sync_retry_attempts'] ?? null ),
			'A retry that could not be scheduled must not be counted.'
		);
		$this->assert_recent_error_logged( 'Could not schedule a Babbel sync retry', 'A scheduling failure must be logged.' );
		$this->update_post( $post_id, array() );
		$this->assert_synced( $post_id, 'Precondition: the story must be in sync.' );

		// An update Babbel applied without answering is checked before the next comparison.
		$current_title = (string) get_post_field( 'post_title', $post_id );
		$this->set_lost_babbel_responses( array( 'PUT' ) );
		$this->update_post( $post_id, array( 'post_title' => $title . ' onbeantwoord' ) );
		$this->set_lost_babbel_responses( array() );
		$this->assert_same( true, KnabbelWP\get_story_state( $post_id )['remote_unverified'] ?? null, 'An unanswered update must be marked unverified.' );
		$this->update_post( $post_id, array( 'post_title' => $current_title ) );
		$this->assert_same( $current_title, $this->get_babbel_story( $story_id )['title'] ?? null, 'Reverting must reach Babbel after an unanswered update.' );
		$this->assert_synced( $post_id, 'A verified story in line with the post must clear the failure.' );

		// A delete Babbel applied without answering does not keep a re-enabled story deleted.
		$this->set_lost_babbel_responses( array( 'DELETE' ) );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->set_lost_babbel_responses( array() );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Re-enabling after an unanswered delete must restore the story.' );
		$this->assert_story_dates_in_window(
			$this->get_babbel_story( $story_id ),
			$dates_before,
			$dates_after,
			'A story restored after an unanswered delete must get a new window.'
		);
		$this->assert_synced( $post_id, 'A verified restore must clear the failure.' );

		// A delete that went unanswered while the post no longer wants a story clears on the next save.
		KnabbelWP\update_story_state( $post_id, array( 'window_base' => $this->past_post_date( 30 ) ) );
		$this->set_lost_babbel_responses( array( 'DELETE' ) );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->set_lost_babbel_responses( array() );
		$this->update_post( $post_id, array() );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'A verified delete must end in the deleted state.' );
		$this->assert_synced( $post_id, 'An ordinary save must clear a resolved delete failure.' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Precondition: re-enabling must restore the story.' );

		// A restore Babbel applied without answering does not keep a disabled story live.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: disabling must delete the story.' );
		$this->set_lost_babbel_responses( array( 'PATCH' ) );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->set_lost_babbel_responses( array() );
		$this->assert_babbel_response_code( 200, 'GET', '/stories/' . $story_id );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Disabling after an unanswered restore must delete the story.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Precondition: re-enabling must restore the story.' );
		KnabbelWP\update_story_state( $post_id, array( 'window_base' => $this->past_post_date( 30 ) ) );
		$this->update_post( $post_id, array() );
		$this->assert_synced( $post_id, 'Precondition: the story must be in sync.' );

		// Retries stop after the configured number of attempts.
		$this->set_babbel_writes_failing( true );
		$this->set_post_date( $post_id, $this->past_post_date( 6 ) );
		$max_attempts = count( KnabbelWP\STORY_SYNC_RETRY_DELAYS );
		for ( $attempt = 1; $attempt < $max_attempts; ++$attempt ) {
			$this->run_pending_sync_retry( $post_id );
		}
		$this->assert_same( $max_attempts, KnabbelWP\get_story_state( $post_id )['sync_retry_attempts'] ?? null, 'Every retry must be counted.' );
		$this->run_pending_sync_retry( $post_id );
		$this->assert_same( 0, $this->sync_retry_count( $post_id ), 'No retry may be scheduled after the last attempt.' );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Sent, 'update' );

		// A failed delete is retried as a delete while the post still wants none.
		$this->set_babbel_writes_failing( false );
		$this->update_post( $post_id, array() );
		$this->assert_synced( $post_id, 'Precondition: the story must be in sync.' );
		$this->set_babbel_writes_failing( true );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Sent, 'delete' );
		$this->set_babbel_writes_failing( false );
		$this->run_pending_sync_retry( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'The retry must delete the story.' );
		$this->assert_synced( $post_id, 'A successful delete retry must clear the failure.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		// A retry whose operation is no longer wanted sends nothing and clears the failure.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Precondition: re-enabling must restore the story.' );
		$this->set_babbel_writes_failing( true );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->set_babbel_writes_failing( false );
		$this->assert_same(
			0,
			$this->count_babbel_requests( fn () => KnabbelWP\retry_story_sync( $post_id ) ),
			'A retry for an unwanted delete must not send a request.'
		);
		$this->assert_synced( $post_id, 'A retry with nothing left to sync must clear the failure.' );
		$this->assert_babbel_response_code( 200, 'GET', '/stories/' . $story_id );

		// A story synced before the snapshot existed is read from Babbel once. A start date later than the
		// post date fixes when it went live, so the window stays; the end date follows the settings.
		$legacy_state = KnabbelWP\get_story_state( $post_id );
		$post_date    = (string) get_post_field( 'post_date', $post_id );
		$kept         = KnabbelWP\calculate_story_dates( $this->past_post_date( 2 ) );
		$this->babbel_request(
			'PUT',
			'/stories/' . $story_id,
			array(
				'start_date' => $kept['start_date'],
				'end_date'   => $kept['end_date'],
			)
		);
		unset( $legacy_state['synced'], $legacy_state['window_base'] );
		$legacy_state['last_sync_error'] = KnabbelWP\build_story_sync_error( 'E2E: an earlier version failed', 'update' );
		update_post_meta( $post_id, '_zw_knabbel_story_state', $legacy_state );
		$this->update_post( $post_id, array( 'post_title' => $title . ' gemigreerd' ) );
		$story = $this->get_babbel_story( $story_id );
		$this->assert_same( $title . ' gemigreerd', $story['title'] ?? null, 'A migrated story must get the title change.' );
		$this->assert_same(
			array( $kept['start_date'], $kept['end_date'] ),
			array( $this->date_only( $story['start_date'] ?? '' ), $this->date_only( $story['end_date'] ?? '' ) ),
			'Migrating must keep a window that started after the post date.'
		);
		$this->assert_synced( $post_id, 'A migrated story in line with the post must clear a legacy error.' );

		// A start date before the post date means a date change never reached Babbel: migrating sends it.
		$lost = KnabbelWP\calculate_story_dates( $this->past_post_date( 40 ) );
		$this->babbel_request(
			'PUT',
			'/stories/' . $story_id,
			array(
				'start_date' => $lost['start_date'],
				'end_date'   => $lost['end_date'],
			)
		);
		$legacy_state = KnabbelWP\get_story_state( $post_id );
		unset( $legacy_state['synced'], $legacy_state['window_base'] );
		update_post_meta( $post_id, '_zw_knabbel_story_state', $legacy_state );
		KnabbelWP\retry_story_sync( $post_id );
		$this->assert_story_window( $story_id, $post_date, 'Migrating must send a date change that never reached Babbel.' );

		// With changed offsets, the window follows the current settings: here the end date moves.
		$created = KnabbelWP\calculate_story_dates( $this->past_post_date( 2 ) );
		$this->babbel_request(
			'PUT',
			'/stories/' . $story_id,
			array(
				'start_date' => $created['start_date'],
				'end_date'   => $created['end_date'],
			)
		);
		$legacy_state = KnabbelWP\get_story_state( $post_id );
		unset( $legacy_state['synced'], $legacy_state['window_base'] );
		update_post_meta( $post_id, '_zw_knabbel_story_state', $legacy_state );
		$settings                    = get_option( 'knabbel_settings', array() );
		$settings['end_days_offset'] = 5;
		update_option( 'knabbel_settings', $settings );
		try {
			KnabbelWP\retry_story_sync( $post_id );
			$live     = ( new DateTimeImmutable( $created['start_date'], wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
			$expected = KnabbelWP\calculate_story_dates( max( $post_date, $live ) );
		} finally {
			$this->configure_plugin();
		}
		$story = $this->get_babbel_story( $story_id );
		$this->assert_same(
			array( $expected['start_date'], $expected['end_date'] ),
			array( $this->date_only( $story['start_date'] ?? '' ), $this->date_only( $story['end_date'] ?? '' ) ),
			'With changed offsets, a migrated window must follow the documented rule.'
		);

		$this->assert_same( 1, $this->count_babbel_stories_by_title( $title . ' gemigreerd' ), 'Retries must never create another story.' );
		$this->assert_same( 0, $this->story_action_count( $post_id ), 'Retries must never queue story generation.' );
		$this->assert_same( $ai_calls, (int) get_option( 'knabbel_e2e_ai_call_count', 0 ), 'Retries must never generate speech text.' );

		// A late scheduled publication gets a now window, also when it had to wait for the lock or the
		// last update of the scheduled story went unanswered.
		foreach ( array( 'direct', 'busy', 'unanswered' ) as $variant ) {
			$busy    = 'busy' === $variant;
			$late_id = $this->create_enabled_draft(
				'E2E late publicatie ' . $variant,
				'Een geplande publicatie die te laat door cron wordt uitgevoerd krijgt een venster vanaf de publicatie.'
			);
			$this->schedule_post( $late_id, $this->future_post_date( 2 ) );
			$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $late_id ) );
			$late_story_id = (string) ( KnabbelWP\get_story_state( $late_id )['story_id'] ?? '' );
			if ( 'unanswered' === $variant ) {
				$this->set_lost_babbel_responses( array( 'PUT' ) );
				$this->update_post( $late_id, array( 'post_title' => 'E2E late publicatie onbeantwoord bewerkt' ) );
				$this->set_lost_babbel_responses( array() );
			}
			$overdue = $this->past_post_date( 1 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Makes the scheduled date overdue, as a late cron run finds it.
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => $overdue,
					'post_date_gmt' => get_gmt_from_date( $overdue ),
				),
				array( 'ID' => $late_id )
			);
			clean_post_cache( $late_id );
			$dates_before = KnabbelWP\calculate_story_dates( 'now' );
			if ( $busy ) {
				$this->insert_sync_lock( $late_id, time() + 60 );
				add_filter( 'knabbel_sync_lock_wait', '__return_zero' );
			}
			try {
				wp_publish_post( $late_id );
			} finally {
				if ( $busy ) {
					remove_filter( 'knabbel_sync_lock_wait', '__return_zero' );
					$this->delete_sync_lock( $late_id );
				}
			}
			if ( $busy ) {
				$this->run_action_scheduler( self::DEFERRED_SYNC_HOOK, 1, array( 'post_id' => $late_id ) );
			}
			$dates_after = KnabbelWP\calculate_story_dates( 'now' );
			$this->assert_story_dates_in_window(
				$this->get_babbel_story( $late_story_id ),
				$dates_before,
				$dates_after,
				'A late publication must get a now window (' . $variant . ').'
			);
		}
	}

	/**
	 * Counts pending deferred syncs for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int Action count.
	 */
	private function deferred_sync_count( int $post_id ): int {
		return $this->action_count( self::DEFERRED_SYNC_HOOK, ActionScheduler_Store::STATUS_PENDING, array( 'post_id' => $post_id ) );
	}

	/**
	 * Verifies that a restored story matches its post again.
	 */
	private function test_restore_window(): void {
		// Record every story as a restore brings it back, before the update gives it its new window.
		$restored = array();
		$capture  = static function ( $response, array $args, string $url ) use ( &$restored ) {
			if ( 'PATCH' === ( $args['method'] ?? '' ) && str_contains( $url, '/stories/' ) && is_array( $response ) ) {
				$restored[] = json_decode( wp_remote_retrieve_body( $response ), true );
			}
			return $response;
		};
		add_filter( 'http_response', $capture, 10, 3 );
		try {
			$this->check_restore_windows();
		} finally {
			remove_filter( 'http_response', $capture, 10 );
		}

		// A restored story must never be selectable for a bulletin before its update: Babbel only
		// selects a story on one of its weekdays, and a story the plugin deleted is parked on none.
		$this->assert_same( true, count( $restored ) >= 4, 'The scenario must restore stories.' );
		foreach ( $restored as $story ) {
			$this->assert_same( 0, $story['weekdays'] ?? null, 'A story must come back from a restore parked on no weekdays.' );
		}
	}

	/**
	 * Verifies #95's acceptance test with Babbel's own bulletin selection rules.
	 *
	 * Babbel selects a story for a bulletin on a date when it is active, the date
	 * lies within its window and its weekdays include that date's weekday (plus
	 * audio and a voice, which only narrow the selection further). The story is
	 * made active, as an editor would, and checked against those rules on the
	 * days of its old window, also at the moment a restore brings it back.
	 *
	 * Babbel versions that restore and update in one request bring every story
	 * back with its new window at once. With earlier versions, which the plugin
	 * simulates here too, a story this plugin did not park, because it was
	 * deleted directly in Babbel or by an earlier plugin version, is never
	 * restored: it stays deleted and the post gets a new story.
	 */
	private function test_embargo_never_selectable(): void {
		$this->with_two_step_restore( fn () => $this->check_embargo_routes( false ) );
		if ( $this->babbel_restores_atomically() ) {
			$this->check_embargo_routes( true );
			$this->check_restore_in_one_request_failures();
		}

		$this->with_two_step_restore( fn () => $this->check_parking_needs_evidence() );
		$this->check_save_during_creation();
	}

	/**
	 * Checks every route by which a story comes back for a later embargo.
	 *
	 * @param bool $atomic Whether Babbel restores and updates in one request.
	 */
	private function check_embargo_routes( bool $atomic ): void {
		foreach ( array( 'unschedule', 'trash', 'external', 'legacy' ) as $route ) {
			$route     .= $atomic ? ' (one request)' : ' (two requests)';
			$post_id    = $this->create_enabled_draft(
				'E2E embargo nooit selecteerbaar ' . $route,
				'Een gepland artikel mag nooit eerder dan zijn venster in een bulletin terechtkomen.'
			);
			$first_date = $this->future_post_date( 2 );
			$this->schedule_post( $post_id, $first_date );
			$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
			$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
			$this->set_remote_story_status( $story_id, 'active' );
			$old_day = KnabbelWP\calculate_story_dates( $first_date )['start_date'];
			$this->assert_same( true, $this->selectable_on( $story_id, $old_day ), 'Precondition: the active story must be selectable in its window.' );

			// Pull the post back, then give it a later embargo date.
			if ( str_starts_with( $route, 'trash' ) ) {
				wp_trash_post( $post_id );
				wp_untrash_post( $post_id );
			} else {
				if ( ! str_starts_with( $route, 'unschedule' ) ) {
					// Deleted without parking: directly in Babbel, or by an earlier plugin version.
					$this->assert_babbel_response_code( 204, 'DELETE', '/stories/' . $story_id );
				}
				$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
			}
			$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: the story must be deleted.' );
			if ( str_starts_with( $route, 'legacy' ) ) {
				// Earlier versions kept no snapshot of what Babbel confirmed.
				$legacy = get_post_meta( $post_id, '_zw_knabbel_story_state', true );
				unset( $legacy['synced'] );
				update_post_meta( $post_id, '_zw_knabbel_story_state', $legacy );
			}

			$at_restore = array();
			$probe      = function ( $response, array $args, string $url ) use ( $story_id, $old_day, &$at_restore ) {
				if ( 'PATCH' === ( $args['method'] ?? '' ) && str_ends_with( $url, '/stories/' . $story_id ) ) {
					$at_restore[] = $this->selectable_on( $story_id, $old_day );
				}
				return $response;
			};
			$embargo    = $this->future_post_date( 10 );
			add_filter( 'http_response', $probe, 10, 3 );
			try {
				$this->schedule_post( $post_id, $embargo );
			} finally {
				remove_filter( 'http_response', $probe, 10 );
			}

			$new_day = KnabbelWP\calculate_story_dates( $embargo )['start_date'];
			if ( $atomic || str_starts_with( $route, 'unschedule' ) || str_starts_with( $route, 'trash' ) ) {
				$this->assert_story_status( $post_id, StoryStatus::Sent, 'Rescheduling must restore the story (' . $route . ').' );
				$this->assert_same(
					array( false ),
					$at_restore,
					'At the moment of the restore, the story must not be selectable on its old window (' . $route . ').'
				);
				$this->assert_same(
					false,
					$this->selectable_on( $story_id, $old_day ),
					'After the restore, the old window must not be selectable (' . $route . ').'
				);
				$this->assert_same( true, $this->selectable_on( $story_id, $new_day ), 'The story must be selectable in its new window (' . $route . ').' );
				$this->assert_same(
					$story_id,
					(string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' ),
					'The restore must keep the story (' . $route . ').'
				);
				continue;
			}

			$this->assert_same( array(), $at_restore, 'An unparked story must never be restored in two requests (' . $route . ').' );
			$this->assert_story_status( $post_id, StoryStatus::Scheduled, 'Rescheduling must send a new story instead (' . $route . ').' );
			$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
			$this->assert_story_status( $post_id, StoryStatus::Sent, 'The new story must be sent (' . $route . ').' );
			$new_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
			$this->assert_same( true, '' !== $new_id && $new_id !== $story_id, 'The post must get a new story (' . $route . ').' );
			$old = $this->babbel_get_json( '/stories?trashed=with&filter[id]=' . $story_id, 'The old story must be readable.' );
			$this->assert_same( true, null !== ( $old['data'][0]['deleted_at'] ?? null ), 'The old story must stay deleted (' . $route . ').' );
			$this->set_remote_story_status( $new_id, 'active' );
			foreach ( array( $story_id, $new_id ) as $id ) {
				$this->assert_same( false, $this->selectable_on( $id, $old_day ), 'No story may be selectable on the old window (' . $route . ').' );
			}
			$this->assert_same( true, $this->selectable_on( $new_id, $new_day ), 'The new story must be selectable in its new window (' . $route . ').' );
		}
	}

	/**
	 * Verifies failures around a restore in one request.
	 *
	 * An expired story keeps its claim to the default status when the update
	 * after the restore fails, and a 404 to the combined request never leads
	 * to a restore in two requests.
	 */
	private function check_restore_in_one_request_failures(): void {
		$post_id = $this->create_enabled_draft(
			'E2E herstel in een verzoek verlopen',
			'Een verlopen verhaal komt terug, maar de status volgt pas later.'
		);
		$this->schedule_post( $post_id, $this->future_post_date( 2 ) );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
		$this->set_remote_story_status( $story_id, 'expired' );
		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: the story must be deleted.' );

		$this->set_failing_babbel_methods( array( 'PUT' ) );
		try {
			$this->schedule_post( $post_id, $this->future_post_date( 10 ) );
		} finally {
			$this->set_failing_babbel_methods( array() );
		}
		$this->assert_same( 'expired', $this->get_babbel_story( $story_id )['status'] ?? null, 'Precondition: the status update must have failed.' );
		$this->assert_same( true, KnabbelWP\get_story_state( $post_id )['expired_reset'] ?? null, 'A failed status update must keep the obligation.' );
		$this->run_pending_sync_retry( $post_id );
		$this->assert_same(
			(string) get_option( 'knabbel_settings' )['default_status'],
			$this->get_babbel_story( $story_id )['status'] ?? null,
			'The retry must give the restored story the default status.'
		);

		// A 404 to the combined request proves Babbel restores in one request, so no bare restore follows.
		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: the story must be deleted again.' );
		$bare     = 0;
		$not_here = static function ( $preempt, array $args, string $url ) use ( $story_id, &$bare ) {
			if ( 'PATCH' !== ( $args['method'] ?? '' ) || ! str_ends_with( $url, '/stories/' . $story_id ) ) {
				return $preempt;
			}
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			if ( array( 'deleted_at' => '' ) === $body ) {
				++$bare;
				return $preempt;
			}
			return array(
				'headers'  => array( 'content-type' => 'application/problem+json' ),
				'body'     => (string) wp_json_encode(
					array(
						'status' => 404,
						'detail' => 'Story not found',
						'code'   => 'story.not_found',
					)
				),
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $not_here, 1, 3 );
		try {
			$this->schedule_post( $post_id, $this->future_post_date( 12 ) );
		} finally {
			remove_filter( 'pre_http_request', $not_here, 1 );
		}
		$this->assert_same( 0, $bare, 'A 404 to the combined request must never lead to a restore in two requests.' );
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same(
			array( StoryStatus::Deleted->value, 1 ),
			array( $state['status'] ?? null, $this->sync_retry_count( $post_id ) ),
			'The story must stay deleted and be retried.'
		);
		$this->run_pending_sync_retry( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'The retry must restore the story in one request.' );
	}

	/**
	 * Verifies that only a confirmed park lets a deleted story be restored.
	 *
	 * The park succeeds and the delete is rejected; enabling the story again sends
	 * its weekdays back, but the answer is lost. After a deletion outside
	 * WordPress, the story must not be restored as if it were still parked.
	 */
	private function check_parking_needs_evidence(): void {
		$post_id    = $this->create_enabled_draft(
			'E2E parkeren vraagt bewijs',
			'Een verloren antwoord mag een verhaal niet als geparkeerd laten gelden.'
		);
		$first_date = $this->future_post_date( 2 );
		$this->schedule_post( $post_id, $first_date );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
		$this->set_remote_story_status( $story_id, 'active' );
		$old_day = KnabbelWP\calculate_story_dates( $first_date )['start_date'];

		$reject_delete = static function ( $preempt, array $args ) {
			if ( 'DELETE' !== ( $args['method'] ?? '' ) ) {
				return $preempt;
			}
			return array(
				'headers'  => array( 'content-type' => 'application/problem+json' ),
				'body'     => (string) wp_json_encode( array( 'detail' => 'E2E: delete rejected' ) ),
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $reject_delete, 1, 2 );
		try {
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		} finally {
			remove_filter( 'pre_http_request', $reject_delete, 1 );
		}
		$this->assert_same( 0, $this->get_babbel_story( $story_id )['weekdays'] ?? null, 'Precondition: the story must be parked.' );

		$this->set_lost_babbel_responses( array( 'PUT' ) );
		try {
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		} finally {
			$this->set_lost_babbel_responses( array() );
		}
		$this->assert_same( 127, $this->get_babbel_story( $story_id )['weekdays'] ?? null, 'Precondition: the lost update must have applied.' );
		$this->assert_same(
			127,
			KnabbelWP\get_story_state( $post_id )['synced']['weekdays'] ?? null,
			'An unanswered update must not leave the story recorded as parked.'
		);

		$this->assert_babbel_response_code( 204, 'DELETE', '/stories/' . $story_id );
		$restores = 0;
		// Reads the story ID by reference, so it follows each case below.
		$count   = static function ( $response, array $args, string $url ) use ( &$story_id, &$restores ) {
			if ( 'PATCH' === ( $args['method'] ?? '' ) && str_ends_with( $url, '/stories/' . $story_id ) ) {
				++$restores;
			}
			return $response;
		};
		$embargo = $this->future_post_date( 10 );
		add_filter( 'http_response', $count, 10, 3 );
		try {
			$this->schedule_post( $post_id, $embargo );
		} finally {
			remove_filter( 'http_response', $count, 10 );
		}
		$this->assert_same( 0, $restores, 'A story without evidence of parking must never be restored.' );
		$this->assert_story_status( $post_id, StoryStatus::Scheduled, 'The post must get a new story instead.' );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$new_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
		$this->assert_same( true, '' !== $new_id && $new_id !== $story_id, 'The post must get a new story.' );
		$this->assert_same( false, $this->selectable_on( $story_id, $old_day ), 'The old story must stay out of bulletins.' );

		// A confirmed park proves nothing later: an editor gives the story its weekdays back in Babbel
		// and deletes it there, while WordPress still records it parked.
		$post_id    = $this->create_enabled_draft(
			'E2E parkeren later ongedaan',
			'Een redacteur zet de weekdagen in Babbel terug voordat het verhaal verdwijnt.'
		);
		$first_date = $this->future_post_date( 2 );
		$this->schedule_post( $post_id, $first_date );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
		$this->set_remote_story_status( $story_id, 'active' );
		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_same( 0, KnabbelWP\get_story_state( $post_id )['synced']['weekdays'] ?? null, 'Precondition: the story must be recorded parked.' );
		$this->edit_in_babbel( 'PATCH', $story_id, array( 'deleted_at' => '' ) );
		$this->edit_in_babbel( 'PUT', $story_id, array( 'weekdays' => 127 ) );
		$this->assert_babbel_response_code( 204, 'DELETE', '/stories/' . $story_id );
		$restores = 0;
		add_filter( 'http_response', $count, 10, 3 );
		try {
			$this->schedule_post( $post_id, $this->future_post_date( 10 ) );
		} finally {
			remove_filter( 'http_response', $count, 10 );
		}
		$this->assert_same( 0, $restores, 'A story Babbel shows unparked must never be restored.' );
		$this->assert_story_status( $post_id, StoryStatus::Scheduled, 'The post must get a new story instead.' );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$this->assert_same(
			false,
			$this->selectable_on( $story_id, KnabbelWP\calculate_story_dates( $first_date )['start_date'] ),
			'The old story must stay out of bulletins.'
		);

		// A story restored in Babbel is already live: it is only brought in line, never restored again.
		$post_id = $this->create_enabled_draft(
			'E2E al hersteld in Babbel',
			'Het verhaal is buiten WordPress al teruggezet.'
		);
		$this->schedule_post( $post_id, $this->future_post_date( 2 ) );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->edit_in_babbel( 'PATCH', $story_id, array( 'deleted_at' => '' ) );
		$embargo  = $this->future_post_date( 10 );
		$restores = 0;
		add_filter( 'http_response', $count, 10, 3 );
		try {
			$this->schedule_post( $post_id, $embargo );
		} finally {
			remove_filter( 'http_response', $count, 10 );
		}
		$this->assert_same( 0, $restores, 'A live story must not be restored again.' );
		$this->assert_synced( $post_id, 'A live story must be brought in line.' );
		$this->assert_same(
			KnabbelWP\calculate_story_dates( $embargo )['start_date'],
			substr( (string) ( $this->get_babbel_story( $story_id )['start_date'] ?? '' ), 0, 10 ),
			'A live story must get the new window.'
		);
	}

	/**
	 * Edits a story directly in Babbel, as an editor would.
	 *
	 * @param string               $method   HTTP method.
	 * @param string               $story_id Babbel story ID.
	 * @param array<string, mixed> $body     Request body.
	 */
	private function edit_in_babbel( string $method, string $story_id, array $body ): void {
		$response = $this->babbel_request( $method, '/stories/' . $story_id, $body );
		$this->assert_same( 200, wp_remote_retrieve_response_code( $response ), 'Precondition: the edit in Babbel must succeed.' );
	}

	/**
	 * Verifies that a save while the story is being created still reaches Babbel.
	 */
	private function check_save_during_creation(): void {
		$post_id = $this->create_enabled_draft(
			'E2E opslaan tijdens aanmaken',
			'Een nieuwe embargodatum tijdens het aanmaken moet alsnog in Babbel komen.'
		);
		$this->schedule_post( $post_id, $this->future_post_date( 2 ) );
		$embargo    = $this->future_post_date( 10 );
		$reschedule = function ( $response, array $args, string $url ) use ( $post_id, $embargo ) {
			static $done = false;
			if ( ! $done && 'POST' === ( $args['method'] ?? '' ) && str_ends_with( $url, '/stories' ) ) {
				$done = true;
				$this->schedule_post( $post_id, $embargo );
			}
			return $response;
		};
		add_filter( 'http_response', $reschedule, 10, 3 );
		try {
			$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		} finally {
			remove_filter( 'http_response', $reschedule, 10 );
		}
		$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
		$this->assert_same(
			KnabbelWP\calculate_story_dates( $embargo )['start_date'],
			substr( (string) ( $this->get_babbel_story( $story_id )['start_date'] ?? '' ), 0, 10 ),
			'A reschedule during creation must give the new story its new window.'
		);
	}

	/**
	 * Reports whether Babbel restores a story and updates it in one request.
	 *
	 * Earlier versions reject fields next to deleted_at before looking up the
	 * story; supporting versions report the missing story instead.
	 *
	 * @return bool True when Babbel supports it.
	 */
	private function babbel_restores_atomically(): bool {
		if ( null === $this->atomic_restore ) {
			$response             = $this->babbel_request(
				'PATCH',
				'/stories/999999999',
				array(
					'deleted_at' => '',
					'title'      => 'E2E probe',
				)
			);
			$this->atomic_restore = 404 === wp_remote_retrieve_response_code( $response );
		}

		return $this->atomic_restore;
	}

	/**
	 * Runs a callback as if Babbel could only restore without fields.
	 *
	 * Answers a restore that carries fields as Babbel v0.17.0 does: 400 with an
	 * unknown field, before anything is restored.
	 *
	 * @param callable $callback The checks to run.
	 */
	private function with_two_step_restore( callable $callback ): void {
		$reject = static function ( $preempt, array $args, string $url ) {
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			if (
				'PATCH' !== ( $args['method'] ?? '' )
				|| ! str_starts_with( $url, self::BABBEL_BASE_URL . '/stories/' )
				|| ! is_array( $body )
				|| ! array_key_exists( 'deleted_at', $body )
				|| array() === array_diff_key( $body, array( 'deleted_at' => true ) )
			) {
				return $preempt;
			}
			return array(
				'headers'  => array( 'content-type' => 'application/problem+json' ),
				'body'     => (string) wp_json_encode(
					array(
						'title'  => 'Bad Request',
						'status' => 400,
						'detail' => 'Request body is not valid JSON for this endpoint',
						'errors' => array(
							array(
								'field'   => (string) array_key_first( array_diff_key( $body, array( 'deleted_at' => true ) ) ),
								'code'    => 'unknown_field',
								'message' => 'unknown field',
							),
						),
					)
				),
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $reject, 1, 3 );
		try {
			$callback();
		} finally {
			remove_filter( 'pre_http_request', $reject, 1 );
		}
	}

	/**
	 * Reports whether Babbel's bulletin rules select a story on a date.
	 *
	 * Mirrors Babbel's GetStoriesForBulletin conditions on status, window and
	 * weekday through the story list filters.
	 *
	 * @param string $story_id Babbel story ID.
	 * @param string $date     Date as Y-m-d.
	 * @return bool True when the story matches.
	 */
	private function selectable_on( string $story_id, string $date ): bool {
		$weekday_bit = 1 << (int) ( new DateTimeImmutable( $date, wp_timezone() ) )->format( 'w' );
		$list        = $this->babbel_get_json(
			'/stories?' . http_build_query(
				array(
					'filter' => array(
						'id'         => $story_id,
						'status'     => 'active',
						'start_date' => array( 'lte' => $date ),
						'end_date'   => array( 'gte' => $date ),
						'weekdays'   => array( 'band' => $weekday_bit ),
					),
				)
			),
			'The bulletin selection query must succeed.'
		);

		return array() !== ( $list['data'] ?? array() );
	}

	/**
	 * Restores stories in every way a post can bring its story back.
	 */
	private function check_restore_windows(): void {
		// Unschedule, then reschedule for a later embargo date.
		$post_id = $this->create_enabled_draft( 'E2E embargo verschoven', 'Een gepland artikel wordt teruggetrokken en later opnieuw ingepland.' );
		$this->schedule_post( $post_id, $this->future_post_date( 2 ) );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );
		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: unscheduling must delete the story.' );
		$embargo_date = $this->future_post_date( 10 );
		$this->schedule_post( $post_id, $embargo_date );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Rescheduling must restore the story.' );
		$this->assert_story_window( $story_id, $embargo_date, 'A restored story must get the window of the new embargo date.' );
		$this->assert_same( 127, $this->get_babbel_story( $story_id )['weekdays'] ?? null, 'A restored story must get its weekdays back.' );
		$this->assert_synced( $post_id, 'A complete restore must leave nothing to sync.' );

		// Trash and untrash a scheduled post, then schedule it again.
		wp_trash_post( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: trashing must delete the story.' );
		wp_untrash_post( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Untrashing to draft must keep the story deleted.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
		$later_embargo = $this->future_post_date( 12 );
		$this->schedule_post( $post_id, $later_embargo );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Scheduling the untrashed post must restore the story.' );
		$this->assert_story_window( $story_id, $later_embargo, 'The story must get the window of the later embargo date.' );

		// Unpublish, then publish again.
		list( $post_id, $story_id ) = $this->create_sent_story(
			'E2E opnieuw gepubliceerd',
			'Een gepubliceerd artikel gaat offline en wordt later opnieuw gepubliceerd.'
		);
		$this->set_stale_story_window( $story_id );
		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: unpublishing must delete the story.' );
		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$this->assert_story_dates_in_window(
			$this->get_babbel_story( $story_id ),
			$dates_before,
			$dates_after,
			'Publishing again must restore with a new window.'
		);

		// An expired story gets the default status back; any other status stays.
		foreach ( array(
			'expired' => 'draft',
			'active'  => 'active',
		) as $remote_status => $expected_status ) {
			$this->set_remote_story_status( $story_id, $remote_status );
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
			$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: disabling must delete the story.' );
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
			$this->assert_same(
				$expected_status,
				$this->get_babbel_story( $story_id )['status'] ?? null,
				'A restored ' . $remote_status . ' story must end ' . $expected_status . '.'
			);
		}

		// A restore whose update Babbel rejects deletes the story again, so it cannot stay live.
		$this->set_stale_story_window( $story_id );
		$this->set_remote_story_status( $story_id, 'expired' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$reject_put = static function ( $preempt, array $args ) {
			if ( 'PUT' === ( $args['method'] ?? '' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/problem+json' ),
					'body'     => wp_json_encode(
						array(
							'detail' => 'E2E: updating is not allowed right now',
							'code'   => 'auth.forbidden',
						)
					),
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'cookies'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $reject_put, 1, 2 );
		try {
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		} finally {
			remove_filter( 'pre_http_request', $reject_put, 1 );
		}
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Deleted, 'restore' );
		$this->assert_same(
			'Story could not be updated after restoring and was deleted again',
			$state['message'] ?? null,
			'A failed restore update must not read as a success.'
		);
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
		$this->assert_same( 1, $this->sync_retry_count( $post_id ), 'A failed restore must be retried.' );
		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->run_pending_sync_retry( $post_id );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$story       = $this->get_babbel_story( $story_id );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'The retried restore must send the new window.' );
		$this->assert_same( 'draft', $story['status'] ?? null, 'The retried restore must decide the status again.' );
		$this->assert_synced( $post_id, 'A successful retry must complete the restore.' );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'A successful retry must leave the story sent.' );

		// When deleting it again fails too, the story stays live, parked, until a retry sends the new window and any owed status.
		$this->set_stale_story_window( $story_id );
		$this->set_remote_story_status( $story_id, 'expired' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->set_failing_babbel_methods( array( 'PUT', 'DELETE' ) );
		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$this->set_failing_babbel_methods( array() );
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Sent, 'restore' );
		$this->assert_same(
			'Story restored in Babbel, but updating it failed',
			$state['message'] ?? null,
			'A partial restore must not read as a success.'
		);
		$this->assert_same( true, $state['remote_unverified'] ?? null, 'An unanswered write must be verified, so the retry reads Babbel first.' );
		$stale = KnabbelWP\calculate_story_dates( $this->future_post_date( 40 ) );
		$this->assert_same(
			$stale['start_date'],
			$this->date_only( $this->get_babbel_story( $story_id )['start_date'] ?? '' ),
			'Babbel must still have the old window.'
		);

		// When Babbel's status cannot be read, or an answer lacks it, the default status stays owed and nothing is sent.
		$no_status = static function ( $response, array $args, string $url ) use ( $story_id, $post_id ) {
			if (
				'GET' === ( $args['method'] ?? 'GET' )
				&& str_ends_with( $url, '/stories/' . $story_id )
				&& empty( KnabbelWP\get_story_state( $post_id )['remote_unverified'] )
				&& is_array( $response )
			) {
				$response['body'] = '{}';
			}
			return $response;
		};
		add_filter( 'http_response', $no_status, 20, 3 );
		try {
			$this->run_pending_sync_retry( $post_id );
		} finally {
			remove_filter( 'http_response', $no_status, 20 );
		}
		$this->assert_same(
			array( true, 1, 'expired' ),
			array(
				KnabbelWP\get_story_state( $post_id )['expired_reset'] ?? null,
				$this->sync_retry_count( $post_id ),
				$this->get_babbel_story( $story_id )['status'] ?? null,
			),
			'An answer without a status must keep the owed default status and retry.'
		);

		// Only the status read fails: the one made after the unanswered write has been verified.
		$fail_second = static function ( $preempt, array $args, string $url ) use ( $story_id, $post_id ) {
			if (
				'GET' === ( $args['method'] ?? 'GET' )
				&& str_ends_with( $url, '/stories/' . $story_id )
				&& empty( KnabbelWP\get_story_state( $post_id )['remote_unverified'] )
			) {
				return new WP_Error( 'http_request_failed', 'E2E: Babbel could not be read' );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $fail_second, 1, 3 );
		try {
			$this->run_pending_sync_retry( $post_id );
		} finally {
			remove_filter( 'pre_http_request', $fail_second, 1 );
		}
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same(
			array( true, 1, 'expired' ),
			array( $state['expired_reset'] ?? null, $this->sync_retry_count( $post_id ), $this->get_babbel_story( $story_id )['status'] ?? null ),
			'An unreadable status must keep the owed default status and retry.'
		);

		$this->run_pending_sync_retry( $post_id );
		$story = $this->get_babbel_story( $story_id );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'The retry must send the new window.' );
		$this->assert_same( 'draft', $story['status'] ?? null, 'A story still expired must get the default status once its update succeeds.' );
		$this->assert_synced( $post_id, 'A successful retry must clear the partial restore.' );

		// A story is never deleted unparked: when parking fails, the delete waits for a retry.
		$this->set_failing_babbel_methods( array( 'PUT' ) );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->set_failing_babbel_methods( array() );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Sent, 'delete' );
		$this->assert_babbel_response_code( 200, 'GET', '/stories/' . $story_id );
		$this->run_pending_sync_retry( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'The retry must park and delete the story.' );
		$this->assert_same( 0, $this->trashed_story( $story_id )['weekdays'] ?? null, 'The retried delete must park the story.' );

		// A parked story whose delete Babbel rejects gets its weekdays back once it is wanted again.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Precondition: re-enabling must restore the story.' );
		$reject_delete = static function ( $preempt, array $args ) {
			if ( 'DELETE' === ( $args['method'] ?? '' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/problem+json' ),
					'body'     => wp_json_encode(
						array(
							'detail' => 'E2E: deleting is not allowed right now',
							'code'   => 'auth.forbidden',
						)
					),
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'cookies'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $reject_delete, 1, 2 );
		try {
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		} finally {
			remove_filter( 'pre_http_request', $reject_delete, 1 );
		}
		$this->assert_same( 0, $this->get_babbel_story( $story_id )['weekdays'] ?? null, 'Precondition: the story must be parked but live.' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_same( 127, $this->get_babbel_story( $story_id )['weekdays'] ?? null, 'A parked story that is wanted again must get its weekdays back.' );
		$this->assert_synced( $post_id, 'A parked story in line with the post must clear the failure.' );

		// A restore whose answer lacks the story reads it, and still sends the new window.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: disabling must delete the story.' );
		$empty_patch = static function ( $response, array $args ) {
			if ( 'PATCH' === ( $args['method'] ?? '' ) && is_array( $response ) ) {
				$response['body'] = '{}';
			}
			return $response;
		};
		add_filter( 'http_response', $empty_patch, 20, 2 );
		try {
			$dates_before = KnabbelWP\calculate_story_dates( 'now' );
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
			$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		} finally {
			remove_filter( 'http_response', $empty_patch, 20 );
		}
		$story = $this->get_babbel_story( $story_id );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'A restore without the story in its answer must still send the new window.' );
		$this->assert_same( 127, $story['weekdays'] ?? null, 'A restore without the story in its answer must send the weekdays back.' );

		// A restore that cannot read the story at all is undone visibly and retried.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: disabling must delete the story.' );
		// The lookup before the restore still works; reading the restored story does not.
		$fail_read = static function ( $preempt, array $args, string $url ) use ( $story_id ) {
			if ( 'GET' === ( $args['method'] ?? 'GET' ) && str_ends_with( $url, '/stories/' . $story_id ) ) {
				return new WP_Error( 'http_request_failed', 'E2E: Babbel is unreachable' );
			}
			return $preempt;
		};
		add_filter( 'http_response', $empty_patch, 20, 2 );
		add_filter( 'pre_http_request', $fail_read, 1, 3 );
		try {
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		} finally {
			remove_filter( 'pre_http_request', $fail_read, 1 );
			remove_filter( 'http_response', $empty_patch, 20 );
		}
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same(
			array( StoryStatus::Deleted->value, 'Could not read the restored story from Babbel', 1 ),
			array( $state['status'] ?? null, $state['last_sync_error']['message'] ?? null, $this->sync_retry_count( $post_id ) ),
			'An unreadable restore must be undone with a visible error and a retry.'
		);
		$this->run_pending_sync_retry( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'The retry must finish the restore.' );

		// An unreadable restore whose rollback Babbel rejects keeps the new window on the retry.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: disabling must delete the story.' );
		$this->set_stale_story_window_while_deleted( $post_id, $story_id );
		$reject_delete_only = static function ( $preempt, array $args ) {
			if ( 'DELETE' === ( $args['method'] ?? '' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/problem+json' ),
					'body'     => wp_json_encode(
						array(
							'detail' => 'E2E: deleting is not allowed right now',
							'code'   => 'auth.forbidden',
						)
					),
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'cookies'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'http_response', $empty_patch, 20, 2 );
		add_filter( 'pre_http_request', $reject_delete_only, 1, 2 );
		add_filter( 'pre_http_request', $fail_read, 1, 3 );
		try {
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		} finally {
			remove_filter( 'pre_http_request', $fail_read, 1 );
			remove_filter( 'pre_http_request', $reject_delete_only, 1 );
			remove_filter( 'http_response', $empty_patch, 20 );
		}
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Precondition: a rejected rollback must keep the story live.' );
		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->run_pending_sync_retry( $post_id );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$story       = $this->get_babbel_story( $story_id );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'The retry must give the restored story a new window.' );
		$this->assert_same( 127, $story['weekdays'] ?? null, 'The retry must give the restored story its weekdays back.' );
		$this->assert_same( 'draft', $story['status'] ?? null, 'The retry must still give the expired story its default status.' );

		// A restore whose answers carry the story without its status still decides on the default status.
		foreach ( array( 'PATCH', 'GET' ) as $statusless ) {
			$this->set_remote_story_status( $story_id, 'expired' );
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
			$stripped     = 0;
			$strip_status = static function ( $response, array $args, string $url ) use ( $statusless, $story_id, &$stripped ) {
				$method = $args['method'] ?? 'GET';
				if ( ! is_array( $response ) || ! str_ends_with( $url, '/stories/' . $story_id ) ) {
					return $response;
				}
				if ( 'PATCH' === $method && 'GET' === $statusless ) {
					// Leave no fields, so the restore has to read the story.
					$response['body'] = '{}';
				} elseif ( $method === $statusless && 0 === $stripped++ ) {
					$story = json_decode( wp_remote_retrieve_body( $response ), true );
					unset( $story['status'] );
					$response['body'] = (string) wp_json_encode( $story );
				}
				return $response;
			};
			add_filter( 'http_response', $strip_status, 20, 3 );
			try {
				update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
			} finally {
				remove_filter( 'http_response', $strip_status, 20 );
			}
			$this->assert_same(
				'draft',
				$this->get_babbel_story( $story_id )['status'] ?? null,
				'A restore whose ' . $statusless . ' answer lacks the status must still give an expired story its default status.'
			);
			$this->assert_synced( $post_id, 'A restore that read the status must leave nothing to sync.' );
		}

		// A status read during a failed restore is final: a later editor choice of "expired" is not overwritten.
		$this->set_remote_story_status( $story_id, 'active' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->update_post( $post_id, array( 'post_title' => 'E2E status eenmaal beslist' ) );
		$statusless_patch = static function ( $response, array $args, string $url ) use ( $story_id ) {
			if ( 'PATCH' === ( $args['method'] ?? '' ) && str_ends_with( $url, '/stories/' . $story_id ) && is_array( $response ) ) {
				$story = json_decode( wp_remote_retrieve_body( $response ), true );
				unset( $story['status'] );
				$response['body'] = (string) wp_json_encode( $story );
			}
			return $response;
		};
		$reject_writes    = static function ( $preempt, array $args ) {
			if ( in_array( $args['method'] ?? '', array( 'PUT', 'DELETE' ), true ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/problem+json' ),
					'body'     => wp_json_encode(
						array(
							'detail' => 'E2E: not allowed right now',
							'code'   => 'auth.forbidden',
						)
					),
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'cookies'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'http_response', $statusless_patch, 20, 3 );
		add_filter( 'pre_http_request', $reject_writes, 1, 2 );
		try {
			update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		} finally {
			remove_filter( 'pre_http_request', $reject_writes, 1 );
			remove_filter( 'http_response', $statusless_patch, 20 );
		}
		$this->assert_same(
			null,
			KnabbelWP\get_story_state( $post_id )['expired_reset'] ?? null,
			'A status read as active during the restore must not stay owed.'
		);
		$this->set_remote_story_status( $story_id, 'expired' );
		$this->run_pending_sync_retry( $post_id );
		$this->assert_same( 'expired', $this->get_babbel_story( $story_id )['status'] ?? null, 'An editor choice made later must be kept.' );
		$this->set_remote_story_status( $story_id, 'active' );

		// A restore Babbel applied without answering still gives an expired story its default status.
		$this->set_remote_story_status( $story_id, 'expired' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->set_lost_babbel_responses( array( 'PATCH' ) );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->set_lost_babbel_responses( array() );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: an unanswered restore must be verified first.' );
		$this->run_pending_sync_retry( $post_id );
		$this->assert_same(
			'draft',
			$this->get_babbel_story( $story_id )['status'] ?? null,
			'An expired story restored without an answer must get the default status.'
		);
		$this->assert_synced( $post_id, 'The verified restore must leave nothing to sync.' );

		// An update that Babbel applied without answering cannot leave the story deleted unparked.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->update_post( $post_id, array( 'post_title' => 'E2E opnieuw gepubliceerd onbeantwoord' ) );
		$this->set_lost_babbel_responses( array( 'PUT' ) );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->set_lost_babbel_responses( array() );
		$live = $this->get_babbel_story( $story_id );
		$this->assert_same( 0, $live['weekdays'] ?? null, 'After an unanswered update, the story must be parked again before it is deleted.' );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'A story whose park went unanswered must not be deleted.' );
		$this->run_pending_sync_retry( $post_id );
		$this->assert_same( 127, $this->get_babbel_story( $story_id )['weekdays'] ?? null, 'The retry must give the story its weekdays back.' );
		$this->assert_synced( $post_id, 'The retry must leave nothing to sync.' );
	}

	/**
	 * Gives a deleted story an expired status and a window far from today by restoring, changing and
	 * deleting it in Babbel.
	 *
	 * @param int    $post_id  Post ID, whose story state must stay deleted.
	 * @param string $story_id Babbel story ID.
	 */
	private function set_stale_story_window_while_deleted( int $post_id, string $story_id ): void {
		$this->babbel_request( 'PATCH', '/stories/' . $story_id, array( 'deleted_at' => '' ) );
		$stale = KnabbelWP\calculate_story_dates( $this->future_post_date( 40 ) );
		$this->babbel_request(
			'PUT',
			'/stories/' . $story_id,
			array(
				'start_date' => $stale['start_date'],
				'end_date'   => $stale['end_date'],
				'weekdays'   => 0,
				'status'     => 'expired',
			)
		);
		$this->assert_babbel_response_code( 204, 'DELETE', '/stories/' . $story_id );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Precondition: the story must stay deleted.' );
	}

	/**
	 * Reads a soft-deleted story through the list with trashed stories.
	 *
	 * @param string $story_id Babbel story ID.
	 * @return array<string, mixed> The story, or an empty array.
	 */
	private function trashed_story( string $story_id ): array {
		$list = $this->babbel_get_json(
			'/stories?' . http_build_query(
				array(
					'trashed' => 'with',
					'filter'  => array( 'id' => $story_id ),
				)
			),
			'The story must be listed with trashed stories.'
		);

		return is_array( $list['data'][0] ?? null ) ? $list['data'][0] : array();
	}

	/**
	 * Moves a live story's window far away, so a restore must visibly send a new one.
	 *
	 * @param string $story_id Babbel story ID.
	 */
	private function set_stale_story_window( string $story_id ): void {
		$stale = KnabbelWP\calculate_story_dates( $this->future_post_date( 40 ) );
		$this->babbel_request(
			'PUT',
			'/stories/' . $story_id,
			array(
				'start_date' => $stale['start_date'],
				'end_date'   => $stale['end_date'],
			)
		);
		$story = $this->get_babbel_story( $story_id );
		$this->assert_same( $stale['start_date'], $this->date_only( $story['start_date'] ?? '' ), 'Precondition: the stale window must be set.' );
	}

	/**
	 * Sets a live story's status through the verification client.
	 *
	 * @param string $story_id Babbel story ID.
	 * @param string $status   Story status.
	 */
	private function set_remote_story_status( string $story_id, string $status ): void {
		$response = $this->babbel_request( 'PUT', '/stories/' . $story_id, array( 'status' => $status ) );
		$this->assert_same( 200, wp_remote_retrieve_response_code( $response ), 'Precondition: the status update must succeed.' );
		$this->assert_same( $status, $this->get_babbel_story( $story_id )['status'] ?? null, 'Precondition: the story must have status ' . $status . '.' );
	}

	/**
	 * Makes Babbel writes fail at the HTTP layer, as when Babbel is unreachable.
	 *
	 * Logins and reads still work, so repeated failures never trigger Babbel's
	 * account lockout and the verification client can keep reading.
	 *
	 * @param bool $failing Whether writes must fail.
	 */
	private function set_babbel_writes_failing( bool $failing ): void {
		$this->set_failing_babbel_methods( $failing ? array( 'PUT', 'PATCH', 'DELETE' ) : array() );
	}

	/**
	 * Makes story requests with the given methods fail at the HTTP layer.
	 *
	 * @param array<int, string> $methods HTTP methods that must fail; empty to stop failing.
	 */
	private function set_failing_babbel_methods( array $methods ): void {
		$this->failing_babbel_methods = $methods;
		remove_filter( 'pre_http_request', array( $this, 'fail_babbel_write' ), 1 );
		if ( array() !== $methods ) {
			add_filter( 'pre_http_request', array( $this, 'fail_babbel_write' ), 1, 3 );
		}
	}

	/**
	 * Fails Babbel story writes; see set_babbel_writes_failing().
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @param string                              $url     Request URL.
	 * @return false|array<string, mixed>|WP_Error Short-circuit value.
	 */
	public function fail_babbel_write( false|array|WP_Error $preempt, array $args, string $url ): false|array|WP_Error {
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		if ( str_starts_with( $url, self::BABBEL_BASE_URL . '/stories' ) && in_array( $method, $this->failing_babbel_methods, true ) ) {
			return new WP_Error( 'http_request_failed', 'E2E: Babbel is unreachable' );
		}

		return $preempt;
	}

	/**
	 * Reports whether a post's sync lock row exists.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when the lock is held.
	 */
	private function sync_lock_exists( int $post_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock bypasses the options cache.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'knabbel_sync_lock_' . $post_id ) );
	}

	/**
	 * Holds a post's sync lock as another request would.
	 *
	 * @param int $post_id    Post ID.
	 * @param int $expires_at Unix time at which the lock counts as abandoned.
	 */
	private function insert_sync_lock( int $post_id, int $expires_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'knabbel_sync_lock_' . $post_id,
				'option_value' => $expires_at . ':e2e-other-request',
				'autoload'     => 'off',
			)
		);
	}

	/**
	 * Removes a post's sync lock row, whoever holds it.
	 *
	 * @param int $post_id Post ID.
	 */
	private function delete_sync_lock( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock bypasses the options cache.
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'knabbel_sync_lock_' . $post_id ) );
	}

	/**
	 * Asserts that a message is among the recent errors.
	 *
	 * @param string $error_message Logged message.
	 * @param string $message       Failure message.
	 */
	private function assert_recent_error_logged( string $error_message, string $message ): void {
		$messages = array_column( (array) get_option( 'knabbel_recent_errors', array() ), 'message' );
		$this->assert_same( true, in_array( $error_message, $messages, true ), $message );
	}

	/**
	 * Lets story requests with the given methods reach Babbel but loses their answer.
	 *
	 * @param array<int, string> $methods HTTP methods whose answer is lost; empty to stop.
	 */
	private function set_lost_babbel_responses( array $methods ): void {
		$this->lost_babbel_methods = $methods;
		remove_filter( 'http_response', array( $this, 'lose_babbel_response' ), 1 );
		if ( array() !== $methods ) {
			add_filter( 'http_response', array( $this, 'lose_babbel_response' ), 1, 3 );
		}
	}

	/**
	 * Replaces an answer from Babbel with a timeout; see set_lost_babbel_responses().
	 *
	 * @param array<string, mixed>|WP_Error $response Response.
	 * @param array<string, mixed>          $args     Request arguments.
	 * @param string                        $url      Request URL.
	 * @return array<string, mixed>|WP_Error Response.
	 */
	public function lose_babbel_response( array|WP_Error $response, array $args, string $url ): array|WP_Error {
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		// Only an answer to a request Babbel carried out is lost; a rejected request changed nothing.
		if (
			str_starts_with( $url, self::BABBEL_BASE_URL . '/stories' )
			&& in_array( $method, $this->lost_babbel_methods, true )
			&& ! is_wp_error( $response )
			&& wp_remote_retrieve_response_code( $response ) < 300
		) {
			return new WP_Error( 'http_request_failed', 'E2E: the answer from Babbel was lost' );
		}

		return $response;
	}

	/**
	 * Changes a post date without changing its status.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $date    Local MySQL datetime.
	 */
	private function set_post_date( int $post_id, string $date ): void {
		$this->update_post(
			$post_id,
			array(
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
				'edit_date'     => true,
			)
		);
	}

	/**
	 * Returns a stable past local timestamp.
	 *
	 * @param int $days Days before now.
	 * @return string MySQL datetime.
	 */
	private function past_post_date( int $days ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( sprintf( '-%d days', $days ) )->setTime( 12, 0 )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Counts pending sync retries for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int Action count.
	 */
	private function sync_retry_count( int $post_id ): int {
		return $this->action_count( self::SYNC_RETRY_HOOK, ActionScheduler_Store::STATUS_PENDING, array( 'post_id' => $post_id ) );
	}

	/**
	 * Runs the one pending sync retry for a post before its due time.
	 *
	 * @param int $post_id Post ID.
	 */
	private function run_pending_sync_retry( int $post_id ): void {
		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => self::SYNC_RETRY_HOOK,
				'group'    => self::ACTION_GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'args'     => array( 'post_id' => $post_id ),
				'per_page' => -1,
			),
			'ids'
		);
		$this->assert_same( 1, count( $action_ids ), 'Exactly one sync retry must be pending.' );
		ActionScheduler::runner()->process_action( (int) $action_ids[0], 'Knabbel E2E' );
		$this->assert_same(
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler::store()->get_status( (int) $action_ids[0] ),
			'The sync retry action must complete.'
		);
	}

	/**
	 * Asserts that no sync failure or retry remains.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Failure message.
	 */
	private function assert_synced( int $post_id, string $message ): void {
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same(
			array( null, null, 0 ),
			array(
				$state['last_sync_error'] ?? null,
				$state['sync_retry_attempts'] ?? null,
				$this->sync_retry_count( $post_id ),
			),
			$message
		);
	}

	/**
	 * Counts Babbel API requests made by a callback.
	 *
	 * @param callable $callback Code to observe.
	 * @return int Number of requests to the Babbel API.
	 */
	private function count_babbel_requests( callable $callback ): int {
		$count   = 0;
		$counter = static function ( $preempt, array $args, string $url ) use ( &$count ) {
			if ( str_starts_with( $url, self::BABBEL_BASE_URL ) ) {
				++$count;
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $counter, 1, 3 );
		try {
			$callback();
		} finally {
			remove_filter( 'pre_http_request', $counter, 1 );
		}

		return $count;
	}

	/**
	 * Asserts that a story window follows a base date.
	 *
	 * @param string $story_id  Babbel story ID.
	 * @param string $base_date Local MySQL datetime the window derives from.
	 * @param string $message   Failure message.
	 */
	private function assert_story_window( string $story_id, string $base_date, string $message ): void {
		$story = $this->get_babbel_story( $story_id );
		$dates = KnabbelWP\calculate_story_dates( $base_date );
		$this->assert_same(
			array( $dates['start_date'], $dates['end_date'] ),
			array( $this->date_only( $story['start_date'] ?? '' ), $this->date_only( $story['end_date'] ?? '' ) ),
			$message
		);
	}

	/**
	 * Verifies plugin lifecycle cleanup.
	 */
	private function test_deactivation_cleanup(): void {
		$this->configure_plugin();
		$result = KnabbelWP\babbel_test_connection();
		$this->assert_same( true, $result['success'], 'Precondition: session cache must exist before deactivation.' );
		update_option(
			'knabbel_few_shot_examples',
			array(
				array(
					'input'  => 'x',
					'output' => 'y',
				),
			),
			false
		);

		$pending_id = $this->create_enabled_draft(
			'E2E deactivatie wachtrij',
			'Een nog wachtende geparametriseerde storyactie moet bij deactivatie worden opgeruimd.'
		);
		$this->update_post( $pending_id, array( 'post_status' => 'publish' ) );
		$this->assert_same( 1, $this->story_action_count( $pending_id ), 'Precondition: a parameterized story action must be pending.' );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( 'zw-knabbel-wp/zw-knabbel-wp.php' );

		$this->assert_same( true, ! is_plugin_active( 'zw-knabbel-wp/zw-knabbel-wp.php' ), 'The plugin must be inactive after deactivation.' );
		$this->assert_same( 0, $this->action_count( self::STORY_HOOK, ActionScheduler_Store::STATUS_PENDING ), 'Deactivation must clear story actions.' );
		$this->assert_same(
			0,
			$this->action_count( self::FEW_SHOT_HOOK, ActionScheduler_Store::STATUS_PENDING ),
			'Deactivation must clear the recurring few-shot action.'
		);
		$this->assert_same( false, get_transient( KnabbelWP\KNABBEL_SESSION_TRANSIENT ), 'Deactivation must clear Babbel sessions.' );
		$this->assert_same( false, get_option( 'knabbel_few_shot_examples', false ), 'Deactivation must clear few-shot data.' );
	}

	/**
	 * Creates an enabled draft or fails on WordPress errors.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return int Post ID.
	 * @throws RuntimeException When WordPress cannot create the post.
	 */
	private function create_enabled_draft( string $title, string $content ): int {
		$post_id = $this->create_draft( $title, $content );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );

		return $post_id;
	}

	/**
	 * Creates a plain draft or fails on WordPress errors.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return int Post ID.
	 * @throws RuntimeException When WordPress cannot create the post.
	 */
	private function create_draft( string $title, string $content ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Could not create test post: ' . $post_id->get_error_message() );
		}

		return $post_id;
	}

	/**
	 * Updates a post or fails on WordPress errors.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $updates Post fields.
	 * @throws RuntimeException When WordPress cannot update the post.
	 */
	private function update_post( int $post_id, array $updates ): void {
		$result = wp_update_post( array_merge( array( 'ID' => $post_id ), $updates ), true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'Could not update test post: ' . $result->get_error_message() );
		}
	}

	/**
	 * Publishes and processes a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function publish_and_process( int $post_id ): void {
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
	}

	/**
	 * Creates and processes a story to sent status.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return array{0: int, 1: string} Post ID and Babbel story ID.
	 */
	private function create_sent_story( string $title, string $content ): array {
		$post_id = $this->create_enabled_draft( $title, $content );
		$this->publish_and_process( $post_id );
		$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );

		return array( $post_id, $story_id );
	}

	/**
	 * Moves a post to a future publication date.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $date    Local MySQL datetime.
	 */
	private function schedule_post( int $post_id, string $date ): void {
		$this->update_post(
			$post_id,
			array(
				'post_status'   => 'publish',
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
				'edit_date'     => true,
			)
		);
	}

	/**
	 * Runs due actions through the queue runner.
	 *
	 * Delegates to the shared helper in the knabbel-e2e-control MU plugin.
	 *
	 * @param string                    $hook           Hook to execute.
	 * @param int                       $expected_count Expected number of actions to process.
	 * @param array<string, mixed>|null $args           Optional exact action arguments.
	 * @throws RuntimeException When an action does not complete successfully.
	 */
	private function run_action_scheduler( string $hook, int $expected_count = 1, ?array $args = null ): void {
		if ( ! function_exists( 'knabbel_e2e_run_due_actions' ) ) {
			throw new RuntimeException( 'The Knabbel E2E control MU plugin is required. Check the tests/e2e/compose.yml mount.' );
		}

		$processed = knabbel_e2e_run_due_actions( $hook, self::ACTION_GROUP, $args );
		$this->assert_same( $expected_count, $processed, sprintf( 'Expected %d due action(s) for hook %s.', $expected_count, $hook ) );
	}

	/**
	 * Counts actions by hook, status, and arguments.
	 *
	 * @param string                    $hook   Action hook.
	 * @param string                    $status Action Scheduler status.
	 * @param array<string, mixed>|null $args   Optional exact action arguments.
	 * @return int Action count.
	 */
	private function action_count( string $hook, string $status, ?array $args = null ): int {
		$query = array(
			'hook'     => $hook,
			'group'    => self::ACTION_GROUP,
			'status'   => $status,
			'per_page' => -1,
		);

		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return count( as_get_scheduled_actions( $query, 'ids' ) );
	}

	/**
	 * Counts pending story actions for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int Action count.
	 */
	private function story_action_count( int $post_id ): int {
		return $this->action_count( self::STORY_HOOK, ActionScheduler_Store::STATUS_PENDING, array( 'post_id' => $post_id ) );
	}

	/**
	 * Sends a request through the independent test client.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   API path below /api/v1.
	 * @param array<string, mixed>|null $body   Optional JSON body.
	 * @return array<string, mixed> WordPress HTTP response.
	 * @throws RuntimeException When the HTTP request fails.
	 */
	private function babbel_request( string $method, string $path, ?array $body = null ): array {
		if ( array() === $this->babbel_cookies ) {
			$this->babbel_login();
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'cookies' => $this->babbel_cookies,
		);

		if ( null !== $body ) {
			$args['headers'] = array( 'Content-Type' => 'application/json' );
			$args['body']    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BABBEL_BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Independent Babbel request failed: ' . $response->get_error_message() );
		}

		return $response;
	}

	/**
	 * Authenticates the independent verification client.
	 *
	 * @throws RuntimeException When the HTTP request fails.
	 */
	private function babbel_login(): void {
		// Keep the Babbel fixture credentials in sync with tests/playwright/utils.ts.
		$response = wp_remote_post(
			self::BABBEL_BASE_URL . '/sessions',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'username' => 'admin',
						'password' => 'admin',
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Independent Babbel login failed: ' . $response->get_error_message() );
		}

		$this->assert_same( 201, wp_remote_retrieve_response_code( $response ), 'Independent Babbel client must authenticate.' );
		$this->babbel_cookies = wp_remote_retrieve_cookies( $response );
		$this->assert_same( true, ! empty( $this->babbel_cookies ), 'Independent Babbel login must return a cookie.' );
	}

	/**
	 * Sends a GET request and decodes its JSON body.
	 *
	 * @param string $path    API path.
	 * @param string $message Failure message for a non-200 response.
	 * @return array<string, mixed> Decoded response.
	 */
	private function babbel_get_json( string $path, string $message ): array {
		$response = $this->babbel_request( 'GET', $path );
		$this->assert_same( 200, wp_remote_retrieve_response_code( $response ), $message );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );
		$this->assert_same( true, is_array( $decoded ), 'Babbel response must decode to an object.' );

		return $decoded;
	}

	/**
	 * Fetches and decodes one Babbel story.
	 *
	 * @param string $story_id Story ID.
	 * @return array<string, mixed> Story response.
	 */
	private function get_babbel_story( string $story_id ): array {
		$this->assert_same( true, '' !== $story_id, 'Story ID is required for remote verification.' );

		return $this->babbel_get_json( '/stories/' . rawurlencode( $story_id ), 'Expected Babbel story to be readable.' );
	}

	/**
	 * Counts stories with an exact title.
	 *
	 * @param string $title Story title.
	 * @return int Matching count.
	 */
	private function count_babbel_stories_by_title( string $title ): int {
		$path    = '/stories?' . http_build_query(
			array(
				'filter' => array( 'title' => $title ),
				'limit'  => 100,
			)
		);
		$decoded = $this->babbel_get_json( $path, 'Babbel story list must be readable.' );
		$stories = $decoded['data'] ?? null;
		$this->assert_same( true, is_array( $stories ), 'Babbel story list data must be an array.' );
		// The filter is applied server-side, so a full page means results were truncated.
		$this->assert_same( true, count( $stories ) < 100, 'Babbel story list must not fill the verification page.' );

		return count(
			array_filter(
				$stories,
				static fn( array $story ): bool => ( $story['title'] ?? null ) === $title
			)
		);
	}

	/**
	 * Asserts a response status.
	 *
	 * @param int    $expected Expected status.
	 * @param string $method   HTTP method.
	 * @param string $path     API path.
	 */
	private function assert_babbel_response_code( int $expected, string $method, string $path ): void {
		$response = $this->babbel_request( $method, $path );
		$this->assert_same(
			$expected,
			wp_remote_retrieve_response_code( $response ),
			sprintf( 'Babbel %s %s returned an unexpected status.', $method, $path )
		);
	}

	/**
	 * Returns a stable future local timestamp.
	 *
	 * @param int $days Days from now.
	 * @return string MySQL datetime.
	 */
	private function future_post_date( int $days ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( sprintf( '+%d days', $days ) )->setTime( 12, 0 )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Normalizes an API date or datetime to Y-m-d.
	 *
	 * @param mixed $value API value.
	 * @return string Date portion.
	 */
	private function date_only( mixed $value ): string {
		return is_string( $value ) ? substr( $value, 0, 10 ) : '';
	}

	/**
	 * Asserts the persisted lifecycle status.
	 *
	 * @param int         $post_id  Post ID.
	 * @param StoryStatus $expected Expected status.
	 * @param string      $message  Failure message.
	 */
	private function assert_story_status( int $post_id, StoryStatus $expected, string $message ): void {
		$this->assert_same( $expected->value, KnabbelWP\get_story_state( $post_id )['status'] ?? null, $message );
	}

	/**
	 * Asserts retryable lifecycle state.
	 *
	 * @param int         $post_id       Post ID.
	 * @param string      $story_id      Expected Babbel story ID.
	 * @param StoryStatus $status        Expected preserved lifecycle status.
	 * @param string      $operation     Expected failed sync operation.
	 * @param string|null $error_message Expected sync error message, when asserted.
	 */
	private function assert_sync_failure_preserved(
		int $post_id,
		string $story_id,
		StoryStatus $status,
		string $operation,
		?string $error_message = null
	): void {
		$state          = KnabbelWP\get_story_state( $post_id );
		$message_prefix = ucfirst( $operation ) . ' failure must ';
		$this->assert_same( $status->value, $state['status'] ?? null, $message_prefix . 'preserve ' . $status->value . ' state.' );
		$this->assert_same( $story_id, (string) ( $state['story_id'] ?? '' ), $message_prefix . 'preserve the story ID.' );
		$this->assert_same( $operation, $state['last_sync_error']['operation'] ?? null, $message_prefix . 'identify its operation.' );
		if ( null !== $error_message ) {
			$this->assert_same( $error_message, $state['last_sync_error']['message'] ?? null, $message_prefix . 'report the Babbel problem detail.' );
		}
	}

	/**
	 * Asserts dates within a processing window.
	 *
	 * @param array<string, mixed>  $story   Babbel story.
	 * @param array<string, string> $before  Expected dates captured before processing.
	 * @param array<string, string> $after   Expected dates captured after processing.
	 * @param string                $message Failure message prefix.
	 */
	private function assert_story_dates_in_window( array $story, array $before, array $after, string $message ): void {
		$actual   = array(
			'start_date' => $this->date_only( $story['start_date'] ?? '' ),
			'end_date'   => $this->date_only( $story['end_date'] ?? '' ),
		);
		$expected = array(
			array(
				'start_date' => $before['start_date'],
				'end_date'   => $before['end_date'],
			),
			array(
				'start_date' => $after['start_date'],
				'end_date'   => $after['end_date'],
			),
		);

		$this->assert_same(
			true,
			in_array( $actual, $expected, true ),
			$message
		);
	}

	/**
	 * Asserts strict equality.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 * @throws RuntimeException When the values differ.
	 */
	private function assert_same( mixed $expected, mixed $actual, string $message ): void {
		++$this->assertion_count;
		if ( $expected !== $actual ) {
			throw new RuntimeException( sprintf( '%s Expected %s, got %s.', $message, wp_json_encode( $expected ), wp_json_encode( $actual ) ) );
		}
	}
}

( new Knabbel_E2E_Suite() )->run();
