<?php
declare( strict_types=1 );

namespace KutybaIt\Automater\WC;

use Exception;
use KutybaIt\Automater\Automater\Proxy;
use WC_Order;
use WC_Order_Item_Product;

class OrderProcessor {
	protected $integration;
	protected $proxy;

	public function __construct( Integration $integration ) {
		$this->integration = $integration;
		$this->proxy       = new Proxy( $integration->get_api_key(), $integration->get_api_secret() );
	}

	public function order_placed( int $order_id ) {
		if ( ! $this->integration->api_enabled() ) {
			return;
		}

		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( "Automater.pl: Order has been placed: ID $order_id" );
		}

		$this->create_transaction( $order_id );
	}

	public function order_completed( int $order_id ) {
		if ( ! $this->integration->api_enabled() ) {
			return;
		}

		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( "Automater.pl: Payment has been received: ID $order_id" );
		}

		$this->pay_transaction( $order_id );
	}

	protected function create_transaction( int $order_id ) {
		$order = wc_get_order( $order_id );

		$items    = $order->get_items();
		$result   = [];
		$result[] = __( 'Automater.pl codes:', 'automater-pl' );

		$products = $this->validate_items( $items, $result );
		$this->validate_products_stock( $products, $result );
		$this->create_automater_transaction( $products, $order, $result );
		$this->add_order_note( $result, $order );
		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( 'Automater.pl: ' . implode( ' | ', $result ) );
		}
	}

	protected function validate_items( array $items, array &$result ): array {
		$products = [];
		/** @var WC_Order_Item_Product $item */
		foreach ( $items as $item ) {
			try {
				$automater_product_id = $this->integration->get_automater_product_id_for_wc_product( $item->get_product() );
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
				$codes_count = $this->proxy->get_count_for_product( $automater_product_id );
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

	protected function create_automater_transaction( array $products, WC_Order $order, array &$result ) {
		if ( count( $products ) ) {
			try {
				if ( $this->integration->get_debug_log() ) {
					wc_get_logger()->notice( 'Automater.pl: Creating automater transaction' );
					wc_get_logger()->notice( 'Automater.pl: ' . $order->get_billing_email() );
					wc_get_logger()->notice( 'Automater.pl: ' . $order->get_billing_phone() );
					wc_get_logger()->notice( 'Automater.pl: ' . sprintf( __( 'Order from %s, id: #%s', 'automater-pl' ), get_bloginfo( 'name' ), $order->get_order_number() ) );
				}
				$response = $this->proxy->create_transaction(
					$products, $order->get_billing_email(),
					$order->get_billing_phone(),
					sprintf( __( 'Order from %s, id: #%s', 'automater-pl' ), get_bloginfo( 'name' ), $order->get_order_number() )
				);
				if ( $this->integration->get_debug_log() ) {
					wc_get_logger()->notice( 'Automater.pl: ' . var_export( $response, true ) );
				}
				if ( $response['code'] === 200 ) {
					if ( $automater_cart_id = $response['cart_id'] ) {
						$order->update_meta_data( 'automater_cart_id', $automater_cart_id );
						$order->save();
						$result[] = sprintf( __( 'Created cart number: %s', 'automater-pl' ), $automater_cart_id );
					}
				}
			} catch ( Exception $e ) {
				if ( $this->integration->get_debug_log() ) {
					wc_get_logger()->notice( 'Automater.pl: ' . $e->getMessage() );
				}
				$result[] = $e->getMessage();
			}
		}
	}

	protected function add_order_note( array $status, WC_Order $order ) {
		if ( ! $order ) {
			return;
		}
		$order->add_order_note( implode( '<br>', $status ) );
	}

	protected function pay_transaction( int $order_id ) {
		$order             = wc_get_order( $order_id );
		$automater_cart_id = $order->get_meta( 'automater_cart_id' );

		if ( ! $automater_cart_id ) {
			return;
		}

		$result = [];
		try {
			$response = $this->proxy->create_payment( $automater_cart_id, $order->get_id(), $order->get_subtotal() );
			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: ' . var_export( $response, true ) );
			}
			if ( $response['code'] === 200 ) {
				$result[] = sprintf( __( 'Automater.pl - paid successfully: %s', 'automater-pl' ), $automater_cart_id );
			}
		} catch ( Exception $e ) {
			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: ' . $e->getMessage() );
			}
			$result[] = $e->getMessage();
			$this->add_order_note( $result, $order );

			return;
		}
		$this->add_order_note( $result, $order );
	}
}
