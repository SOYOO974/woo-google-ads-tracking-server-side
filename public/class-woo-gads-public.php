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

        $settings = get_option('woo_gads_settings');
        $is_builtin = !empty($settings['enable_builtin_banner']);

        $banner_message = !empty($settings['banner_message']) ? $settings['banner_message'] : "Nous utilisons des cookies pour assurer le bon fonctionnement du site, mesurer l'audience et personnaliser les publicités.";
        $accept_text = !empty($settings['banner_accept_text']) ? $settings['banner_accept_text'] : 'Accepter';
        $decline_text = !empty($settings['banner_decline_text']) ? $settings['banner_decline_text'] : 'Refuser';
        $privacy_url = !empty($settings['banner_privacy_url']) ? $settings['banner_privacy_url'] : '';

        $banner_config = array(
            'enabled'      => $is_builtin,
            'message'      => esc_html($banner_message),
            'accept_text'  => esc_html($accept_text),
            'decline_text' => esc_html($decline_text),
            'privacy_url'  => esc_url($privacy_url),
        );

        $js = "
		(function() {
			// 1. Capture des identifiants de clics Google Ads (gclid, wbraid, gbraid)
			var urlParams = new URLSearchParams(window.location.search);
			var paramsToSave = ['gclid', 'wbraid', 'gbraid'];
			
			paramsToSave.forEach(function(param) {
				if (urlParams.has(param)) {
					var value = urlParams.get(param);
					var date = new Date();
					date.setTime(date.getTime() + (90 * 24 * 60 * 60 * 1000));
					document.cookie = 'woo_gads_' + param + '=' + value + '; expires=' + date.toUTCString() + '; path=/; SameSite=Lax';
					try { localStorage.setItem('woo_gads_' + param, value); } catch(e) {}
				} else {
					// Restauration de sécurité depuis localStorage si le cookie a été purgé par le navigateur (ex: Safari ITP)
					var cookieMatch = document.cookie.match(new RegExp('(^|; )woo_gads_' + param + '=([^;]+)'));
					if (!cookieMatch) {
						try {
							var storedVal = localStorage.getItem('woo_gads_' + param);
							if (storedVal) {
								var date = new Date();
								date.setTime(date.getTime() + (90 * 24 * 60 * 60 * 1000));
								document.cookie = 'woo_gads_' + param + '=' + storedVal + '; expires=' + date.toUTCString() + '; path=/; SameSite=Lax';
							}
						} catch(e) {}
					}
				}
			});

			// 2. Gestion de la bannière native Google Consent Mode v2
			var bannerConfig = " . wp_json_encode($banner_config) . ";
			if (!bannerConfig.enabled) return;

			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}

			function getCookie(name) {
				var match = document.cookie.match(new RegExp('(^|; )' + name + '=([^;]+)'));
				return match ? decodeURIComponent(match[2]) : null;
			}

			var savedConsent = getCookie('woo_gads_consent');
			var consentData = null;

			if (savedConsent) {
				try {
					consentData = JSON.parse(savedConsent);
				} catch(e) {}
			}

			if (consentData) {
				var isGranted = consentData.marketing === true;
				var state = isGranted ? 'granted' : 'denied';
				gtag('consent', 'default', {
					'ad_storage': state,
					'ad_user_data': state,
					'ad_personalization': state,
					'analytics_storage': state
				});
				gtag('consent', 'update', {
					'ad_storage': state,
					'ad_user_data': state,
					'ad_personalization': state,
					'analytics_storage': state
				});
			} else {
				// Consent default denied jusqu'au choix explicite de l'utilisateur
				gtag('consent', 'default', {
					'ad_storage': 'denied',
					'ad_user_data': 'denied',
					'ad_personalization': 'denied',
					'analytics_storage': 'denied'
				});

				document.addEventListener('DOMContentLoaded', function() {
					if (document.getElementById('woo-gads-banner')) return;

					var style = document.createElement('style');
					style.innerHTML = '#woo-gads-banner{position:fixed;bottom:20px;left:20px;right:20px;max-width:440px;background:#ffffff;color:#1f2937;padding:18px 20px;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15),0 8px 10px -6px rgba(0,0,0,0.1);border:1px solid #e5e7eb;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;font-size:13px;line-height:1.5;z-index:9999999;box-sizing:border-box;transition:opacity 0.25s ease,transform 0.25s ease;}@media(min-width:640px){#woo-gads-banner{left:auto;right:24px;bottom:24px;}}#woo-gads-banner p{margin:0 0 14px 0;color:#374151;font-size:13px;line-height:1.5;}#woo-gads-banner a{color:#4b5563;text-decoration:underline;margin-left:4px;}#woo-gads-banner .woo-gads-buttons{display:flex;gap:10px;justify-content:flex-end;}#woo-gads-banner button{cursor:pointer;font-size:13px;font-weight:600;padding:8px 16px;border-radius:6px;transition:all 0.15s ease;border:1px solid transparent;outline:none;}#woo-gads-btn-accept{background:#111827;color:#ffffff;}#woo-gads-btn-accept:hover{background:#1f2937;}#woo-gads-btn-decline{background:#f3f4f6;color:#374151;border-color:#d1d5db!important;}#woo-gads-btn-decline:hover{background:#e5e7eb;}';
					document.head.appendChild(style);

					var banner = document.createElement('div');
					banner.id = 'woo-gads-banner';

					var privacyHtml = bannerConfig.privacy_url ? ' <a href=\"' + bannerConfig.privacy_url + '\" target=\"_blank\">En savoir plus</a>' : '';

					banner.innerHTML = '<p>' + bannerConfig.message + privacyHtml + '</p>' +
						'<div class=\"woo-gads-buttons\">' +
							'<button type=\"button\" id=\"woo-gads-btn-decline\">' + bannerConfig.decline_text + '</button>' +
							'<button type=\"button\" id=\"woo-gads-btn-accept\">' + bannerConfig.accept_text + '</button>' +
						'</div>';

					document.body.appendChild(banner);

					function setConsent(accepted) {
						var payload = {
							marketing: accepted,
							analytics: accepted,
							timestamp: new Date().toISOString()
						};
						var date = new Date();
						date.setTime(date.getTime() + (180 * 24 * 60 * 60 * 1000));
						document.cookie = 'woo_gads_consent=' + encodeURIComponent(JSON.stringify(payload)) + '; expires=' + date.toUTCString() + '; path=/; SameSite=Lax';

						var state = accepted ? 'granted' : 'denied';
						gtag('consent', 'update', {
							'ad_storage': state,
							'ad_user_data': state,
							'ad_personalization': state,
							'analytics_storage': state
						});

						banner.style.opacity = '0';
						banner.style.transform = 'translateY(15px)';
						setTimeout(function() {
							if (banner.parentNode) banner.parentNode.removeChild(banner);
						}, 250);
					}

					document.getElementById('woo-gads-btn-accept').addEventListener('click', function() {
						setConsent(true);
					});

					document.getElementById('woo-gads-btn-decline').addEventListener('click', function() {
						setConsent(false);
					});
				});
			}
		})();
		";
        wp_add_inline_script($this->plugin_name . '-capture', $js);
    }

    public function save_click_ids($order_id_or_order, $data = null)
    {
        $order = ($order_id_or_order instanceof WC_Order) ? $order_id_or_order : wc_get_order($order_id_or_order);
        if (!$order) {
            return;
        }

        $order_id = $order->get_id();

        // Protection anti-réentrance : éviter de réécrire plusieurs fois si plusieurs hooks s'exécutent successivement
        if ($order->get_meta('_woo_gads_ids_saved') === '1') {
            return;
        }

        $paramsToSave = array('gclid', 'wbraid', 'gbraid');
        foreach ($paramsToSave as $param) {
            $value = '';
            $cookie_name = 'woo_gads_' . $param;
            if (!empty($_COOKIE[$cookie_name])) {
                $value = sanitize_text_field($_COOKIE[$cookie_name]);
            } elseif (!empty($_COOKIE[$param])) {
                $value = sanitize_text_field($_COOKIE[$param]);
            } elseif (!empty($_COOKIE['wpgens_' . $param])) {
                $value = sanitize_text_field($_COOKIE['wpgens_' . $param]);
            } elseif (!empty($_GET[$param])) {
                $value = sanitize_text_field($_GET[$param]);
            }

            if (!empty($value)) {
                $order->update_meta_data('_woo_gads_' . $param, $value);
                update_post_meta($order_id, '_woo_gads_' . $param, $value);
            }
        }

        // Save consent status
        $settings = get_option('woo_gads_settings');
        $is_builtin = !empty($settings['enable_builtin_banner']);
        $consent_cookie = $is_builtin ? 'woo_gads_consent' : (isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name']) ? $settings['consent_cookie_name'] : 'concord_consent');
        
        $cookie_value = null;
        if (isset($_COOKIE[$consent_cookie]) && !empty($_COOKIE[$consent_cookie])) {
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
            $decoded_val = urldecode($cookie_value);
            $order->update_meta_data('_woo_gads_consent', sanitize_text_field($decoded_val));
            update_post_meta($order_id, '_woo_gads_consent', sanitize_text_field($decoded_val));
        } else {
            $order->update_meta_data('_woo_gads_consent', 'no_cookie_found');
            update_post_meta($order_id, '_woo_gads_consent', 'no_cookie_found');
        }

        $order->update_meta_data('_woo_gads_ids_saved', '1');
        $order->save();
    }
}
