<?php
declare( strict_types=1 );

use DI\Container;
use DI\ContainerBuilder;

final class DI {
	/** @var \DI\Container */
	private $container;

	/** @var DI */
	private static $instance;

	private function __construct() {
		$this->buildContainer();
	}

	public static function getInstance(): DI {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function getContainer(): Container {
		return $this->container;
	}

	private function buildContainer(): Container {
		$builder = new ContainerBuilder();
		$builder->addDefinitions( [
		] );

		$this->container = $builder->build();

		return $this->container;
	}
}
