<?php

class Woo_Gads_Api
{
    /**
     * Per-request runtime deduplication locks keyed by order ID.
     *
     * @var array<int, bool>
     */
    private static $purchases_sent_in_request = array();
    private static $leads_sent_in_request = array();

    /**
     * Reset in-memory per-request deduplication locks (useful for CLI/batch tests).
     */
    public static function reset_runtime_locks()
    {
        self::$purchases_sent_in_request = array();
        self::$leads_sent_in_request = array();
    }

    public static function get_target_statuses($settings = null)
    {
        if ($settings === null) {
            $settings = get_option('woo_gads_settings', array());
        }

        $statuses = array();

        if (isset($settings['order_statuses']) && is_array($settings['order_statuses']) && !empty($settings['order_statuses'])) {
            $statuses = $settings['order_statuses'];
        } elseif (isset($settings['order_status']) && !empty($settings['order_status'])) {
            // Backward compatibility with legacy single-string setting
            if ($settings['order_status'] === 'processing_or_completed') {
                $statuses = array('processing', 'completed');
            } else {
                $statuses = array($settings['order_status']);
            }
        } else {
            // Default if not configured
            $statuses = array('processing', 'completed');
        }

        $normalized = array();
        foreach ($statuses as $st) {
            if (is_string($st) && $st !== '') {
                $st = trim($st);
                if (strpos($st, 'wc-') === 0) {
                    $st = substr($st, 3);
                }
                if ($st !== '') {
                    $normalized[] = $st;
                }
            }
        }
        $statuses = $normalized;

        // Essential e-commerce rule: In WooCommerce, 'processing' (En cours) means the order was paid.
        // Once shipped or fulfilled, it naturally moves to 'completed' (Terminée).
        // Therefore, whenever 'processing' is an enabled trigger status, 'completed' is also legitimate and must be included.
        if (in_array('processing', $statuses, true) && !in_array('completed', $statuses, true)) {
            $statuses[] = 'completed';
        }

        return array_values(array_unique($statuses));
    }

