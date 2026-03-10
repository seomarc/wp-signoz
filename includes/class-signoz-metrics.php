<?php
/**
 * Metrics collection for WP SigNoz.
 *
 * @package WP_SigNoz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects and sends performance metrics to SigNoz.
 */
class SigNoz_Metrics {

	/**
	 * Plugin config.
	 *
	 * @var SigNoz_Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param SigNoz_Config $config Plugin configuration.
	 */
	public function __construct( SigNoz_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Collect and send metrics at shutdown.
	 */
	public function collect() {
		$metrics = $this->gather_metrics();

		if ( empty( $metrics ) ) {
			return;
		}

		$payload = $this->build_payload( $metrics );
		$this->send( $payload );
	}

	/**
	 * Gather all metrics for this request.
	 *
	 * @return array Metrics data.
	 */
	private function gather_metrics() {
		$now_nano       = (string) ( (int) ( microtime( true ) * 1e9 ) );
		$request_start  = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true );
		$response_time  = microtime( true ) - (float) $request_start;
		$memory_usage   = memory_get_peak_usage( true );
		$uri            = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$method         = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		$status_code    = http_response_code() ?: 200;

		$route_attributes = $this->make_attributes(
			array(
				'http.method' => $method,
				'http.route'  => $uri,
			)
		);

		$metrics = array();

		// Response time histogram.
		$metrics[] = array(
			'name'        => 'wordpress.http.response_time',
			'description' => 'HTTP response time in seconds',
			'unit'        => 's',
			'gauge'       => array(
				'dataPoints' => array(
					array(
						'asDouble'         => $response_time,
						'timeUnixNano'     => $now_nano,
						'attributes'       => $route_attributes,
					),
				),
			),
		);

		// Memory usage.
		$metrics[] = array(
			'name'        => 'wordpress.memory.peak_usage',
			'description' => 'Peak memory usage in bytes',
			'unit'        => 'By',
			'gauge'       => array(
				'dataPoints' => array(
					array(
						'asInt'            => (string) $memory_usage,
						'timeUnixNano'     => $now_nano,
						'attributes'       => $route_attributes,
					),
				),
			),
		);

		// SQL query count and duration.
		global $wpdb;
		if ( ! empty( $wpdb->queries ) ) {
			$query_count    = count( $wpdb->queries );
			$total_duration = 0.0;
			foreach ( $wpdb->queries as $q ) {
				$total_duration += (float) $q[1];
			}

			$metrics[] = array(
				'name'        => 'wordpress.db.query_count',
				'description' => 'Number of database queries per request',
				'unit'        => '{queries}',
				'gauge'       => array(
					'dataPoints' => array(
						array(
							'asInt'            => (string) $query_count,
							'timeUnixNano'     => $now_nano,
							'attributes'       => $route_attributes,
						),
					),
				),
			);

			$metrics[] = array(
				'name'        => 'wordpress.db.query_duration',
				'description' => 'Total database query duration in seconds',
				'unit'        => 's',
				'gauge'       => array(
					'dataPoints' => array(
						array(
							'asDouble'         => $total_duration,
							'timeUnixNano'     => $now_nano,
							'attributes'       => $route_attributes,
						),
					),
				),
			);
		}

		// Error rate.
		if ( $status_code >= 400 ) {
			$metrics[] = array(
				'name'        => 'wordpress.http.errors',
				'description' => 'HTTP error count',
				'unit'        => '{errors}',
				'sum'         => array(
					'dataPoints'             => array(
						array(
							'asInt'            => '1',
							'timeUnixNano'     => $now_nano,
							'attributes'       => $this->make_attributes(
								array(
									'http.status_code' => $status_code,
									'http.route'       => $uri,
								)
							),
						),
					),
					'aggregationTemporality' => 1, // DELTA
					'isMonotonic'            => true,
				),
			);
		}

		return $metrics;
	}

	/**
	 * Build the OTLP metrics payload.
	 *
	 * @param array $metrics Metrics data.
	 * @return array OTLP payload.
	 */
	private function build_payload( $metrics ) {
		return array(
			'resourceMetrics' => array(
				array(
					'resource'     => array(
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
					'scopeMetrics' => array(
						array(
							'scope'   => array(
								'name'    => 'wp-signoz',
								'version' => WP_SIGNOZ_VERSION,
							),
							'metrics' => $metrics,
						),
					),
				),
			),
		);
	}

	/**
	 * Send metrics to SigNoz.
	 *
	 * @param array $payload OTLP metrics payload.
	 */
	private function send( $payload ) {
		$endpoint = $this->config->get_otlp_endpoint( 'metrics' );
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
