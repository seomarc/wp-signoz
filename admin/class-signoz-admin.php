<?php
/**
 * Admin settings page for WP SigNoz.
 *
 * @package WP_SigNoz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin menu, settings fields, and handles AJAX test connection.
 */
class SigNoz_Admin {

	/**
	 * Plugin config.
	 *
	 * @var SigNoz_Config
	 */
	private $config;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->config = new SigNoz_Config();

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_signoz_test_connection', array( $this, 'ajax_test_connection' ) );
		add_filter( 'plugin_action_links_' . WP_SIGNOZ_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add the SigNoz settings menu under Settings.
	 */
	public function add_menu() {
		add_options_page(
			__( 'SigNoz Settings', 'wp-signoz' ),
			__( 'SigNoz', 'wp-signoz' ),
			'manage_options',
			'wp-signoz',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register all settings fields.
	 */
	public function register_settings() {
		// Section: Connection.
		add_settings_section(
			'signoz_connection',
			__( 'Connection', 'wp-signoz' ),
			function () {
				echo '<p>' . esc_html__( 'Configure the connection to your SigNoz instance.', 'wp-signoz' ) . '</p>';
			},
			'wp-signoz'
		);

		$this->add_field( 'signoz_endpoint', __( 'OTLP Endpoint', 'wp-signoz' ), 'url', 'signoz_connection', 'http://signoz-host:4318' );
		$this->add_field( 'signoz_access_token', __( 'Access Token', 'wp-signoz' ), 'password', 'signoz_connection', __( 'Leave blank for self-hosted', 'wp-signoz' ) );
		$this->add_field( 'signoz_service_name', __( 'Service Name', 'wp-signoz' ), 'text', 'signoz_connection', 'my-wordpress-site' );
		$this->add_field( 'signoz_environment', __( 'Environment', 'wp-signoz' ), 'text', 'signoz_connection', 'production' );
		$this->add_field( 'signoz_sampling_rate', __( 'Sampling Rate', 'wp-signoz' ), 'number', 'signoz_connection', '1.0 (0.0 to 1.0)' );

		// Section: Features.
		add_settings_section(
			'signoz_features',
			__( 'Features', 'wp-signoz' ),
			function () {
				echo '<p>' . esc_html__( 'Enable or disable specific instrumentation features.', 'wp-signoz' ) . '</p>';
			},
			'wp-signoz'
		);

		$this->add_checkbox_field( 'signoz_trace_sql', __( 'Trace SQL Queries', 'wp-signoz' ), 'signoz_features' );
		$this->add_checkbox_field( 'signoz_trace_http', __( 'Trace External HTTP', 'wp-signoz' ), 'signoz_features' );
		$this->add_checkbox_field( 'signoz_capture_logs', __( 'Capture Logs', 'wp-signoz' ), 'signoz_features' );

		// Register each setting.
		$settings = array(
			'signoz_endpoint',
			'signoz_access_token',
			'signoz_service_name',
			'signoz_environment',
			'signoz_sampling_rate',
			'signoz_trace_sql',
			'signoz_trace_http',
			'signoz_capture_logs',
		);

		foreach ( $settings as $setting ) {
			register_setting( 'wp-signoz', $setting, array( 'sanitize_callback' => array( $this, 'sanitize_' . $setting ) ) );
		}
	}

	/**
	 * Add a text/url/password/number field.
	 *
	 * @param string $id          Option name.
	 * @param string $title       Field label.
	 * @param string $type        Input type.
	 * @param string $section     Section ID.
	 * @param string $placeholder Placeholder text.
	 */
	private function add_field( $id, $title, $type, $section, $placeholder = '' ) {
		$config_key = str_replace( 'signoz_', '', $id );
		$is_const   = $this->config->is_constant( $config_key );

		add_settings_field(
			$id,
			$title,
			function () use ( $id, $type, $placeholder, $is_const, $config_key ) {
				$value = $is_const ? $this->config->get( $config_key ) : get_option( $id, '' );
				$disabled = $is_const ? ' disabled="disabled"' : '';

				if ( 'number' === $type ) {
					printf(
						'<input type="number" id="%1$s" name="%1$s" value="%2$s" step="0.01" min="0" max="1" class="regular-text" placeholder="%3$s"%4$s />',
						esc_attr( $id ),
						esc_attr( $value ),
						esc_attr( $placeholder ),
						$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					);
				} else {
					printf(
						'<input type="%5$s" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s"%4$s />',
						esc_attr( $id ),
						esc_attr( $value ),
						esc_attr( $placeholder ),
						$disabled, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_attr( $type )
					);
				}

				if ( $is_const ) {
					echo '<p class="description">' . esc_html__( 'Defined in wp-config.php', 'wp-signoz' ) . '</p>';
				}
			},
			'wp-signoz',
			$section
		);
	}

	/**
	 * Add a checkbox field.
	 *
	 * @param string $id      Option name.
	 * @param string $title   Field label.
	 * @param string $section Section ID.
	 */
	private function add_checkbox_field( $id, $title, $section ) {
		add_settings_field(
			$id,
			$title,
			function () use ( $id ) {
				$value = get_option( $id, 1 );
				printf(
					'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
					esc_attr( $id ),
					checked( $value, 1, false ),
					esc_html__( 'Enabled', 'wp-signoz' )
				);
			},
			'wp-signoz',
			$section
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include WP_SIGNOZ_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_wp-signoz' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-signoz-admin',
			WP_SIGNOZ_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WP_SIGNOZ_VERSION
		);

		wp_enqueue_script(
			'wp-signoz-admin',
			WP_SIGNOZ_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WP_SIGNOZ_VERSION,
			true
		);

		wp_localize_script(
			'wp-signoz-admin',
			'wpSignoz',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'signoz_test_connection' ),
				'i18n'    => array(
					'testing'    => __( 'Testing...', 'wp-signoz' ),
					'success'    => __( 'Connection successful!', 'wp-signoz' ),
					'error'      => __( 'Connection failed: ', 'wp-signoz' ),
					'testButton' => __( 'Test Connection', 'wp-signoz' ),
				),
			)
		);
	}

	/**
	 * AJAX handler for testing the SigNoz connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'signoz_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wp-signoz' ) );
		}

		$endpoint = $this->config->get( 'endpoint' );
		if ( empty( $endpoint ) ) {
			wp_send_json_error( __( 'OTLP Endpoint is not configured.', 'wp-signoz' ) );
		}

		$url     = rtrim( $endpoint, '/' ) . '/v1/traces';
		$headers = $this->config->get_otlp_headers();

		// Send a minimal test payload.
		$test_payload = array(
			'resourceSpans' => array(),
		);

		$response = wp_remote_post(
			$url,
			array(
				'body'      => wp_json_encode( $test_payload ),
				'headers'   => $headers,
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( __( 'Connection successful!', 'wp-signoz' ) );
		} else {
			$body = wp_remote_retrieve_body( $response );
			wp_send_json_error(
				sprintf(
					/* translators: 1: HTTP status code, 2: response body */
					__( 'HTTP %1$d — %2$s', 'wp-signoz' ),
					$code,
					$body
				)
			);
		}
	}

	/**
	 * Add Settings link to plugin actions.
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified links.
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'options-general.php?page=wp-signoz' ),
			__( 'Settings', 'wp-signoz' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	// -- Sanitization callbacks --

	/**
	 * Sanitize endpoint URL.
	 *
	 * @param string $value Input value.
	 * @return string Sanitized URL.
	 */
	public function sanitize_signoz_endpoint( $value ) {
		return esc_url_raw( rtrim( $value, '/' ) );
	}

	/**
	 * Sanitize access token.
	 *
	 * @param string $value Input value.
	 * @return string Sanitized token.
	 */
	public function sanitize_signoz_access_token( $value ) {
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize service name.
	 *
	 * @param string $value Input value.
	 * @return string Sanitized name.
	 */
	public function sanitize_signoz_service_name( $value ) {
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize environment.
	 *
	 * @param string $value Input value.
	 * @return string Sanitized environment.
	 */
	public function sanitize_signoz_environment( $value ) {
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize sampling rate.
	 *
	 * @param mixed $value Input value.
	 * @return float Clamped between 0.0 and 1.0.
	 */
	public function sanitize_signoz_sampling_rate( $value ) {
		$rate = (float) $value;
		return max( 0.0, min( 1.0, $rate ) );
	}

	/**
	 * Sanitize checkbox (trace SQL).
	 *
	 * @param mixed $value Input value.
	 * @return int 1 or 0.
	 */
	public function sanitize_signoz_trace_sql( $value ) {
		return $value ? 1 : 0;
	}

	/**
	 * Sanitize checkbox (trace HTTP).
	 *
	 * @param mixed $value Input value.
	 * @return int 1 or 0.
	 */
	public function sanitize_signoz_trace_http( $value ) {
		return $value ? 1 : 0;
	}

	/**
	 * Sanitize checkbox (capture logs).
	 *
	 * @param mixed $value Input value.
	 * @return int 1 or 0.
	 */
	public function sanitize_signoz_capture_logs( $value ) {
		return $value ? 1 : 0;
	}
}

// Initialize admin when loaded.
new SigNoz_Admin();
