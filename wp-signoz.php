<?php
/**
 * Plugin Name: WP SigNoz
 * Plugin URI:  https://searchops.io/wp-signoz
 * Description: Integração com SigNoz — observabilidade completa com traces, métricas e logs via OpenTelemetry.
 * Version:     1.0.0
 * Author:      SearchOps
 * Author URI:  https://searchops.io/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-signoz
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package WP_SigNoz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_SIGNOZ_VERSION', '1.0.0' );
define( 'WP_SIGNOZ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_SIGNOZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_SIGNOZ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload Composer dependencies.
$autoload = WP_SIGNOZ_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// Load plugin classes.
require_once WP_SIGNOZ_PLUGIN_DIR . 'includes/class-signoz-config.php';
require_once WP_SIGNOZ_PLUGIN_DIR . 'includes/class-signoz-tracer.php';
require_once WP_SIGNOZ_PLUGIN_DIR . 'includes/class-signoz-metrics.php';
require_once WP_SIGNOZ_PLUGIN_DIR . 'includes/class-signoz-logger.php';

if ( is_admin() ) {
	require_once WP_SIGNOZ_PLUGIN_DIR . 'admin/class-signoz-admin.php';
}

/**
 * Main plugin class.
 */
final class WP_SigNoz {

	/**
	 * Singleton instance.
	 *
	 * @var WP_SigNoz|null
	 */
	private static $instance = null;

	/**
	 * Configuration instance.
	 *
	 * @var SigNoz_Config
	 */
	public $config;

	/**
	 * Tracer instance.
	 *
	 * @var SigNoz_Tracer
	 */
	public $tracer;

	/**
	 * Metrics instance.
	 *
	 * @var SigNoz_Metrics
	 */
	public $metrics;

	/**
	 * Logger instance.
	 *
	 * @var SigNoz_Logger
	 */
	public $logger;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_SigNoz
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->config = new SigNoz_Config();

		if ( ! $this->config->get( 'endpoint' ) ) {
			return;
		}

		$this->tracer  = new SigNoz_Tracer( $this->config );
		$this->metrics = new SigNoz_Metrics( $this->config );
		$this->logger  = new SigNoz_Logger( $this->config );

		$this->init_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function init_hooks() {
		// Start root span early.
		add_action( 'plugins_loaded', array( $this->tracer, 'start_root_span' ), 1 );

		// Track SQL queries.
		if ( $this->config->get( 'trace_sql' ) ) {
			add_filter( 'query', array( $this->tracer, 'before_query' ), 1 );
			add_action( 'shutdown', array( $this->tracer, 'capture_queries' ), 5 );
		}

		// Track external HTTP requests.
		if ( $this->config->get( 'trace_http' ) ) {
			add_filter( 'pre_http_request', array( $this->tracer, 'before_http_request' ), 10, 3 );
			add_action( 'http_api_debug', array( $this->tracer, 'after_http_request' ), 10, 5 );
		}

		// Capture logs.
		if ( $this->config->get( 'capture_logs' ) ) {
			$this->logger->register_handlers();
		}

		// Collect metrics on shutdown.
		add_action( 'shutdown', array( $this->metrics, 'collect' ), 8 );

		// Finalize and flush on shutdown.
		add_action( 'shutdown', array( $this->tracer, 'finish_root_span' ), 9 );
		add_action( 'shutdown', array( $this, 'flush' ), 10 );

		// Auth tracking.
		add_action( 'wp_login', array( $this->logger, 'on_login' ), 10, 2 );
		add_action( 'wp_logout', array( $this->logger, 'on_logout' ) );
		add_action( 'wp_login_failed', array( $this->logger, 'on_login_failed' ) );
	}

	/**
	 * Flush all telemetry data to SigNoz.
	 */
	public function flush() {
		$this->tracer->flush();
		$this->logger->flush();
	}
}

/**
 * Initialize the plugin.
 */
function wp_signoz_init() {
	return WP_SigNoz::get_instance();
}

add_action( 'plugins_loaded', 'wp_signoz_init', 0 );

// Register activation/deactivation hooks.
register_activation_hook( __FILE__, 'wp_signoz_activate' );
register_deactivation_hook( __FILE__, 'wp_signoz_deactivate' );

/**
 * Plugin activation callback.
 */
function wp_signoz_activate() {
	$defaults = array(
		'signoz_endpoint'      => '',
		'signoz_access_token'  => '',
		'signoz_service_name'  => get_bloginfo( 'name', 'raw' ),
		'signoz_environment'   => 'production',
		'signoz_sampling_rate' => 1.0,
		'signoz_trace_sql'     => 1,
		'signoz_trace_http'    => 1,
		'signoz_capture_logs'  => 1,
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}
}

/**
 * Plugin deactivation callback.
 */
function wp_signoz_deactivate() {
	// Cleanup if needed in the future.
}
