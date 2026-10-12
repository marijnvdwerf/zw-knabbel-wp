<?php
/**
 * Babbel API integration for story synchronization.
 *
 * Handles authentication, session management, and API communication with the Babbel API.
 *
 * @package KnabbelWP
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// One cached session record for the configured credentials, stored with their fingerprint.
const KNABBEL_SESSION_TRANSIENT = 'knabbel_session';

/**
 * Returns the configured Babbel API credentials.
 *
 * @since 0.1.0
 * @return array{base_url: string, username: string, password: string} Array with base_url, username, and password.
 *
 * @phpstan-return BabbelCredentials
 */
function babbel_get_credentials(): array {
	$options = get_plugin_settings();
	return array(
		'base_url' => rtrim( (string) $options['api_base_url'], '/' ),
		'username' => (string) $options['api_username'],
		'password' => (string) $options['api_password'],
	);
}

/**
 * Returns cached or newly created session cookies.
 *
 * @since 0.1.0
 * @since 0.8.0 Caches a single fingerprinted session for 23 hours.
 * @return array<int, \WP_Http_Cookie>|\WP_Error Session cookies array or WP_Error on failure.
 */
function babbel_get_session_cookies(): array|\WP_Error {
	$credentials = babbel_get_credentials();

	if ( empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
		return new \WP_Error( 'missing_credentials', __( 'Username and password are required', 'zw-knabbel-wp' ) );
	}

	// A keyed hash, so a cached session is never reused for other credentials.
	$fingerprint = wp_hash( implode( "\n", $credentials ), 'auth', 'sha256' );
	$session     = get_transient( KNABBEL_SESSION_TRANSIENT );

	if (
		is_array( $session )
		&& is_string( $session['fingerprint'] ?? null )
		&& hash_equals( $fingerprint, $session['fingerprint'] )
		&& is_array( $session['cookies'] ?? null )
		&& array() !== $session['cookies']
	) {
		return $session['cookies'];
	}

	// Create fresh session.
	$login_endpoint = $credentials['base_url'] . '/sessions';
	$login_data     = wp_json_encode(
		array(
			'username' => $credentials['username'],
			'password' => $credentials['password'],
		)
	);

		$login_args = array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $login_data,
			'timeout' => 30,
		);

		$response = wp_remote_post( $login_endpoint, $login_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 201 !== $response_code ) {
			$error = babbel_error_message( $response_code, wp_remote_retrieve_body( $response ) );
			return new \WP_Error( 'login_failed', __( 'Login failed: ', 'zw-knabbel-wp' ) . $error );
		}

		$cookies = wp_remote_retrieve_cookies( $response );
		if ( empty( $cookies ) ) {
			return new \WP_Error( 'no_cookies', __( 'No session cookies received', 'zw-knabbel-wp' ) );
		}

		// Babbel sessions last 24 hours; renew an hour early. A 401 still forces a new login.
		set_transient(
			KNABBEL_SESSION_TRANSIENT,
			array(
				'fingerprint' => $fingerprint,
				'cookies'     => $cookies,
			),
			DAY_IN_SECONDS - HOUR_IN_SECONDS
		);

		return $cookies;
}

/**
 * Clears cached session cookies.
 *
 * Needs only this file, so uninstall.php can call it.
 *
 * @since 0.1.0
 * @since 0.8.0 Clears the single fingerprinted session.
 */
function babbel_clear_session_cache(): void {
	delete_transient( KNABBEL_SESSION_TRANSIENT );
}

/**
 * Formats a Babbel error response.
 *
 * Babbel reports errors as RFC 9457 Problem Details; field errors from
 * `errors[]` follow `detail` in parentheses. Responses Babbel does not shape,
 * such as unknown routes, recovered panics or proxy errors, fall back to the
 * body as plain text or the HTTP status description.
 *
 * @since 0.8.0
 * @param int    $code HTTP status code.
 * @param string $body Raw response body.
 * @return string Translated error message.
 */
