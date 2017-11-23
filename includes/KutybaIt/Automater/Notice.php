<?php

namespace KutybaIt\Automater;

class Notice {
	public static function render_error( $message ) {
		?>
        <div class="notice notice-error">
            <p><?php echo $message; ?></p>
        </div>
		<?php
	}

	public static function render_notice( $message ) {
		?>
        <div class="notice">
            <p><?php echo $message; ?></p>
        </div>
		<?php
	}
}