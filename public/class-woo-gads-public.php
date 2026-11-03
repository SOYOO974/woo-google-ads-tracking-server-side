<?php

class Woo_Gads_Public
{

    private $plugin_name;
    private $version;

    /**
     * Flag to allow filter evaluation during admin scanner checks.
     *
     * @var bool
     */
    public static $in_scanner_check = false;

    /**
     * Prevent duplicate rendering of hidden checkout inputs.
     *
     * @var bool
     */
    private static $checkout_fields_rendered = false;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Extract a clean Google Ads Click ID from a Google Conversion Linker cookie (_gcl_aw, _gcl_dc, _gcl_gb).
     * Expected format: GCL.<timestamp>.<gclid> (3rd segment after GCL.xxx.).
     * Also handles GCL.<version>.<timestamp>.<gclid> or raw click IDs if present.
     *
     * @param string $raw_cookie Raw cookie value.
     * @return string Clean click ID or empty string.
     */
    public static function extract_click_id_from_gcl_cookie($raw_cookie)
    {
        if (empty($raw_cookie) || !is_string($raw_cookie)) {
            return '';
        }

        $clean = trim(urldecode($raw_cookie));
        if ($clean === '') {
            return '';
        }

        if (stripos($clean, 'GCL.') === 0) {
            $all_parts = explode('.', $clean);
            if (count($all_parts) >= 3) {
                if (
                    count($all_parts) >= 4 &&
                    strlen($all_parts[1]) <= 2 &&
                    ctype_digit($all_parts[1]) &&
                    strlen($all_parts[2]) >= 9 &&
                    ctype_digit($all_parts[2])
                ) {
                    $extracted = implode('.', array_slice($all_parts, 3));
                } else {
                    $extracted = implode('.', array_slice($all_parts, 2));
                }
                $extracted = trim($extracted);
                return $extracted !== '' ? sanitize_text_field($extracted) : '';
            }
            return '';
        }

        // If it's already a raw click ID (no GCL. prefix and no spaces/JSON)
        if (strlen($clean) > 8 && strpos($clean, '{') !== 0 && strpos($clean, ' ') === false) {
            return sanitize_text_field($clean);
        }

        return '';
    }

    /**
     * Return configured attribution retention window in days (default: 90 days).
     *
     * @return int
     */
    public static function get_click_window_days()
    {
        $settings = get_option('woo_gads_settings', array());
        return isset($settings['click_window_days']) ? max(1, min(90, (int) $settings['click_window_days'])) : 90;
    }