    /**
     * Return the list of quote / lead payment method IDs that must NEVER trigger a Purchase conversion
     * unless the order is genuinely paid.
     *
     * Default: array('we_quote_request'), filterable via 'woo_gads_lead_payment_methods'.
     *
     * @param array|null $settings Plugin settings array.
     * @return array<int, string>
     */
    public static function get_lead_payment_methods($settings = null)
    {
        if ($settings === null) {
            $settings = get_option('woo_gads_settings', array());
        }
        if (!is_array($settings)) {
            $settings = array();
        }

        if (!array_key_exists('lead_payment_methods', $settings)) {
            $methods = array('we_quote_request');
        } else {
            $raw = $settings['lead_payment_methods'];
            if (is_array($raw)) {
                $items = $raw;
            } elseif (is_string($raw)) {
                $items = preg_split('/[\s,;]+/', $raw);
            } else {
                $items = array();
            }

            $methods = array();
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_scalar($item)) {
                        continue;
                    }
                    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string) $item));
                    if ($clean !== '') {
                        $methods[] = $clean;
                    }
                }
            }
        }

        $filtered = apply_filters('woo_gads_lead_payment_methods', $methods, $settings);
        if (!is_array($filtered)) {
            return array();
        }

        $final = array();
        foreach ($filtered as $m) {
            if (is_scalar($m)) {
                $clean = trim((string) $m);
                if ($clean !== '') {
                    $final[] = $clean;
                }
            }
        }

        return array_values(array_unique($final));
    }

    /**
     * Check whether an order uses a configured quote / lead payment method (e.g. 'we_quote_request').
     *
     * @param \WC_Order|int $order    Order object or ID.
     * @param array|null    $settings Optional plugin settings.
     * @return bool
     */
    public static function is_lead_order($order, $settings = null)
    {
        $wc_order = ($order instanceof \WC_Order) ? $order : (is_numeric($order) ? wc_get_order($order) : null);
        if (!$wc_order || !is_a($wc_order, 'WC_Order')) {
            return false;
        }

        $payment_method = method_exists($wc_order, 'get_payment_method') ? trim((string) $wc_order->get_payment_method()) : '';
        if ($payment_method === '') {
            $order_id = (int) $wc_order->get_id();
            $payment_method = trim((string) $wc_order->get_meta('_payment_method'));
            if ($payment_method === '' && $order_id > 0) {
                $payment_method = trim((string) get_post_meta($order_id, '_payment_method', true));
            }
        }

        if ($payment_method === '') {
            return false;
        }

        $lead_methods = array_map('strtolower', self::get_lead_payment_methods($settings));
        return in_array(strtolower($payment_method), $lead_methods, true);
    }

    /**
     * Determine if an order is genuinely paid ($order->get_date_paid() non-null or $order->is_paid()).
     *
     * @param \WC_Order|int $order
     * @return bool
     */
    public static function is_order_really_paid($order)
    {
        $wc_order = ($order instanceof \WC_Order) ? $order : (is_numeric($order) ? wc_get_order($order) : null);
        if (!$wc_order || !is_a($wc_order, 'WC_Order')) {
            return false;
        }

        $date_paid = null;
        if (method_exists($wc_order, 'get_date_paid')) {
            $date_paid = $wc_order->get_date_paid('edit');
            if (empty($date_paid)) {
                $date_paid = $wc_order->get_date_paid();
            }
        }

        $is_paid = (!empty($date_paid)) || (method_exists($wc_order, 'is_paid') && $wc_order->is_paid());

        return (bool) apply_filters('woo_gads_is_order_paid', $is_paid, $wc_order);
    }

    /**
     * Lock A check: true when the order uses a lead/quote payment method AND has NOT been genuinely paid.
     * Such orders must NEVER be sent as Purchase conversions, even with $force = true.
     *
     * @param \WC_Order|int $order
     * @param array|null    $settings
     * @return bool
     */
    public static function is_unpaid_lead_order($order, $settings = null)
    {
        return self::is_lead_order($order, $settings) && !self::is_order_really_paid($order);
    }

    /**
     * Return eligible order statuses for Lead / Quote conversions (default: ['on-hold', 'pending']).
     *
     * @param array|null $settings
     * @return array<int, string>
     */
    public static function get_lead_target_statuses($settings = null)
    {
        if ($settings === null) {
            $settings = get_option('woo_gads_settings', array());
        }

        $statuses = array();
        if (is_array($settings) && isset($settings['lead_order_statuses']) && is_array($settings['lead_order_statuses']) && !empty($settings['lead_order_statuses'])) {
            foreach ($settings['lead_order_statuses'] as $st) {
                if (is_string($st) && $st !== '') {
                    $st = trim($st);
                    if (strpos($st, 'wc-') === 0) {
                        $st = substr($st, 3);
                    }
                    if ($st !== '') {
                        $statuses[] = $st;
                    }
                }
            }
        }

        if (empty($statuses)) {
            $statuses = array('on-hold', 'pending');
        }

        $filtered = apply_filters('woo_gads_lead_target_statuses', array_values(array_unique($statuses)), $settings);
        return is_array($filtered) ? array_values(array_unique($filtered)) : array('on-hold', 'pending');
    }

    /**
     * Check whether the "Quote Request" (Demande de devis) conversion action is enabled and configured.
     *
     * @param array|null $settings
     * @return bool
     */
    public static function is_lead_conversion_enabled($settings = null)
    {
        if ($settings === null) {
            $settings = get_option('woo_gads_settings', array());
        }
        if (!is_array($settings)) {
            return false;
        }

        $enabled = !empty($settings['enable_lead_conversion']) && (string) $settings['enable_lead_conversion'] !== '0';
        $action_id = isset($settings['lead_conversion_action_id']) ? trim((string) $settings['lead_conversion_action_id']) : '';

        return $enabled && ($action_id !== '');
    }

    /**
     * Return the configured lead conversion value ratio (default: 0.25).
     *
     * @param array|null $settings
     * @return float
     */
    public static function get_lead_value_ratio($settings = null)
    {
        if ($settings === null) {
            $settings = get_option('woo_gads_settings', array());
        }
        if (!is_array($settings) || !isset($settings['lead_value_ratio']) || trim((string) $settings['lead_value_ratio']) === '') {
            return 0.25;
        }

        $normalized = str_replace(',', '.', trim((string) $settings['lead_value_ratio']));
        if (!is_numeric($normalized)) {
            return 0.25;
        }

        return max(0.0, (float) $normalized);
    }

    /**
     * Return the configured lead conversion value cap in EUR (default: 750.0).
     *
     * @param array|null $settings
     * @return float
     */
    public static function get_lead_value_cap($settings = null)
    {
        if ($settings === null) {
            $settings = get_option('woo_gads_settings', array());
        }
        if (!is_array($settings) || !isset($settings['lead_value_cap']) || trim((string) $settings['lead_value_cap']) === '') {
            return 750.0;
        }

        $normalized = str_replace(',', '.', trim((string) $settings['lead_value_cap']));
        if (!is_numeric($normalized)) {
            return 750.0;
        }

        return max(0.0, (float) $normalized);
    }

    /**
     * Compute the capped, rounded conversion value for a quote/lead order: min(total_ttc * ratio, cap).
     *
     * @param \WC_Order  $order
     * @param array|null $settings
     * @return float
     */
    public static function calculate_lead_value($order, $settings = null)
    {
        $total = method_exists($order, 'get_total') ? (float) $order->get_total() : 0.0;
        $ratio = self::get_lead_value_ratio($settings);
        $cap   = self::get_lead_value_cap($settings);

        $value = $total * $ratio;
        if ($cap > 0.0) {
            $value = min($value, $cap);
        }

        return round(max(0.0, $value), 2);
    }

    /**
     * Normalize a conversion action identifier (numeric ID or full resource name) into
     * "customers/{customer_id}/conversionActions/{action_id}".
     *
     * @param string $merchant_id   Customer ID (digits).
     * @param string $raw_action_id Numeric ID or resource name.
     * @return string Normalized resource name or empty string if invalid.
     */
    public static function format_conversion_action_resource($merchant_id, $raw_action_id)
    {
        $clean_merchant = preg_replace('/[^0-9]/', '', (string) $merchant_id);
        $raw = trim((string) $raw_action_id);
        if ($raw === '') {
            return '';
        }

        if (preg_match('#^customers/([0-9\-]+)/conversionActions/([0-9]+)$#i', $raw, $matches)) {
            $cust = preg_replace('/[^0-9]/', '', $matches[1]);
            return ($cust !== '' && $matches[2] !== '') ? "customers/{$cust}/conversionActions/{$matches[2]}" : '';
        }

        if (preg_match('#^conversionActions/([0-9]+)$#i', $raw, $matches)) {
            return ($clean_merchant !== '' && $matches[1] !== '') ? "customers/{$clean_merchant}/conversionActions/{$matches[1]}" : '';
        }

        $clean_action = preg_replace('/[^0-9]/', '', $raw);
        if ($clean_merchant === '' || $clean_action === '') {
            return '';
        }

        return "customers/{$clean_merchant}/conversionActions/{$clean_action}";
    }

    /**
     * Production environment guard (default: true, filterable via 'woo_gads_is_production').
     *
     * @param \WC_Order|null $order
     * @param string         $action_type 'purchase' or 'lead'.
     * @return bool
     */
    public static function is_production_environment($order = null, $action_type = 'purchase')
    {
        return (bool) apply_filters('woo_gads_is_production', true, $order, $action_type);
    }

    /**
     * Determine whether Google Ads API requests should include "validateOnly": true.
     *
     * @param array|null     $settings
     * @param \WC_Order|null $order
     * @param string         $action_type 'purchase' or 'lead'.
     * @return bool
     */
    public static function is_validate_only($settings = null, $order = null, $action_type = 'purchase')
    {
        if ($settings === null) {
            $settings = get_option('woo_gads_settings', array());
        }
        $enabled = is_array($settings) && !empty($settings['validate_only']) && (string) $settings['validate_only'] !== '0';

        return (bool) apply_filters('woo_gads_validate_only', $enabled, $order, $action_type);
    }

    /**
     * Read an order meta value with HPOS + legacy postmeta fallback.
     *
     * @param \WC_Order $order
     * @param int       $order_id
     * @param string    $key
     * @return string
     */
    private static function get_order_meta_dual($order, $order_id, $key)
    {
        $val = $order ? $order->get_meta($key) : '';
        if (($val === '' || $val === null) && $order_id > 0) {
            $val = get_post_meta($order_id, $key, true);
        }
        return is_scalar($val) ? (string) $val : '';
    }

    /**
     * Persist an order meta value to both HPOS and legacy postmeta.
     *
     * @param \WC_Order $order
     * @param int       $order_id
     * @param string    $key
     * @param string    $value
     */
    private static function update_order_meta_dual($order, $order_id, $key, $value)
    {
        if ($order) {
            $order->update_meta_data($key, $value);
            if ($order_id > 0) {
                $order->save();
            }
        }
        if ($order_id > 0) {
            update_post_meta($order_id, $key, $value);
        }
    }

    /**
     * Delete an order meta key from both HPOS and legacy postmeta.
     *
     * @param \WC_Order $order
     * @param int       $order_id
     * @param string    $key
     */
    private static function delete_order_meta_dual($order, $order_id, $key)
    {
        if ($order) {
            $order->delete_meta_data($key);
            if ($order_id > 0) {
                $order->save();
            }
        }
        if ($order_id > 0) {
            delete_post_meta($order_id, $key);
        }
    }

    /**
     * Parse a raw consent cookie string/JSON and determine if marketing consent is granted.
     *
     * @param mixed $cookie_value Raw cookie value.
     * @return bool True if marketing consent is granted, false otherwise.
     */
    public static function parse_consent_value($cookie_value)
    {
        if (empty($cookie_value) || !is_string($cookie_value) || $cookie_value === 'no_cookie_found') {
            return false;
        }

        $clean = trim(stripslashes(urldecode($cookie_value)));
        if ($clean === '' || $clean === 'no_cookie_found' || $clean === 'denied' || $clean === 'false' || $clean === '0') {
            return false;
        }
        if ($clean === 'granted' || $clean === 'true' || $clean === '1' || $clean === 'allow') {
            return true;
        }

        $cookie_data = json_decode($clean, true);
        if (!is_array($cookie_data)) {
            $cookie_data = json_decode(stripslashes(html_entity_decode($clean, ENT_QUOTES)), true);
        }

        if (is_array($cookie_data)) {
            if (isset($cookie_data['marketing'])) {
                return $cookie_data['marketing'] === true || $cookie_data['marketing'] === 'true' || $cookie_data['marketing'] === 1 || $cookie_data['marketing'] === 'granted';
            }
            if (isset($cookie_data['advertising'])) {
                return $cookie_data['advertising'] === true || $cookie_data['advertising'] === 'true' || $cookie_data['advertising'] === 1 || $cookie_data['advertising'] === 'granted';
            }
        }

        return false;
    }

    /**
     * Synchronize a resolved click ID across all standardized order meta keys
     * (_woo_gads_<type>, _gads_<type>, _<type>) on both HPOS and legacy postmeta.
     *
     * @param \WC_Order|null $wc_order
     * @param int            $order_id
     * @param string         $type     'gclid', 'wbraid', or 'gbraid'.
     * @param string         $val      Clean click ID value.
     */
    private static function sync_order_click_id_metas($wc_order, $order_id, $type, $val)
    {
        $val = trim((string) $val);
        if ($val === '' || $order_id <= 0) {
            return;
        }

        if (class_exists('Woo_Gads_Public') && stripos($val, 'GCL.') === 0) {
            $extracted = Woo_Gads_Public::extract_click_id_from_gcl_cookie($val);
            if ($extracted !== '') {
                $val = $extracted;
            }
        }

        $target_keys = array(
            "_woo_gads_{$type}",
            "_gads_{$type}",
            "_{$type}",
        );

        $needs_save = false;
        foreach ($target_keys as $mk) {
            if ($wc_order) {
                $curr = $wc_order->get_meta($mk);
                if ($curr !== $val) {
                    $wc_order->update_meta_data($mk, $val);
                    $needs_save = true;
                }
            }
            update_post_meta($order_id, $mk, $val);
        }

        if ($needs_save && $wc_order) {
            $wc_order->save();
        }
    }

    /**
     * Helper to retrieve a click ID from order meta with all known fallbacks
     * (HPOS, postmeta, _gclid, _gads_gclid, WP Gens UTM, WooCommerce Order Attribution landing URL, generic)
     */
    public static function get_order_click_id($order, $type = 'gclid')
    {
        $wc_order = ($order instanceof \WC_Order) ? $order : (is_numeric($order) ? wc_get_order($order) : null);
        $order_id = $wc_order ? $wc_order->get_id() : (is_numeric($order) ? (int) $order : 0);

        if (!$order_id) {
            return '';
        }

        $keys = array();
        if ($type === 'gclid') {
            $keys = array('_woo_gads_gclid', '_gads_gclid', '_gclid', 'gclid', '_wpgens_gclid', 'wpgens_gclid');
        } elseif ($type === 'wbraid') {
            $keys = array('_woo_gads_wbraid', '_gads_wbraid', '_wbraid', 'wbraid', '_wpgens_wbraid', 'wpgens_wbraid');
        } elseif ($type === 'gbraid') {
            $keys = array('_woo_gads_gbraid', '_gads_gbraid', '_gbraid', 'gbraid', '_wpgens_gbraid', 'wpgens_gbraid');
        }

        foreach ($keys as $key) {
            $val = '';
            if ($wc_order) {
                $val = $wc_order->get_meta($key);
            }
            if (empty($val)) {
                $val = get_post_meta($order_id, $key, true);
            }
            if (!empty($val) && is_string($val)) {
                $val = trim($val);
                if (class_exists('Woo_Gads_Public') && stripos($val, 'GCL.') === 0) {
                    $val = Woo_Gads_Public::extract_click_id_from_gcl_cookie($val);
                }
                if (!empty($val)) {
                    self::sync_order_click_id_metas($wc_order, $order_id, $type, $val);
                    return $val;
                }
            }
        }

        // Check WooCommerce 8.5+ native Order Attribution landing URL (_wc_order_attribution_session_entry)
        $session_entry = $wc_order ? $wc_order->get_meta('_wc_order_attribution_session_entry') : '';
        if (empty($session_entry)) {
            $session_entry = get_post_meta($order_id, '_wc_order_attribution_session_entry', true);
        }
        if (!empty($session_entry) && is_string($session_entry) && strpos($session_entry, $type . '=') !== false) {
            $parsed_url = wp_parse_url($session_entry);
            if (!empty($parsed_url['query'])) {
                parse_str($parsed_url['query'], $q_vars);
                if (!empty($q_vars[$type]) && is_string($q_vars[$type])) {
                    $val = sanitize_text_field(trim($q_vars[$type]));
                    if (!empty($val)) {
                        self::sync_order_click_id_metas($wc_order, $order_id, $type, $val);
                        return $val;
                    }
                }
            }
        }

        // Dynamic scan across ALL order metadata for any third-party plugin storing the click ID
        if ($wc_order && method_exists($wc_order, 'get_meta_data')) {
            foreach ($wc_order->get_meta_data() as $meta) {
                $meta_key_lower = strtolower($meta->key);
                if (strpos($meta_key_lower, '_gads_api') !== false || strpos($meta_key_lower, '_gads_lead') !== false) {
                    continue;
                }
                if (strpos($meta_key_lower, $type) !== false) {
                    $raw_val = is_string($meta->value) ? trim($meta->value) : '';
                    if (class_exists('Woo_Gads_Public') && stripos($raw_val, 'GCL.') === 0) {
                        $raw_val = Woo_Gads_Public::extract_click_id_from_gcl_cookie($raw_val);
                    }
                    if (!empty($raw_val) && strlen($raw_val) > 8 && strlen($raw_val) < 255 && strpos($raw_val, '{') !== 0 && strpos($raw_val, ' ') === false) {
                        self::sync_order_click_id_metas($wc_order, $order_id, $type, $raw_val);
                        return $raw_val;
                    }
                }
            }
        }

        $raw_post_metas = get_post_meta($order_id);
        if (is_array($raw_post_metas)) {
            foreach ($raw_post_metas as $k => $vals) {
                $k_lower = strtolower($k);
                if (strpos($k_lower, '_gads_api') !== false || strpos($k_lower, '_gads_lead') !== false) {
                    continue;
                }
                if (strpos($k_lower, $type) !== false) {
                    $raw_val = isset($vals[0]) && is_string($vals[0]) ? trim($vals[0]) : '';
                    if (class_exists('Woo_Gads_Public') && stripos($raw_val, 'GCL.') === 0) {
                        $raw_val = Woo_Gads_Public::extract_click_id_from_gcl_cookie($raw_val);
                    }
                    if (!empty($raw_val) && strlen($raw_val) > 8 && strlen($raw_val) < 255 && strpos($raw_val, '{') !== 0 && strpos($raw_val, ' ') === false) {
                        self::sync_order_click_id_metas($wc_order, $order_id, $type, $raw_val);
                        return $raw_val;
                    }
                }
            }
        }

        // Synchronous checkout fallback: if called during the customer's own checkout/payment execution, resolve from live cookies/session
        if (
            class_exists('Woo_Gads_Public') &&
            (did_action('woocommerce_checkout_order_processed') || did_action('woocommerce_store_api_checkout_update_order_meta') || did_action('woocommerce_new_order')) &&
            !is_admin()
        ) {
            $live_ids = Woo_Gads_Public::resolve_current_click_ids();
            if (!empty($live_ids[$type])) {
                self::sync_order_click_id_metas($wc_order, $order_id, $type, $live_ids[$type]);
                return $live_ids[$type];
            }
        }

        return '';
    }

    /**
     * Returns all captured click IDs for an order as an associative array ('GCLID' => ..., 'WBRAID' => ..., 'GBRAID' => ...)
     */
    public static function get_all_order_click_ids($order)
    {
        $gclid = self::get_order_click_id($order, 'gclid');
        $wbraid = self::get_order_click_id($order, 'wbraid');
        $gbraid = self::get_order_click_id($order, 'gbraid');

        $ids = array();
        if (!empty($gclid)) { $ids['GCLID'] = $gclid; }
        if (!empty($wbraid)) { $ids['WBRAID'] = $wbraid; }
        if (!empty($gbraid)) { $ids['GBRAID'] = $gbraid; }

        return $ids;
    }

    /**
     * Batch scan and rescue past orders within a specified window (in days).
     * - Respects Lock A: unpaid quote/lead orders are NEVER sent as Purchase conversions.
     * - When the "Quote Request" (Demande de devis) action is enabled, rescues unsent quotes as Lead conversions.
     * - Only orders with a valid click ID (GCLID, WBRAID, GBRAID) are sent to Google Ads.
     */
    public function batch_rescue_orders($days = 14, $force_any_status = false)
    {
        $days = max(1, min(90, (int) $days));
        $force_any_status = ($force_any_status === true || $force_any_status === 1 || $force_any_status === '1');
        $after_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $settings = get_option('woo_gads_settings', array());
        $target_statuses = self::get_target_statuses($settings);
        $lead_enabled = self::is_lead_conversion_enabled($settings);
        $lead_target_statuses = self::get_lead_target_statuses($settings);

        // Fetch orders using WC_Order_Query
        $orders = wc_get_orders(array(
            'limit' => 300,
            'date_created' => '>=' . $after_date,
            'orderby' => 'date',
            'order' => 'DESC',
            'type' => 'shop_order',
        ));

        $stats = array(
            'days' => $days,
            'total_inspected' => count($orders),
            'already_sent' => 0,
            'skipped_no_click_id' => 0,
            'skipped_status' => 0,
            'skipped_expired' => 0,
            'skipped_unpaid_leads' => 0,
            'skipped_statuses_breakdown' => array(),
            'orders_with_click_id_blocked_by_status' => array(),
            'rescued_success' => 0,
            'lead_rescued_success' => 0,
            'rescued_failed' => 0,
            'details' => array(),
        );

        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order || is_a($order, 'WC_Order_Refund')) {
                continue;
            }
            $order_id = $order->get_id();
            $order_status = $order->get_status();

            // Always ensure click IDs are resolved and backfilled across _woo_gads_gclid, _gads_gclid, _gclid
            $click_ids = self::get_all_order_click_ids($order);

            // =========================================================================
            // BRANCH 1: UNPAID QUOTE / LEAD ORDER (Lock A strictly blocks Purchase)
            // =========================================================================
            if (self::is_unpaid_lead_order($order, $settings)) {
                $stats['skipped_unpaid_leads']++;

                // Lock A: mark Purchase status as ignored without ever touching _gads_api_sent
                $existing_purchase_sent = self::get_order_meta_dual($order, $order_id, '_gads_api_sent');
                if ($existing_purchase_sent !== '1') {
                    self::update_order_meta_dual($order, $order_id, '_gads_api_status', 'Ignoré (devis, non payé)');
                }

                // Rescue as Lead ("Demande de devis") ONLY if Lead tracking is enabled
                if (!$lead_enabled) {
                    continue;
                }

                $lead_sent = self::get_order_meta_dual($order, $order_id, '_gads_lead_sent');
                if ($lead_sent === '1') {
                    $stats['already_sent']++;
                    continue;
                }

                $lead_status_eligible = in_array($order_status, $lead_target_statuses, true);
                if (!$lead_status_eligible && !$force_any_status) {
                    $stats['skipped_status']++;
                    $status_name = function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order_status) : $order_status;
                    $key_label = $status_name . ' (Devis)';
                    if (!isset($stats['skipped_statuses_breakdown'][$key_label])) {
                        $stats['skipped_statuses_breakdown'][$key_label] = 0;
                    }
                    $stats['skipped_statuses_breakdown'][$key_label]++;
                    continue;
                }

                if (empty($click_ids)) {
                    $curr_lead_status = self::get_order_meta_dual($order, $order_id, '_gads_lead_status');
                    if ($curr_lead_status === '' || strpos($curr_lead_status, 'En attente') !== false) {
                        self::update_order_meta_dual($order, $order_id, '_gads_lead_status', 'Ignoré (Aucun identifiant de clic)');
                    }
                    $stats['skipped_no_click_id']++;
                    continue;
                }

                // Reset failed lead marker (never touch _gads_api_sent!) and trigger lead conversion
                self::delete_order_meta_dual($order, $order_id, '_gads_lead_sent');
                $this->trigger_lead_conversion($order, true);

                $fresh_order = wc_get_order($order_id);
                $new_lead_sent = self::get_order_meta_dual($fresh_order ?: $order, $order_id, '_gads_lead_sent');
                $new_lead_status = self::get_order_meta_dual($fresh_order ?: $order, $order_id, '_gads_lead_status');
                $new_lead_val = self::get_order_meta_dual($fresh_order ?: $order, $order_id, '_gads_lead_value');

                if ($new_lead_sent === '1') {
                    if (strpos($new_lead_status, 'Ignoré (Clic expiré') !== false || strpos($new_lead_status, 'Ignoré (Clic introuvable') !== false) {
                        $stats['skipped_expired']++;
                        $stats['details'][] = array(
                            'order_id'    => $order_id,
                            'action_type' => 'lead',
                            'status'      => 'ignored',
                            'message'     => '[Devis] ' . $new_lead_status,
                        );
                    } else {
                        $stats['rescued_success']++;
                        $stats['lead_rescued_success']++;
                        $val_suffix = ($new_lead_val !== '') ? ' - ' . $new_lead_val . ' €' : '';
                        $stats['details'][] = array(
                            'order_id'    => $order_id,
                            'action_type' => 'lead',
                            'status'      => 'success',
                            'message'     => '[Devis] Envoyé avec succès (' . implode(', ', array_keys($click_ids)) . $val_suffix . ')',
                        );
                    }
                } else {
                    $stats['rescued_failed']++;
                    $stats['details'][] = array(
                        'order_id'    => $order_id,
                        'action_type' => 'lead',
                        'status'      => 'failed',
                        'message'     => '[Devis] ' . ($new_lead_status ?: 'Échec de transmission'),
                    );
                }

                continue;
            }

            // =========================================================================
            // BRANCH 2: STANDARD ORDER OR PAID QUOTE -> PURCHASE CONVERSION FLOW
            // =========================================================================
            $api_sent = self::get_order_meta_dual($order, $order_id, '_gads_api_sent');

            // 1. If already sent successfully, skip
            if ($api_sent === '1') {
                $stats['already_sent']++;
                continue;
            }

            // 2. Status eligibility check
            $status_is_eligible = in_array($order_status, $target_statuses, true);
            if (!$status_is_eligible && !$force_any_status) {
                $stats['skipped_status']++;
                $status_name = function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order_status) : $order_status;
                if (!isset($stats['skipped_statuses_breakdown'][$status_name])) {
                    $stats['skipped_statuses_breakdown'][$status_name] = 0;
                }
                $stats['skipped_statuses_breakdown'][$status_name]++;

                // If this order HAS a click ID but was blocked only by its status, report it!
                if (!empty($click_ids)) {
                    $stats['orders_with_click_id_blocked_by_status'][] = array(
                        'order_id' => $order_id,
                        'status' => $status_name,
                        'click_ids' => array_keys($click_ids),
                    );
                }
                continue;
            }

            // 3. If no click ID found -> Mark as Ignored (Non-Google Ads traffic)
            if (empty($click_ids)) {
                $current_status = self::get_order_meta_dual($order, $order_id, '_gads_api_status');
                if ($current_status === '' || strpos($current_status, 'En attente') !== false) {
                    self::update_order_meta_dual($order, $order_id, '_gads_api_status', 'Ignoré (Aucun identifiant de clic)');
                }
                $stats['skipped_no_click_id']++;
                continue;
            }

            // 4. Click ID found & eligible status -> Rescue Purchase conversion
            self::delete_order_meta_dual($order, $order_id, '_gads_api_sent');

            $this->trigger_conversion($order, true);

            // Reload fresh order meta
            $fresh_order = wc_get_order($order_id);
            $new_sent = self::get_order_meta_dual($fresh_order ?: $order, $order_id, '_gads_api_sent');
            $new_status = self::get_order_meta_dual($fresh_order ?: $order, $order_id, '_gads_api_status');

            if ($new_sent === '1') {
                if (strpos($new_status, 'Ignoré (Clic expiré') !== false || strpos($new_status, 'Ignoré (Clic introuvable') !== false) {
                    $stats['skipped_expired']++;
                    $stats['details'][] = array(
                        'order_id'    => $order_id,
                        'action_type' => 'purchase',
                        'status'      => 'ignored',
                        'message'     => '[Achat] ' . $new_status,
                    );
                } else {
                    $stats['rescued_success']++;
                    $stats['details'][] = array(
                        'order_id'    => $order_id,
                        'action_type' => 'purchase',
                        'status'      => 'success',
                        'message'     => '[Achat] Envoyée avec succès (' . implode(', ', array_keys($click_ids)) . ')',
                    );
                }
            } else {
                $stats['rescued_failed']++;
                $stats['details'][] = array(
                    'order_id'    => $order_id,
                    'action_type' => 'purchase',
                    'status'      => 'failed',
                    'message'     => '[Achat] ' . ($new_status ?: 'Échec de transmission'),
                );
            }
        }

        return $stats;
    }

    /**
     * Safe hook wrapper for single-argument Purchase status hooks (prevents extra hook args from acting as $force).
     */
    public function on_order_status_trigger($order_id)
    {
        $this->trigger_conversion($order_id, false);
    }

    public function on_order_status_changed($order_id, $old_status, $new_status, $order = null)
    {
        $this->trigger_conversion($order ?: $order_id, false);
    }

    /**
     * Safe hook wrapper for Lead / Quote order creation & status hooks.
     */
    public function on_lead_order_hook($order_id_or_order)
    {
        $this->trigger_lead_conversion($order_id_or_order, false);
    }

    public function on_lead_status_changed($order_id, $old_status, $new_status, $order = null)
    {
        $this->trigger_lead_conversion($order ?: $order_id, false);
    }

    /**
     * Resolve Consent Mode v2 state for an order (live $_COOKIE first if front-end, order meta fallback).
     * Also normalizes and saves `_gads_consent` ('granted' / 'denied') for WP Agent Bridge compatibility.
     *
     * @param \WC_Order  $order
     * @param int        $order_id
     * @param array|null $settings
     * @return array{marketing_consent: bool, consent_log_msg: string}
     */
    private function resolve_order_consent($order, $order_id, $settings)
    {
        $is_builtin = !empty($settings['enable_builtin_banner']);
        $consent_cookie = $is_builtin ? 'woo_gads_consent' : (isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name']) ? $settings['consent_cookie_name'] : 'concord_consent');
        $marketing_consent = false;

        $cookie_value = null;
        if (!is_admin() && isset($_COOKIE[$consent_cookie]) && !empty($_COOKIE[$consent_cookie])) {
            $cookie_value = stripslashes(urldecode($_COOKIE[$consent_cookie]));
        } elseif (!is_admin() && !empty($_COOKIE['woo_gads_consent'])) {
            $cookie_value = stripslashes(urldecode($_COOKIE['woo_gads_consent']));
        } elseif (!is_admin() && !empty($_COOKIE['concord_consent'])) {
            $cookie_value = stripslashes(urldecode($_COOKIE['concord_consent']));
        } elseif (!is_admin()) {
            if (strpos($consent_cookie, 'concord-allow-state-') === 0) {
                foreach ($_COOKIE as $key => $val) {
                    if (strpos($key, 'concord-allow-state-') === 0) {
                        $cookie_value = stripslashes(urldecode($val));
                        break;
                    }
                }
            }
        }

        if (!$cookie_value) {
            $meta_cookie = self::get_order_meta_dual($order, $order_id, '_woo_gads_consent');
            if ($meta_cookie === '') {
                $meta_cookie = self::get_order_meta_dual($order, $order_id, '_concord_consent');
            }
            if ($meta_cookie !== '' && $meta_cookie !== 'no_cookie_found') {
                $cookie_value = stripslashes(urldecode(html_entity_decode($meta_cookie, ENT_QUOTES)));
            }
        }

        if ($cookie_value) {
            $marketing_consent = self::parse_consent_value($cookie_value);
        }

        // Ensure normalized _gads_consent meta is stored for WP Agent Bridge tracking/audit
        $norm_consent = $marketing_consent ? 'granted' : 'denied';
        self::update_order_meta_dual($order, $order_id, '_gads_consent', $norm_consent);

        if ($marketing_consent) {
            $consent_log_msg = 'Envoi avec données clients (GRANTED)';
        } elseif ($cookie_value && $cookie_value !== 'no_cookie_found') {
            $consent_log_msg = 'Envoi anonymisé (DENIED - Refusé par l\'utilisateur)';
        } else {
            $consent_log_msg = $is_builtin ? 'Envoi anonymisé (DENIED - Sans choix visiteur / Par défaut)' : 'Envoi anonymisé (DENIED - Cookie absent)';
        }

        return array(
            'marketing_consent' => $marketing_consent,
            'consent_log_msg'   => $consent_log_msg,
        );
    }

    /**
     * Trigger a Purchase conversion ("Achats") for an order.
     *
     * Strictly enforces Lock A: an order using a quote/lead payment method (e.g. 'we_quote_request')
     * is NEVER sent as a Purchase unless it is genuinely paid ($order->is_paid() or get_date_paid() non-null),
     * even when $force = true. Never touches `_gads_api_sent` on an unpaid quote.
     *
     * @param int|\WC_Order $order_id_or_order
     * @param bool          $force
     * @return array
     */
    public function trigger_conversion($order_id_or_order, $force = false)
    {
        $force = ($force === true);

        $order = ($order_id_or_order instanceof \WC_Order) ? $order_id_or_order : wc_get_order($order_id_or_order);
        if (!$order || !is_a($order, 'WC_Order') || is_a($order, 'WC_Order_Refund')) {
            return array('success' => false, 'status' => 'order_not_found');
        }

        $order_id = (int) $order->get_id();
        $settings = get_option('woo_gads_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }

        // LOCK A (Highest Priority): Never send an unpaid quote/lead order as a Purchase, even if $force === true.
        // Never touch `_gads_api_sent` so that if the quote is genuinely paid later, the real sale can still be sent.
        if (self::is_unpaid_lead_order($order, $settings)) {
            self::update_order_meta_dual($order, $order_id, '_gads_api_status', 'Ignoré (devis, non payé)');
            return array(
                'success' => false,
                'status'  => 'skipped_unpaid_lead',
                'message' => 'Ignoré (devis, non payé)',
            );
        }

        // Prevent duplicate sending (runtime lock + persistent meta)
        if (!$force && !empty(self::$purchases_sent_in_request[$order_id])) {
            return array('success' => true, 'status' => 'already_sent');
        }

        $already_sent = self::get_order_meta_dual($order, $order_id, '_gads_api_sent');
        if ($already_sent === '1' && !$force) {
            return array('success' => true, 'status' => 'already_sent');
        }

        // Production environment guard
        if (!self::is_production_environment($order, 'purchase')) {
            self::update_order_meta_dual($order, $order_id, '_gads_api_status', 'Ignoré (Hors production)');
            return array('success' => false, 'status' => 'skipped_non_production');
        }

        if (!$force) {
            $target_statuses = self::get_target_statuses($settings);
            $current_status = $order->get_status();

            if (!in_array($current_status, $target_statuses, true)) {
                return array('success' => false, 'status' => 'ineligible_status');
            }
        }

        $gclid = self::get_order_click_id($order, 'gclid');
        $wbraid = self::get_order_click_id($order, 'wbraid');
        $gbraid = self::get_order_click_id($order, 'gbraid');

        if (empty($gclid) && empty($wbraid) && empty($gbraid)) {
            self::update_order_meta_dual($order, $order_id, '_gads_api_status', 'Ignoré (Aucun identifiant de clic)');
            return array('success' => false, 'status' => 'skipped_no_click_id');
        }

        $consent_info = $this->resolve_order_consent($order, $order_id, $settings);
        $marketing_consent = $consent_info['marketing_consent'];
        $consent_log_msg = $consent_info['consent_log_msg'];

        $developer_token = isset($settings['developer_token']) ? trim((string) $settings['developer_token']) : '';
        $merchant_id = isset($settings['merchant_id']) ? preg_replace('/[^0-9]/', '', (string) $settings['merchant_id']) : '';
        $conversion_action_id = isset($settings['conversion_action_id']) ? trim((string) $settings['conversion_action_id']) : '';
        $resource_name = self::format_conversion_action_resource($merchant_id, $conversion_action_id);

        if ($developer_token === '' || $merchant_id === '' || $resource_name === '') {
            self::update_order_meta_dual($order, $order_id, '_gads_api_status', 'Erreur de configuration');
            return array('success' => false, 'status' => 'config_error');
        }

        $validate_only = self::is_validate_only($settings, $order, 'purchase');
        $payload = $this->build_payload($order, $merchant_id, $conversion_action_id, $marketing_consent, array(
            'action_type'   => 'purchase',
            'validate_only' => $validate_only,
        ));

        if (!$payload) {
            self::update_order_meta_dual($order, $order_id, '_gads_api_status', 'Ignoré (Aucun identifiant de clic)');
            return array('success' => false, 'status' => 'skipped_no_click_id');
        }

        return $this->dispatch_conversion_payload($order, $order_id, $payload, $settings, $consent_log_msg, 'purchase', $validate_only);
    }

    /**
     * Trigger a Quote / Lead conversion ("Demande de devis") for an order using a lead payment method.
     *
     * - Uses isolated metas: `_gads_lead_sent`, `_gads_lead_status`, `_gads_lead_value`.
     * - NEVER writes or deletes `_gads_api_sent`.
     * - Sets `orderId = "devis-{order_id}"` and `conversionValue = min(total_ttc * ratio, cap)`.
     * - Skips sending a lead if the order has already become a paid sale (`is_order_really_paid`).
     *
     * @param int|\WC_Order $order_id_or_order
     * @param bool          $force
     * @return array
     */
    public function trigger_lead_conversion($order_id_or_order, $force = false)
    {
        $force = ($force === true);

        $order = ($order_id_or_order instanceof \WC_Order) ? $order_id_or_order : wc_get_order($order_id_or_order);
        if (!$order || !is_a($order, 'WC_Order') || is_a($order, 'WC_Order_Refund')) {
            return array('success' => false, 'status' => 'order_not_found');
        }

        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return array('success' => false, 'status' => 'order_not_saved');
        }

        $settings = get_option('woo_gads_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }

        if (!self::is_lead_order($order, $settings)) {
            return array('success' => false, 'status' => 'not_lead_order');
        }

        // If unpaid, ensure Purchase status explicitly reflects Lock A without touching _gads_api_sent
        if (!self::is_order_really_paid($order)) {
            $purchase_sent = self::get_order_meta_dual($order, $order_id, '_gads_api_sent');
            if ($purchase_sent !== '1') {
                self::update_order_meta_dual($order, $order_id, '_gads_api_status', 'Ignoré (devis, non payé)');
            }
        } elseif (!$force) {
            // Quote became a paid order -> Purchase conversion handles the real sale; never re-send lead
            return array('success' => false, 'status' => 'already_paid_order');
        }

        // Feature toggle check
        if (empty($settings['enable_lead_conversion']) || (string) $settings['enable_lead_conversion'] === '0') {
            return array('success' => false, 'status' => 'lead_disabled');
        }

        // Deduplication (runtime lock + persistent _gads_lead_sent meta)
        if (!$force && !empty(self::$leads_sent_in_request[$order_id])) {
            return array('success' => true, 'status' => 'already_sent');
        }

        $lead_already_sent = self::get_order_meta_dual($order, $order_id, '_gads_lead_sent');
        if ($lead_already_sent === '1' && !$force) {
            return array('success' => true, 'status' => 'already_sent');
        }

        // Production environment guard
        if (!self::is_production_environment($order, 'lead')) {
            self::update_order_meta_dual($order, $order_id, '_gads_lead_status', 'Ignoré (Hors production)');
            return array('success' => false, 'status' => 'skipped_non_production');
        }

        if (!$force) {
            $lead_statuses = self::get_lead_target_statuses($settings);
            $current_status = $order->get_status();

            if (!in_array($current_status, $lead_statuses, true)) {
                return array('success' => false, 'status' => 'ineligible_status');
            }
        }

        $gclid = self::get_order_click_id($order, 'gclid');
        $wbraid = self::get_order_click_id($order, 'wbraid');
        $gbraid = self::get_order_click_id($order, 'gbraid');

        if (empty($gclid) && empty($wbraid) && empty($gbraid)) {
            self::update_order_meta_dual($order, $order_id, '_gads_lead_status', 'Ignoré (Aucun identifiant de clic)');
            return array('success' => false, 'status' => 'skipped_no_click_id');
        }

        $lead_value = self::calculate_lead_value($order, $settings);
        self::update_order_meta_dual($order, $order_id, '_gads_lead_value', number_format($lead_value, 2, '.', ''));

        $consent_info = $this->resolve_order_consent($order, $order_id, $settings);
        $marketing_consent = $consent_info['marketing_consent'];
        $consent_log_msg = $consent_info['consent_log_msg'];

        $developer_token = isset($settings['developer_token']) ? trim((string) $settings['developer_token']) : '';
        $merchant_id = isset($settings['merchant_id']) ? preg_replace('/[^0-9]/', '', (string) $settings['merchant_id']) : '';
        $lead_action_id = isset($settings['lead_conversion_action_id']) ? trim((string) $settings['lead_conversion_action_id']) : '';
        $resource_name = self::format_conversion_action_resource($merchant_id, $lead_action_id);

        if ($developer_token === '' || $merchant_id === '' || $resource_name === '') {
            self::update_order_meta_dual($order, $order_id, '_gads_lead_status', 'Erreur de configuration (Devis)');
            return array('success' => false, 'status' => 'config_error');
        }

        $validate_only = self::is_validate_only($settings, $order, 'lead');
        $payload = $this->build_payload($order, $merchant_id, $lead_action_id, $marketing_consent, array(
            'action_type'      => 'lead',
            'conversion_value' => $lead_value,
            'order_id_prefix'  => 'devis-',
            'validate_only'    => $validate_only,
        ));

        if (!$payload) {
            self::update_order_meta_dual($order, $order_id, '_gads_lead_status', 'Ignoré (Aucun identifiant de clic)');
            return array('success' => false, 'status' => 'skipped_no_click_id');
        }

        return $this->dispatch_conversion_payload($order, $order_id, $payload, $settings, $consent_log_msg, 'lead', $validate_only);
    }

    /**
     * Shared Google Ads API HTTP transport, OAuth 401 auto-refresh, partialFailureError handling,
     * Click ID-only fallback, database logging, and isolated order meta persistence.
     *
     * @param \WC_Order $order
     * @param int       $order_id
     * @param array     $payload
     * @param array     $settings
     * @param string    $consent_log_msg
     * @param string    $action_type   'purchase' or 'lead'.
     * @param bool      $validate_only Whether validateOnly mode is active.
     * @return array
     */
    private function dispatch_conversion_payload($order, $order_id, $payload, $settings, $consent_log_msg, $action_type = 'purchase', $validate_only = false)
    {
        $is_lead = ($action_type === 'lead');
        $sent_meta_key = $is_lead ? '_gads_lead_sent' : '_gads_api_sent';
        $status_meta_key = $is_lead ? '_gads_lead_status' : '_gads_api_status';
        $mode_suffix = $validate_only ? ' [validateOnly]' : '';

        $developer_token = isset($settings['developer_token']) ? trim((string) $settings['developer_token']) : '';
        $merchant_id = isset($settings['merchant_id']) ? preg_replace('/[^0-9]/', '', (string) $settings['merchant_id']) : '';
        $manager_id = isset($settings['manager_id']) ? preg_replace('/[^0-9]/', '', (string) $settings['manager_id']) : '';

        // Get Access Token
        $oauth = new Woo_Gads_Oauth();
        $access_token = $oauth->get_access_token();

        if (!$access_token) {
            Woo_Gads_Db::insert_log($order_id, 0, 'N/A', 'N/A', 'OAuth Access Token missing', 'error', $consent_log_msg . $mode_suffix, $action_type);
            self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Erreur OAuth');
            $this->send_error_email($order_id, 'Token OAuth manquant ou expiré. Veuillez vérifier votre connexion dans les réglages du plugin.', $action_type);
            return array('success' => false, 'status' => 'oauth_error');
        }

        // Send to API
        $url = "https://googleads.googleapis.com/v23/customers/{$merchant_id}:uploadClickConversions";

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'developer-token' => $developer_token,
            'Content-Type' => 'application/json',
        );

        if (!empty($manager_id)) {
            $headers['login-customer-id'] = $manager_id;
        }

        $args = array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'method' => 'POST',
            'timeout' => 30,
        );

        $response = wp_remote_post($url, $args);
        $http_status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Auto-recovery on 401 Unauthorized (expired or desynchronized cached transient)
        if (!is_wp_error($response) && $http_status === 401) {
            delete_transient('woo_gads_access_token');
            $new_access_token = $oauth->get_access_token(true);
            if ($new_access_token) {
                $args['headers']['Authorization'] = 'Bearer ' . $new_access_token;
                $response = wp_remote_post($url, $args);
                $http_status = (int) wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
            }
        }

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            Woo_Gads_Db::insert_log($order_id, 0, $payload, 'N/A', $consent_log_msg . $mode_suffix . ' | Erreur HTTP: ' . $error_message, 'error', $consent_log_msg . $mode_suffix, $action_type);
            self::update_order_meta_dual($order, $order_id, $sent_meta_key, 'Failed: ' . $error_message);
            self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Erreur HTTP: ' . substr($error_message, 0, 50));
            $this->send_error_email($order_id, 'Erreur de requête HTTP cURL/WordPress : ' . $error_message, $action_type);
            $this->check_consecutive_missing_cookies();
            return array('success' => false, 'status' => 'http_error', 'message' => $error_message);
        }

        $body_decoded = !empty($body) ? json_decode($body, true) : null;
        $is_http_2xx = ($http_status >= 200 && $http_status < 300);
        $has_partial_failure = ($is_http_2xx && is_array($body_decoded) && !empty($body_decoded['partialFailureError']));

        if ($has_partial_failure) {
            $pf_error = $body_decoded['partialFailureError'];
            $pf_msg = isset($pf_error['message']) ? $pf_error['message'] : 'Erreur de conversion partielle';
            if (!empty($pf_error['details']) && is_array($pf_error['details'])) {
                foreach ($pf_error['details'] as $detail) {
                    if (!empty($detail['errors']) && is_array($detail['errors'])) {
                        foreach ($detail['errors'] as $err) {
                            if (!empty($err['message'])) {
                                $pf_msg .= ' | ' . $err['message'];
                            }
                        }
                    }
                }
            }

            // 1. Resilient fallback: if Google Ads rejects userIdentifiers because Customer Data Terms are not yet accepted,
            // immediately retry with Click ID only (GCLID/WBRAID/GBRAID) so zero conversion is lost.
            $is_terms_error = (stripos($pf_msg, 'CUSTOMER_NOT_ACCEPTED_CUSTOMER_DATA_TERMS') !== false || stripos($pf_msg, 'customer data processing terms') !== false);
            if ($is_terms_error && !empty($payload['conversions'][0]['userIdentifiers'])) {
                $fallback_payload = $payload;
                unset($fallback_payload['conversions'][0]['userIdentifiers']);

                $fb_response = wp_remote_post($url, array(
                    'method'  => 'POST',
                    'headers' => $headers,
                    'body'    => wp_json_encode($fallback_payload),
                    'timeout' => 20,
                ));

                if (!is_wp_error($fb_response)) {
                    $fb_http_status = (int) wp_remote_retrieve_response_code($fb_response);
                    $fb_body = wp_remote_retrieve_body($fb_response);
                    $fb_body_decoded = !empty($fb_body) ? json_decode($fb_body, true) : null;
                    $fb_is_2xx = ($fb_http_status >= 200 && $fb_http_status < 300);
                    $fb_has_partial = ($fb_is_2xx && is_array($fb_body_decoded) && !empty($fb_body_decoded['partialFailureError']));

                    if ($fb_is_2xx && !$fb_has_partial) {
                        $fb_log_msg = $consent_log_msg . $mode_suffix . ' | Succès (Repli Click ID seul - Conditions données client non validées)';
                        Woo_Gads_Db::insert_log($order_id, $fb_http_status, $fallback_payload, $fb_body_decoded, '', 'success', $fb_log_msg, $action_type);

                        if ($is_lead) {
                            self::$leads_sent_in_request[$order_id] = true;
                        } else {
                            self::$purchases_sent_in_request[$order_id] = true;
                        }
                        self::update_order_meta_dual($order, $order_id, $sent_meta_key, '1');
                        self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Succès (Repli Click ID seul)' . $mode_suffix);

                        $this->send_error_email($order_id, 'Alerte Google Ads : La commande #' . $order_id . ' a été transmise avec succès grâce à son Click ID (GCLID/WBRAID/GBRAID), mais les données client (email, téléphone) ont été rejetées car les Conditions relatives au traitement des données client ne sont pas encore validées dans votre compte Google Ads (Objectifs > Conversions > Paramètres des conversions). Veuillez vous rendre dans Google Ads pour les valider afin d\'activer les conversions améliorées.', $action_type);
                        $this->check_consecutive_missing_cookies();
                        return array('success' => true, 'status' => 'success_click_id_fallback');
                    }
                }
            }

            // 2. Expired click (prior to Google Ads conversion window)
            $is_click_window_expired = (
                stripos($pf_msg, 'click-through window') !== false ||
                stripos($pf_msg, 'CLICK_BEFORE_CONVERSION_CLICK_THROUGH_WINDOW') !== false ||
                stripos($pf_msg, 'EVENT_DATE_BEFORE_CLICK_THROUGH_WINDOW') !== false ||
                stripos($pf_msg, 'EXPIRED_GCLID') !== false ||
                stripos($pf_msg, 'occurred before this conversion') !== false
            );
            if ($is_click_window_expired) {
                $log_msg = $consent_log_msg . $mode_suffix . ' | Ignoré par Google Ads : Clic antérieur à la fenêtre de conversion (Expiré)';
                Woo_Gads_Db::insert_log($order_id, $http_status, $payload, $body_decoded, '', 'ignored', $log_msg, $action_type);

                if ($is_lead) {
                    self::$leads_sent_in_request[$order_id] = true;
                } else {
                    self::$purchases_sent_in_request[$order_id] = true;
                }
                self::update_order_meta_dual($order, $order_id, $sent_meta_key, '1');
                self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Ignoré (Clic expiré - hors fenêtre d\'attribution)' . $mode_suffix);

                $this->check_consecutive_missing_cookies();
                return array('success' => true, 'status' => 'expired_click', 'message' => 'Clic expiré (antérieur à la fenêtre de conversion)');
            }

            // 3. Conversion already exists in Google Ads
            $is_already_exists = (
                stripos($pf_msg, 'CLICK_CONVERSION_ALREADY_EXISTS') !== false ||
                stripos($pf_msg, 'CONVERSION_ALREADY_EXISTS') !== false ||
                stripos($pf_msg, 'already exists') !== false
            );
            if ($is_already_exists) {
                $log_msg = $consent_log_msg . $mode_suffix . ' | Succès : Conversion déjà enregistrée dans Google Ads';
                Woo_Gads_Db::insert_log($order_id, $http_status, $payload, $body_decoded, '', 'success', $log_msg, $action_type);

                if ($is_lead) {
                    self::$leads_sent_in_request[$order_id] = true;
                } else {
                    self::$purchases_sent_in_request[$order_id] = true;
                }
                self::update_order_meta_dual($order, $order_id, $sent_meta_key, '1');
                self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Succès (Déjà enregistrée dans Google Ads)' . $mode_suffix);

                $this->check_consecutive_missing_cookies();
                return array('success' => true, 'status' => 'already_exists');
            }

            // 4. Click not found in Google Ads (test/synthetic click, bot, or > 90 days)
            $is_click_not_found = (
                stripos($pf_msg, 'CLICK_NOT_FOUND') !== false ||
                stripos($pf_msg, 'The click for this conversion was not found') !== false
            );
            if ($is_click_not_found) {
                $log_msg = $consent_log_msg . $mode_suffix . ' | Ignoré par Google Ads : Clic introuvable';
                Woo_Gads_Db::insert_log($order_id, $http_status, $payload, $body_decoded, '', 'ignored', $log_msg, $action_type);

                if ($is_lead) {
                    self::$leads_sent_in_request[$order_id] = true;
                } else {
                    self::$purchases_sent_in_request[$order_id] = true;
                }
                self::update_order_meta_dual($order, $order_id, $sent_meta_key, '1');
                self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Ignoré (Clic introuvable dans Google Ads)' . $mode_suffix);

                $this->check_consecutive_missing_cookies();
                return array('success' => false, 'status' => 'click_not_found', 'message' => 'Clic introuvable dans Google Ads');
            }

            // 5. Unexpected partial failure: log and send email alert
            $error_log_msg = $consent_log_msg . $mode_suffix . ' | Échec partiel : ' . $pf_msg;
            Woo_Gads_Db::insert_log($order_id, $http_status, $payload, $body_decoded, $error_log_msg, 'error', $consent_log_msg . $mode_suffix, $action_type);

            self::update_order_meta_dual($order, $order_id, $sent_meta_key, 'Failed Partial: ' . substr($pf_msg, 0, 100));
            self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Échec partiel - ' . substr($pf_msg, 0, 150));

            $this->send_error_email($order_id, 'Échec partiel Google Ads (HTTP 200 avec partialFailureError) : ' . $pf_msg, $action_type);
            $this->check_consecutive_missing_cookies();
            return array('success' => false, 'status' => 'partial_failure_error', 'message' => $pf_msg);
        } elseif ($is_http_2xx) {
            // HTTP 2xx success: status = 'success', error = '' (empty) for WP Agent Bridge compatibility
            Woo_Gads_Db::insert_log($order_id, $http_status, $payload, $body_decoded, '', 'success', $consent_log_msg . $mode_suffix, $action_type);

            if ($is_lead) {
                self::$leads_sent_in_request[$order_id] = true;
            } else {
                self::$purchases_sent_in_request[$order_id] = true;
            }
            self::update_order_meta_dual($order, $order_id, $sent_meta_key, '1');
            self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Succès' . $mode_suffix);
            $this->check_consecutive_missing_cookies();
            return array('success' => true, 'status' => 'success');
        } else {
            $error_details = 'Erreur API Google Ads (HTTP ' . $http_status . ').';
            if (!empty($body)) {
                if ($body_decoded && isset($body_decoded['error'])) {
                    if (isset($body_decoded['error']['message'])) {
                        $error_details .= ' Message : ' . $body_decoded['error']['message'];
                    }
                    if (isset($body_decoded['error']['details']) && is_array($body_decoded['error']['details'])) {
                        foreach ($body_decoded['error']['details'] as $detail) {
                            if (isset($detail['errors']) && is_array($detail['errors'])) {
                                foreach ($detail['errors'] as $err) {
                                    if (isset($err['message'])) {
                                        $error_details .= ' | Détail : ' . $err['message'];
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $error_details .= ' Détails bruts : ' . trim(substr(strip_tags($body), 0, 500));
                }
            }

            Woo_Gads_Db::insert_log($order_id, $http_status, $payload, $body_decoded, $consent_log_msg . $mode_suffix . ' | ' . $error_details, 'error', $consent_log_msg . $mode_suffix, $action_type);
            self::update_order_meta_dual($order, $order_id, $sent_meta_key, 'Failed HTTP: ' . $http_status);
            self::update_order_meta_dual($order, $order_id, $status_meta_key, 'Échec API (' . $http_status . ') - ' . substr($error_details, 0, 150));

            $this->send_error_email($order_id, $error_details, $action_type);
            $this->check_consecutive_missing_cookies();
            return array('success' => false, 'status' => 'api_error', 'message' => $error_details);
        }
    }

    /**
     * Build the Google Ads `uploadClickConversions` request payload for either a Purchase or a Lead/Quote.
     *
     * CRITICAL RULE (Incident v1.3.0 / AGENTS.md): NEVER remove the `$has_click_id` guard.
     * Conversions without GCLID, WBRAID, or GBRAID must never be sent to avoid 100% revenue/lead over-attribution.
     *
     * @param \WC_Order $order
     * @param string    $merchant_id
     * @param string    $conversion_action_id Numeric ID or full resource name.
     * @param bool      $marketing_consent
     * @param array     $options              Optional keys: 'action_type', 'conversion_value', 'order_id_prefix', 'validate_only'.
     * @return array|false
     */
    private function build_payload($order, $merchant_id, $conversion_action_id, $marketing_consent, $options = array())
    {
        $order_id = $order->get_id();
        $gclid = self::get_order_click_id($order, 'gclid');
        $wbraid = self::get_order_click_id($order, 'wbraid');
        $gbraid = self::get_order_click_id($order, 'gbraid');

        $has_click_id = !empty($gclid) || !empty($wbraid) || !empty($gbraid);

        if (!$has_click_id) {
            return false;
        }

        $resource_name = self::format_conversion_action_resource($merchant_id, $conversion_action_id);
        if ($resource_name === '') {
            return false;
        }

        // Use real order creation timestamp for accurate historical attribution in Google Ads
        $date_created = $order->get_date_created();
        $conversion_timestamp = $date_created ? $date_created->getTimestamp() : time();
        $conversion_date_time = gmdate('Y-m-d H:i:s+00:00', $conversion_timestamp);

        $conversion_value = isset($options['conversion_value']) ? round((float) $options['conversion_value'], 2) : (float) $order->get_total();
        $order_id_prefix = isset($options['order_id_prefix']) ? (string) $options['order_id_prefix'] : '';
        $currency = method_exists($order, 'get_currency') ? (string) $order->get_currency() : '';
        if ($currency === '') {
            $currency = 'EUR';
        }

        $conversion = array(
            'conversionAction'   => $resource_name,
            'conversionDateTime' => $conversion_date_time,
            'conversionValue'    => $conversion_value,
            'currencyCode'       => $currency,
            'orderId'            => $order_id_prefix . (string) $order_id,
        );

        if (!empty($gclid)) {
            $conversion['gclid'] = $gclid;
        }
        if (!empty($wbraid)) {
            $conversion['wbraid'] = $wbraid;
        }
        if (!empty($gbraid)) {
            $conversion['gbraid'] = $gbraid;
        }

        // Consent Mode v2 Object
        $conversion['consent'] = array(
            'adUserData' => $marketing_consent ? 'GRANTED' : 'DENIED',
            'adPersonalization' => $marketing_consent ? 'GRANTED' : 'DENIED'
        );

        // Enhanced Conversions User Data (Only if Consent is GRANTED)
        if ($marketing_consent) {
            $user_identifier = array();

            $email = method_exists($order, 'get_billing_email') ? $order->get_billing_email() : '';
            if (!empty($email)) {
                $user_identifier[] = array(
                    'userIdentifierSource' => 'FIRST_PARTY',
                    'hashedEmail' => hash('sha256', strtolower(trim($email)))
                );
            }

            $phone = method_exists($order, 'get_billing_phone') ? $order->get_billing_phone() : '';
            if (!empty($phone)) {
                $clean_phone = ltrim(trim($phone), '+');
                $user_identifier[] = array(
                    'userIdentifierSource' => 'FIRST_PARTY',
                    'hashedPhoneNumber' => hash('sha256', $clean_phone)
                );
            }

            $first_name = method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '';
            $last_name = method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '';

            if (!empty($first_name) && !empty($last_name)) {
                $address = array(
                    'hashedFirstName' => hash('sha256', strtolower(trim($first_name))),
                    'hashedLastName' => hash('sha256', strtolower(trim($last_name))),
                );

                $country = method_exists($order, 'get_billing_country') ? $order->get_billing_country() : '';
                $zip = method_exists($order, 'get_billing_postcode') ? $order->get_billing_postcode() : '';

                if (!empty($country) && !empty($zip)) {
                    $address['countryCode'] = $country;
                    $address['postalCode'] = $zip;
                    $user_identifier[] = array(
                        'userIdentifierSource' => 'FIRST_PARTY',
                        'addressInfo' => $address
                    );
                }
            }

            if (!empty($user_identifier)) {
                $conversion['userIdentifiers'] = $user_identifier;
            }
        }

        $request_body = array(
            'conversions'    => array($conversion),
            'partialFailure' => true,
        );

        if (!empty($options['validate_only'])) {
            $request_body['validateOnly'] = true;
        }

        return $request_body;
    }

    private function send_error_email($order_id, $error_message, $action_type = 'purchase')
    {
        $settings = get_option('woo_gads_settings');

        if (empty($settings['enable_email_alerts']) || $settings['enable_email_alerts'] !== '1') {
            return;
        }

        $to = !empty($settings['alert_email']) ? sanitize_email($settings['alert_email']) : get_option('admin_email');
        if (!is_email($to)) {
            return;
        }

        $is_lead = ($action_type === 'lead');
        $type_label = $is_lead ? 'Demande de devis' : 'Achat';
        $subject = $is_lead
            ? ('Erreur Google Ads Server-Side (Devis) - Commande #' . $order_id)
            : ('Erreur Google Ads Server-Side - Commande #' . $order_id);

        $message = "Bonjour,\n\n";
        $message .= "Une erreur est survenue lors de l'envoi de la conversion (" . $type_label . ") pour la commande #" . $order_id . " à l'API Google Ads.\n\n";
        $message .= "Détails de l'erreur :\n" . $error_message . "\n\n";
        $message .= "Vous pouvez consulter le tableau de bord (onglet Diagnostic de l'API) pour plus d'informations et réessayer l'envoi manuellement.\n\n";
        $message .= "Cordialement,\nLe Plugin Woo Google Ads Server-Side";

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        wp_mail($to, $subject, $message, $headers);
    }

    private function check_consecutive_missing_cookies()
    {
        $settings = get_option('woo_gads_settings');
        // Ne pas alerter si la bannière native est active (le visiteur qui n'interagit pas est anonymisé par défaut, ce n'est pas une anomalie de script)
        if (!empty($settings['enable_builtin_banner'])) {
            return;
        }

        // Avoid sending multiple alerts within 24 hours
        if (get_transient('woo_gads_cookie_alert_sent')) {
            return;
        }

        // Retrieve last 6 logs
        $logs = Woo_Gads_Db::get_logs(6);

        // We need at least 6 logs to alert
        if (count($logs) < 6) {
            return;
        }

        $missing_count = 0;
        foreach ($logs as $log) {
            $log_text = (!empty($log->message) ? $log->message : '') . ' ' . (!empty($log->error) ? $log->error : '');
            if (strpos($log_text, 'Cookie absent') !== false) {
                $missing_count++;
            }
        }

        // If all of the last 6 logs show "Cookie absent"
        if ($missing_count === 6) {
            $this->send_cookie_alert_email();
            set_transient('woo_gads_cookie_alert_sent', '1', DAY_IN_SECONDS);
        }
    }

    private function send_cookie_alert_email()
    {
        $settings = get_option('woo_gads_settings');

        if (empty($settings['enable_email_alerts']) || $settings['enable_email_alerts'] !== '1') {
            return;
        }

        $to = !empty($settings['alert_email']) ? sanitize_email($settings['alert_email']) : get_option('admin_email');
        if (!is_email($to)) {
            return;
        }

        $subject = 'Alerte : Dysfonctionnement du cookie de consentement (Concord)';

        $message = "Bonjour,\n\n";
        $message .= "Le plugin Google Ads Server-Side a détecté que les 6 derniers envois de conversion ont été faits sans le cookie de consentement (Cookie absent).\n\n";
        $message .= "Cela signifie très probablement que le script de votre bannière de consentement (Concord) est manquant, inactif, ou que le nom du cookie configuré dans les réglages du plugin est incorrect.\n\n";
        $message .= "Actuellement, toutes les conversions associées à vos campagnes publicitaires sont envoyées anonymisées à Google Ads (DENIED), ce qui dégrade l'optimisation de vos enchères.\n\n";
        $message .= "Veuillez vous rendre sur l'onglet 'Diagnostic & Logs' du plugin dans l'administration de votre site pour exécuter le test en direct et identifier la cause.\n\n";
        $message .= "Cordialement,\nLe Plugin Woo Google Ads Server-Side";

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        wp_mail($to, $subject, $message, $headers);
    }
}
