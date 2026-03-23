<?php

class Woo_Gads_Admin
{

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function add_plugin_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'Google Ads Server-Side',
            'Google Ads Server-Side',
            'manage_woocommerce',
            $this->plugin_name,
            array($this, 'display_plugin_setup_page')
        );
    }

    public function register_settings()
    {
        register_setting('woo_gads_options', 'woo_gads_settings');

        // Handle OAuth Callback
        if (isset($_GET['page']) && $_GET['page'] === $this->plugin_name && isset($_GET['code'])) {
            $code = sanitize_text_field($_GET['code']);
            $redirect_uri = admin_url('admin.php?page=' . $this->plugin_name);

            $oauth = new Woo_Gads_Oauth();
            $refresh_token = $oauth->exchange_code_for_token($code, $redirect_uri);

            if ($refresh_token) {
                $settings = get_option('woo_gads_settings', array());
                $settings['refresh_token'] = $refresh_token;
                update_option('woo_gads_settings', $settings);

                // Redirect to clean URL and show success
                wp_redirect(admin_url('admin.php?page=' . $this->plugin_name . '&oauth_success=1'));
                exit;
            } else {
                wp_redirect(admin_url('admin.php?page=' . $this->plugin_name . '&oauth_error=1'));
                exit;
            }
        }
    }

    public function display_plugin_setup_page()
    {
        require_once dirname(__FILE__) . '/partials/woo-gads-admin-display.php';
    }

    public function test_connection()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $oauth = new Woo_Gads_Oauth();
        $token = $oauth->get_access_token(true);

        if ($token) {
            wp_send_json_success('Connexion réussie ! Token récupéré.');
        } else {
            wp_send_json_error('Échec de la connexion. Vérifiez vos identifiants OAuth.');
        }
    }

    public function add_action_links($links)
    {
        $settings_link = array(
            '<a href="' . admin_url('admin.php?page=' . $this->plugin_name) . '">' . __('Réglages', 'woo-gads-server-side') . '</a>',
        );
        return array_merge($settings_link, $links);
    }

    public function retry_conversion()
    {
        check_ajax_referer('woo_gads_retry', '_ajax_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error('ID de commande invalide.');
        }

        // We reset the sent meta so it can be sent again
        delete_post_meta($order_id, '_gads_api_sent');
        
        $api = new Woo_Gads_Api();
        $api->trigger_conversion($order_id);

        $new_status = get_post_meta($order_id, '_gads_api_status', true);
        
        if ($new_status === 'Succès') {
            wp_send_json_success('Renvoi réussi');
        } else {
            wp_send_json_error($new_status);
        }
    }
}
