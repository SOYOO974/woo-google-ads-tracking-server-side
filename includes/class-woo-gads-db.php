<?php

class Woo_Gads_Db
{
    private static $schema_checked = false;

    /**
     * Ensure the logs table has the `status` and `message` columns and migrate historical HTTP 2xx logs
     * so WP Agent Bridge (/wp-json/agent-bridge/v1/tracking/audit) accurately counts successes vs errors,
     * and backfill recent order click ID / consent metas.
     *
     * @param bool $force Force running the migration even if version matches.
     */
    public static function maybe_upgrade($force = false)
    {
        $installed_ver = get_option('woo_gads_db_version', '');
        $target_ver = defined('WOO_GADS_VERSION') ? WOO_GADS_VERSION : '1.5.5';

        if (!$force && $installed_ver === $target_ver) {
            return;
        }

        self::ensure_columns();
        self::migrate_historical_logs();
        self::backfill_recent_orders();

        update_option('woo_gads_db_version', $target_ver, true);
    }

    /**
     * Ensure `status` and `message` columns exist on wp_woo_gads_logs.
     */
    public static function ensure_columns()
    {
        if (self::$schema_checked) {
            return;
        }
        self::$schema_checked = true;

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $table_name = $wpdb->prefix . 'woo_gads_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);
        if (!is_array($columns)) {
            return;
        }

        if (!in_array('status', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN status varchar(32) NOT NULL DEFAULT 'success' AFTER http_status");
        }
        if (!in_array('message', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN message text NOT NULL AFTER status");
        }
    }

    /**
     * Repair historical HTTP 2xx rows where informational consent strings ("Envoi avec données clients (GRANTED)", etc.)
     * were stored in the `error` column and caused WP Agent Bridge to count them as errors.
     */
    public static function migrate_historical_logs()
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $table_name = $wpdb->prefix . 'woo_gads_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return;
        }

        // 1. Mark HTTP 2xx rows without error keywords as status = 'success' and move consent message out of `error`
        $wpdb->query(
            "UPDATE {$table_name}
             SET message = CASE WHEN (message = '' OR message IS NULL) THEN error ELSE message END,
                 status = CASE WHEN error LIKE '%Ignoré%' THEN 'ignored' ELSE 'success' END,
                 error = ''
             WHERE http_status >= 200 AND http_status < 300
               AND error != ''
               AND error NOT LIKE '%Échec%'
               AND error NOT LIKE '%Erreur%'
               AND error NOT LIKE '%Error%'
               AND error NOT LIKE '%Failed%'"
        );

        // 2. Ensure true error rows have status = 'error'
        $wpdb->query(
            "UPDATE {$table_name}
             SET status = 'error'
             WHERE http_status < 200 OR http_status >= 300 OR (error != '' AND error IS NOT NULL)"
        );
    }

    /**
     * Backfill recent orders with standardized meta keys (_woo_gads_gclid, _gads_gclid, _gclid, _gads_consent)
     * from existing order metas, WooCommerce native Order Attribution (_wc_order_attribution_session_entry),
     * and historical wp_woo_gads_logs payloads.
     */
    public static function backfill_recent_orders()
    {
        if (!function_exists('wc_get_orders') || !class_exists('Woo_Gads_Api')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_gads_logs';

        // 1. First recover click IDs and consent from wp_woo_gads_logs payloads for logged orders
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            $logs = $wpdb->get_results("SELECT order_id, payload FROM {$table_name} ORDER BY id DESC LIMIT 50");
            if (is_array($logs)) {
                foreach ($logs as $log) {
                    $order_id = (int) $log->order_id;
                    if ($order_id <= 0) {
                        continue;
                    }
                    $order = wc_get_order($order_id);
                    if (!$order || !is_a($order, 'WC_Order') || is_a($order, 'WC_Order_Refund')) {
                        continue;
                    }

                    $payload = maybe_unserialize($log->payload);
                    if (is_string($payload)) {
                        $decoded = json_decode($payload, true);
                        if (is_array($decoded)) {
                            $payload = $decoded;
                        }
                    }

                    $modified = false;
                    if (is_array($payload) && !empty($payload['conversions'][0]) && is_array($payload['conversions'][0])) {
                        $conv = $payload['conversions'][0];
                        foreach (array('gclid', 'wbraid', 'gbraid') as $param) {
                            if (!empty($conv[$param]) && is_string($conv[$param])) {
                                $val = trim($conv[$param]);
                                foreach (array("_woo_gads_{$param}", "_gads_{$param}", "_{$param}") as $meta_key) {
                                    if ($order->get_meta($meta_key) !== $val) {
                                        $order->update_meta_data($meta_key, $val);
                                        update_post_meta($order_id, $meta_key, $val);
                                        $modified = true;
                                    }
                                }
                            }
                        }

                        if (!empty($conv['consent']['adUserData'])) {
                            $consent_norm = (strtoupper($conv['consent']['adUserData']) === 'GRANTED') ? 'granted' : 'denied';
                            if ($order->get_meta('_gads_consent') !== $consent_norm) {
                                $order->update_meta_data('_gads_consent', $consent_norm);
                                update_post_meta($order_id, '_gads_consent', $consent_norm);
                                $modified = true;
                            }
                        }
                    }

                    if ($modified) {
                        $order->save();
                    }
                }
            }
        }

        // 2. Scan the 50 most recent orders to ensure all meta aliases (_woo_gads_gclid, _gads_gclid, _gclid, _gads_consent) are synchronized
        $orders = wc_get_orders(array(
            'limit'   => 50,
            'orderby' => 'date',
            'order'   => 'DESC',
            'type'    => 'shop_order',
        ));

        if (!is_array($orders)) {
            return;
        }

        foreach ($orders as $order) {
            if (!is_a($order, 'WC_Order') || is_a($order, 'WC_Order_Refund')) {
                continue;
            }
            $order_id = $order->get_id();
            $modified = false;

            // Resolve click IDs (also extracts from _wc_order_attribution_session_entry and backfills all 3 keys)
            Woo_Gads_Api::get_order_click_id($order, 'gclid');
            Woo_Gads_Api::get_order_click_id($order, 'wbraid');
            Woo_Gads_Api::get_order_click_id($order, 'gbraid');

            // Normalize _gads_consent if _woo_gads_consent exists
            $existing_gads_consent = $order->get_meta('_gads_consent');
            if (empty($existing_gads_consent)) {
                $raw_consent = $order->get_meta('_woo_gads_consent');
                if (empty($raw_consent)) {
                    $raw_consent = get_post_meta($order_id, '_woo_gads_consent', true);
                }
                if (!empty($raw_consent)) {
                    $is_granted = Woo_Gads_Api::parse_consent_value($raw_consent);
                    $norm = $is_granted ? 'granted' : 'denied';
                    $order->update_meta_data('_gads_consent', $norm);
                    update_post_meta($order_id, '_gads_consent', $norm);
                    $modified = true;
                }
            }

            if ($modified) {
                $order->save();
            }
        }
    }

