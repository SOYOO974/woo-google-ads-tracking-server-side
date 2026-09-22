<?php

class Woo_Gads
{

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct()
    {
        $this->plugin_name = 'woo-gads-server-side';
        $this->version = WOO_GADS_VERSION;

        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function load_dependencies()
    {
        require_once WOO_GADS_PLUGIN_DIR . 'includes/class-woo-gads-loader.php';
        require_once WOO_GADS_PLUGIN_DIR . 'includes/class-woo-gads-db.php';
        require_once WOO_GADS_PLUGIN_DIR . 'includes/api/class-woo-gads-oauth.php';
        require_once WOO_GADS_PLUGIN_DIR . 'includes/api/class-woo-gads-api.php';

        require_once WOO_GADS_PLUGIN_DIR . 'admin/class-woo-gads-admin.php';
        require_once WOO_GADS_PLUGIN_DIR . 'public/class-woo-gads-public.php';

        $this->loader = new Woo_Gads_Loader();
    }

    private function define_admin_hooks()
    {
        $plugin_admin = new Woo_Gads_Admin($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');
        $this->loader->add_action('admin_init', $plugin_admin, 'register_settings');
        $this->loader->add_action('wp_ajax_woo_gads_test_connection', $plugin_admin, 'test_connection');
        $this->loader->add_action('wp_ajax_woo_gads_retry_conversion', $plugin_admin, 'retry_conversion');
        $this->loader->add_action('wp_ajax_woo_gads_batch_rescue', $plugin_admin, 'batch_rescue');

        $plugin_basename = plugin_basename(WOO_GADS_PLUGIN_DIR . 'woo-gads-server-side.php');
        $this->loader->add_filter('plugin_action_links_' . $plugin_basename, $plugin_admin, 'add_action_links');
    }

    private function define_public_hooks()
    {
        $plugin_public = new Woo_Gads_Public($this->get_plugin_name(), $this->get_version());

        // Google Consent Mode v2 early initialization at Priority 1 in wp_head
        $this->loader->add_action('wp_head', $plugin_public, 'inject_consent_mode_default', 1);

        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');

        // Shortcode registration on init
        $this->loader->add_action('init', $plugin_public, 'register_shortcodes');

        // Capture click IDs and consent across all checkout modes (Classic Shortcode, Blocks Store API, Processed, New Order)
        $this->loader->add_action('woocommerce_checkout_update_order_meta', $plugin_public, 'save_click_ids', 10, 2);
        $this->loader->add_action('woocommerce_store_api_checkout_update_order_meta', $plugin_public, 'save_click_ids', 10, 1);
        $this->loader->add_action('woocommerce_checkout_order_processed', $plugin_public, 'save_click_ids', 10, 3);
        $this->loader->add_action('woocommerce_new_order', $plugin_public, 'save_click_ids', 10, 2);

        $plugin_api = new Woo_Gads_Api();
        // Hook into order status processing, completed, and on-hold
        $this->loader->add_action('woocommerce_order_status_on-hold', $plugin_api, 'trigger_conversion', 10, 1);
        $this->loader->add_action('woocommerce_order_status_processing', $plugin_api, 'trigger_conversion', 10, 1);
        $this->loader->add_action('woocommerce_order_status_completed', $plugin_api, 'trigger_conversion', 10, 1);

        // Safety nets: trigger on payment complete and any status changes
        $this->loader->add_action('woocommerce_payment_complete', $plugin_api, 'trigger_conversion', 10, 1);
        $this->loader->add_action('woocommerce_order_status_changed', $plugin_api, 'on_order_status_changed', 10, 4);
    }

    public function run()
    {
        $this->loader->run();
    }

    public function get_plugin_name()
    {
        return $this->plugin_name;
    }

    public function get_version()
    {
        return $this->version;
    }

    public function get_loader()
    {
        return $this->loader;
    }

}
