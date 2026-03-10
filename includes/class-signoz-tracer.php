<?php
/**
 * Distributed tracing for WP SigNoz.
 *
 * @package WP_SigNoz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages spans and traces, sending them to SigNoz via OTLP/HTTP.
 */
class SigNoz_Tracer {

	/**
	 * Plugin config.
	 *
	 * @var SigNoz_Config
	 */
	private $config;

	/**
	 * Trace ID (128-bit hex).
	 *
	 * @var string
	 */
	private $trace_id;

	/**
	 * Root span ID.
	 *
	 * @var string
	 */
	private $root_span_id;

	/**
	 * Start time in nanoseconds.
	 *
	 * @var int
	 */
	private $start_time;

	/**
	 * Collected child spans.
	 *
	 * @var array
	 */
	private $spans = array();

	/**
	 * HTTP request tracking data.
	 *
	 * @var array
	 */
	private $http_requests = array();

	/**
	 * Whether the root span has been started.
	 *
	 * @var bool
	 */
	private $started = false;

	/**
	 * Constructor.
	 *
	 * @param SigNoz_Config $config Plugin configuration.
	 */
	public function __construct( SigNoz_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Start the root span for this request.
	 */
	public function start_root_span() {
		// Apply sampling rate.
		$rate = (float) $this->config->get( 'sampling_rate' );
		if ( $rate < 1.0 && ( mt_rand() / mt_getrandmax() ) > $rate ) {
			return;
		}

		$this->trace_id     = $this->generate_trace_id();
		$this->root_span_id = $this->generate_span_id();
		$this->start_time   = $this->now_nano();
		$this->started      = true;
	}

	/**
	 * Check if tracing is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->started;
	}

	/**
	 * Get the current trace ID.
	 *
	 * @return string
	 */
	public function get_trace_id() {
		return $this->trace_id;
	}

	/**
	 * Get the root span ID.
	 *
	 * @return string
	 */
	public function get_root_span_id() {
		return $this->root_span_id;
	}

	/**
	 * Filter hook to track queries before execution.
	 *
	 * @param string $query SQL query string.
	 * @return string Unmodified query.
	 */
	public function before_query( $query ) {
		return $query;
	}

	/**
	 * Capture all queries from $wpdb at shutdown.
	 */
	public function capture_queries() {
		if ( ! $this->started ) {
			return;
		}

		global $wpdb;

		if ( empty( $wpdb->queries ) ) {
			return;
		}

		foreach ( $wpdb->queries as $query_data ) {
			$sql      = $query_data[0];
			$duration = (float) $query_data[1];
			$caller   = $query_data[2];

			$operation = strtoupper( strtok( trim( $sql ), ' ' ) );

			// Extract table name.
			$table = $this->extract_table_name( $sql, $operation );

			$span_id    = $this->generate_span_id();
			$start_nano = $this->start_time + (int) ( ( microtime( true ) - ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) - $duration ) * 1e9 );
			$end_nano   = $start_nano + (int) ( $duration * 1e9 );

			$this->spans[] = array(
				'traceId'           => $this->trace_id,
				'spanId'            => $span_id,
				'parentSpanId'      => $this->root_span_id,
				'name'              => 'DB ' . $operation,
				'kind'              => 3, // SPAN_KIND_CLIENT
				'startTimeUnixNano' => (string) $start_nano,
				'endTimeUnixNano'   => (string) $end_nano,
				'attributes'        => $this->make_attributes(
					array(
						'db.system'    => 'mysql',
						'db.statement' => $sql,
						'db.operation' => $operation,
						'db.sql.table' => $table,
						'code.caller'  => $caller,
					)
				),
				'status'            => array( 'code' => 0 ),
			);
		}
	}

	/**
	 * Filter hook before external HTTP requests.
	 *
	 * @param false|array|\WP_Error $preempt Preempt value.
	 * @param array                 $args    Request arguments.
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error
	 */
	public function before_http_request( $preempt, $args, $url ) {
		if ( ! $this->started ) {
			return $preempt;
		}

		$request_id = md5( $url . wp_json_encode( $args ) . microtime() );

		$this->http_requests[ $request_id ] = array(
			'url'        => $url,
			'method'     => $args['method'] ?? 'GET',
			'start_time' => $this->now_nano(),
		);

		return $preempt;
	}

	/**
	 * Action hook after external HTTP requests.
	 *
	 * @param array|\WP_Error $response    Response or error.
	 * @param string          $context     Context ('response').
	 * @param string          $class       Transport class.
	 * @param array           $args        Request arguments.
	 * @param string          $url         Request URL.
	 */
	public function after_http_request( $response, $context, $class, $args, $url ) {
		if ( ! $this->started ) {
			return;
		}

		$end_time = $this->now_nano();

		// Find the matching request.
		$matched_id   = null;
		$matched_data = null;
		foreach ( $this->http_requests as $id => $data ) {
			if ( $data['url'] === $url ) {
				$matched_id   = $id;
				$matched_data = $data;
				break;
			}
		}

		if ( ! $matched_data ) {
			return;
		}

		unset( $this->http_requests[ $matched_id ] );

		$status_code = 0;
		$span_status = 0; // OK
		if ( is_wp_error( $response ) ) {
			$span_status = 2; // ERROR
		} elseif ( is_array( $response ) ) {
			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code >= 400 ) {
				$span_status = 2;
			}
		}

		$parsed = wp_parse_url( $url );

