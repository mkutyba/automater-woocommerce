<?php
declare( strict_types=1 );

namespace KutybaIt\Automater;

use KutybaIt\Automater\WC\Integration;
use KutybaIt\Automater\WC\Synchronizer;

class Activator {
	public static function activate() {
		Synchronizer::maybe_create_product_attribute();
	}

	public static function deactivate() {
		$integration = di( Integration::class );
		$integration->unschedule_cron_job();
	}
}
