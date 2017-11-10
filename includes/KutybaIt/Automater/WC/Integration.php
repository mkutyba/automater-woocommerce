<?php
declare( strict_types=1 );

namespace KutybaIt\Automater\WC;

use Exception;
use KutybaIt\Automater\Automater\Proxy;
use KutybaIt\Automater\Notice;
use WC_Admin_Settings;
use WC_Integration;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

class Integration extends WC_Integration {
	protected $api_key;
	protected $api_secret;
	protected $debug_log;
	protected $enable_cron_job;
	protected $proxy;

	/**
	 * Init and hook in the integration.
	 */
	public function __construct() {
		$this->id                 = 'automater-integration';
		$this->method_title       = __( 'Automater.pl', 'automater-pl' );
		$this->method_description = sprintf( __( 'An integration with Automater.pl by %s', 'automater-pl' ), '<A href="https://kutyba.it" target="_blank">kutyba.it</a>' );
		$link_import              = admin_url( 'admin-ajax.php?action=import_automater_products&nonce=' . wp_create_nonce( 'import_automater_products_nonce' ) );
		$link_stocks              = admin_url( 'admin-ajax.php?action=update_automater_stocks&nonce=' . wp_create_nonce( 'update_automater_stocks_nonce' ) );
		$this->method_description .= '<br><br><a href="' . $link_import . '" class="page-title-action">' . __( 'Import products from your Automater.pl account', 'automater-pl' ) . '</a>';
		$this->method_description .= '<br><br><a href="' . $link_stocks . '" class="page-title-action">' . __( 'Update products stocks', 'automater-pl' ) . '</a>';
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();
		// Define user set variables.
		$this->api_key         = $this->get_option( 'api_key' );
		$this->api_secret      = $this->get_option( 'api_secret' );
		$this->debug_log       = $this->get_option( 'debug_log' ) === 'yes';
		$this->enable_cron_job = $this->get_option( 'enable_cron_job' ) === 'yes';

		// Actions and filters.

		add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );
		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, [ $this, 'sanitize_settings' ] );
		// Display an admin notice, if setup is required.
		add_action( 'admin_notices', [ $this, 'maybe_display_admin_notice' ] );
		// Hook order placed
		add_action( 'woocommerce_thankyou', [ $this, 'order_placed' ], 10, 1 );
		// Hook order completed (paid)
		add_action( 'woocommerce_order_status_completed', [ $this, 'order_completed' ], 10, 1 );

		add_action( 'wp_ajax_import_automater_products', [ $this, 'import_automater_products' ] );
		add_action( 'wp_ajax_update_automater_stocks', [ $this, 'update_automater_stocks' ] );

		if ( isset( $_REQUEST['import'] ) && $_REQUEST['import'] === 'success' ) {
			WC_Admin_Settings::add_message( __( 'Products import success.', 'automater-pl' ) );
		} elseif ( isset( $_REQUEST['import'] ) && $_REQUEST['import'] === 'failed' ) {
			WC_Admin_Settings::add_error( __( 'Products import failed. Check logs.', 'automater-pl' ) );
		} elseif ( isset( $_REQUEST['import'] ) && $_REQUEST['import'] === 'nothing' ) {
			WC_Admin_Settings::add_message( __( 'Nothing new to import.', 'automater-pl' ) );
		}

		if ( isset( $_REQUEST['update'] ) && $_REQUEST['update'] === 'success' ) {
			WC_Admin_Settings::add_message( __( 'Stocks update success.', 'automater-pl' ) );
		} elseif ( isset( $_REQUEST['update'] ) && $_REQUEST['update'] === 'nothing' ) {
			WC_Admin_Settings::add_message( __( 'Nothing to update.', 'automater-pl' ) );
		}

		add_filter( 'cron_schedules', [ $this, 'add_cron_recurrence_interval' ] );
		add_action( 'update_stocks_job_action', function () {
			if ( $this->debug_log ) {
				wc_get_logger()->notice( 'Running update stocks job (cron)' );
			}
			$this->update_stocks_job();
		} );
		add_action( 'init', [ $this, 'schedule_cron_job' ] );
	}

	public function maybe_create_product_attribute() {
		wc_get_logger()->notice( 'maybe_create_product_attribute' );
		global $wpdb;

		$attribute_name = 'automater_product';

		$exists = $wpdb->get_var(
			$wpdb->prepare( "
                    SELECT attribute_id
                    FROM " . $wpdb->prefix . "woocommerce_attribute_taxonomies
                    WHERE attribute_name = %s",
				$attribute_name )
		);

		if ( ! $exists ) {
			wc_get_logger()->notice( "Create product attribute '$attribute_name'" );
			$wpdb->insert( $wpdb->prefix . "woocommerce_attribute_taxonomies", [
				'attribute_name'    => $attribute_name,
				'attribute_label'   => __( 'Automater Product' ),
				'attribute_type'    => 'select',
				'attribute_orderby' => 'menu_order',
				'attribute_public'  => 0,
			] );
			delete_transient( 'wc_attribute_taxonomies' );
		}
	}

	public function add_cron_recurrence_interval( $schedules ) {
		$schedules['5min'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'automater-pl' )
		);

		return $schedules;
	}

	public function unschedule_cron_job() {
		$timestamp = wp_next_scheduled( 'update_stocks_job_action' );

		if ( $timestamp ) {
			if ( $this->debug_log ) {
				wc_get_logger()->notice( 'Removing cron job' );
			}
			wp_unschedule_event( $timestamp, 'update_stocks_job_action' );
		}
	}

	public function schedule_cron_job() {
		if ( ! $this->enable_cron_job ) {
			return $this->unschedule_cron_job();
		}
		if ( ! wp_next_scheduled( 'update_stocks_job_action' ) ) {
			if ( $this->debug_log ) {
				wc_get_logger()->notice( 'Creating new cron job' );
			}
			wp_schedule_event( time(), '5min', 'update_stocks_job_action' );
		}
	}

	protected function api_enabled() {
		return $this->valid_key( $this->api_key, false ) && $this->valid_key( $this->api_secret, false );
	}

	public function import_automater_products() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'import_automater_products_nonce' ) ) {
			exit( __( 'Cheatin&#8217; uh?' ) );
		}

		if ( ! $this->api_enabled() ) {
			exit( __( 'Please provide API configuration first.' ) );
		}

		$taxonomy = 'pa_automater_product';

		$tax = get_taxonomy( $taxonomy );
		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			wp_die(
				'<h1>' . __( 'Cheatin&#8217; uh?' ) . '</h1>' .
				'<p>' . __( 'Sorry, you are not allowed to create terms in this taxonomy.' ) . '</p>',
				403
			);
		}

		$referer = wp_unslash( $_SERVER['HTTP_REFERER'] );
		$referer = remove_query_arg( [
			'_wp_http_referer',
			'_wpnonce',
			'error',
			'message',
			'paged',
			'import',
			'update',
		], $referer );

		$imported = 0;
		$errored  = 0;

		$products = $this->get_proxy()->get_all_products();

		$terms = get_terms( $taxonomy, [ 'orderby' => 'name', 'hide_empty' => 0, ] );

		$existing_products = [];
		foreach ( $terms as $term ) {
			$existing_products[ $term->slug ] = $term->term_id;
		}
		$to_delete = $existing_products;

		foreach ( $products as $product ) {
			$product['id'] = sanitize_term_field( 'slug', $product['id'], 0, $taxonomy, 'db' );

			if ( isset( $existing_products[ $product['id'] ] ) ) {
				unset( $to_delete[ $product['id'] ] );
				continue;
			}

			$params = [
				'post_type' => 'product',
				'slug'      => $product['id'],
			];

			if ( $this->debug_log ) {
				wc_get_logger()->notice( 'Importing product: ID ' . $product['id'] );
			}
			$ret = wp_insert_term( $product['name'], $taxonomy, $params );
			if ( $ret && ! is_wp_error( $ret ) ) {
				$imported ++;
			} else {
				wc_get_logger()->error( 'Product was not imported because of error: ID ' . $product['id'] );
			}
		}

		foreach ( $to_delete as $delete ) {
			if ( $this->debug_log ) {
				wc_get_logger()->notice( 'Deleting not existing product: ID ' . $delete );
			}
			wp_delete_term( $delete, $taxonomy );
		}

		if ( $imported ) {
			$location = add_query_arg( 'import', 'success', $referer );
		} elseif ( $errored ) {
			$location = add_query_arg( 'import', 'failed', $referer );
		} else {
			$location = add_query_arg( 'import', 'nothing', $referer );
		}

		wp_redirect( $location );
		exit();
	}

	public function update_automater_stocks() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'update_automater_stocks_nonce' ) ) {
			exit( __( 'Cheatin&#8217; uh?' ) );
		}

		if ( ! $this->api_enabled() ) {
			exit( __( 'Please provide API configuration first.' ) );
		}

		if ( $this->debug_log ) {
			wc_get_logger()->notice( 'Running update stocks job (manual)' );
		}

		$referer = wp_unslash( $_SERVER['HTTP_REFERER'] );
		$referer = remove_query_arg( [
			'_wp_http_referer',
			'_wpnonce',
			'error',
			'message',
			'paged',
			'import',
			'update',
		], $referer );

		$updated = $this->update_stocks_job();

		if ( $updated ) {
			$location = add_query_arg( 'update', 'success', $referer );
		} else {
			$location = add_query_arg( 'update', 'nothing', $referer );
		}

		wp_redirect( $location );
		exit();
	}

	protected function update_stocks_job(): int {
		if ( ! $this->api_enabled() ) {
			exit();
		}
		$updated  = 0;
		$products = wc_get_products( [ 'automater_product' => '' ] );
		foreach ( $products as $product ) {
			$automater_product_id = $this->get_automater_product_id_for_wc_product( $product );
			if ( ! $automater_product_id ) {
				continue;
			}

			$this->update_product_stock_from_automater( $product, $automater_product_id );
			$updated ++;
		}

		return $updated;
	}

	/**
	 * @param $product WC_Product
	 * @param $automater_product_id string
	 */
	protected function update_product_stock_from_automater( $product, $automater_product_id ) {
		if ( ! $product->get_manage_stock() ) {
			return;
		}

		$qty = $this->get_proxy()->get_count_for_product( $automater_product_id );

		if ( $this->debug_log ) {
			wc_get_logger()->notice( 'Updating product stock: ID ' . $product->get_id() . ', quantity ' . $qty );
		}

		$product->set_stock_quantity( $qty );
		$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
		$product->save();
	}

	/**
	 * Initialize integration settings form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'api_key'         => [
				'title'       => __( 'API Key', 'automater-pl' ),
				'type'        => 'text',
				'description' => __( 'Login to Automater.pl and get keys from Settings / settings / API', 'automater-pl' ),
				'desc_tip'    => true,
				'default'     => ''
			],
			'api_secret'      => [
				'title'       => __( 'API Secret', 'automater-pl' ),
				'type'        => 'text',
				'description' => __( 'Login to Automater.pl and get keys from Settings / settings / API', 'automater-pl' ),
				'desc_tip'    => true,
				'default'     => ''
			],
			'debug_log'       => [
				'title'       => __( 'Debug Log', 'automater-pl' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable the logging of API calls and errors', 'automater-pl' ),
				'desc_tip'    => true,
				'default'     => 'no'
			],
			'enable_cron_job' => [
				'title'       => __( 'Synchronize stocks every 5 minutes', 'automater-pl' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable cron job to synchronize products stocks', 'automater-pl' ),
				'desc_tip'    => true,
				'default'     => 'no'
			]
		];
	}

	/**
	 * Sanitize our settings
	 */
	public function sanitize_settings( $settings ) {
		if ( isset( $settings ) ) {
			if ( isset( $settings['api_key'] ) ) {
				$settings['api_key'] = trim( $settings['api_key'] );
			}
			if ( isset( $settings['api_secret'] ) ) {
				$settings['api_secret'] = trim( $settings['api_secret'] );
			}
		}

		return $settings;
	}

	public function validate_api_key_field( $key, $value ) {
		$value = trim( $value );
		if ( ! $this->valid_key( $value ) ) {
			WC_Admin_Settings::add_error( __( 'Looks like you made a mistake with the API Key field. Make sure it is 32 characters copied from Automater.pl settings', 'automater-pl' ) );
		}

		return $value;
	}

	public function validate_api_secret_field( $key, $value ) {
		$value = trim( $value );
		if ( ! $this->valid_key( $value ) ) {
			WC_Admin_Settings::add_error( __( 'Looks like you made a mistake with the API Secret field. Make sure it is 32 characters copied from Automater.pl settings', 'automater-pl' ) );
		}

		return $value;
	}

	protected function valid_key( $value, $can_be_empty = true ) {
		$valid = false;
		if ( $can_be_empty ) {
			$valid = $valid || ! isset( $value ) || $value === '';
		}
		$valid = $valid || strlen( $value ) === 32;

		return $valid;
	}

	/**
	 * Display an admin notice, if not on the integration screen and if the account isn't yet connected.
	 */
	public function maybe_display_admin_notice() {
		if ( isset( $_GET['page'], $_GET['tab'] ) && $_GET['page'] === 'wc-settings' && $_GET['tab'] === 'integration' ) {
			return;
		}
		if ( $this->api_key && $this->api_secret ) {
			return;
		}

		$url = $this->get_settings_url();
		Notice::render_notice( __( 'Automater.pl integration is almost ready. To get started, connect your account by providing API Keys.', 'automater-pl' ) . ' <a href="' . esc_url( $url ) . '">' . __( 'Go to settings', 'automater-pl' ) . '</a>' );
	}

	/**
	 * Generate a URL to our specific settings screen.
	 */
	public function get_settings_url() {
		$url = admin_url( 'admin.php' );
		$url = add_query_arg( 'page', 'wc-settings', $url );
		$url = add_query_arg( 'tab', 'integration', $url );

		return $url;
	}

	protected function get_proxy() {
		if ( $this->proxy === null ) {
			$this->proxy = new Proxy( $this->api_key, $this->api_secret );
		}

		return $this->proxy;
	}

	public function order_placed( $order_id ) {
		if ( ! $this->api_enabled() ) {
			return;
		}

		if ( $this->debug_log ) {
			wc_get_logger()->notice( "Order has been placed: ID $order_id" );
		}

		$this->create_transaction( $order_id );
	}

	public function order_completed( $order_id ) {
		if ( ! $this->api_enabled() ) {
			return;
		}

		if ( $this->debug_log ) {
			wc_get_logger()->notice( "Payment has been received: ID $order_id" );
		}

		$this->pay_transaction( $order_id );
	}

	protected function create_transaction( $order_id ) {
		$order = wc_get_order( $order_id );

		$items    = $order->get_items();
		$result   = [];
		$result[] = __( 'Automater.pl codes:', 'automater-pl' );

		$products = $this->validate_items( $items, $result );
		$this->validate_products_stock( $products, $result );
		$this->create_automater_transaction( $products, $order, $result );
		$this->add_order_note( $result, $order );
		if ( $this->debug_log ) {
			wc_get_logger()->notice( implode( ' | ', $result ) );
		}
	}

	protected function validate_items( $items, &$result ) {
		$products = [];
		/** @var WC_Order_Item_Product $item */
		foreach ( $items as $item ) {
			try {
				$automater_product_id = $this->get_automater_product_id_for_wc_product( $item->get_product() );
				if ( ! $automater_product_id ) {
					$result[] = sprintf( __( 'Product not managed by automater: %s [%s]', 'automater-pl' ), $item->get_name(), $item->get_id() );
					continue;
				}
				$qty = (int) $item->get_quantity();
				if ( is_nan( $qty ) || $qty <= 0 ) {
					$result[] = sprintf( __( 'Invalid quantity of product: %s [%s]', 'automater-pl' ), $item->get_name(), $item->get_id() );
					continue;
				}
				if ( ! isset( $products[ $automater_product_id ] ) ) {
					$products[ $automater_product_id ] = 0;
				}
				$products[ $automater_product_id ] += $qty;
			} catch ( Exception $e ) {
				$result[] = $e->getMessage() . sprintf( ': %s [%s]', $item->get_name(), $item->get_id() );
			}
		}

		return $products;
	}

	protected function validate_products_stock( array &$products, array &$result ) {
		foreach ( $products as $automater_product_id => $qty ) {
			try {
				if ( ! $qty ) {
					$result[] = sprintf( __( 'No codes for ID: %s', 'automater-pl' ), $automater_product_id );
					unset( $products[ $automater_product_id ] );
					continue;
				}
				$codes_count = $this->get_proxy()->get_count_for_product( $automater_product_id );
				if ( ! $codes_count ) {
					$result[] = sprintf( __( 'No codes for ID: %s', 'automater-pl' ), $automater_product_id );
					unset( $products[ $automater_product_id ] );
					continue;
				}
				if ( $codes_count < $qty ) {
					$result[]                          = sprintf( __( 'Not enough codes for ID, sent less: %s', 'automater-pl' ), $automater_product_id );
					$products[ $automater_product_id ] = $codes_count;
				}
			} catch ( Exception $e ) {
				$result[] = $e->getMessage() . sprintf( ': %s', $automater_product_id );
				unset( $products[ $automater_product_id ] );
			}
		}
	}

	private function create_automater_transaction( $products, WC_Order $order, &$result ) {
		if ( count( $products ) ) {
			try {
				if ( $this->debug_log ) {
					wc_get_logger()->notice( 'Creating automater transaction' );
					wc_get_logger()->notice( $order->get_billing_email() );
					wc_get_logger()->notice( $order->get_billing_phone() );
					wc_get_logger()->notice( sprintf( __( 'Order from %s, id: #%s', 'automater-pl' ), get_bloginfo( 'name' ), $order->get_order_number() ) );
				}
				$response = $this->get_proxy()->create_transaction(
					$products, $order->get_billing_email(),
					$order->get_billing_phone(),
					sprintf( __( 'Order from %s, id: #%s', 'automater-pl' ), get_bloginfo( 'name' ), $order->get_order_number() )
				);
				if ( $this->debug_log ) {
					wc_get_logger()->notice( var_export( $response, true ) );
				}
				if ( $response['code'] == '200' ) {
					if ( $automater_cart_id = $response['cart_id'] ) {
						$order->update_meta_data( 'automater_cart_id', $automater_cart_id );
						$order->save();
						$result[] = sprintf( __( 'Created cart number: %s', 'automater-pl' ), $automater_cart_id );
					}
				}
			} catch ( Exception $e ) {
				if ( $this->debug_log ) {
					wc_get_logger()->notice( $e->getMessage() );
				}
				$result[] = $e->getMessage();
			}
		}
	}

	/**
	 * @param $status
	 * @param $order WC_Order|bool
	 */
	protected function add_order_note( $status, $order ) {
		if ( ! $order ) {
			return;
		}
		$order->add_order_note( implode( '<br>', $status ) );
	}

	/**
	 * @param $product WC_Product
	 *
	 * @return array|bool
	 */
	protected function get_automater_product_id_for_wc_product( $product ) {
		$attributes = $product->get_attributes();
		$attribute  = 'automater_product';

		$attribute_object = false;
		if ( isset( $attributes[ $attribute ] ) ) {
			$attribute_object = $attributes[ $attribute ];
		} elseif ( isset( $attributes[ 'pa_' . $attribute ] ) ) {
			$attribute_object = $attributes[ 'pa_' . $attribute ];
		}
		if ( ! $attribute_object ) {
			$automater_product_id = false;
		} else {
			$automater_product_id = wc_get_product_terms( $product->get_id(), $attribute_object->get_name(), [ 'fields' => 'slugs' ] );
			$automater_product_id = array_values( $automater_product_id )[0];
		}

		return $automater_product_id;
	}

	protected function pay_transaction( $order_id ) {
		$order             = wc_get_order( $order_id );
		$automater_cart_id = $order->get_meta( 'automater_cart_id' );

		if ( ! $automater_cart_id ) {
			return;
		}

		$result = [];
		try {
			$response = $this->get_proxy()->create_payment( $automater_cart_id, $order->get_id(), $order->get_subtotal() );
			if ( $this->debug_log ) {
				wc_get_logger()->notice( var_export( $response, true ) );
			}
			if ( $response['code'] == '200' ) {
				$result[] = sprintf( __( 'Automater.pl - paid successfully: %s', 'automater-pl' ), $automater_cart_id );
			}
		} catch ( Exception $e ) {
			if ( $this->debug_log ) {
				wc_get_logger()->notice( $e->getMessage() );
			}
			$result[] = $e->getMessage();
			$this->add_order_note( $result, $order );

			return;
		}
		$this->add_order_note( $result, $order );
	}
}
