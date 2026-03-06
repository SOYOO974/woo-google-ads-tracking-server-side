<?php

class Woo_Gads_Api
{

    public function trigger_conversion($order_id)
    {
        // Prevent duplicate sending
        $already_sent = get_post_meta($order_id, '_gads_api_sent', true);
        if ($already_sent === '1') {
            return;
        }

        $settings = get_option('woo_gads_settings');

        // Consent check
        $consent_cookie = isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name']) ? $settings['consent_cookie_name'] : 'concord_consent';
        if (!isset($_COOKIE[$consent_cookie]) || empty($_COOKIE[$consent_cookie])) {
            // No consent, no tracking
            return;
        }

        // Only process on the specified status to avoid double processing if order is completed from processing
        $target_status = isset($settings['order_status']) ? $settings['order_status'] : 'processing';
        $order = wc_get_order($order_id);

        if (!$order || $order->get_status() !== $target_status) {
            return;
        }

        // Extract settings
        $developer_token = isset($settings['developer_token']) ? $settings['developer_token'] : '';
        $merchant_id = isset($settings['merchant_id']) ? $settings['merchant_id'] : '';
        $conversion_action_id = isset($settings['conversion_action_id']) ? $settings['conversion_action_id'] : '';

        if (empty($developer_token) || empty($merchant_id) || empty($conversion_action_id)) {
            return;
        }

        // Get Access Token
        $oauth = new Woo_Gads_Oauth();
        $access_token = $oauth->get_access_token();

        if (!$access_token) {
            Woo_Gads_Db::insert_log($order_id, 0, 'N/A', 'N/A', 'OAuth Access Token missing');
            return;
        }

        // Build Payload
        $payload = $this->build_payload($order, $merchant_id, $conversion_action_id);

        if (!$payload) {
            // Missing essential click IDs
            return;
        }

        // Send to API
        $url = "https://googleads.googleapis.com/v17/customers/{$merchant_id}:uploadClickConversions";

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'developer-token' => $developer_token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
            'method' => 'POST',
            'timeout' => 30,
        );

        $response = wp_remote_post($url, $args);
        $http_status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            Woo_Gads_Db::insert_log($order_id, 0, $payload, 'N/A', $error_message);
            update_post_meta($order_id, '_gads_api_sent', 'Failed: ' . $error_message);
        } else {
            // Success or logical failure
            Woo_Gads_Db::insert_log($order_id, $http_status, $payload, json_decode($body, true), '');
            if ($http_status == 200) {
                update_post_meta($order_id, '_gads_api_sent', '1');
            } else {
                update_post_meta($order_id, '_gads_api_sent', 'Failed HTTP: ' . $http_status);
            }
        }
    }

    private function build_payload($order, $merchant_id, $conversion_action_id)
    {
        $order_id = $order->get_id();
        $gclid = get_post_meta($order_id, '_woo_gads_gclid', true);
        $wbraid = get_post_meta($order_id, '_woo_gads_wbraid', true);
        $gbraid = get_post_meta($order_id, '_woo_gads_gbraid', true);

        if (empty($gclid) && empty($wbraid) && empty($gbraid)) {
            return false;
        }

        $conversion = array(
            'conversionAction' => "customers/{$merchant_id}/conversionActions/{$conversion_action_id}",
            'conversionDateTime' => gmdate('Y-m-d H:i:s+00:00'),
            'conversionValue' => (float) $order->get_total(),
            'currencyCode' => $order->get_currency(),
            'orderId' => (string) $order_id,
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

        // Enhanced Conversions User Data
        $user_identifier = array();

        $email = $order->get_billing_email();
        if (!empty($email)) {
            $user_identifier[] = array(
                'hashedEmail' => hash('sha256', strtolower(trim($email)))
            );
        }

        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            $clean_phone = ltrim(trim($phone), '+');
            $user_identifier[] = array(
                'hashedPhoneNumber' => hash('sha256', $clean_phone)
            );
        }

        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();

        if (!empty($first_name) && !empty($last_name)) {
            $address = array(
                'hashedFirstName' => hash('sha256', strtolower(trim($first_name))),
                'hashedLastName' => hash('sha256', strtolower(trim($last_name))),
            );

            // Google Ads requires at least hashedFirstName, hashedLastName, countryCode, and postalCode for address if provided
            $country = $order->get_billing_country();
            $zip = $order->get_billing_postcode();

            if (!empty($country) && !empty($zip)) {
                $address['countryCode'] = $country;
                $address['postalCode'] = $zip;
                $user_identifier[] = array(
                    'addressInfo' => $address
                );
            }
        }

        if (!empty($user_identifier)) {
            $conversion['userIdentifiers'] = $user_identifier;
        }

        return array(
            'conversions' => array($conversion),
            'partialFailure' => true,
        );
    }
}
