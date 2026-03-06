<?php

class Woo_Gads_Activator
{

    public static function activate()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'woo_gads_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			order_id bigint(20) NOT NULL,
			http_status smallint(5) NOT NULL,
			payload text NOT NULL,
			response text NOT NULL,
			error text NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

}
