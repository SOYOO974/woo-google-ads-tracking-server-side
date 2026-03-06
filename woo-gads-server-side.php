<?php
/**
 * Plugin Name:       Woo Google Ads Server-Side Tracking
 * Plugin URI:        https://example.com
 * Description:       Envoi des conversions WooCommerce à l'API Google Ads en server-side, respectant le consentement.
 * Version:           1.0.0
 * Author:            SOYOO
 * Text Domain:       woo-gads-server-side
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

define('WOO_GADS_VERSION', '1.0.0');
define('WOO_GADS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_GADS_PLUGIN_URL', plugin_dir_url(__FILE__));

require plugin_dir_path(__FILE__) . 'includes/class-woo-gads-activator.php';
require plugin_dir_path(__FILE__) . 'includes/class-woo-gads-deactivator.php';

function activate_woo_gads()
{
	Woo_Gads_Activator::activate();
}

function deactivate_woo_gads()
{
	Woo_Gads_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_woo_gads');
register_deactivation_hook(__FILE__, 'deactivate_woo_gads');

require plugin_dir_path(__FILE__) . 'includes/class-woo-gads.php';

function run_woo_gads()
{
	$plugin = new Woo_Gads();
	$plugin->run();
}
run_woo_gads();