    /**
     * Insert a conversion transmission log entry.
     *
     * For HTTP 2xx responses without error, `status` is set to `'success'` and `error` is kept empty (`''`)
     * so that WP Agent Bridge (`woo-get-data-for-ai`) accurately counts `success_count` instead of `error_count`.
     * Informational messages (such as "Envoi avec données clients (GRANTED)") are stored in `message`.
     *
     * @param int         $order_id    WooCommerce Order ID.
     * @param int         $http_status HTTP response status code.
     * @param mixed       $payload     Request payload sent to Google Ads API.
     * @param mixed       $response    Decoded API response body.
     * @param string      $error       Error details (empty on HTTP 2xx success).
     * @param string|null $status      Explicit status ('success', 'ignored', 'error'). Auto-detected if null.
     * @param string      $message     Informational/consent log message (e.g., "Envoi avec données clients (GRANTED)").
     */
    public static function insert_log($order_id, $http_status, $payload, $response, $error = '', $status = null, $message = '')
    {
        global $wpdb;
        self::ensure_columns();

        $http_code = (int) $http_status;
        $is_http_2xx = ($http_code >= 200 && $http_code < 300);

        if ($status === null) {
            if ($is_http_2xx) {
                $has_error_keyword = (
                    stripos($error, 'Échec') !== false ||
                    stripos($error, 'Erreur') !== false ||
                    stripos($error, 'Error') !== false ||
                    stripos($error, 'Failed') !== false
                );
                if (!$has_error_keyword) {
                    $status = (stripos($error, 'Ignoré') !== false) ? 'ignored' : 'success';
                    if (empty($message) && !empty($error)) {
                        $message = $error;
                    }
                    $error = '';
                } else {
                    $status = 'error';
                }
            } else {
                $status = 'error';
            }
        } elseif ($status === 'success' || $status === 'ignored') {
            if (empty($message) && !empty($error)) {
                $message = $error;
            }
            $error = '';
        }

        if (!isset($wpdb) || !is_object($wpdb)) {
            return array(
                'order_id'    => (int) $order_id,
                'http_status' => $http_code,
                'status'      => $status,
                'message'     => $message,
                'error'       => $error,
            );
        }

        $table_name = $wpdb->prefix . 'woo_gads_logs';

        $wpdb->insert(
            $table_name,
            array(
                'time'        => current_time('mysql'),
                'order_id'    => (int) $order_id,
                'http_status' => $http_code,
                'status'      => $status,
                'message'     => (string) $message,
                'payload'     => maybe_serialize($payload),
                'response'    => maybe_serialize($response),
                'error'       => (string) $error,
            )
        );

        self::cleanup_logs();

        return array(
            'order_id'    => (int) $order_id,
            'http_status' => $http_code,
            'status'      => $status,
            'message'     => $message,
            'error'       => $error,
        );
    }

    public static function get_logs($limit = 50)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_gads_logs';

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name ORDER BY id DESC LIMIT %d", $limit));
    }

    private static function cleanup_logs()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_gads_logs';

        // Keep only the last 50 logs. We find the 50th ID and delete everything smaller.
        $max_id_query = $wpdb->get_var("SELECT id FROM $table_name ORDER BY id DESC LIMIT 50, 1");

        if ($max_id_query) {
            $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE id <= %d", $max_id_query));
        }
    }

}