		$this->spans[] = array(
			'traceId'           => $this->trace_id,
			'spanId'            => $this->generate_span_id(),
			'parentSpanId'      => $this->root_span_id,
			'name'              => 'HTTP ' . $matched_data['method'] . ' ' . ( $parsed['host'] ?? '' ),
			'kind'              => 3, // SPAN_KIND_CLIENT
			'startTimeUnixNano' => (string) $matched_data['start_time'],
			'endTimeUnixNano'   => (string) $end_time,
			'attributes'        => $this->make_attributes(
				array(
					'http.method'      => $matched_data['method'],
					'http.url'         => $url,
					'http.status_code' => $status_code,
					'net.peer.name'    => $parsed['host'] ?? '',
				)
			),
			'status'            => array( 'code' => $span_status ),
		);
	}

	/**
	 * Finish the root span and prepare for flush.
	 */
	public function finish_root_span() {
		if ( ! $this->started ) {
			return;
		}

		$end_time = $this->now_nano();

		$method      = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		$uri         = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$status_code = http_response_code() ?: 200;
		$user_agent  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		$attributes = array(
			'http.method'      => $method,
			'http.url'         => home_url( $uri ),
			'http.status_code' => $status_code,
			'http.user_agent'  => $user_agent,
			'service.name'     => $this->config->get( 'service_name' ),
			'deployment.environment' => $this->config->get( 'environment' ),
		);

		// WordPress-specific attributes.
		if ( function_exists( 'get_post_type' ) && get_post_type() ) {
			$attributes['wordpress.post_type'] = get_post_type();
		}

		if ( function_exists( 'is_admin' ) ) {
			$attributes['wordpress.is_admin'] = is_admin() ? 'true' : 'false';
		}

		$template = $this->get_current_template();
		if ( $template ) {
			$attributes['wordpress.template'] = $template;
		}

		$span_status = 0; // OK
		if ( $status_code >= 500 ) {
			$span_status = 2; // ERROR
		}

		$root_span = array(
			'traceId'           => $this->trace_id,
			'spanId'            => $this->root_span_id,
			'name'              => $method . ' ' . $uri,
			'kind'              => 2, // SPAN_KIND_SERVER
			'startTimeUnixNano' => (string) $this->start_time,
			'endTimeUnixNano'   => (string) $end_time,
			'attributes'        => $this->make_attributes( $attributes ),
			'status'            => array( 'code' => $span_status ),
		);

		// Prepend root span.
		array_unshift( $this->spans, $root_span );
	}

	/**
	 * Flush collected spans to SigNoz via OTLP/HTTP.
	 */
	public function flush() {
		if ( ! $this->started || empty( $this->spans ) ) {
			return;
		}

		$payload = array(
			'resourceSpans' => array(
				array(
					'resource'   => array(
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
					'scopeSpans' => array(
						array(
							'scope' => array(
								'name'    => 'wp-signoz',
								'version' => WP_SIGNOZ_VERSION,
							),
							'spans' => $this->spans,
						),
					),
				),
			),
		);

		$this->send_otlp( 'traces', $payload );
		$this->spans = array();
	}

	/**
	 * Send OTLP data to SigNoz.
	 *
	 * @param string $signal  Signal type: 'traces', 'metrics', or 'logs'.
	 * @param array  $payload Data payload.
	 * @return bool Success.
	 */
	public function send_otlp( $signal, $payload ) {
		$endpoint = $this->config->get_otlp_endpoint( $signal );
		$headers  = $this->config->get_otlp_headers();

		$body = wp_json_encode( $payload );
		if ( ! $body ) {
			return false;
		}

		$args = array(
			'body'      => $body,
			'headers'   => $headers,
			'timeout'   => 5,
			'blocking'  => false, // Non-blocking for performance.
			'sslverify' => true,
		);

		$response = wp_remote_post( $endpoint, $args );

		return ! is_wp_error( $response );
	}

	/**
	 * Generate a 128-bit trace ID.
	 *
	 * @return string 32-char hex string.
	 */
	private function generate_trace_id() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Generate a 64-bit span ID.
	 *
	 * @return string 16-char hex string.
	 */
	private function generate_span_id() {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Get current time in nanoseconds.
	 *
	 * @return int
	 */
	private function now_nano() {
		return (int) ( microtime( true ) * 1e9 );
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
			} elseif ( is_bool( $value ) ) {
				$result[] = array(
					'key'   => $key,
					'value' => array( 'boolValue' => $value ),
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

	/**
	 * Extract table name from SQL query.
	 *
	 * @param string $sql       SQL statement.
	 * @param string $operation SQL operation type.
	 * @return string Table name or empty string.
	 */
	private function extract_table_name( $sql, $operation ) {
		$table = '';

		switch ( $operation ) {
			case 'SELECT':
			case 'DELETE':
				if ( preg_match( '/\bFROM\s+`?(\w+)`?/i', $sql, $matches ) ) {
					$table = $matches[1];
				}
				break;
			case 'INSERT':
			case 'REPLACE':
				if ( preg_match( '/\bINTO\s+`?(\w+)`?/i', $sql, $matches ) ) {
					$table = $matches[1];
				}
				break;
			case 'UPDATE':
				if ( preg_match( '/\bUPDATE\s+`?(\w+)`?/i', $sql, $matches ) ) {
					$table = $matches[1];
				}
				break;
		}

		return $table;
	}

	/**
	 * Get the current WordPress template being used.
	 *
	 * @return string Template filename or empty string.
	 */
	private function get_current_template() {
		if ( ! function_exists( 'get_page_template_slug' ) ) {
			return '';
		}

		global $template;
		if ( ! empty( $template ) ) {
			return basename( $template );
		}

		return '';
	}
}
