<?php
/**
 * Plugin Name:       Woo Google Ads Server-Side Tracking
 * Plugin URI:        https://example.com
 * Description:       Envoi des conversions WooCommerce à l'API Google Ads en server-side, respectant le consentement.
 * Version:           1.6.1
 * Author:            SOYOO
 * Text Domain:       woo-gads-server-side
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

define('WOO_GADS_VERSION', '1.6.1');
define('WOO_GADS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_GADS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Load plugin translations with priority to Loco Translate custom directory
 * (wp-content/languages/loco/plugins/) before falling back to bundled /languages/.
 */
function woo_gads_load_textdomain()
{
	$domain = 'woo-gads-server-side';
	$locale = function_exists('determine_locale') ? determine_locale() : get_locale();
	$locale = apply_filters('plugin_locale', $locale, $domain);

	if (defined('WP_LANG_DIR')) {
		$loco_mofile = WP_LANG_DIR . '/loco/plugins/' . $domain . '-' . $locale . '.mo';
		if (file_exists($loco_mofile)) {
			load_textdomain($domain, $loco_mofile);
		}
	}

	load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'woo_gads_load_textdomain', 1);

// Initialize the Plugin Update Checker
require_once WOO_GADS_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/SOYOO974/woo-google-ads-tracking-server-side/',
	__FILE__,
	'woo-google-ads-tracking-server-side'
);

// Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

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
