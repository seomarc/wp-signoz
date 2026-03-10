<?php
/**
 * Settings page template for WP SigNoz.
 *
 * @package WP_SigNoz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wp-signoz-settings">
	<h1>
		<span class="dashicons dashicons-chart-area" style="font-size: 30px; margin-right: 8px; vertical-align: middle;"></span>
		<?php echo esc_html( get_admin_page_title() ); ?>
	</h1>

	<div class="signoz-header-banner">
		<p>
			<?php
			printf(
				/* translators: %s: SigNoz website link */
				esc_html__( 'Send observability data (traces, metrics, and logs) from WordPress to %s via OpenTelemetry.', 'wp-signoz' ),
				'<a href="https://signoz.io/" target="_blank" rel="noopener noreferrer">SigNoz</a>'
			);
			?>
		</p>
		<p class="signoz-author">
			<?php
			printf(
				/* translators: %s: author link */
				esc_html__( 'Developed by %s', 'wp-signoz' ),
				'<a href="https://searchops.io/" target="_blank" rel="noopener noreferrer">SearchOps</a>'
			);
			?>
		</p>
	</div>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'wp-signoz' );
		do_settings_sections( 'wp-signoz' );
		?>

		<div class="signoz-actions">
			<?php submit_button( __( 'Save Settings', 'wp-signoz' ), 'primary', 'submit', false ); ?>
			<button type="button" id="signoz-test-connection" class="button button-secondary">
				<span class="dashicons dashicons-networking" style="vertical-align: middle; margin-right: 4px;"></span>
				<?php esc_html_e( 'Test Connection', 'wp-signoz' ); ?>
			</button>
		</div>

		<div id="signoz-test-result" class="notice" style="display: none;">
			<p></p>
		</div>
	</form>

	<div class="signoz-info-cards">
		<div class="signoz-card">
			<h3><?php esc_html_e( 'wp-config.php Override', 'wp-signoz' ); ?></h3>
			<p><?php esc_html_e( 'You can define constants in wp-config.php to override admin settings:', 'wp-signoz' ); ?></p>
			<code>
				define('SIGNOZ_ENDPOINT', 'http://...');<br>
				define('SIGNOZ_ACCESS_TOKEN', '...');<br>
				define('SIGNOZ_SERVICE_NAME', '...');<br>
				define('SIGNOZ_ENVIRONMENT', '...');<br>
				define('SIGNOZ_SAMPLING_RATE', 1.0);
			</code>
		</div>

		<div class="signoz-card">
			<h3><?php esc_html_e( 'SigNoz Cloud', 'wp-signoz' ); ?></h3>
			<p>
				<?php esc_html_e( 'For SigNoz Cloud, use the following endpoint format:', 'wp-signoz' ); ?>
			</p>
			<code>https://ingest.&lt;region&gt;.signoz.cloud:443</code>
			<p><?php esc_html_e( 'And provide your ingestion access token.', 'wp-signoz' ); ?></p>
		</div>
	</div>
</div>
