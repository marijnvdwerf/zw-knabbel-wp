<?php
/**
 * Plugin Name: Knabbel E2E AI Client Stub
 * Description: Deterministic transport for the WordPress AI Client's OpenAI provider.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'local' !== wp_get_environment_type() ) {
	return;
}

add_filter(
	'pre_http_request',
	static function ( false|array|WP_Error $preempt, array $parsed_args, string $url ): false|array|WP_Error {
		if ( 'https://api.openai.com/v1/models' === $url ) {
			$body = array(
				'data' => array(
					array( 'id' => 'gpt-4.1-mini' ),
					array( 'id' => 'gpt-4.1' ),
				),
			);
		} elseif ( 'https://api.openai.com/v1/responses' === $url ) {
			$headers = $parsed_args['headers'] ?? array();
			if ( ! is_array( $headers ) || 'Bearer e2e-openai-key' !== ( array_change_key_case( $headers, CASE_LOWER )['authorization'] ?? '' ) ) {
				return new WP_Error( 'knabbel_e2e_ai_auth_error', 'The Connector credential did not reach the AI provider' );
			}

			update_option( 'knabbel_e2e_ai_call_count', (int) get_option( 'knabbel_e2e_ai_call_count', 0 ) + 1, false );

			$request_body = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
			if ( is_array( $request_body ) ) {
				update_option( 'knabbel_e2e_ai_last_request', $request_body, false );

				if ( array_key_exists( 'temperature', $request_body ) ) {
					return new WP_Error(
						'knabbel_e2e_ai_unsupported_parameter',
						'Unsupported parameter: temperature is not supported with this model.'
					);
				}
			}

			if ( 'error' === get_option( 'knabbel_e2e_ai_mode', 'success' ) ) {
				return new WP_Error( 'knabbel_e2e_ai_error', 'Deterministic AI provider failure' );
			}

			// Queued outputs are answered in order before the default text; a queued null is a failure.
			$queued_outputs = get_option( 'knabbel_e2e_ai_outputs', array() );
			$text           = 'Deterministische E2E-radiospreektekst.'; // Keep in sync with suite.php and editor-flow.spec.ts.
			if ( is_array( $queued_outputs ) && array() !== $queued_outputs ) {
				$queued = array_shift( $queued_outputs );
				update_option( 'knabbel_e2e_ai_outputs', $queued_outputs, false );
				if ( null === $queued ) {
					return new WP_Error( 'knabbel_e2e_ai_error', 'Queued AI provider failure' );
				}
				$text = (string) $queued;
			}

			$body = array(
				'output' => array(
					array(
						'type'    => 'message',
						'content' => array(
							array(
								'type' => 'output_text',
								'text' => $text,
							),
						),
					),
				),
			);
		} else {
			return $preempt;
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	},
	10,
	3
);
