<?php
/**
 * Settings administration for Knabbel WP.
 *
 * Manages plugin settings page, field registration, sanitization, and validation.
 *
 * @package KnabbelWP
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings administration hooks.
 *
 * @since 0.1.0
 */
function settings_init(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\settings_add_admin_menu' );
	add_action( 'admin_init', __NAMESPACE__ . '\\settings_register_settings' );
	add_action( 'admin_post_knabbel_clear_recent_errors', __NAMESPACE__ . '\\handle_clear_recent_errors' );
}

/**
 * Clears recent errors for an administrator.
 *
 * @since 0.5.0
 */
function handle_clear_recent_errors(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'zw-knabbel-wp' ) );
	}

	check_admin_referer( 'knabbel_clear_recent_errors' );

	delete_option( 'knabbel_recent_errors' );

	$redirect_url = add_query_arg(
		array(
			'page'                   => 'zw-knabbel-wp-settings',
			'knabbel_errors_cleared' => '1',
		),
		admin_url( 'options-general.php' )
	);

	wp_safe_redirect( $redirect_url . '#knabbel-recent-errors' );
	exit;
}

/**
 * Adds the plugin settings page.
 *
 * @since 0.1.0
 */
function settings_add_admin_menu(): void {
	add_options_page(
		__( 'ZuidWest Knabbel Settings', 'zw-knabbel-wp' ),
		__( 'ZuidWest Knabbel', 'zw-knabbel-wp' ),
		'manage_options',
		'zw-knabbel-wp-settings',
		__NAMESPACE__ . '\\settings_page'
	);
}

/**
 * Registers the plugin settings.
 *
 * Only registers the settings group and sanitization callback.
 * Field rendering is handled manually in settings_page().
 *
 * @since 0.1.0
 */
function settings_register_settings(): void {
	register_setting(
		'knabbel_settings_group',
		'knabbel_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
			'default'           => default_settings(),
		)
	);
}

/**
 * Sanitizes settings before storage.
 *
 * @since 0.1.0
 * @since 0.7.0 Removed support for the `few_shot_count` setting.
 * @param array<string, mixed> $input The raw input array from the form.
 * @return array<string, mixed> The sanitized settings array.
 */
function sanitize_settings( array $input ): array {
	$sanitized = array();

	// URL fields.
	if ( isset( $input['api_base_url'] ) ) {
		$sanitized['api_base_url'] = esc_url_raw( rtrim( $input['api_base_url'], '/' ) );
	}

	// Text fields.
	$text_fields = array( 'api_username', 'api_password' );
	foreach ( $text_fields as $field ) {
		if ( isset( $input[ $field ] ) ) {
			$sanitized[ $field ] = sanitize_text_field( $input[ $field ] );
		}
	}

	// Model select: empty means automatic, anything else must be "provider/model".
	if ( isset( $input['ai_model'] ) ) {
		$ai_model              = sanitize_text_field( $input['ai_model'] );
		$sanitized['ai_model'] = null !== ai_parse_model_setting( $ai_model ) ? $ai_model : '';
	}

	// Textarea fields (prompts) - preserve newlines.
	$textarea_fields = array( 'speech_prompt' );
	foreach ( $textarea_fields as $field ) {
		if ( isset( $input[ $field ] ) ) {
			$sanitized[ $field ] = sanitize_textarea_field( $input[ $field ] );
		}
	}

	// Integer fields.
	if ( isset( $input['start_days_offset'] ) ) {
		$sanitized['start_days_offset'] = absint( $input['start_days_offset'] );
	}
	if ( isset( $input['end_days_offset'] ) ) {
		$sanitized['end_days_offset'] = absint( $input['end_days_offset'] );
	}
	// Select field with allowed values.
	if ( isset( $input['default_status'] ) ) {
		$sanitized['default_status'] = in_array( $input['default_status'], array( 'draft', 'active' ), true )
			? $input['default_status']
			: 'draft';
	}

	// Boolean/checkbox fields, stored as real booleans to match default_settings().
	$sanitized['debug_mode'] = ! empty( $input['debug_mode'] );

	// Weekday checkboxes.
	foreach ( array_keys( WEEKDAY_MAP ) as $day ) {
		$field_name               = 'weekday_' . $day;
		$sanitized[ $field_name ] = ! empty( $input[ $field_name ] );
	}

	return $sanitized;
}