function babbel_error_message( int $code, string $body ): string {
	$problem = json_decode( $body, true );
	if ( is_array( $problem ) && is_string( $problem['detail'] ?? null ) ) {
		$detail       = $problem['detail'];
		$field_errors = array();
		foreach ( (array) ( $problem['errors'] ?? array() ) as $error ) {
			if ( is_array( $error ) && is_string( $error['field'] ?? null ) && is_string( $error['message'] ?? null ) ) {
				$field_errors[] = $error['field'] . ': ' . $error['message'];
			}
		}
		if ( array() !== $field_errors ) {
			$detail .= ' (' . implode( '; ', $field_errors ) . ')';
		}
	} else {
		$text   = wp_html_excerpt( $body, 1000 );
		$detail = '' !== $text ? $text : get_status_header_desc( $code );
	}

	// translators: %1$d is the HTTP status code, %2$s is the error message.
	return sprintf( __( 'API error: HTTP %1$d - %2$s', 'zw-knabbel-wp' ), $code, $detail );
}

/**
 * Sends an authenticated Babbel request.
 * Automatically retries with fresh session on 401 Unauthorized.
 *
 * @since 0.1.0
 * @param string               $url  The API endpoint URL.
 * @param array<string, mixed> $args Request arguments for wp_remote_request().
 * @return array<string, mixed>|\WP_Error HTTP response array or WP_Error on failure.
 *
 * @phpstan-param WpHttpArgs $args
 * @phpstan-return WpHttpResponse|\WP_Error
 */
function babbel_make_authenticated_request( string $url, array $args = array() ): array|\WP_Error {
	$cookies = babbel_get_session_cookies();

	if ( is_wp_error( $cookies ) ) {
		return $cookies;
	}

	// Add cached session cookies to request.
	$args['cookies'] = $args['cookies'] ?? array();
	foreach ( $cookies as $cookie ) {
		$args['cookies'][] = $cookie;
	}

	$response = wp_remote_request( $url, $args );

	// Retry on 401: clear cache and re-authenticate.
	if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 401 ) {
		log( 'info', 'BabbelApi', 'Got 401, clearing session cache and retrying' );
		babbel_clear_session_cache();

		$cookies = babbel_get_session_cookies();
		if ( is_wp_error( $cookies ) ) {
			return $cookies;
		}

		$args['cookies'] = array();
		foreach ( $cookies as $cookie ) {
			$args['cookies'][] = $cookie;
		}

		$response = wp_remote_request( $url, $args );
	}

	return $response;
}

/**
 * Creates a story through the Babbel API.
 *
 * @since 0.1.0
 * @since 0.8.0 Reports Problem Details errors and requires an integer story ID.
 * @param array<string, mixed> $story_data The story data to send.
 * @return array{success: bool, message: string, story_id?: string} Response with success status and message.
 *
 * @phpstan-param StoryData $story_data
 * @phpstan-return BabbelApiResponse
 */
