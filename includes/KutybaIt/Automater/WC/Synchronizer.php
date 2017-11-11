<?php
declare( strict_types=1 );

namespace KutybaIt\Automater\WC;

use KutybaIt\Automater\Automater\Proxy;
use WC_Product;

class Synchronizer {
	protected $integration;
	protected $proxy;

	public function __construct( Integration $integration ) {
		$this->integration = $integration;
		$this->proxy       = new Proxy( $integration->get_api_key(), $integration->get_api_secret() );
	}

	public static function maybe_create_product_attribute() {
		wc_get_logger()->notice( 'Automater.pl: maybe_create_product_attribute' );
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
			wc_get_logger()->notice( "Automater.pl: Create product attribute '$attribute_name'" );
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

	public function import_automater_products_to_wp_terms() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'import_automater_products_nonce' ) ) {
			exit( __( 'Cheatin&#8217; uh?' ) );
		}

		if ( ! $this->integration->api_enabled() ) {
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

		$products = $this->proxy->get_all_products();

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

			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: Importing product: ID ' . $product['id'] );
			}
			$ret = wp_insert_term( $product['name'], $taxonomy, $params );
			if ( $ret && ! is_wp_error( $ret ) ) {
				$imported ++;
			} else {
				wc_get_logger()->error( 'Automater.pl: Product was not imported because of error: ID ' . $product['id'] );
			}
		}

		foreach ( $to_delete as $delete ) {
			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: Deleting not existing product: ID ' . $delete );
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

	public function init_cron_job() {
		add_filter( 'cron_schedules', [ $this, 'add_cron_recurrence_interval' ] );
		add_action( 'update_stocks_job_action', function () {
			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: Running update stocks job (cron)' );
			}
			$this->update_stocks_job();
		} );
		$this->schedule_cron_job();
//		add_action( 'init', [ $this, 'schedule_cron_job' ] );
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
			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: Removing cron job' );
			}
			wp_unschedule_event( $timestamp, 'update_stocks_job_action' );
		}
	}

	protected function schedule_cron_job() {
		if ( ! $this->integration->get_enable_cron_job() ) {
			$this->unschedule_cron_job();

			return;
		}
		if ( ! wp_next_scheduled( 'update_stocks_job_action' ) ) {
			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: Creating new cron job' );
			}
			wp_schedule_event( time(), '5min', 'update_stocks_job_action' );
		}
	}

	public function update_products_stocks_with_automater_stocks() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'update_automater_stocks_nonce' ) ) {
			exit( __( 'Cheatin&#8217; uh?' ) );
		}

		if ( ! $this->integration->api_enabled() ) {
			exit( __( 'Please provide API configuration first.' ) );
		}

		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( 'Automater.pl: Running update stocks job (manual)' );
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

	public function update_stocks_job(): int {
		if ( ! $this->integration->api_enabled() ) {
			exit();
		}
		$updated  = 0;
		$products = wc_get_products( [ 'automater_product' => '' ] );
		foreach ( $products as $product ) {
			$automater_product_id = $this->integration->get_automater_product_id_for_wc_product( $product );
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

		$qty = $this->proxy->get_count_for_product( $automater_product_id );

		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( 'Automater.pl: Updating product stock: ID ' . $product->get_id() . ', quantity ' . $qty );
		}

		$product->set_stock_quantity( $qty );
		$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
		$product->save();
	}
}