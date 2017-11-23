<?php

namespace KutybaIt\Automater;

use KutybaIt\Automater\WC\Synchronizer;

class Activator {
	public static function activate() {
	}

	public static function deactivate() {
		Synchronizer::unschedule_cron_job();
	}
}
