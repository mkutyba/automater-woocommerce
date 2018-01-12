<?php

namespace KutybaIt\Automater\Automater;

use AutomaterSDK\Client\Client;
use AutomaterSDK\Exception\ApiException;
use AutomaterSDK\Exception\NotFoundException;
use AutomaterSDK\Exception\TooManyRequestsException;
use AutomaterSDK\Exception\UnauthorizedException;
use AutomaterSDK\Request\Entity\TransactionProduct;
use AutomaterSDK\Request\PaymentRequest;
use AutomaterSDK\Request\ProductsRequest;
use AutomaterSDK\Request\TransactionRequest;

class Proxy {
	protected $api_key;
	protected $api_secret;

	public function __construct( $api_key, $api_secret ) {
		$this->api_key    = $api_key;
		$this->api_secret = $api_secret;
	}

	protected function get_client() {
		return new Client( $this->api_key, $this->api_secret );
	}

	public function get_count_for_product( $product_id ) {
		try {
			$product = $this->get_client()->getProductDetails( $product_id );

			return $product->getAvailableCodes();
		} catch ( UnauthorizedException $exception ) {
			wc_get_logger()->error( 'Automater.pl: Invalid API key' );
		} catch ( TooManyRequestsException $exception ) {
			wc_get_logger()->error( 'Automater.pl: Too many requests to Automater: ' . $exception->getMessage() );
		} catch ( NotFoundException $exception ) {
			wc_get_logger()->error( 'Automater.pl: Not found - invalid params' );
		} catch ( ApiException $exception ) {
			wc_get_logger()->error( 'Automater.pl: ' . $exception->getMessage() );
		}

		return 0;
	}

	/**
	 * @return \AutomaterSDK\Response\TransactionResponse
	 * @throws ApiException
	 * @throws NotFoundException
	 * @throws TooManyRequestsException
	 * @throws UnauthorizedException
	 */
	public function create_transaction( $products, $email, $phone, $label ) {
		$transactionRequest = new TransactionRequest();
		switch ( wc_strtolower( substr( get_locale(), 0, 2 ) ) ) {
			case 'pl':
				$transactionRequest->setLanguage( TransactionRequest::LANGUAGE_PL );
				break;
			case 'en':
				$transactionRequest->setLanguage( TransactionRequest::LANGUAGE_EN );
				break;
			default:
				$transactionRequest->setLanguage( TransactionRequest::LANGUAGE_EN );
				break;
		}
		if ( $email ) {
			$transactionRequest->setEmail( $email );
			$transactionRequest->setSendStatusEmail( TransactionRequest::SEND_STATUS_EMAIL_TRUE );
		}
		$transactionRequest->setPhone( $phone );
		$transactionRequest->setCustom( $label );

		$transactionProducts = [];
		foreach ( $products as $product_id => $product ) {
			$transactionProduct = new TransactionProduct();
			$transactionProduct->setId( $product_id );
			$transactionProduct->setQuantity( $product['qty'] );
			$transactionProduct->setPrice( $product['price'] );
			$transactionProduct->setCurrency( $product['currency'] );
			$transactionProducts[] = $transactionProduct;
		}
		$transactionRequest->setProducts( $transactionProducts );

		return $this->get_client()->createTransaction( $transactionRequest );
	}

	/**
	 * @return \AutomaterSDK\Response\PaymentResponse
	 * @throws ApiException
	 * @throws NotFoundException
	 * @throws TooManyRequestsException
	 * @throws UnauthorizedException
	 */
	public function create_payment( $cart_id, $payment_id, $amount, $description ) {
		$paymentRequest = new PaymentRequest();
		$paymentRequest->setPaymentId( $payment_id );
		$paymentRequest->setCurrency( get_woocommerce_currency() );
		$paymentRequest->setAmount( $amount );
		$paymentRequest->setDescription( $description );

		return $this->get_client()->postPayment( $cart_id, $paymentRequest );
	}

	public function get_all_products() {
		$productsResponse = $this->get_products( 1 );
		$data             = $productsResponse->getData();

		for ( $page = 2; $page <= $productsResponse->getPagesCount(); $page ++ ) {
			$productsResponse = $this->get_products( $page );
			$data             = array_merge( $data, $productsResponse->getData() );
		}

		return $data;
	}

	protected function get_products( $page ) {
		$client = $this->get_client();

		$productRequest = new ProductsRequest();
		$productRequest->setType( ProductsRequest::TYPE_SHOP );
		$productRequest->setStatus( ProductsRequest::STATUS_ACTIVE );
		$productRequest->setPage( $page );
		$productRequest->setLimit( 100 );

		try {
			return $client->getProducts( $productRequest );
		} catch ( UnauthorizedException $exception ) {
			wc_get_logger()->error( 'Automater.pl: Invalid API key' );
		} catch ( TooManyRequestsException $exception ) {
			wc_get_logger()->error( 'Automater.pl: Too many requests to Automater: ' . $exception->getMessage() );
		} catch ( NotFoundException $exception ) {
			wc_get_logger()->error( 'Automater.pl: Not found - invalid params' );
		} catch ( ApiException $exception ) {
			wc_get_logger()->error( 'Automater.pl: ' . $exception->getMessage() );
		}

		return [];
	}
}