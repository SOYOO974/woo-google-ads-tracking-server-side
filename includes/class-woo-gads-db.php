<?php

class Woo_Gads_Db
{

    public static function insert_log($order_id, $http_status, $payload, $response, $error = '')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_gads_logs';

        $wpdb->insert(
            $table_name,
            array(
                'time' => current_time('mysql'),
                'order_id' => $order_id,
                'http_status' => $http_status,
                'payload' => maybe_serialize($payload),
                'response' => maybe_serialize($response),
                'error' => $error,
            )
        );

        self::cleanup_logs();
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
