<?php
/**
 * Plugin Name: Automater.pl
 * Plugin URI: https://automater.pl
 * Description: WooCommerce integration with Automater.pl
 * Version: 0.1.3
 * Author: kutyba.it
 * Author URI: https://kutyba.it
 * Requires at least: 4.8
 * Tested up to: 4.9
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

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

__( 'WooCommerce integration with Automater.pl', 'automater-pl' ); // plugin description for poedit

if ( ! defined( 'AUTOMATER_PLUGIN_FILE' ) ) {
	define( 'AUTOMATER_PLUGIN_FILE', __FILE__ );
}

require_once 'includes/autoload.php';
require_once 'includes/DI.php';
require_once 'vendor/autoload.php';

use \KutybaIt\Automater\Automater;
use \KutybaIt\Automater\Activator;

function activate_automater_pl() {
	Activator::activate();
}

function deactivate_automater_pl() {
	Activator::deactivate();
}

register_activation_hook( __FILE__, 'activate_automater_pl' );
register_deactivation_hook( __FILE__, 'deactivate_automater_pl' );

function di( $name ) {
	return DI::getInstance()->getContainer()->get( $name );
}

function automater() {
	return Automater::get_instance();
}

automater();