/**
 * Renders the card-based settings page.
 *
 * @since 0.1.0
 */
function settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to view this page.', 'zw-knabbel-wp' ) );
	}

	// Enqueue admin CSS.
	wp_enqueue_style(
		'zw-knabbel-wp-admin',
		KNABBEL_PLUGIN_URL . 'assets/admin.css',
		array(),
		KNABBEL_VERSION
	);

	// Enqueue admin JS with localized strings.
	wp_enqueue_script(
		'zw-knabbel-wp-admin',
		KNABBEL_PLUGIN_URL . 'assets/admin.js',
		array( 'jquery' ),
		KNABBEL_VERSION,
		true
	);

	wp_localize_script(
		'zw-knabbel-wp-admin',
		'knabbel_admin',
		array(
			'nonce'        => wp_create_nonce( 'knabbel_test_api_nonce' ),
			'testing_text' => __( 'Testing...', 'zw-knabbel-wp' ),
			'button_text'  => __( 'Test API Connection', 'zw-knabbel-wp' ),
			'error_text'   => __( 'An error occurred', 'zw-knabbel-wp' ),
		)
	);

	$settings = get_plugin_settings();
	?>
	<div class="wrap knabbel-wp-admin">
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation after the nonce-protected clear action.
		$clear_status = isset( $_GET['knabbel_errors_cleared'] ) ? sanitize_text_field( wp_unslash( $_GET['knabbel_errors_cleared'] ) ) : '';
		if ( '1' === $clear_status ) :
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Recent errors cleared.', 'zw-knabbel-wp' ); ?></p>
			</div>
		<?php endif; ?>
		<!-- Page Header -->
		<div class="knabbel-page-header">
			<h1><?php esc_html_e( 'ZuidWest Knabbel', 'zw-knabbel-wp' ); ?></h1>
			<button type="submit" form="knabbel-settings-form" class="knabbel-btn knabbel-btn-primary">
				<?php esc_html_e( 'Wijzigingen opslaan', 'zw-knabbel-wp' ); ?>
			</button>
		</div>

		<form id="knabbel-settings-form" method="post" action="options.php">
			<?php settings_fields( 'knabbel_settings_group' ); ?>

			<div class="knabbel-settings-grid">
				<?php
				render_babbel_api_card( $settings );
				render_ai_card( $settings );
				render_defaults_card( $settings );
				?>
			</div>
		</form>

		<?php
		// Verzonden artikelen section (debug mode only).
		if ( debug_enabled() ) {
			render_articles_overview();
		}
		render_recent_errors();
		?>
	</div>
	<?php
}

/**
 * Displays the rolling list of recent errors.
 *
 * Only top-level fields are rendered. Stored context remains intentionally
 * hidden so nested API data can never become an unfiltered admin output path.
 *
 * @since 0.5.0
 */
