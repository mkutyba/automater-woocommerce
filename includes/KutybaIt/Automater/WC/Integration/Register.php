<?php
declare( strict_types=1 );

namespace KutybaIt\Automater\WC\Integration;

use KutybaIt\Automater\Notice;
use KutybaIt\Automater\WC\Integration;

class Register {
	/**
	 * Initialize WooCommerce integration.
	 */
	public static function register_wc_integration() {
		// Checks if WooCommerce is installed.
		if ( class_exists( 'WC_Integration' ) ) {
			// Register the integration.
			add_filter( 'woocommerce_integrations', [ self::class, 'add_integration' ] );
		} else {
			add_action( 'admin_notices', function () {
				Notice::render_error( __( 'Unable to register Automater.pl integration. Is WooCommerce installed?', 'automater-pl' ) );
			} );
		}
	}

	/**
	 * Add a new integration to WooCommerce.
	 */
	public static function add_integration( $integrations ) {
		$integrations[] = Integration::class;

		return $integrations;
	}
}
