<?php
declare( strict_types=1 );

namespace KutybaIt\Automater\Automater;

use Automater\Automater;

class Proxy {
	protected $api_key;
	protected $api_secret;

	public function __construct( $api_key, $api_secret ) {
		$this->api_key    = $api_key;
		$this->api_secret = $api_secret;
	}

	protected function get_instance(): Automater {
		return new Automater( $this->api_key, $this->api_secret );
	}

	public function get_count_for_product( $product_id ) {
		return $this->get_instance()->getAvailableProductsCount( $product_id );
	}

	public function create_transaction( $products, $email, $phone, $label ) {
		$listing_ids = array_keys( $products );
		$quantity    = array_values( $products );

		return $this->get_instance()->createTransaction( $listing_ids, $email, $quantity, $phone, substr( get_locale(), 0, 2 ), 1, $label );
	}

	public function create_payment( $cart_id, $payment_id, $amount ) {
		return $this->get_instance()->createPayment( 'cart', $cart_id, $payment_id, $amount, get_woocommerce_currency() );
	}

	public function get_all_products() {
		$data   = [];
		$result = $this->get_instance()->getProducts();
		if ( $result['code'] === 200 ) {
			$data  = $result['data'];
			$count = $result['count'];
			if ( $count > 50 ) {
				for ( $i = 1; $i * 50 < $count; $i ++ ) {
					$result = $this->get_instance()->getProducts( $i + 1 );
					if ( $result['code'] === 200 ) {
						$data = array_merge( $data, $result['data'] );
					}
				}
			}
		}

		return $data;
	}
}