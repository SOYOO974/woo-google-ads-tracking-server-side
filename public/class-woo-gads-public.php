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
        $accent_color = !empty($settings['banner_accent_color']) ? $settings['banner_accent_color'] : '#111827';
        $position = !empty($settings['banner_position']) ? $settings['banner_position'] : 'bottom-right';

        // Calcul du contraste pour le texte du bouton Accepter (noir ou blanc)
        $hex = ltrim($accent_color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        $accept_text_color = ($luminance > 0.58) ? '#0f172a' : '#ffffff';

        $banner_config = array(
            'enabled'           => $is_builtin,
            'message'           => esc_html($banner_message),
            'accept_text'       => esc_html($accept_text),
            'decline_text'      => esc_html($decline_text),
            'privacy_url'       => esc_url($privacy_url),
            'accent_color'      => esc_attr($accent_color),
            'accept_text_color' => esc_attr($accept_text_color),
            'position'          => esc_attr($position),
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
				// Par défaut (ni clic ni refus) : Google Consent Mode est verrouillé en DENIED (anonymisé)
				gtag('consent', 'default', {
					'ad_storage': 'denied',
					'ad_user_data': 'denied',
					'ad_personalization': 'denied',
					'analytics_storage': 'denied'
				});

				document.addEventListener('DOMContentLoaded', function() {
					if (document.getElementById('woo-gads-banner')) return;
					if (sessionStorage.getItem('woo_gads_banner_dismissed')) return;

					var posRules = '@media(min-width:640px){#woo-gads-banner{right:24px;bottom:24px;left:auto;}}';
					if (bannerConfig.position === 'bottom-left') {
						posRules = '@media(min-width:640px){#woo-gads-banner{left:24px;bottom:24px;right:auto;}}';
					} else if (bannerConfig.position === 'bottom-center') {
						posRules = '@media(min-width:640px){#woo-gads-banner{left:50%;bottom:24px;right:auto;transform:translateX(-50%);}}';
					}

					var style = document.createElement('style');
					style.innerHTML = '#woo-gads-banner{position:fixed;bottom:16px;left:16px;right:16px;max-width:440px;background:#ffffff!important;color:#1e293b!important;padding:18px 20px!important;border-radius:14px!important;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1),0 0 0 1px rgba(0,0,0,0.06)!important;border:1px solid #e2e8f0!important;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif!important;font-size:13px!important;line-height:1.5!important;z-index:99999999!important;box-sizing:border-box!important;animation:wooGadsSlideUp 0.3s cubic-bezier(0.16,1,0.3,1) forwards!important;}' +
						posRules +
						'@keyframes wooGadsSlideUp{from{opacity:0;margin-bottom:-10px;}to{opacity:1;margin-bottom:0;}}' +
						'#woo-gads-banner *{box-sizing:border-box!important;}' +
						'#woo-gads-banner .woo-gads-header{display:flex!important;align-items:center!important;justify-content:space-between!important;margin-bottom:10px!important;}' +
						'#woo-gads-banner .woo-gads-title-wrap{display:flex!important;align-items:center!important;gap:8px!important;}' +
						'#woo-gads-banner .woo-gads-icon{width:18px!important;height:18px!important;color:' + bannerConfig.accent_color + '!important;flex-shrink:0!important;}' +
						'#woo-gads-banner .woo-gads-title{font-size:14px!important;font-weight:700!important;color:#0f172a!important;letter-spacing:-0.01em!important;}' +
						'#woo-gads-btn-close{background:transparent!important;border:none!important;color:#94a3b8!important;font-size:18px!important;line-height:1!important;cursor:pointer!important;padding:2px 6px!important;margin:-4px -4px 0 0!important;border-radius:4px!important;transition:color 0.15s ease!important;}' +
						'#woo-gads-btn-close:hover{color:#334155!important;}' +
						'#woo-gads-banner p.woo-gads-text{margin:0 0 14px 0!important;color:#475569!important;font-size:13px!important;line-height:1.5!important;}' +
						'#woo-gads-banner a.woo-gads-privacy-link{color:' + bannerConfig.accent_color + '!important;text-decoration:underline!important;font-weight:500!important;margin-left:4px!important;}' +
						'#woo-gads-banner .woo-gads-buttons{display:flex!important;gap:10px!important;justify-content:flex-end!important;align-items:center!important;}' +
						'#woo-gads-banner button.woo-gads-btn{cursor:pointer!important;font-size:13px!important;font-weight:600!important;padding:8px 18px!important;border-radius:8px!important;transition:all 0.15s ease!important;text-transform:none!important;letter-spacing:0!important;height:auto!important;line-height:1.4!important;box-shadow:none!important;margin:0!important;}' +
						'#woo-gads-btn-decline{background:#ffffff!important;color:#475569!important;border:1px solid #cbd5e1!important;}' +
						'#woo-gads-btn-decline:hover{background:#f8fafc!important;color:#1e293b!important;border-color:#94a3b8!important;}' +
						'#woo-gads-btn-accept{background:' + bannerConfig.accent_color + '!important;color:' + bannerConfig.accept_text_color + '!important;border:1px solid ' + bannerConfig.accent_color + '!important;box-shadow:0 1px 3px rgba(0,0,0,0.1)!important;}' +
						'#woo-gads-btn-accept:hover{filter:brightness(1.12)!important;box-shadow:0 4px 10px rgba(0,0,0,0.15)!important;}';
					document.head.appendChild(style);

					var banner = document.createElement('div');
					banner.id = 'woo-gads-banner';

					var privacyHtml = bannerConfig.privacy_url ? ' <a href=\"' + bannerConfig.privacy_url + '\" target=\"_blank\" class=\"woo-gads-privacy-link\">En savoir plus</a>' : '';

					var cookieSvg = '<svg class=\"woo-gads-icon\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5\"></path><path d=\"M8.5 8.5v.01\"></path><path d=\"M7.5 15.5v.01\"></path><path d=\"M15.5 15.5v.01\"></path><path d=\"M11.5 12.5v.01\"></path></svg>';

					banner.innerHTML = '<div class=\"woo-gads-header\">' +
							'<div class=\"woo-gads-title-wrap\">' +
								cookieSvg +
								'<span class=\"woo-gads-title\">Gestion des cookies</span>' +
							'</div>' +
							'<button type=\"button\" id=\"woo-gads-btn-close\" title=\"Fermer\">&times;</button>' +
						'</div>' +
						'<p class=\"woo-gads-text\">' + bannerConfig.message + privacyHtml + '</p>' +
						'<div class=\"woo-gads-buttons\">' +
							'<button type=\"button\" id=\"woo-gads-btn-decline\" class=\"woo-gads-btn\">' + bannerConfig.decline_text + '</button>' +
							'<button type=\"button\" id=\"woo-gads-btn-accept\" class=\"woo-gads-btn\">' + bannerConfig.accept_text + '</button>' +
						'</div>';

					document.body.appendChild(banner);

					function closeBanner() {
						banner.style.opacity = '0';
						banner.style.transform = 'translateY(15px)';
						setTimeout(function() {
							if (banner.parentNode) banner.parentNode.removeChild(banner);
						}, 250);
					}

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

						closeBanner();
					}

					document.getElementById('woo-gads-btn-accept').addEventListener('click', function() {
						setConsent(true);
					});

					document.getElementById('woo-gads-btn-decline').addEventListener('click', function() {
						setConsent(false);
					});

					document.getElementById('woo-gads-btn-close').addEventListener('click', function() {
						sessionStorage.setItem('woo_gads_banner_dismissed', '1');
						closeBanner();
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
