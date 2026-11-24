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

    public function get_admin_capability()
    {
        return current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
    }

    private function current_user_can_manage()
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public function add_plugin_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Google Ads Server-Side', 'woo-gads-server-side'),
            __('Google Ads Server-Side', 'woo-gads-server-side'),
            $this->get_admin_capability(),
            $this->plugin_name,
            array($this, 'display_plugin_setup_page')
        );
    }

    public function register_settings()
    {
        register_setting('woo_gads_options', 'woo_gads_settings', array($this, 'sanitize_settings'));

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

    /**
     * Sanitize and normalize plugin settings while preserving backward compatibility
     * and programmatic update_option() calls (such as OAuth token callbacks).
     *
     * @param mixed $input
     * @return array
     */
    public function sanitize_settings($input)
    {
        if (!is_array($input)) {
            return array();
        }

        $is_form_submit = !empty($input['_from_settings_form']);
        unset($input['_from_settings_form']);

        if ($is_form_submit) {
            $checkbox_keys = array(
                'enable_builtin_banner',
                'enable_email_alerts',
                'enable_lead_conversion',
                'send_without_click_id',
                'validate_only',
            );
            foreach ($checkbox_keys as $cb_key) {
                $input[$cb_key] = !empty($input[$cb_key]) ? '1' : '0';
            }

            if (!isset($input['lead_payment_methods'])) {
                $input['lead_payment_methods'] = array();
            }
            if (empty($input['lead_order_statuses']) || !is_array($input['lead_order_statuses'])) {
                $input['lead_order_statuses'] = array('on-hold', 'pending');
            }
        }

        if (array_key_exists('lead_payment_methods', $input)) {
            $raw_methods = $input['lead_payment_methods'];
            if (is_string($raw_methods)) {
                $parts = preg_split('/[\s,;]+/', $raw_methods);
            } elseif (is_array($raw_methods)) {
                $parts = $raw_methods;
            } else {
                $parts = array();
            }
            $clean_methods = array();
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (is_scalar($part)) {
                        $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string) $part));
                        if ($slug !== '') {
                            $clean_methods[] = $slug;
                        }
                    }
                }
            }
            $input['lead_payment_methods'] = array_values(array_unique($clean_methods));
        }

        if (isset($input['lead_conversion_action_id'])) {
            $input['lead_conversion_action_id'] = sanitize_text_field(trim((string) $input['lead_conversion_action_id']));
        }

        if (isset($input['lead_value_ratio'])) {
            $ratio_raw = str_replace(',', '.', trim((string) $input['lead_value_ratio']));
            $input['lead_value_ratio'] = is_numeric($ratio_raw) ? (string) max(0.0, (float) $ratio_raw) : '0.25';
        }

        if (isset($input['lead_value_cap'])) {
            $cap_raw = str_replace(',', '.', trim((string) $input['lead_value_cap']));
            $input['lead_value_cap'] = is_numeric($cap_raw) ? (string) max(0.0, (float) $cap_raw) : '750';
        }

        if (isset($input['lead_order_statuses']) && is_array($input['lead_order_statuses'])) {
            $clean_statuses = array();
            foreach ($input['lead_order_statuses'] as $st) {
                if (is_scalar($st)) {
                    $st_clean = sanitize_key(trim((string) $st));
                    if (strpos($st_clean, 'wc-') === 0) {
                        $st_clean = substr($st_clean, 3);
                    }
                    if ($st_clean !== '') {
                        $clean_statuses[] = $st_clean;
                    }
                }
            }
            $input['lead_order_statuses'] = !empty($clean_statuses) ? array_values(array_unique($clean_statuses)) : array('on-hold', 'pending');
        }

        return $input;
    }

    public function display_plugin_setup_page()
    {
        require_once dirname(__FILE__) . '/partials/woo-gads-admin-display.php';
    }

    public function test_connection()
    {
        if (!$this->current_user_can_manage()) {
            wp_send_json_error(__('Permission denied.', 'woo-gads-server-side'));
        }

        $oauth = new Woo_Gads_Oauth();
        $token = $oauth->get_access_token(true);

        if ($token) {
            wp_send_json_success(__('Connection successful! Access token retrieved.', 'woo-gads-server-side'));
        } else {
            wp_send_json_error(__('Connection failed. Please check your OAuth credentials.', 'woo-gads-server-side'));
        }
    }

    public function add_action_links($links)
    {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=' . $this->plugin_name)) . '" style="font-weight:600;">' . esc_html__('Settings', 'woo-gads-server-side') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function retry_conversion()
    {
        check_ajax_referer('woo_gads_retry', '_ajax_nonce');

        if (!$this->current_user_can_manage()) {
            wp_send_json_error(__('Permission denied.', 'woo-gads-server-side'));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(__('Invalid order ID.', 'woo-gads-server-side'));
        }

        $conversion_type = isset($_POST['conversion_type']) && $_POST['conversion_type'] === 'lead' ? 'lead' : 'purchase';

        $order = wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order') || is_a($order, 'WC_Order_Refund')) {
            wp_send_json_error(__('Order not found or unsupported order type (refunds cannot be resent).', 'woo-gads-server-side'));
        }

        $api = new Woo_Gads_Api();

        if ($conversion_type === 'lead') {
            if (!Woo_Gads_Api::is_lead_order($order)) {
                wp_send_json_error(__('This order does not use a configured quote / lead payment method.', 'woo-gads-server-side'));
            }

            // Reset ONLY lead sent meta (never touch _gads_api_sent)
            $order->delete_meta_data('_gads_lead_sent');
            $order->save();
            delete_post_meta($order_id, '_gads_lead_sent');

            $result = $api->trigger_lead_conversion($order, true);

            $order = wc_get_order($order_id);
            $new_status = $order ? $order->get_meta('_gads_lead_status') : '';
            if (empty($new_status)) {
                $new_status = get_post_meta($order_id, '_gads_lead_status', true);
            }

            if (!empty($result['status']) && $result['status'] === 'lead_disabled') {
                wp_send_json_error(__('Quote / Lead conversion tracking is currently disabled in plugin settings.', 'woo-gads-server-side'));
            } elseif (strpos($new_status, 'Succès') !== false || strpos($new_status, 'Envoyé') !== false || strpos($new_status, 'Ignoré') !== false) {
                wp_send_json_success($new_status);
            } else {
                wp_send_json_error($new_status ?: __('Unknown status', 'woo-gads-server-side'));
            }
            return;
        }

        // Purchase retry: Lock A strictly blocks unpaid quote/lead orders even with $force = true
        if (Woo_Gads_Api::is_unpaid_lead_order($order)) {
            $api->trigger_conversion($order, true);
            $pm = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '';
            wp_send_json_error(sprintf(
                /* translators: 1: Order ID, 2: Payment method slug */
                __('Refused for Purchases: order #%1$d uses unpaid quote payment method "%2$s" (Status: Ignoré (devis, non payé)).', 'woo-gads-server-side'),
                $order_id,
                $pm
            ));
            return;
        }

        // Standard paid order: reset _gads_api_sent and trigger Purchase conversion
        $order->delete_meta_data('_gads_api_sent');
        $order->save();
        delete_post_meta($order_id, '_gads_api_sent');

        $api->trigger_conversion($order, true);

        // Reload order to fetch newly written meta
        $order = wc_get_order($order_id);
        $new_status = $order ? $order->get_meta('_gads_api_status') : '';
        if (empty($new_status)) {
            $new_status = get_post_meta($order_id, '_gads_api_status', true);
        }

        if (strpos($new_status, 'Succès') !== false || strpos($new_status, 'Envoyé') !== false) {
            wp_send_json_success($new_status);
        } elseif (strpos($new_status, 'Ignoré') !== false) {
            wp_send_json_success($new_status);
        } else {
            wp_send_json_error($new_status ?: __('Unknown status', 'woo-gads-server-side'));
        }
    }

    public function batch_rescue()
    {
        check_ajax_referer('woo_gads_batch_rescue', '_ajax_nonce');

        if (!$this->current_user_can_manage()) {
            wp_send_json_error(__('Permission denied.', 'woo-gads-server-side'));
        }

        $days = isset($_POST['days']) ? intval($_POST['days']) : 14;
        if ($days <= 0) {
            $days = 14;
        }
        $force_any_status = !empty($_POST['force_any_status']) && $_POST['force_any_status'] === '1';

        $api = new Woo_Gads_Api();
        $stats = $api->batch_rescue_orders($days, $force_any_status);

        wp_send_json_success($stats);
    }

    /**
     * Inspect site elements (widgets, menus, block templates, Elementor) to verify if the revoyure trigger is installed.
     *
     * @return array Array with 'detected' (bool) and 'source' (string|null).
     */
    public static function check_reopen_link_present()
    {
        // 1. Check classic and block widgets
        $widget_sources = array('widget_block', 'widget_text', 'widget_custom_html');
        foreach ($widget_sources as $option_key) {
            $option_val = get_option($option_key);
            if (is_array($option_val)) {
                $serialized = serialize($option_val);
                if (
                    strpos($serialized, 'woo_gads_cookie_settings') !== false ||
                    strpos($serialized, 'woo-gads-reopen-consent') !== false ||
                    strpos($serialized, '#woo-gads-cookies') !== false
                ) {
                    return array('detected' => true, 'source' => 'Widget de pied de page / Sidebar');
                }
            }
        }

        // 2. Check navigation menus
        $nav_menus = wp_get_nav_menus();
        if (!empty($nav_menus) && is_array($nav_menus)) {
            foreach ($nav_menus as $menu) {
                $items = wp_get_nav_menu_items($menu->term_id);
                if (!empty($items) && is_array($items)) {
                    foreach ($items as $item) {
                        $classes = is_array($item->classes) ? implode(' ', $item->classes) : (string)$item->classes;
                        $url = (string)$item->url;
                        $title = (string)$item->title;
                        if (
                            strpos($classes, 'woo-gads-reopen-consent') !== false ||
                            strpos($url, '#woo-gads-cookies') !== false ||
                            strpos($title, 'woo_gads_cookie_settings') !== false
                        ) {
                            return array('detected' => true, 'source' => 'Menu : ' . esc_html($menu->name));
                        }
                    }
                }
            }
        }

        // 3. Check Block / FSE Template Parts (WP 5.9+)
        if (function_exists('get_block_templates')) {
            $templates = get_block_templates(array(), 'wp_template_part');
            if (!empty($templates) && is_array($templates)) {
                foreach ($templates as $template) {
                    if (isset($template->content) && (strpos($template->slug, 'footer') !== false || strpos($template->theme, 'footer') !== false)) {
                        if (
                            strpos($template->content, 'woo_gads_cookie_settings') !== false ||
                            strpos($template->content, 'woo-gads-reopen-consent') !== false ||
                            strpos($template->content, '#woo-gads-cookies') !== false
                        ) {
                            return array('detected' => true, 'source' => 'Modèle FSE : ' . esc_html($template->slug));
                        }
                    }
                }
            }
        }

        // 4. Check Elementor templates (if installed)
        if (post_type_exists('elementor_library')) {
            global $wpdb;
            $found = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'elementor_library' AND (post_content LIKE '%woo_gads_cookie_settings%' OR post_content LIKE '%woo-gads-reopen-consent%' OR post_content LIKE '%#woo-gads-cookies%') AND post_status = 'publish' LIMIT 1");
            if ($found) {
                return array('detected' => true, 'source' => 'Modèle Elementor (ID #' . $found . ')');
            }
        }

        // 5. Check Woodmart theme settings (Theme Settings > Copyrights)
        $woodmart_options = get_option('xts-woodmart-options');
        if (is_array($woodmart_options)) {
            $copyright_fields = array('copyrights', 'copyrights2');
            foreach ($copyright_fields as $field) {
                if (!empty($woodmart_options[$field]) && is_string($woodmart_options[$field])) {
                    $content = $woodmart_options[$field];
                    if (
                        strpos($content, 'woo_gads_cookie_settings') !== false ||
                        strpos($content, 'woo-gads-reopen-consent') !== false ||
                        strpos($content, '#woo-gads-cookies') !== false
                    ) {
                        return array('detected' => true, 'source' => 'Pied de page Woodmart (Theme Settings > Copyrights)');
                    }
                }
            }
        }

        // 6. Check Woodmart HTML Blocks (cms_block post type)
        if (post_type_exists('cms_block')) {
            global $wpdb;
            $found_block = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'cms_block' AND (post_content LIKE '%woo_gads_cookie_settings%' OR post_content LIKE '%woo-gads-reopen-consent%' OR post_content LIKE '%#woo-gads-cookies%') AND post_status = 'publish' LIMIT 1");
            if ($found_block) {
                return array('detected' => true, 'source' => 'Bloc HTML Woodmart (ID #' . $found_block . ')');
            }
        }

        // 7. Check if Woodmart active filter injects the reopen link
        if (function_exists('woodmart_get_opt')) {
            if (class_exists('Woo_Gads_Public')) {
                Woo_Gads_Public::$in_scanner_check = true;
            }
            $copyrights_val = woodmart_get_opt('copyrights');
            if (class_exists('Woo_Gads_Public')) {
                Woo_Gads_Public::$in_scanner_check = false;
            }

            if (
                is_string($copyrights_val) && (
                    strpos($copyrights_val, 'woo_gads_cookie_settings') !== false ||
                    strpos($copyrights_val, 'woo-gads-reopen-consent') !== false ||
                    strpos($copyrights_val, '#woo-gads-cookies') !== false
                )
            ) {
                return array('detected' => true, 'source' => 'Pied de page Woodmart (Filtre actif)');
            }
        }

        return array('detected' => false, 'source' => null);
    }
}