function babbel_create_story( array $story_data ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories';

	$payload = array(
		'title'      => $story_data['title'],
		'text'       => $story_data['text'],
		'start_date' => $story_data['start_date'],
		'end_date'   => $story_data['end_date'],
		'status'     => $story_data['status'] ?? 'draft',
		'weekdays'   => $story_data['weekdays'] ?? WEEKDAY_ALL,
	);

	// Add metadata if provided.
	if ( isset( $story_data['metadata'] ) ) {
		$payload['metadata'] = $story_data['metadata'];
	}

	try {
		$json_body = wp_json_encode( $payload );
		if ( false === $json_body ) {
			return array(
				'success' => false,
				'message' => __( 'Could not encode story data', 'zw-knabbel-wp' ),
			);
		}
	} catch ( \JsonException $e ) {
		return array(
			'success' => false,
			'message' => __( 'Could not encode story data', 'zw-knabbel-wp' ),
		);
	}

	$args = array(
		'method'  => 'POST',
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => $json_body,
		'timeout' => 30,
	);

		$response = babbel_make_authenticated_request( $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API request failed',
			array(
				'endpoint' => $endpoint,
				'error'    => $response->get_error_message(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

	if ( 201 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API HTTP error',
			array(
				'endpoint'      => $endpoint,
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return array(
			'success' => false,
			'message' => babbel_error_message( $response_code, $body ),
		);
	}

	try {
		$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
	} catch ( \JsonException $e ) {
		log(
			'error',
			'BabbelApi',
			'Invalid JSON response from Babbel API',
			array(
				'endpoint'      => $endpoint,
				'response_body' => substr( $body, 0, 500 ),
				'json_error'    => $e->getMessage(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'Invalid API response', 'zw-knabbel-wp' ),
		);
	}

	if ( ! is_int( $decoded['id'] ?? null ) ) {
		log(
			'error',
			'BabbelApi',
			'No story ID in Babbel API response',
			array(
				'endpoint' => $endpoint,
				'response' => $decoded,
			)
		);
		return array(
			'success' => false,
			'message' => __( 'No story ID received from API', 'zw-knabbel-wp' ),
		);
	}

		// Log successful story creation.
		log(
			'info',
			'BabbelApi',
			'Story successfully created in Babbel API',
			array(
				'story_id' => $decoded['id'],
				'endpoint' => $endpoint,
			)
		);

		return array(
			'success'  => true,
			'story_id' => (string) $decoded['id'],
			'message'  => __( 'Story created successfully', 'zw-knabbel-wp' ),
		);
}


/**
 * Tests the Babbel API connection.
 * Authenticates and then verifies session by fetching current user info.
 *
 * @since 0.1.0
 * @since 0.8.0 Reports Problem Details errors and validates the returned username.
 * @return array{success: bool, message: string} Response with success status and message.
 */
function babbel_test_connection(): array {
	$credentials = babbel_get_credentials();

	if ( empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
		return array(
			'success' => false,
			'message' => __( 'Username and password are required', 'zw-knabbel-wp' ),
		);
	}

	// First authenticate.
	$cookies = babbel_get_session_cookies();
	if ( is_wp_error( $cookies ) ) {
		return array(
			'success' => false,
			'message' => $cookies->get_error_message(),
		);
	}

	// Then verify session by getting current user.
	$endpoint = $credentials['base_url'] . '/sessions/current';
	$response = babbel_make_authenticated_request( $endpoint, array( 'method' => 'GET' ) );

	if ( is_wp_error( $response ) ) {
		return array(
			'success' => false,
			'message' => __( 'Session verification failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );
	if ( 200 !== $response_code ) {
		return array(
			'success' => false,
			'message' => babbel_error_message( $response_code, $body ),
		);
	}

	$decoded = json_decode( $body, true );
	if ( ! is_string( $decoded['username'] ?? null ) ) {
		return array(
			'success' => false,
			'message' => __( 'Invalid API response', 'zw-knabbel-wp' ),
		);
	}

	return array(
		'success' => true,
		// translators: %s is the username.
		'message' => sprintf( __( 'Connected as: %s', 'zw-knabbel-wp' ), $decoded['username'] ),
	);
}

/**
 * Updates an existing Babbel story.
 *
 * @since 0.2.0
 * @since 0.8.0 Reports Problem Details errors.
 * @param string                                                                        $story_id   The Babbel story ID.
 * @param array{title?: string, start_date?: string, end_date?: string, weekdays?: int} $story_data The story data to update.
 * @return array{success: bool, message: string} Response with success status and message.
 *
 * @phpstan-param StoryUpdateData $story_data
 * @phpstan-return BabbelApiResponse
 */
function babbel_update_story( string $story_id, array $story_data ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories/' . $story_id;

	if ( array() === $story_data ) {
		return array(
			'success' => false,
			'message' => __( 'No data to update', 'zw-knabbel-wp' ),
		);
	}

	try {
		$json_body = wp_json_encode( $story_data );
		if ( false === $json_body ) {
			return array(
				'success' => false,
				'message' => __( 'Could not encode update data', 'zw-knabbel-wp' ),
			);
		}
	} catch ( \JsonException $e ) {
		return array(
			'success' => false,
			'message' => __( 'Could not encode update data', 'zw-knabbel-wp' ),
		);
	}

	$args = array(
		'method'  => 'PUT',
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => $json_body,
		'timeout' => 30,
	);

	$response = babbel_make_authenticated_request( $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API update request failed',
			array(
				'endpoint' => $endpoint,
				'story_id' => $story_id,
				'error'    => $response->get_error_message(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );

	if ( 200 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API update HTTP error',
			array(
				'endpoint'      => $endpoint,
				'story_id'      => $story_id,
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return array(
			'success' => false,
			'message' => babbel_error_message( $response_code, $body ),
		);
	}

	log(
		'info',
		'BabbelApi',
		'Story successfully updated in Babbel API',
		array(
			'story_id' => $story_id,
			'endpoint' => $endpoint,
		)
	);

	return array(
		'success' => true,
		'message' => __( 'Story updated successfully', 'zw-knabbel-wp' ),
	);
}

/**
 * Soft-deletes a Babbel story.
 *
 * @since 0.2.0
 * @since 0.8.0 Accepts only HTTP 204 and reports Problem Details errors.
 * @param string $story_id The Babbel story ID.
 * @return array{success: bool, message: string} Response with success status and message.
 *
 * @phpstan-return BabbelApiResponse
 */
function babbel_delete_story( string $story_id ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories/' . $story_id;

	$args = array(
		'method'  => 'DELETE',
		'timeout' => 30,
	);

	$response = babbel_make_authenticated_request( $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API delete request failed',
			array(
				'endpoint' => $endpoint,
				'story_id' => $story_id,
				'error'    => $response->get_error_message(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );

	if ( 204 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API delete HTTP error',
			array(
				'endpoint'      => $endpoint,
				'story_id'      => $story_id,
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return array(
			'success' => false,
			'message' => babbel_error_message( $response_code, $body ),
		);
	}

	log(
		'info',
		'BabbelApi',
		'Story successfully deleted from Babbel API',
		array(
			'story_id' => $story_id,
			'endpoint' => $endpoint,
		)
	);

	return array(
		'success' => true,
		'message' => __( 'Story deleted successfully', 'zw-knabbel-wp' ),
	);
}

/**
 * Restores a soft-deleted Babbel story.
 *
 * The PATCH endpoint only accepts 'status' and 'deleted_at' fields.
 * To update the title after restore, use babbel_update_story() separately.
 *
 * @since 0.2.0
 * @since 0.8.0 Reports Problem Details errors.
 * @since 0.9.0 Returns the restored story as `story`.
 * @param string $story_id The Babbel story ID.
 * @return array{success: bool, message: string, story?: array<string, mixed>} Response with success status and message.
 *
 * @phpstan-return BabbelApiResponse
 */
function babbel_restore_story( string $story_id ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories/' . $story_id;

	// PATCH with deleted_at: "" (empty string) to restore.
	$payload = array( 'deleted_at' => '' );

	try {
		$json_body = wp_json_encode( $payload );
		if ( false === $json_body ) {
			return array(
				'success' => false,
				'message' => __( 'Could not encode restore data', 'zw-knabbel-wp' ),
			);
		}
	} catch ( \JsonException $e ) {
		return array(
			'success' => false,
			'message' => __( 'Could not encode restore data', 'zw-knabbel-wp' ),
		);
	}

	$args = array(
		'method'  => 'PATCH',
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => $json_body,
		'timeout' => 30,
	);

	$response = babbel_make_authenticated_request( $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API restore request failed',
			array(
				'endpoint' => $endpoint,
				'story_id' => $story_id,
				'error'    => $response->get_error_message(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );

	if ( 200 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API restore HTTP error',
			array(
				'endpoint'      => $endpoint,
				'story_id'      => $story_id,
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return array(
			'success' => false,
			'message' => babbel_error_message( $response_code, $body ),
		);
	}

	log(
		'info',
		'BabbelApi',
		'Story successfully restored in Babbel API',
		array(
			'story_id' => $story_id,
			'endpoint' => $endpoint,
		)
	);

	$story = json_decode( $body, true );

	return array(
		'success' => true,
		'message' => __( 'Story restored successfully', 'zw-knabbel-wp' ),
		// PATCH returns the restored story.
		'story'   => is_array( $story ) ? $story : array(),
	);
}

/**
 * Reads one Babbel story.
 *
 * @since 0.9.0
 * @param string $story_id The Babbel story ID.
 * @return array{success: bool, message: string, story?: array<string, mixed>} Response with the decoded story on success.
 *
 * @phpstan-return BabbelApiResponse
 */
function babbel_get_story( string $story_id ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories/' . $story_id;

	$response = babbel_make_authenticated_request(
		$endpoint,
		array(
			'method'  => 'GET',
			'timeout' => 30,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );
	if ( 200 !== $response_code ) {
		return array(
			'success' => false,
			'message' => babbel_error_message( $response_code, $body ),
		);
	}

	$story = json_decode( $body, true );
	if ( ! is_array( $story ) ) {
		return array(
			'success' => false,
			'message' => __( 'Invalid API response', 'zw-knabbel-wp' ),
		);
	}

	return array(
		'success' => true,
		'message' => __( 'Story read successfully', 'zw-knabbel-wp' ),
		'story'   => $story,
	);
}

/**
 * Returns recent stories for few-shot examples.
 *
 * Retrieves active and expired stories sorted by last update (most recently
 * edited first), which are most likely to have been reviewed by editors.
 *
 * @since 0.3.0
 * @since 0.7.0 Replaced the `$limit` parameter with `$created_after` and added response validation.
 * @since 0.8.0 Encodes query values and reports Problem Details errors.
 * @param string $created_after Inclusive RFC 3339 creation cutoff.
 * @return array<int, array<string, mixed>>|\WP_Error Array of story objects or WP_Error on failure.
 */
function babbel_fetch_recent_stories( string $created_after ): array|\WP_Error {
	$credentials = babbel_get_credentials();

	if ( empty( $credentials['base_url'] ) ) {
		return new \WP_Error( 'missing_config', __( 'Babbel API base URL not configured', 'zw-knabbel-wp' ) );
	}

	// add_query_arg() does not encode values; an unencoded "+" in an offset decodes as a space.
	$endpoint = add_query_arg(
		rawurlencode_deep(
			array(
				'sort'                    => '-updated_at',
				'limit'                   => 100,
				'filter[status][ne]'      => 'draft',
				'filter[created_at][gte]' => $created_after,
			)
		),
		$credentials['base_url'] . '/stories'
	);

	$response = babbel_make_authenticated_request(
		$endpoint,
		array(
			'method'  => 'GET',
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Failed to fetch recent stories',
			array( 'error' => $response->get_error_message() )
		);
		return $response;
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );

	if ( 200 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API stories fetch HTTP error',
			array(
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return new \WP_Error(
			'api_error',
			babbel_error_message( $response_code, $body )
		);
	}

	try {
		$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
	} catch ( \JsonException $e ) {
		log(
			'error',
			'BabbelApi',
			'Invalid JSON response when fetching stories',
			array( 'json_error' => $e->getMessage() )
		);
		return new \WP_Error( 'json_error', __( 'Invalid API response', 'zw-knabbel-wp' ) );
	}

	if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
		log( 'error', 'BabbelApi', 'Invalid story collection in Babbel API response' );
		return new \WP_Error( 'invalid_response', __( 'Invalid API response', 'zw-knabbel-wp' ) );
	}

	$stories = array_values( array_filter( $decoded['data'], 'is_array' ) );

	log(
		'info',
		'BabbelApi',
		'Fetched recent stories for few-shot examples',
		array( 'count' => count( $stories ) )
	);

	return $stories;
}
