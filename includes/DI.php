<?php

use DI\ContainerBuilder;

final class DI {
	/** @var \DI\Container */
	private $container;

	/** @var DI */
	private static $instance;

	private function __construct() {
		$this->buildContainer();
	}

	public static function getInstance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function getContainer() {
		return $this->container;
	}

	private function buildContainer() {
		$builder = new ContainerBuilder();
		$builder->addDefinitions( [
		] );

		$this->container = $builder->build();

		return $this->container;
	}
}
