<?php
/**
 * Log capture and forwarding for WP SigNoz.
 *
 * @package WP_SigNoz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures PHP errors, authentication events, and sends logs to SigNoz via OTLP.
 */
class SigNoz_Logger {

	/**
	 * Plugin config.
	 *
	 * @var SigNoz_Config
	 */
	private $config;

	/**
	 * Collected log records.
	 *
	 * @var array
	 */
	private $log_records = array();

	/**
	 * Severity number mapping.
	 *
	 * @var array
	 */
	private static $severity_map = array(
		'TRACE' => 1,
		'DEBUG' => 5,
		'INFO'  => 9,
		'WARN'  => 13,
		'ERROR' => 17,
		'FATAL' => 21,
	);

	/**
	 * PHP error type to severity mapping.
	 *
	 * @var array
	 */
	private static $error_severity = array(
		E_ERROR             => 'FATAL',
		E_WARNING           => 'WARN',
		E_NOTICE            => 'INFO',
		E_DEPRECATED        => 'WARN',
		E_USER_ERROR        => 'ERROR',
		E_USER_WARNING      => 'WARN',
		E_USER_NOTICE       => 'INFO',
		E_USER_DEPRECATED   => 'WARN',
		E_RECOVERABLE_ERROR => 'ERROR',
	);

	/**
	 * Constructor.
	 *
	 * @param SigNoz_Config $config Plugin configuration.
	 */
	public function __construct( SigNoz_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Register PHP error handler and shutdown function.
	 */
	public function register_handlers() {
		set_error_handler( array( $this, 'handle_error' ) );
		register_shutdown_function( array( $this, 'handle_fatal_error' ) );
	}

	/**
	 * PHP error handler.
	 *
	 * @param int    $errno   Error level.
	 * @param string $errstr  Error message.
	 * @param string $errfile File where error occurred.
	 * @param int    $errline Line number.
	 * @return bool False to continue default error handling.
	 */
	public function handle_error( $errno, $errstr, $errfile, $errline ) {
		// Respect error_reporting setting.
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}

		$severity = self::$error_severity[ $errno ] ?? 'ERROR';

		$this->add_log(
			$errstr,
			$severity,
			array(
				'error.type'    => $this->error_type_name( $errno ),
				'code.filepath' => $errfile,
				'code.lineno'   => $errline,
			)
		);

		return false; // Allow default handler to continue.
	}

	/**
	 * Shutdown handler for fatal errors.
	 */
	public function handle_fatal_error() {
		$error = error_get_last();

		if ( $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ), true ) ) {
			$this->add_log(
				$error['message'],
				'FATAL',
				array(
					'error.type'    => $this->error_type_name( $error['type'] ),
					'code.filepath' => $error['file'],
					'code.lineno'   => $error['line'],
				)
			);

			$this->flush();
		}
	}

	/**
	 * Log a successful login.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 */
	public function on_login( $user_login, $user ) {
		$this->add_log(
			'User login successful: ' . $user_login,
			'INFO',
			array(
				'auth.event'   => 'login',
				'auth.user'    => $user_login,
				'auth.user_id' => $user->ID,
			)
		);
	}

	/**
	 * Log a logout event.
	 */
	public function on_logout() {
		$user = wp_get_current_user();

		$this->add_log(
			'User logout: ' . ( $user->user_login ?? 'unknown' ),
			'INFO',
			array(
				'auth.event' => 'logout',
				'auth.user'  => $user->user_login ?? 'unknown',
			)
		);
	}

	/**
	 * Log a failed login attempt.
	 *
	 * @param string $username Attempted username.
	 */
	public function on_login_failed( $username ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

		$this->add_log(
			'Login failed for user: ' . $username,
			'WARN',
			array(
				'auth.event' => 'login_failed',
				'auth.user'  => $username,
				'net.peer.ip' => $ip,
			)
		);
	}

	/**
	 * Add a log record to the buffer.
	 *
	 * @param string $message    Log message body.
	 * @param string $severity   Severity level (INFO, WARN, ERROR, FATAL).
	 * @param array  $attributes Additional attributes.
	 */
	public function add_log( $message, $severity = 'INFO', $attributes = array() ) {
		$severity_number = self::$severity_map[ $severity ] ?? 9;

		$record = array(
			'timeUnixNano'         => (string) ( (int) ( microtime( true ) * 1e9 ) ),
			'severityNumber'       => $severity_number,
			'severityText'         => $severity,
			'body'                 => array(
				'stringValue' => $message,
			),
			'attributes'           => $this->make_attributes( $attributes ),
		);

		// Attach trace context if available.
		$plugin = WP_SigNoz::get_instance();
		if ( $plugin->tracer && $plugin->tracer->is_active() ) {
			$record['traceId'] = $plugin->tracer->get_trace_id();
			$record['spanId']  = $plugin->tracer->get_root_span_id();
		}

		$this->log_records[] = $record;
	}

	/**
	 * Flush collected logs to SigNoz via OTLP/HTTP.
	 */
	public function flush() {
		if ( empty( $this->log_records ) ) {
			return;
		}

		$payload = array(
			'resourceLogs' => array(
				array(
					'resource'  => array(
						'attributes' => $this->make_attributes(
							array(
								'service.name'           => $this->config->get( 'service_name' ),
								'deployment.environment' => $this->config->get( 'environment' ),
								'telemetry.sdk.name'     => 'wp-signoz',
								'telemetry.sdk.version'  => WP_SIGNOZ_VERSION,
								'telemetry.sdk.language' => 'php',
							)
						),
					),
					'scopeLogs' => array(
						array(
							'scope'      => array(
								'name'    => 'wp-signoz',
								'version' => WP_SIGNOZ_VERSION,
							),
							'logRecords' => $this->log_records,
						),
					),
				),
			),
		);

		$endpoint = $this->config->get_otlp_endpoint( 'logs' );
		$headers  = $this->config->get_otlp_headers();

		$body = wp_json_encode( $payload );
		if ( ! $body ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 5,
				'blocking'  => false,
				'sslverify' => true,
			)
		);

		$this->log_records = array();
	}

	/**
	 * Get human-readable error type name.
	 *
	 * @param int $type PHP error constant.
	 * @return string Error type name.
	 */
	private function error_type_name( $type ) {
		$map = array(
			E_ERROR             => 'E_ERROR',
			E_WARNING           => 'E_WARNING',
			E_NOTICE            => 'E_NOTICE',
			E_DEPRECATED        => 'E_DEPRECATED',
			E_USER_ERROR        => 'E_USER_ERROR',
			E_USER_WARNING      => 'E_USER_WARNING',
			E_USER_NOTICE       => 'E_USER_NOTICE',
			E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_CORE_ERROR        => 'E_CORE_ERROR',
			E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
			E_PARSE             => 'E_PARSE',
		);

		return $map[ $type ] ?? 'E_UNKNOWN';
	}

	/**
	 * Convert key-value pairs to OTLP attribute format.
	 *
	 * @param array $attributes Key-value pairs.
	 * @return array OTLP formatted attributes.
	 */
	private function make_attributes( $attributes ) {
		$result = array();

		foreach ( $attributes as $key => $value ) {
			if ( is_int( $value ) ) {
				$result[] = array(
					'key'   => $key,
					'value' => array( 'intValue' => (string) $value ),
				);
			} elseif ( is_float( $value ) ) {
				$result[] = array(
					'key'   => $key,
					'value' => array( 'doubleValue' => $value ),
				);
			} else {
				$result[] = array(
					'key'   => $key,
					'value' => array( 'stringValue' => (string) $value ),
				);
			}
		}

		return $result;
	}
}
