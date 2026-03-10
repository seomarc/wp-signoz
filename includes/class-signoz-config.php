<?php
/**
 * Configuration management for WP SigNoz.
 *
 * @package WP_SigNoz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles reading and providing plugin configuration.
 * wp-config.php constants take priority over admin settings.
 */
class SigNoz_Config {

	/**
	 * Map of config keys to wp-config.php constants and wp_options keys.
	 *
	 * @var array
	 */
	private $config_map = array(
		'endpoint'      => array(
			'constant' => 'SIGNOZ_ENDPOINT',
			'option'   => 'signoz_endpoint',
			'default'  => '',
		),
		'access_token'  => array(
			'constant' => 'SIGNOZ_ACCESS_TOKEN',
			'option'   => 'signoz_access_token',
			'default'  => '',
		),
		'service_name'  => array(
			'constant' => 'SIGNOZ_SERVICE_NAME',
			'option'   => 'signoz_service_name',
			'default'  => 'wordpress',
		),
		'environment'   => array(
			'constant' => 'SIGNOZ_ENVIRONMENT',
			'option'   => 'signoz_environment',
			'default'  => 'production',
		),
		'sampling_rate' => array(
			'constant' => 'SIGNOZ_SAMPLING_RATE',
			'option'   => 'signoz_sampling_rate',
			'default'  => 1.0,
		),
		'trace_sql'     => array(
			'constant' => null,
			'option'   => 'signoz_trace_sql',
			'default'  => true,
		),
		'trace_http'    => array(
			'constant' => null,
			'option'   => 'signoz_trace_http',
			'default'  => true,
		),
		'capture_logs'  => array(
			'constant' => null,
			'option'   => 'signoz_capture_logs',
			'default'  => true,
		),
	);

	/**
	 * Cached config values.
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Get a configuration value.
	 *
	 * @param string $key Configuration key.
	 * @return mixed Configuration value.
	 */
	public function get( $key ) {
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		if ( ! isset( $this->config_map[ $key ] ) ) {
			return null;
		}

		$map = $this->config_map[ $key ];

		// wp-config.php constants have priority.
		if ( $map['constant'] && defined( $map['constant'] ) ) {
			$value = constant( $map['constant'] );
		} else {
			$value = get_option( $map['option'], $map['default'] );
		}

		$this->cache[ $key ] = $value;

		return $value;
	}

	/**
	 * Get all configuration values.
	 *
	 * @return array All config key-value pairs.
	 */
	public function get_all() {
		$values = array();
		foreach ( array_keys( $this->config_map ) as $key ) {
			$values[ $key ] = $this->get( $key );
		}
		return $values;
	}

	/**
	 * Check if a config key is overridden by a wp-config.php constant.
	 *
	 * @param string $key Configuration key.
	 * @return bool True if overridden by constant.
	 */
	public function is_constant( $key ) {
		if ( ! isset( $this->config_map[ $key ] ) ) {
			return false;
		}
		$constant = $this->config_map[ $key ]['constant'];
		return $constant && defined( $constant );
	}

	/**
	 * Get the wp_options key for a config key.
	 *
	 * @param string $key Configuration key.
	 * @return string|null Option name.
	 */
	public function get_option_name( $key ) {
		return isset( $this->config_map[ $key ] ) ? $this->config_map[ $key ]['option'] : null;
	}

	/**
	 * Get the OTLP endpoint URL with the correct path.
	 *
	 * @param string $signal Signal type: 'traces', 'metrics', or 'logs'.
	 * @return string Full endpoint URL.
	 */
	public function get_otlp_endpoint( $signal = 'traces' ) {
		$endpoint = rtrim( $this->get( 'endpoint' ), '/' );
		return $endpoint . '/v1/' . $signal;
	}

	/**
	 * Get headers for OTLP requests.
	 *
	 * @return array HTTP headers.
	 */
	public function get_otlp_headers() {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		$token = $this->get( 'access_token' );
		if ( $token ) {
			$headers['signoz-access-token'] = $token;
		}

		return $headers;
	}
}
