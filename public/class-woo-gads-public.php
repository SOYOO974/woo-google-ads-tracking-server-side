<?php

class Woo_Gads_Public
{

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function enqueue_scripts()
    {
        wp_register_script($this->plugin_name . '-capture', false);
        wp_enqueue_script($this->plugin_name . '-capture');

        $js = "
		document.addEventListener('DOMContentLoaded', function() {
			const urlParams = new URLSearchParams(window.location.search);
			const paramsToSave = ['gclid', 'wbraid', 'gbraid'];
			
			paramsToSave.forEach(function(param) {
				if(urlParams.has(param)) {
					const value = urlParams.get(param);
					const date = new Date();
					date.setTime(date.getTime() + (90*24*60*60*1000));
					document.cookie = 'woo_gads_' + param + '=' + value + '; expires=' + date.toUTCString() + '; path=/';
					localStorage.setItem('woo_gads_' + param, value);
				}
			});
		});
		";
        wp_add_inline_script($this->plugin_name . '-capture', $js);
    }

    public function save_click_ids($order_id, $data)
    {
        $paramsToSave = array('gclid', 'wbraid', 'gbraid');
        foreach ($paramsToSave as $param) {
            $cookie_name = 'woo_gads_' . $param;
            if (isset($_COOKIE[$cookie_name])) {
                $value = sanitize_text_field($_COOKIE[$cookie_name]);
                update_post_meta($order_id, '_woo_gads_' . $param, $value);
            }
        }

        // Save consent status
        $settings = get_option('woo_gads_settings');
        $consent_cookie = isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name']) ? $settings['consent_cookie_name'] : 'concord_consent';
        
        $cookie_value = null;
        if (isset($_COOKIE[$consent_cookie])) {
            $cookie_value = $_COOKIE[$consent_cookie];
        } else {
            // Fallback for prefix match if it's a concord-allow-state cookie
            if (strpos($consent_cookie, 'concord-allow-state-') === 0) {
                foreach ($_COOKIE as $key => $val) {
                    if (strpos($key, 'concord-allow-state-') === 0) {
                        $cookie_value = $val;
                        break;
                    }
                }
            }
        }

        if ($cookie_value !== null) {
            update_post_meta($order_id, '_woo_gads_consent', sanitize_text_field($cookie_value));
        } else {
            update_post_meta($order_id, '_woo_gads_consent', 'no_cookie_found');
        }
    }
}
