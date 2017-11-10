<?php
declare( strict_types=1 );

namespace KutybaIt\Automater;

use KutybaIt\Automater\WC\Integration;

class Automater {
	protected static $_instance;
	public $wc_integration;

	public static function instance(): Automater {
		if ( self::$_instance === null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Construct the plugin.
	 */
	public function __construct() {
		$this->init_hooks();
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	protected function init_hooks() {
		register_activation_hook( __FILE__, [ $this, 'plugin_activation' ] );
		register_deactivation_hook( __FILE__, [ $this, 'plugin_deactivation' ] );
	}

	public function init() {
		$this->init_wc_integration();
		$this->load_plugin_textdomain();
	}

	/**
	 * Initialize the plugin.
	 */
	protected function init_wc_integration() {
		// Checks if WooCommerce is installed.
		if ( class_exists( 'WC_Integration' ) ) {
			// Register the integration.
			add_filter( 'woocommerce_integrations', [ $this, 'add_integration' ] );
		} else {
			add_action( 'admin_notices', function () {
				Notice::render_error( __( 'Unable to register Automater.pl integration.', 'automater-pl' ) );
			} );
		}
	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'automater-pl', false, dirname( plugin_basename( AUTOMATER_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Add a new integration to WooCommerce.
	 */
	public function add_integration( $integrations ): array {
		$integrations[] = '\KutybaIt\Automater\WC\Integration';

		return $integrations;
	}

	public function plugin_activation() {
		$integration = new Integration;
		$integration->maybe_create_product_attribute();
	}

	public function plugin_deactivation() {
		$integration = new Integration;
		$integration->unschedule_cron_job();
	}
}
