<?php

namespace KutybaIt\Automater\WC;

use AutomaterSDK\Exception\ApiException;
use AutomaterSDK\Exception\NotFoundException;
use AutomaterSDK\Exception\TooManyRequestsException;
use AutomaterSDK\Exception\UnauthorizedException;
use AutomaterSDK\Response\PaymentResponse;
use AutomaterSDK\Response\TransactionResponse;
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

	public function order_placed( $order_id ) {
		if ( ! $this->integration->api_enabled() ) {
			return;
		}

		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( "Automater.pl: Order has been placed: ID $order_id" );
		}

		$this->create_transaction( $order_id );
	}

	public function order_processing( $order_id ) {
		if ( ! $this->integration->api_enabled() ) {
			return;
		}

		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( "Automater.pl: Payment has been received: ID $order_id" );
		}

		$this->pay_transaction( $order_id );
	}

	protected function create_transaction( $order_id ) {
		$order = wc_get_order( $order_id );

		$items    = $order->get_items();
		$result   = [];
		$result[] = __( 'Automater.pl codes:', 'automater-pl' );

		$products = $this->transform_order_items( $items, $result );
		$this->create_automater_transaction( $products, $order, $result );
		$this->add_order_note( $result, $order );
		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( 'Automater.pl: ' . implode( ' | ', $result ) );
		}
	}

	protected function transform_order_items( array $items, array &$result ) {
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
				if ( $qty <= 0 || is_nan( $qty ) ) {
					$result[] = sprintf( __( 'Invalid quantity of product: %s [%s]', 'automater-pl' ), $item->get_name(), $item->get_id() );
					continue;
				}
				if ( ! isset( $products[ $automater_product_id ] ) ) {
					$products[ $automater_product_id ]['qty']      = 0;
					$products[ $automater_product_id ]['price']    = $item->get_product()->get_price();
					$products[ $automater_product_id ]['currency'] = get_woocommerce_currency();
				}
				$products[ $automater_product_id ]['qty'] += $qty;
			} catch ( Exception $e ) {
				$result[] = $e->getMessage() . sprintf( ': %s [%s]', $item->get_name(), $item->get_id() );
			}
		}

		return $products;
	}

	protected function create_automater_transaction( array $products, WC_Order $order, array &$result ) {
		if ( count( $products ) ) {
			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: Creating automater transaction' );
				wc_get_logger()->notice( 'Automater.pl: ' . $order->get_billing_email() );
				wc_get_logger()->notice( 'Automater.pl: ' . $order->get_billing_phone() );
				wc_get_logger()->notice( 'Automater.pl: ' . sprintf( __( 'Order from %s, id: #%s', 'automater-pl' ), get_bloginfo( 'name' ), $order->get_order_number() ) );
			}

			$email = $order->get_billing_email();
			$phone = $order->get_billing_phone();
			$label = sprintf( __( 'Order from %s, id: #%s', 'automater-pl' ), get_bloginfo( 'name' ), $order->get_order_number() );

			try {
				/** @var TransactionResponse $response */
				$response = $this->proxy->create_transaction( $products, $email, $phone, $label );
				if ( $this->integration->get_debug_log() ) {
					wc_get_logger()->notice( 'Automater.pl: ' . var_export( $response, true ) );
				}
				if ( $response && $automater_cart_id = $response->getCartId() ) {
					$order->update_meta_data( 'automater_cart_id', $automater_cart_id );
					$order->save();
					$result[] = sprintf( __( 'Created cart number: %s', 'automater-pl' ), $automater_cart_id );
				}
			} catch ( UnauthorizedException $exception ) {
				$this->handle_exception( $result, 'Invalid API key' );
			} catch ( TooManyRequestsException $e ) {
				$this->handle_exception( $result, 'Too many requests to Automater: ' . $e->getMessage() );
			} catch ( NotFoundException $e ) {
				$this->handle_exception( $result, 'Not found - invalid params' );
			} catch ( ApiException $e ) {
				$this->handle_exception( $result, $e->getMessage() );
			}
		}
	}

	protected function add_order_note( array $status, WC_Order $order ) {
		if ( ! $order ) {
			return;
		}
		$order->add_order_note( implode( '<br>', $status ) );
	}

	protected function pay_transaction( $order_id ) {
		$order             = wc_get_order( $order_id );
		$automater_cart_id = $order->get_meta( 'automater_cart_id' );

		if ( ! $automater_cart_id ) {
			return;
		}

		$result   = [];
		$result[] = __( 'Automater.pl codes:', 'automater-pl' );

		$this->create_automater_payment( $order, $automater_cart_id, $result );
		$this->add_order_note( $result, $order );
		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( 'Automater.pl: ' . implode( ' | ', $result ) );
		}
	}

	protected function create_automater_payment( WC_Order $order, $automater_cart_id, &$result ) {
		$payment_id  = $order->get_id();
		$amount      = $order->get_subtotal();
		$description = $order->get_payment_method();

		try {
			/** @var PaymentResponse $response */
			$response = $this->proxy->create_payment( $automater_cart_id, $payment_id, $amount, $description );
			if ( $this->integration->get_debug_log() ) {
				wc_get_logger()->notice( 'Automater.pl: ' . var_export( $response, true ) );
			}
			if ( $response ) {
				$result[] = sprintf( __( 'Automater.pl - paid successfully: %s', 'automater-pl' ), $automater_cart_id );
			}
		} catch ( UnauthorizedException $exception ) {
			$this->handle_exception( $result, 'Invalid API key' );
		} catch ( TooManyRequestsException $e ) {
			$this->handle_exception( $result, 'Too many requests to Automater: ' . $e->getMessage() );
		} catch ( NotFoundException $e ) {
			$this->handle_exception( $result, 'Not found - invalid params' );
		} catch ( ApiException $e ) {
			$this->handle_exception( $result, $e->getMessage() );
		}
	}

	protected function handle_exception( array &$result, $exception_message ) {
		if ( $this->integration->get_debug_log() ) {
			wc_get_logger()->notice( 'Automater.pl: ' . $exception_message );
		}
		$result[] = 'Automater.pl: ' . $exception_message;
	}
}