function render_recent_errors(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stored_errors = get_option( 'knabbel_recent_errors', array() );
	if ( ! is_array( $stored_errors ) || empty( $stored_errors ) ) {
		return;
	}

	$recent_errors = array_reverse( prepare_error_history( $stored_errors ) );

	?>
	<div id="knabbel-recent-errors" class="knabbel-settings-card" style="margin-top: 24px;">
		<div class="knabbel-card-title knabbel-articles-header">
			<div class="header-left">
				<span class="dashicons dashicons-warning card-icon"></span>
				<h2><?php esc_html_e( 'Recent Errors', 'zw-knabbel-wp' ); ?></h2>
			</div>
		</div>
		<div class="knabbel-card-content" style="padding: 0;">
			<table class="knabbel-articles-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'zw-knabbel-wp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Component', 'zw-knabbel-wp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Message', 'zw-knabbel-wp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $recent_errors ) ) : ?>
						<tr>
							<td colspan="3"><?php esc_html_e( 'No valid recent errors to display.', 'zw-knabbel-wp' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $recent_errors as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( format_stored_datetime( $entry['timestamp'] ) ); ?></td>
								<td class="mono"><?php echo esc_html( $entry['component'] ); ?></td>
								<td class="error-message"><?php echo esc_html( $entry['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<div class="knabbel-articles-footer">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="knabbel_clear_recent_errors" />
				<?php wp_nonce_field( 'knabbel_clear_recent_errors' ); ?>
				<button type="submit" class="knabbel-btn knabbel-btn-secondary">
					<?php esc_html_e( 'Clear Errors', 'zw-knabbel-wp' ); ?>
				</button>
			</form>
		</div>
	</div>
	<?php
}

/**
 * Displays the Babbel API settings card.
 *
 * @since 0.1.0
 * @param array<string, mixed> $settings Current settings.
 */
function render_babbel_api_card( array $settings ): void {
	?>
	<div class="knabbel-settings-card">
		<div class="knabbel-card-title">
			<span class="dashicons dashicons-cloud card-icon"></span>
			<h2><?php esc_html_e( 'Babbel API', 'zw-knabbel-wp' ); ?></h2>
		</div>
		<div class="knabbel-card-content">
			<p class="knabbel-card-description">
				<?php esc_html_e( 'Configure the Babbel API settings.', 'zw-knabbel-wp' ); ?>
			</p>

			<div class="knabbel-field">
				<label class="knabbel-field-label">
					<?php esc_html_e( 'Babbel API Base URL', 'zw-knabbel-wp' ); ?>
					<span class="required">*</span>
				</label>
				<input type="text"
					name="knabbel_settings[api_base_url]"
					class="knabbel-field-input"
					value="<?php echo esc_attr( $settings['api_base_url'] ); ?>"
					placeholder="https://babbel.example.com/api/v1" />
				<p class="knabbel-field-description">
					<?php esc_html_e( 'The base URL of the Babbel API (without trailing slash).', 'zw-knabbel-wp' ); ?>
				</p>
			</div>

			<div class="knabbel-field-row">
				<div class="knabbel-field">
					<label class="knabbel-field-label">
						<?php esc_html_e( 'Babbel API Username', 'zw-knabbel-wp' ); ?>
						<span class="required">*</span>
					</label>
					<input type="text"
						name="knabbel_settings[api_username]"
						class="knabbel-field-input"
						value="<?php echo esc_attr( $settings['api_username'] ); ?>"
						placeholder="<?php esc_attr_e( 'username', 'zw-knabbel-wp' ); ?>" />
				</div>

				<div class="knabbel-field">
					<label class="knabbel-field-label">
						<?php esc_html_e( 'Babbel API Password', 'zw-knabbel-wp' ); ?>
						<span class="required">*</span>
					</label>
					<input type="password"
						name="knabbel_settings[api_password]"
						class="knabbel-field-input"
						value="<?php echo esc_attr( $settings['api_password'] ); ?>" />
				</div>
			</div>

			<div class="knabbel-field" style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #f0f0f1;">
				<label class="knabbel-toggle">
					<input type="checkbox"
						name="knabbel_settings[debug_mode]"
						value="1"
						<?php checked( ! empty( $settings['debug_mode'] ) ); ?> />
					<span class="knabbel-toggle-track"></span>
					<span class="knabbel-toggle-label">
						<?php esc_html_e( 'Enable Debug Mode', 'zw-knabbel-wp' ); ?>
					</span>
				</label>
				<p class="knabbel-field-description" style="margin-left: 50px;">
					<?php esc_html_e( 'Show debug information and status in the sidebar. Only enable for troubleshooting.', 'zw-knabbel-wp' ); ?>
				</p>
			</div>

			<div class="knabbel-test-connection">
				<button type="button" id="test-babbel-api" class="knabbel-btn knabbel-btn-secondary">
					<?php esc_html_e( 'Test API Connection', 'zw-knabbel-wp' ); ?>
				</button>
				<span id="api-test-result" class="knabbel-test-result" role="status"></span>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Displays the AI settings card.
 *
 * @since 0.5.0
 * @since 0.7.0 Removed the configurable few-shot count field.
 * @param array<string, mixed> $settings Current settings.
 */
function render_ai_card( array $settings ): void {
	$ai_models = ai_get_available_models();
	?>
	<div class="knabbel-settings-card">
		<div class="knabbel-card-title">
			<span class="dashicons dashicons-lightbulb card-icon"></span>
			<h2><?php esc_html_e( 'AI Generation', 'zw-knabbel-wp' ); ?></h2>
		</div>
		<div class="knabbel-card-content">
			<p class="knabbel-card-description">
				<?php esc_html_e( 'WordPress selects a suitable model from your configured AI providers.', 'zw-knabbel-wp' ); ?>
				<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>">
					<?php esc_html_e( 'Manage AI providers', 'zw-knabbel-wp' ); ?>
				</a>
			</p>

			<?php if ( array() !== $ai_models ) : ?>
				<div class="knabbel-field">
					<label class="knabbel-field-label" for="knabbel-ai-model">
						<?php esc_html_e( 'AI Model', 'zw-knabbel-wp' ); ?>
					</label>
					<select id="knabbel-ai-model" name="knabbel_settings[ai_model]" class="knabbel-field-input">
						<option value=""><?php esc_html_e( 'Automatic', 'zw-knabbel-wp' ); ?></option>
						<?php foreach ( $ai_models as $provider_data ) : ?>
							<optgroup label="<?php echo esc_attr( $provider_data['label'] ); ?>">
								<?php foreach ( $provider_data['models'] as $model_value => $model_name ) : ?>
									<option value="<?php echo esc_attr( $model_value ); ?>" <?php selected( $settings['ai_model'], $model_value ); ?>>
										<?php echo esc_html( $model_name ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
					<p class="knabbel-field-description">
						<?php esc_html_e( 'Choose a configured text model, or let WordPress select one automatically.', 'zw-knabbel-wp' ); ?>
					</p>
				</div>
			<?php else : ?>
				<?php // The hidden field preserves the stored value: sanitize_settings() drops keys absent from the form. ?>
				<input type="hidden" name="knabbel_settings[ai_model]" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" />
				<p class="knabbel-field-description">
					<?php esc_html_e( 'No configured text generation models are available.', 'zw-knabbel-wp' ); ?>
				</p>
			<?php endif; ?>

			<div class="knabbel-field">
				<label class="knabbel-field-label" for="knabbel-speech-prompt">
					<?php esc_html_e( 'Speech Text Generation Prompt', 'zw-knabbel-wp' ); ?>
				</label>
				<textarea id="knabbel-speech-prompt"
					name="knabbel_settings[speech_prompt]"
					class="knabbel-field-input"
					rows="4"
					placeholder="<?php echo esc_attr( AI_DEFAULT_INSTRUCTION ); ?>"><?php echo esc_textarea( $settings['speech_prompt'] ); ?></textarea>
				<p class="knabbel-field-description">
					<?php esc_html_e( 'Prompt for converting to radio-friendly speech text. Leave empty for default prompt.', 'zw-knabbel-wp' ); ?>
				</p>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Displays the story defaults card.
 *
 * @since 0.1.0
 * @param array<string, mixed> $settings Current settings.
 */
function render_defaults_card( array $settings ): void {
	$weekdays  = array(
		'sunday'    => __( 'Sun', 'zw-knabbel-wp' ),
		'monday'    => __( 'Mon', 'zw-knabbel-wp' ),
		'tuesday'   => __( 'Tue', 'zw-knabbel-wp' ),
		'wednesday' => __( 'Wed', 'zw-knabbel-wp' ),
		'thursday'  => __( 'Thu', 'zw-knabbel-wp' ),
		'friday'    => __( 'Fri', 'zw-knabbel-wp' ),
		'saturday'  => __( 'Sat', 'zw-knabbel-wp' ),
	);
	$is_active = 'active' === $settings['default_status'];
	?>
	<div class="knabbel-settings-card full-width">
		<div class="knabbel-card-title">
			<span class="dashicons dashicons-clock card-icon"></span>
			<h2><?php esc_html_e( 'Story Defaults', 'zw-knabbel-wp' ); ?></h2>
		</div>
		<div class="knabbel-card-content" style="padding: 0;">
			<table class="knabbel-form-table">
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Start Date (days from now)', 'zw-knabbel-wp' ); ?></td>
					<td>
						<div class="input-inline">
							<input type="number"
								name="knabbel_settings[start_days_offset]"
								class="knabbel-field-input"
								value="<?php echo esc_attr( (string) $settings['start_days_offset'] ); ?>"
								min="0" />
							<span class="input-suffix"><?php esc_html_e( 'Number of days from publication for start date.', 'zw-knabbel-wp' ); ?></span>
						</div>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'End Date (days from now)', 'zw-knabbel-wp' ); ?></td>
					<td>
						<div class="input-inline">
							<input type="number"
								name="knabbel_settings[end_days_offset]"
								class="knabbel-field-input"
								value="<?php echo esc_attr( (string) $settings['end_days_offset'] ); ?>"
								min="0" />
							<span class="input-suffix"><?php esc_html_e( 'Number of days from publication for end date.', 'zw-knabbel-wp' ); ?></span>
						</div>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Status', 'zw-knabbel-wp' ); ?></td>
					<td>
						<input type="hidden" name="knabbel_settings[default_status]" value="draft" />
						<label class="knabbel-toggle">
							<input type="checkbox"
								name="knabbel_settings[default_status]"
								value="active"
								<?php checked( $is_active ); ?> />
							<span class="knabbel-toggle-track"></span>
							<span class="knabbel-toggle-label"><?php esc_html_e( 'Active', 'zw-knabbel-wp' ); ?></span>
						</label>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Days', 'zw-knabbel-wp' ); ?></td>
					<td>
						<div class="knabbel-weekdays">
							<?php foreach ( $weekdays as $key => $label ) : ?>
								<?php
								$field_name = 'weekday_' . $key;
								$checked    = ! empty( $settings[ $field_name ] );
								?>
								<label class="knabbel-weekday <?php echo $checked ? 'checked' : ''; ?>">
									<input type="checkbox"
										name="knabbel_settings[<?php echo esc_attr( $field_name ); ?>]"
										value="1"
										<?php checked( $checked ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="knabbel-field-description" style="margin-top: 8px;">
							<?php esc_html_e( 'Which days the story may be broadcast.', 'zw-knabbel-wp' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
	</div>
	<?php
}

/**
 * Displays the Verzonden Artikelen card.
 *
 * @since 0.1.0
 */
function render_articles_overview(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Debug post selector, no state change.
	$selected_id = isset( $_GET['knabbel_debug_post'] ) ? intval( $_GET['knabbel_debug_post'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug from admin URL.
	$page_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'zw-knabbel-wp-settings';

	$latest_post  = null;
	$latest_state = null;
	$latest_ts    = 0;
	$candidates   = array();

	$query = new \WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'any',
			'posts_per_page'         => 25,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded admin-only query; no pagination and post meta is primed for 25 results.
			'meta_query'             => array(
				array(
					'key'     => '_zw_knabbel_story_state',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	if ( $query->have_posts() ) {
		foreach ( $query->posts as $post ) {
			$state = get_post_meta( $post->ID, '_zw_knabbel_story_state', true );
			if ( ! is_array( $state ) || empty( $state['status_changed_at'] ) ) {
				continue;
			}
			$ts           = strtotime( $state['status_changed_at'] . ' ' . wp_timezone_string() );
			$candidates[] = array(
				'post'  => $post,
				'state' => $state,
				'ts'    => $ts,
			);
			if ( $ts && $ts > $latest_ts ) {
				$latest_ts    = $ts;
				$latest_post  = $post;
				$latest_state = $state;
			}
		}
	}

	// Select current item.
	$selected       = null;
	$selected_state = null;
	if ( $selected_id && ! empty( $candidates ) ) {
		foreach ( $candidates as $item ) {
			if ( $item['post']->ID === $selected_id ) {
				$selected       = $item['post'];
				$selected_state = $item['state'];
				break;
			}
		}
	}
	if ( ! $selected && $latest_post ) {
		$selected       = $latest_post;
		$selected_state = $latest_state;
	}

	if ( ! $selected || ! $selected_state ) {
		return;
	}

	$status     = $selected_state['status'] ?? '';
	$slug       = $status ? sanitize_key( $status ) : 'none';
	$label      = $status ? ( StoryStatus::tryFrom( $status )?->label() ?? $status ) : '—';
	$is_error   = 'error' === $status;
	$is_sent    = 'sent' === $status;
	$sync_error = get_story_sync_error( $selected_state );

	// Build select options.
	$options_html = '';
	foreach ( $candidates as $item ) {
		$post          = $item['post'];
		$title         = esc_html( get_the_title( $post ) );
		$sel           = selected( $post->ID, $selected->ID, false );
		$options_html .= '<option value="' . intval( $post->ID ) . '" ' . $sel . '>' . $title . '</option>';
	}

	// phpcs:ignore Generic.Files.LineLength.TooLong -- Inline JS for refresh and select change.
	$refresh_js = "location.href=location.pathname + '?page=' + encodeURIComponent('" . esc_js( $page_slug ) . "') + '&knabbel_debug_post=' + encodeURIComponent(document.getElementById('knabbel_debug_post').value) + '#knabbel-debug-overview'";
	?>
	<div id="knabbel-debug-overview" class="knabbel-settings-card" style="margin-top: 24px;">
		<div class="knabbel-card-title knabbel-articles-header">
			<div class="header-left">
				<span class="dashicons dashicons-clipboard card-icon"></span>
				<h2><?php esc_html_e( 'Knabbel Story Debug Overview', 'zw-knabbel-wp' ); ?></h2>
			</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
				<select id="knabbel_debug_post"
					name="knabbel_debug_post"
					class="knabbel-articles-select"
					<?php // phpcs:ignore Generic.Files.LineLength.TooLong -- Inline JS for anchor navigation ?>
					onchange="location.href=this.form.action + '?page=' + this.form.page.value + '&knabbel_debug_post=' + this.value + '#knabbel-debug-overview';">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
					echo $options_html;
					?>
				</select>
				<noscript><button class="button"><?php esc_html_e( 'Show', 'zw-knabbel-wp' ); ?></button></noscript>
			</form>
		</div>
		<div class="knabbel-card-content" style="padding: 0;">
			<table class="knabbel-articles-table">
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Article', 'zw-knabbel-wp' ); ?></td>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $selected->ID ) ); ?>" class="article-link">
							<?php echo esc_html( get_the_title( $selected ) ); ?>
						</a>
						<span class="post-id">(Post ID: <?php echo intval( $selected->ID ); ?>)</span>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Status', 'zw-knabbel-wp' ); ?></td>
					<td>
						<span class="knabbel-status-badge <?php echo esc_attr( $slug ); ?>">
							<span class="status-dot"></span>
							<?php echo esc_html( $label ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Last status change', 'zw-knabbel-wp' ); ?></td>
					<td>
						<?php echo esc_html( format_stored_datetime( $selected_state['status_changed_at'] ) ); ?>
					</td>
				</tr>
				<?php if ( $is_sent && ! empty( $selected_state['story_id'] ) ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Babbel ID', 'zw-knabbel-wp' ); ?></td>
					<td class="mono"><?php echo esc_html( $selected_state['story_id'] ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $is_error && ! empty( $selected_state['message'] ) && null === $sync_error ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Message', 'zw-knabbel-wp' ); ?></td>
					<td class="error-message"><?php echo esc_html( $selected_state['message'] ); ?></td>
				</tr>
				<?php elseif ( 'processing' === $status ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Message', 'zw-knabbel-wp' ); ?></td>
					<td><?php esc_html_e( 'Story is being processed...', 'zw-knabbel-wp' ); ?></td>
				</tr>
				<?php elseif ( 'scheduled' === $status ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Babbel ID', 'zw-knabbel-wp' ); ?></td>
					<td class="muted">—</td>
				</tr>
				<?php endif; ?>
				<?php if ( null !== $sync_error ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Last sync error', 'zw-knabbel-wp' ); ?></td>
					<td class="error-message">
						<?php echo esc_html( $sync_error['message'] ); ?>
						<?php if ( ! empty( $sync_error['operation'] ) ) : ?>
							<div class="muted">
								<?php esc_html_e( 'Operation', 'zw-knabbel-wp' ); ?>:
								<code><?php echo esc_html( $sync_error['operation'] ); ?></code>
							</div>
						<?php endif; ?>
						<div class="muted">
							<?php esc_html_e( 'Occurred', 'zw-knabbel-wp' ); ?>:
							<?php echo esc_html( format_stored_datetime( $sync_error['occurred_at'] ) ); ?>
						</div>
					</td>
				</tr>
				<?php endif; ?>
			</table>
		</div>
		<div class="knabbel-articles-footer">
			<div class="footer-right">
				<button type="button" class="knabbel-refresh" onclick="<?php echo esc_attr( $refresh_js ); ?>">
					<?php esc_html_e( 'Refresh', 'zw-knabbel-wp' ); ?>
				</button>
			</div>
		</div>
	</div>
	<?php
}
