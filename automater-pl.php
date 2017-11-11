<?php
/**
 * Plugin Name: Automater.pl
 * Plugin URI: https://automater.pl
 * Description: WooCommerce integration with Automater.pl
 * Version: 0.1.0
 * Author: kutyba.it
 * Author URI: https://kutyba.it
 * Requires at least: 4.8
 * Tested up to: 4.8
 *
 * Text Domain: automater-pl
 * Domain Path: /languages
 *
 * WC requires at least: 3.2
 * WC tested up to: 3.2
 *
 * Copyright: © 2017 Mateusz Kutyba.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'AUTOMATER_PLUGIN_FILE' ) ) {
	define( 'AUTOMATER_PLUGIN_FILE', __FILE__ );
}

require_once 'includes/autoload.php';
require_once 'lib/Automater-PHP-SDK/autoload.php';

use \KutybaIt\Automater\Automater;

function automater() {
	return Automater::instance();
}

automater();