    /**
     * Resolve click IDs (gclid, wbraid, gbraid) from all available request sources:
     * $_GET, $_POST / checkout AJAX payload, first-party cookies, _gcl_aw / _gcl_gb cookies, and WC()->session.
     *
     * @param array $posted_data Optional parsed POST data (e.g. from update_order_review).
     * @return array Associative array of non-empty click IDs ['gclid' => ..., 'wbraid' => ..., 'gbraid' => ...].
     */
    public static function resolve_current_click_ids($posted_data = array())
    {
        $params = array('gclid', 'wbraid', 'gbraid');
        $resolved = array();

        foreach ($params as $param) {
            $value = '';

            // 1. Direct URL query parameter ($_GET['gclid'], $_GET['wbraid'], $_GET['gbraid'])
            if (!empty($_GET[$param]) && is_string($_GET[$param])) {
                $value = sanitize_text_field(wp_unslash($_GET[$param]));
            }

            // 2. Posted checkout fields (AJAX checkout or update_order_review)
            if ($value === '' && is_array($posted_data)) {
                if (!empty($posted_data['woo_gads_' . $param]) && is_string($posted_data['woo_gads_' . $param])) {
                    $value = sanitize_text_field(wp_unslash($posted_data['woo_gads_' . $param]));
                } elseif (!empty($posted_data['_' . $param]) && is_string($posted_data['_' . $param])) {
                    $value = sanitize_text_field(wp_unslash($posted_data['_' . $param]));
                } elseif (!empty($posted_data[$param]) && is_string($posted_data[$param])) {
                    $value = sanitize_text_field(wp_unslash($posted_data[$param]));
                }
            }
            if ($value === '') {
                if (!empty($_POST['woo_gads_' . $param]) && is_string($_POST['woo_gads_' . $param])) {
                    $value = sanitize_text_field(wp_unslash($_POST['woo_gads_' . $param]));
                } elseif (!empty($_POST['_' . $param]) && is_string($_POST['_' . $param])) {
                    $value = sanitize_text_field(wp_unslash($_POST['_' . $param]));
                } elseif (!empty($_POST[$param]) && is_string($_POST[$param])) {
                    $value = sanitize_text_field(wp_unslash($_POST[$param]));
                }
            }

            // 3. Plugin first-party cookie (woo_gads_gclid, etc.)
            if ($value === '' && !empty($_COOKIE['woo_gads_' . $param]) && is_string($_COOKIE['woo_gads_' . $param])) {
                $value = self::extract_click_id_from_gcl_cookie(wp_unslash($_COOKIE['woo_gads_' . $param]));
                if ($value === '') {
                    $value = sanitize_text_field(wp_unslash($_COOKIE['woo_gads_' . $param]));
                }
            }

            // 4. Google Conversion Linker cookies (_gcl_aw / _gcl_dc for gclid, _gcl_gb for gbraid/wbraid)
            if ($value === '' && $param === 'gclid') {
                if (!empty($_COOKIE['_gcl_aw'])) {
                    $value = self::extract_click_id_from_gcl_cookie(wp_unslash($_COOKIE['_gcl_aw']));
                }
                if ($value === '' && !empty($_COOKIE['_gcl_dc'])) {
                    $value = self::extract_click_id_from_gcl_cookie(wp_unslash($_COOKIE['_gcl_dc']));
                }
            }
            if ($value === '' && $param === 'gbraid' && !empty($_COOKIE['_gcl_gb'])) {
                $value = self::extract_click_id_from_gcl_cookie(wp_unslash($_COOKIE['_gcl_gb']));
            }

            // 5. Generic / third-party cookies (_gclid, gclid, wpgens_gclid)
            if ($value === '') {
                $fallback_cookies = array('_' . $param, $param, 'wpgens_' . $param, '_wpgens_' . $param);
                foreach ($fallback_cookies as $ck) {
                    if (!empty($_COOKIE[$ck]) && is_string($_COOKIE[$ck])) {
                        $extracted = self::extract_click_id_from_gcl_cookie(wp_unslash($_COOKIE[$ck]));
                        $value = $extracted !== '' ? $extracted : sanitize_text_field(wp_unslash($_COOKIE[$ck]));
                        if ($value !== '') {
                            break;
                        }
                    }
                }
            }

            // 6. WooCommerce Session fallback
            if ($value === '' && function_exists('WC') && isset(WC()->session) && is_object(WC()->session)) {
                $sess_val = WC()->session->get('woo_gads_' . $param);
                if (empty($sess_val)) {
                    $sess_val = WC()->session->get('_' . $param);
                }
                if (empty($sess_val)) {
                    $sess_val = WC()->session->get($param);
                }
                if (!empty($sess_val) && is_string($sess_val)) {
                    $value = sanitize_text_field($sess_val);
                }
            }

            $value = trim($value);
            if ($value !== '') {
                $resolved[$param] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Server-side capture of click IDs on `init` / `woocommerce_init`.
     * Extracts GCLID from $_GET['gclid'] and $_COOKIE['_gcl_aw'] (plus wbraid/gbraid),
     * stores them in a 90-day first-party cookie and in WC()->session.
     */
    public function capture_request_click_ids()
    {
        if (class_exists('Woo_Gads_Db')) {
            Woo_Gads_Db::maybe_upgrade();
        }

        $resolved = self::resolve_current_click_ids();
        if (empty($resolved)) {
            return;
        }

        $days = self::get_click_window_days();
        $expire = time() + ($days * DAY_IN_SECONDS);
        $has_url_click_id = (!empty($_GET['gclid']) || !empty($_GET['wbraid']) || !empty($_GET['gbraid']));
        $is_ajax_or_checkout = (
            (defined('DOING_AJAX') && DOING_AJAX) ||
            !empty($_GET['wc-ajax']) ||
            (function_exists('is_checkout') && is_checkout()) ||
            (function_exists('is_cart') && is_cart())
        );

        foreach ($resolved as $param => $value) {
            $cookie_name = 'woo_gads_' . $param;
            $short_cookie = '_' . $param;

            // Populate $_COOKIE superglobal immediately for the current request
            $_COOKIE[$cookie_name] = $value;
            if (empty($_COOKIE[$short_cookie])) {
                $_COOKIE[$short_cookie] = $value;
            }

            // Set long-lived (90d) first-party cookie when landing with URL parameter or during checkout/AJAX when cookie was only in _gcl_aw
            if (!headers_sent() && ($has_url_click_id || ($is_ajax_or_checkout && empty($_COOKIE[$cookie_name])))) {
                if (PHP_VERSION_ID >= 70300) {
                    setcookie($cookie_name, $value, array(
                        'expires'  => $expire,
                        'path'     => '/',
                        'secure'   => is_ssl(),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ));
                } else {
                    setcookie($cookie_name, $value, $expire, '/; SameSite=Lax', '', is_ssl(), false);
                }
            }

            // Persist in WooCommerce Session
            if (function_exists('WC') && isset(WC()->session) && is_object(WC()->session)) {
                if (!WC()->session->has_session() && ($has_url_click_id || $is_ajax_or_checkout)) {
                    WC()->session->set_customer_session_cookie(true);
                }
                if (WC()->session->has_session() || $has_url_click_id || $is_ajax_or_checkout) {
                    WC()->session->set('woo_gads_' . $param, $value);
                    WC()->session->set('_' . $param, $value);
                }
            }
        }
    }

    /**
     * Render hidden inputs inside WooCommerce checkout form so click IDs in cookies/localStorage/_gcl_aw
     * are transmitted even if cookies are stripped during AJAX checkout POST.
     */
    public function render_checkout_hidden_fields()
    {
        if (self::$checkout_fields_rendered) {
            return;
        }
        self::$checkout_fields_rendered = true;

        $resolved = self::resolve_current_click_ids();
        foreach (array('gclid', 'wbraid', 'gbraid') as $param) {
            $val = isset($resolved[$param]) ? $resolved[$param] : '';
            echo '<input type="hidden" name="woo_gads_' . esc_attr($param) . '" id="woo_gads_field_' . esc_attr($param) . '" value="' . esc_attr($val) . '" />';
        }
    }

    /**
     * Capture click IDs during WooCommerce AJAX checkout updates (?wc-ajax=update_order_review)
     * and persist them to WC()->session and any draft/awaiting order.
     *
     * @param string $post_data URL-encoded form data from checkout.
     */
    public function on_checkout_update_order_review($post_data)
    {
        $parsed = array();
        if (!empty($post_data) && is_string($post_data)) {
            parse_str($post_data, $parsed);
        }

        $resolved = self::resolve_current_click_ids($parsed);
        if (empty($resolved)) {
            return;
        }

        if (function_exists('WC') && isset(WC()->session) && is_object(WC()->session)) {
            foreach ($resolved as $param => $value) {
                WC()->session->set('woo_gads_' . $param, $value);
                WC()->session->set('_' . $param, $value);
            }

            // If an order is already awaiting payment or exists as a Store API draft in session, enrich it immediately
            $draft_order_id = (int) WC()->session->get('order_awaiting_payment', 0);
            if ($draft_order_id <= 0) {
                $draft_order_id = (int) WC()->session->get('store_api_draft_order', 0);
            }
            if ($draft_order_id > 0) {
                $this->save_click_ids($draft_order_id, $parsed);
            }
        }
    }

    /**
     * Inject Google Consent Mode v2 default initialization at Priority 1 in wp_head.
     * Ensures gtag('consent', 'default', ...) executes synchronously before any Google tags/GTM load.
     */
    public function inject_consent_mode_default()
    {
        $settings = get_option('woo_gads_settings');
        $is_builtin = !empty($settings['enable_builtin_banner']);
        $external_cookie = (isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name'])) ? $settings['consent_cookie_name'] : 'concord_consent';
        ?>
<!-- Google Consent Mode v2 (Woo Gads Server-Side) -->
<script data-cfasync="false" type="text/javascript">
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}

(function() {
	var isBuiltin = <?php echo $is_builtin ? 'true' : 'false'; ?>;
	var externalCookie = <?php echo wp_json_encode($external_cookie); ?>;
	var isGranted = false;

	function readCookie(name) {
		var m = document.cookie.match(new RegExp('(^|; )' + name + '=([^;]+)'));
		return m ? decodeURIComponent(m[2]) : null;
	}

	if (isBuiltin) {
		var raw = readCookie('woo_gads_consent');
		if (raw) {
			try {
				var parsed = JSON.parse(raw);
				if (parsed && parsed.marketing === true) {
					isGranted = true;
				}
			} catch(e) {}
		}
	} else {
		var extVal = readCookie(externalCookie);
		if (!extVal && externalCookie && externalCookie.indexOf('concord-allow-state-') === 0) {
			var cookies = document.cookie.split('; ');
			for (var i = 0; i < cookies.length; i++) {
				var parts = cookies[i].split('=');
				if (parts[0].indexOf('concord-allow-state-') === 0) {
					extVal = decodeURIComponent(parts[1]);
					break;
				}
			}
		}
		if (extVal) {
			if (extVal === 'denied' || extVal === 'false' || extVal === '0') {
				isGranted = false;
			} else {
				try {
					var parsedExt = JSON.parse(extVal);
					if (parsedExt && (parsedExt.marketing === false || parsedExt.advertising === false)) {
						isGranted = false;
					} else {
						isGranted = true;
					}
				} catch(e) {
					isGranted = true;
				}
			}
		}
	}

	var consentState = isGranted ? 'granted' : 'denied';
	gtag('consent', 'default', {
		'ad_storage': consentState,
		'ad_user_data': consentState,
		'ad_personalization': consentState,
		'analytics_storage': consentState
	});
})();
</script>
<!-- End Google Consent Mode v2 -->
<?php
    }

    /**
     * Register [woo_gads_cookie_settings] shortcode for footer / page reopening of consent banner.
     */
    public function register_shortcodes()
    {
        add_shortcode('woo_gads_cookie_settings', array($this, 'render_cookie_settings_shortcode'));
    }

    /**
     * Render the accessible link/button for reopening cookie consent.
     */
    public function render_cookie_settings_shortcode($atts = array())
    {
        $atts = shortcode_atts(array(
            'text'  => __('Gestion des cookies', 'woo-gads-server-side'),
            'class' => '',
            'tag'   => 'a',
        ), $atts, 'woo_gads_cookie_settings');

        $tag = in_array(strtolower($atts['tag']), array('a', 'button', 'span'), true) ? strtolower($atts['tag']) : 'a';
        $extra_classes = !empty($atts['class']) ? ' ' . esc_attr($atts['class']) : '';
        $href = ($tag === 'a') ? ' href="#woo-gads-cookies"' : '';
        $role = ($tag === 'a') ? ' role="button"' : '';

        return sprintf(
            '<%1$s%2$s%3$s class="woo-gads-reopen-consent%4$s" data-woo-gads-reopen="1" style="cursor: pointer;">%5$s</%1$s>',
            $tag,
            $href,
            $role,
            $extra_classes,
            esc_html($atts['text'])
        );
    }

    /**
     * Filter Woodmart theme copyright option to automatically inject the CNIL consent reopening link if missing.
     *
     * @param mixed  $opt  Option value.
     * @param string $slug Option slug.
     * @return mixed
     */
    public function filter_woodmart_copyrights($opt, $slug)
    {
        if ($slug === 'copyrights' && !empty($opt) && (!is_admin() || self::$in_scanner_check)) {
            if (
                strpos($opt, 'woo_gads_cookie_settings') === false &&
                strpos($opt, 'woo-gads-reopen-consent') === false &&
                strpos($opt, '#woo-gads-cookies') === false
            ) {
                $opt .= ' <span class="confo-footer-sep" style="margin: 0 8px; opacity: 0.5;">•</span> [woo_gads_cookie_settings text="' . esc_attr__('Gérer mes cookies', 'woo-gads-server-side') . '"]';
            }
        }

        return $opt;
    }

    /**
     * Print CSS styles for the consent reopening trigger.
     */
    public function print_reopen_consent_styles()
    {
        ?>
<style id="woo-gads-reopen-consent-css">
a.woo-gads-reopen-consent { cursor: pointer; text-decoration: underline; text-underline-offset: 3px; transition: color 0.2s ease, opacity 0.2s ease; }
.wd-copyrights a.woo-gads-reopen-consent { color: inherit; }
.wd-copyrights a.woo-gads-reopen-consent:hover { color: #DB3832; opacity: 1; }
</style>
<?php
    }

    public function enqueue_scripts()
    {
        wp_register_script($this->plugin_name . '-capture', false);
        wp_enqueue_script($this->plugin_name . '-capture');

        $settings = get_option('woo_gads_settings');
        $click_window_days = self::get_click_window_days();
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
            'click_window_days' => (int) $click_window_days,
        );

        $js = "
		(function() {
			// 1. Capture et gestion anti-zombie des identifiants de clics Google Ads (gclid, _gcl_aw, wbraid, gbraid)
			var clickWindowDays = " . (int) $click_window_days . ";
			var maxAgeMs = clickWindowDays * 24 * 60 * 60 * 1000;
			var urlParams = new URLSearchParams(window.location.search);
			var paramsToSave = ['gclid', 'wbraid', 'gbraid'];

			function extractFromGclCookie(cookieName) {
				var match = document.cookie.match(new RegExp('(^|; )' + cookieName + '=([^;]+)'));
				if (!match) return null;
				var raw = decodeURIComponent(match[2]).trim();
				if (raw.toUpperCase().indexOf('GCL.') === 0) {
					var parts = raw.split('.');
					if (parts.length >= 3) {
						var extracted = (parts.length >= 4 && parts[1].length <= 2 && /^\\d+$/.test(parts[1]) && parts[2].length >= 9 && /^\\d+$/.test(parts[2]))
							? parts.slice(3).join('.').trim()
							: parts.slice(2).join('.').trim();
						return extracted || null;
					}
				}
				return null;
			}

			paramsToSave.forEach(function(param) {
				var incomingVal = urlParams.has(param) ? urlParams.get(param) : null;
				if (!incomingVal && param === 'gclid') {
					incomingVal = extractFromGclCookie('_gcl_aw') || extractFromGclCookie('_gcl_dc');
				} else if (!incomingVal && param === 'gbraid') {
					incomingVal = extractFromGclCookie('_gcl_gb');
				}

				if (incomingVal) {
					// Clic publicitaire détecté dans l'URL ou dans le cookie Google Conversion Linker (_gcl_aw / _gcl_gb)
					var now = Date.now();
					var expireDate = new Date(now + maxAgeMs);
					document.cookie = 'woo_gads_' + param + '=' + incomingVal + '; expires=' + expireDate.toUTCString() + '; path=/; SameSite=Lax';
					document.cookie = '_' + param + '=' + incomingVal + '; expires=' + expireDate.toUTCString() + '; path=/; SameSite=Lax';
					try {
						localStorage.setItem('woo_gads_' + param, JSON.stringify({
							value: incomingVal,
							timestamp: now
						}));
					} catch(e) {}
				} else {
					// Navigation sans nouveau clic : contrôle anti-zombie et restauration conditionnelle
					var cookieMatch = document.cookie.match(new RegExp('(^|; )woo_gads_' + param + '=([^;]+)'));
					var storedRaw = null;
					try { storedRaw = localStorage.getItem('woo_gads_' + param); } catch(e) {}

					if (storedRaw) {
						var storedVal = null;
						var storedTime = null;
						try {
							var parsed = JSON.parse(storedRaw);
							if (parsed && typeof parsed === 'object' && parsed.value) {
								storedVal = parsed.value;
								storedTime = parsed.timestamp;
							}
						} catch(e) {
							storedVal = null;
							storedTime = null;
						}

						var isExpired = false;
						if (storedTime && typeof storedTime === 'number') {
							if ((Date.now() - storedTime) > maxAgeMs) {
								isExpired = true;
							}
						} else {
							isExpired = true;
						}

						if (isExpired) {
							try { localStorage.removeItem('woo_gads_' + param); } catch(e) {}
							document.cookie = 'woo_gads_' + param + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax';
						} else if (!cookieMatch && storedVal) {
							var remainingMs = maxAgeMs - (Date.now() - storedTime);
							if (remainingMs > 0) {
								var expDate = new Date(Date.now() + remainingMs);
								document.cookie = 'woo_gads_' + param + '=' + storedVal + '; expires=' + expDate.toUTCString() + '; path=/; SameSite=Lax';
								document.cookie = '_' + param + '=' + storedVal + '; expires=' + expDate.toUTCString() + '; path=/; SameSite=Lax';
							}
						}
					}
				}
			});

			// Synchronisation automatique des champs cachés du checkout WooCommerce (classique & AJAX)
			function getActiveClickId(param) {
				var m = document.cookie.match(new RegExp('(^|; )woo_gads_' + param + '=([^;]+)'));
				if (m && m[2]) return decodeURIComponent(m[2]);
				if (param === 'gclid') {
					var gcl = extractFromGclCookie('_gcl_aw') || extractFromGclCookie('_gcl_dc');
					if (gcl) return gcl;
				}
				try {
					var raw = localStorage.getItem('woo_gads_' + param);
					if (raw) {
						var p = JSON.parse(raw);
						if (p && p.value) return p.value;
					}
				} catch(e) {}
				return '';
			}

			function syncCheckoutFields() {
				var forms = document.querySelectorAll('form.checkout, form.wc-block-checkout__form');
				paramsToSave.forEach(function(param) {
					var val = getActiveClickId(param);
					if (!val) return;
					var fieldName = 'woo_gads_' + param;
					var existingInputs = document.querySelectorAll('input[name=\"' + fieldName + '\"]');
					if (existingInputs.length > 0) {
						existingInputs.forEach(function(inp) { inp.value = val; });
					} else if (forms.length > 0) {
						forms.forEach(function(f) {
							var hidden = document.createElement('input');
							hidden.type = 'hidden';
							hidden.name = fieldName;
							hidden.value = val;
							f.appendChild(hidden);
						});
					}
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', syncCheckoutFields);
			} else {
				syncCheckoutFields();
			}
			if (typeof jQuery !== 'undefined') {
				jQuery(document.body).on('init_checkout updated_checkout checkout_place_order', syncCheckoutFields);
			}

			// 2. Gestion de la bannière native Google Consent Mode v2 & Revoyure CNIL
			var bannerConfig = " . wp_json_encode($banner_config) . ";
			if (!bannerConfig.enabled) return;

			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}

			function getCookie(name) {
				var match = document.cookie.match(new RegExp('(^|; )' + name + '=([^;]+)'));
				return match ? decodeURIComponent(match[2]) : null;
			}

			function dispatchConsentEvents(accepted) {
				var payload = {
					accepted: accepted,
					marketing: accepted,
					analytics: accepted,
					source: 'woo_gads'
				};
				try {
					window.dispatchEvent(new CustomEvent('woo_gads_consent_updated', { detail: payload }));
					document.dispatchEvent(new CustomEvent('woo_gads_consent_updated', { detail: payload }));
					window.dispatchEvent(new CustomEvent('woo_consent_updated', { detail: payload }));
					document.dispatchEvent(new CustomEvent('woo_consent_updated', { detail: payload }));
				} catch(e) {}
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

				dispatchConsentEvents(accepted);
				closeBanner();
			}

			function ensureStyles() {
				if (document.getElementById('woo-gads-banner-style')) return;
				var posRules = '@media(min-width:640px){#woo-gads-banner{right:24px;bottom:24px;left:auto;}}';
				if (bannerConfig.position === 'bottom-left') {
					posRules = '@media(min-width:640px){#woo-gads-banner{left:24px;bottom:24px;right:auto;}}';
				} else if (bannerConfig.position === 'bottom-center') {
					posRules = '@media(min-width:640px){#woo-gads-banner{left:50%;bottom:24px;right:auto;transform:translateX(-50%);}}';
				}

				var style = document.createElement('style');
				style.id = 'woo-gads-banner-style';
				style.innerHTML = '#woo-gads-banner{position:fixed;bottom:16px;left:16px;right:16px;max-width:440px;background:#ffffff!important;color:#1e293b!important;padding:18px 20px!important;border-radius:14px!important;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1),0 0 0 1px rgba(0,0,0,0.06)!important;border:1px solid #e2e8f0!important;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif!important;font-size:13px!important;line-height:1.5!important;z-index:99999999!important;box-sizing:border-box!important;transition:opacity 0.25s ease, transform 0.25s ease!important;animation:wooGadsSlideUp 0.3s cubic-bezier(0.16,1,0.3,1) forwards!important;}' +
					posRules +
					'@keyframes wooGadsSlideUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}' +
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
			}

			function createBanner() {
				var existing = document.getElementById('woo-gads-banner');
				if (existing) return existing;
				ensureStyles();

				var banner = document.createElement('div');
				banner.id = 'woo-gads-banner';
				banner.setAttribute('role', 'dialog');
				banner.setAttribute('aria-modal', 'false');
				banner.setAttribute('aria-label', 'Gestion des cookies');

				var privacyHtml = bannerConfig.privacy_url ? ' <a href=\"' + bannerConfig.privacy_url + '\" target=\"_blank\" class=\"woo-gads-privacy-link\">En savoir plus</a>' : '';
				var cookieSvg = '<svg class=\"woo-gads-icon\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5\"></path><path d=\"M8.5 8.5v.01\"></path><path d=\"M7.5 15.5v.01\"></path><path d=\"M15.5 15.5v.01\"></path><path d=\"M11.5 12.5v.01\"></path></svg>';

				banner.innerHTML = '<div class=\"woo-gads-header\">' +
						'<div class=\"woo-gads-title-wrap\">' +
							cookieSvg +
							'<span class=\"woo-gads-title\">Gestion des cookies</span>' +
						'</div>' +
						'<button type=\"button\" id=\"woo-gads-btn-close\" aria-label=\"Fermer\" title=\"Fermer\">&times;</button>' +
					'</div>' +
					'<p class=\"woo-gads-text\">' + bannerConfig.message + privacyHtml + '</p>' +
					'<div class=\"woo-gads-buttons\">' +
						'<button type=\"button\" id=\"woo-gads-btn-decline\" class=\"woo-gads-btn\">' + bannerConfig.decline_text + '</button>' +
						'<button type=\"button\" id=\"woo-gads-btn-accept\" class=\"woo-gads-btn\">' + bannerConfig.accept_text + '</button>' +
					'</div>';

				document.body.appendChild(banner);

				document.getElementById('woo-gads-btn-accept').addEventListener('click', function() {
					setConsent(true);
				});

				document.getElementById('woo-gads-btn-decline').addEventListener('click', function() {
					setConsent(false);
				});

				document.getElementById('woo-gads-btn-close').addEventListener('click', function() {
					try { sessionStorage.setItem('woo_gads_banner_dismissed', '1'); } catch(e) {}
					closeBanner();
				});

				return banner;
			}

			function openBanner() {
				try { sessionStorage.removeItem('woo_gads_banner_dismissed'); } catch(e) {}
				var banner = document.getElementById('woo-gads-banner');
				if (!banner) {
					banner = createBanner();
				} else {
					banner.style.display = 'block';
					banner.style.opacity = '1';
					banner.style.transform = 'translateY(0)';
					banner.style.pointerEvents = 'auto';
				}
				var acceptBtn = document.getElementById('woo-gads-btn-accept');
				if (acceptBtn) acceptBtn.focus();
			}

			function closeBanner() {
				var banner = document.getElementById('woo-gads-banner');
				if (!banner) return;
				banner.style.opacity = '0';
				banner.style.transform = 'translateY(15px)';
				banner.style.pointerEvents = 'none';
				setTimeout(function() {
					if (banner.parentNode) banner.parentNode.removeChild(banner);
				}, 260);
			}

			window.wooGadsOpenConsentBanner = openBanner;
			window.wooGadsCloseConsentBanner = closeBanner;
			window.wooGadsSetConsent = setConsent;

			// Écouteur de clic universel pour la revoyure (shortcode [woo_gads_cookie_settings], lien de menu, classe CSS)
			document.addEventListener('click', function(e) {
				var trigger = e.target && e.target.closest ? e.target.closest('.woo-gads-reopen-consent, [data-woo-gads-reopen], a[href=\"#woo-gads-cookies\"]') : null;
				if (trigger) {
					e.preventDefault();
					openBanner();
				}
			});

			// Affichage initial automatique si aucun choix n'a été enregistré
			var savedConsent = getCookie('woo_gads_consent');
			var isDismissed = false;
			try { isDismissed = sessionStorage.getItem('woo_gads_banner_dismissed') === '1'; } catch(e) {}

			if (!savedConsent && !isDismissed) {
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', openBanner);
				} else {
					openBanner();
				}
			}
		})();
		";
        wp_add_inline_script($this->plugin_name . '-capture', $js);
    }

    /**
     * Persist captured click IDs (gclid, wbraid, gbraid) and consent state onto the WooCommerce order.
     * Writes to _woo_gads_<param>, _gads_<param>, and _<param> (_gclid) via $order->update_meta_data() and update_post_meta().
     *
     * @param int|\WC_Order $order_id_or_order Order ID or WC_Order instance.
     * @param mixed         $data              Optional checkout posted data.
     */
    public function save_click_ids($order_id_or_order, $data = null)
    {
        $order = ($order_id_or_order instanceof WC_Order) ? $order_id_or_order : wc_get_order($order_id_or_order);
        if (!$order || !is_a($order, 'WC_Order') || is_a($order, 'WC_Order_Refund')) {
            return;
        }

        $order_id = (int) $order->get_id();
        $posted_array = is_array($data) ? $data : array();
        $resolved = self::resolve_current_click_ids($posted_array);

        // Protection anti-réentrance : ne court-circuiter que si un identifiant de clic est DÉJÀ présent sur la commande
        $already_has_click_id = (
            !empty($order->get_meta('_woo_gads_gclid')) ||
            !empty($order->get_meta('_woo_gads_wbraid')) ||
            !empty($order->get_meta('_woo_gads_gbraid'))
        );
        if ($order->get_meta('_woo_gads_ids_saved') === '1' && ($already_has_click_id || empty($resolved))) {
            return;
        }

        $saved_any_click_id = false;
        $paramsToSave = array('gclid', 'wbraid', 'gbraid');

        foreach ($paramsToSave as $param) {
            $value = isset($resolved[$param]) ? $resolved[$param] : '';

            // Fallback: inspect WooCommerce 8.5+ native Order Attribution landing URL (_wc_order_attribution_session_entry)
            if ($value === '') {
                $session_entry = $order->get_meta('_wc_order_attribution_session_entry');
                if (empty($session_entry) && $order_id > 0) {
                    $session_entry = get_post_meta($order_id, '_wc_order_attribution_session_entry', true);
                }
                if (!empty($session_entry) && is_string($session_entry) && strpos($session_entry, $param . '=') !== false) {
                    $parsed_url = wp_parse_url($session_entry);
                    if (!empty($parsed_url['query'])) {
                        parse_str($parsed_url['query'], $q_vars);
                        if (!empty($q_vars[$param]) && is_string($q_vars[$param])) {
                            $value = sanitize_text_field(trim($q_vars[$param]));
                        }
                    }
                }
            }

            if ($value !== '') {
                $saved_any_click_id = true;
                $meta_keys = array(
                    '_woo_gads_' . $param,
                    '_gads_' . $param,
                    '_' . $param,
                );
                foreach ($meta_keys as $mk) {
                    $order->update_meta_data($mk, $value);
                    if ($order_id > 0) {
                        update_post_meta($order_id, $mk, $value);
                    }
                }
            }
        }

        // Save consent status
        $settings = get_option('woo_gads_settings');
        $is_builtin = !empty($settings['enable_builtin_banner']);
        $consent_cookie = $is_builtin ? 'woo_gads_consent' : (isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name']) ? $settings['consent_cookie_name'] : 'concord_consent');

        $cookie_value = null;
        if (isset($_COOKIE[$consent_cookie]) && !empty($_COOKIE[$consent_cookie])) {
            $cookie_value = $_COOKIE[$consent_cookie];
        } elseif (!empty($_COOKIE['woo_gads_consent'])) {
            $cookie_value = $_COOKIE['woo_gads_consent'];
        } elseif (!empty($_COOKIE['concord_consent'])) {
            $cookie_value = $_COOKIE['concord_consent'];
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
            $clean_val = stripslashes(urldecode($cookie_value));
            $is_granted = class_exists('Woo_Gads_Api') ? Woo_Gads_Api::parse_consent_value($clean_val) : false;
            $norm_consent = $is_granted ? 'granted' : 'denied';

            $order->update_meta_data('_woo_gads_consent', $clean_val);
            $order->update_meta_data('_gads_consent', $norm_consent);
            if ($order_id > 0) {
                update_post_meta($order_id, '_woo_gads_consent', $clean_val);
                update_post_meta($order_id, '_gads_consent', $norm_consent);
            }
        } elseif (empty($order->get_meta('_woo_gads_consent'))) {
            $order->update_meta_data('_woo_gads_consent', 'no_cookie_found');
            $order->update_meta_data('_gads_consent', 'denied');
            if ($order_id > 0) {
                update_post_meta($order_id, '_woo_gads_consent', 'no_cookie_found');
                update_post_meta($order_id, '_gads_consent', 'denied');
            }
        }

        if ($saved_any_click_id) {
            $order->update_meta_data('_woo_gads_ids_saved', '1');
            if ($order_id > 0) {
                update_post_meta($order_id, '_woo_gads_ids_saved', '1');
            }
        }

        // Only call $order->save() if the order already has a database ID (during woocommerce_checkout_create_order, WC saves right after)
        if ($order_id > 0) {
            $order->save();
        }
    }
}
