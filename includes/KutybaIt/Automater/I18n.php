<?php
declare( strict_types=1 );

namespace KutybaIt\Automater;

class I18n {
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'automater-pl', false, dirname( plugin_basename( AUTOMATER_PLUGIN_FILE ) ) . '/languages' );
	}
}
