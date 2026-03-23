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

        // Only process on the specified status to avoid double processing if order is completed from processing
        $target_status = isset($settings['order_status']) ? $settings['order_status'] : 'processing';
        $order = wc_get_order($order_id);

        if (!$order || $order->get_status() !== $target_status) {
            return;
        }

        $gclid = get_post_meta($order_id, '_woo_gads_gclid', true);
        $wbraid = get_post_meta($order_id, '_woo_gads_wbraid', true);
        $gbraid = get_post_meta($order_id, '_woo_gads_gbraid', true);

        if (empty($gclid) && empty($wbraid) && empty($gbraid)) {
            update_post_meta($order_id, '_gads_api_status', 'Ignoré (Aucun identifiant de clic)');
            return;
        }

        // Consent Mode v2 check
        $consent_cookie = isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name']) ? $settings['consent_cookie_name'] : 'concord_consent';
        $marketing_consent = false;

        // Try to read from $_COOKIE first (if triggered synchronously during checkout)
        // Fallback to order meta (if triggered asynchronously via webhook)
        $cookie_value = null;
        if (isset($_COOKIE[$consent_cookie]) && !empty($_COOKIE[$consent_cookie])) {
            $cookie_value = stripslashes($_COOKIE[$consent_cookie]);
        } else {
            $meta_cookie = get_post_meta($order_id, '_woo_gads_consent', true);
            if ($meta_cookie && $meta_cookie !== 'no_cookie_found') {
                $cookie_value = html_entity_decode($meta_cookie, ENT_QUOTES); // Decode if sanitized as text field
            }
        }

        if ($cookie_value) {
            $cookie_data = json_decode($cookie_value, true);
            if (is_array($cookie_data) && isset($cookie_data['marketing']) && $cookie_data['marketing'] === true) {
                $marketing_consent = true;
            }
        }

        $consent_log_msg = $marketing_consent ? 'Envoi avec données clients (GRANTED)' : 'Envoi anonymisé (DENIED - Cookie absent/refusé)';

        // Extract settings
        $developer_token = isset($settings['developer_token']) ? $settings['developer_token'] : '';
        $merchant_id = isset($settings['merchant_id']) ? $settings['merchant_id'] : '';
        $conversion_action_id = isset($settings['conversion_action_id']) ? $settings['conversion_action_id'] : '';

        if (empty($developer_token) || empty($merchant_id) || empty($conversion_action_id)) {
            update_post_meta($order_id, '_gads_api_status', 'Erreur de configuration');
            return;
        }

        // Get Access Token
        $oauth = new Woo_Gads_Oauth();
        $access_token = $oauth->get_access_token();

        if (!$access_token) {
            Woo_Gads_Db::insert_log($order_id, 0, 'N/A', 'N/A', 'OAuth Access Token missing');
            update_post_meta($order_id, '_gads_api_status', 'Erreur OAuth');
            $this->send_error_email($order_id, 'Token OAuth manquant ou expiré. Veuillez vérifier votre connexion dans les réglages du plugin.');
            return;
        }

        // Build Payload
        $payload = $this->build_payload($order, $merchant_id, $conversion_action_id, $marketing_consent);

        if (!$payload) {
            // Missing essential click IDs
            update_post_meta($order_id, '_gads_api_status', 'Ignoré (Aucun identifiant de clic)');
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
            Woo_Gads_Db::insert_log($order_id, 0, $payload, 'N/A', $consent_log_msg . ' | Erreur HTTP: ' . $error_message);
            update_post_meta($order_id, '_gads_api_sent', 'Failed: ' . $error_message);
            update_post_meta($order_id, '_gads_api_status', 'Erreur HTTP: ' . substr($error_message, 0, 50));
            $this->send_error_email($order_id, 'Erreur de requête HTTP cURL/WordPress : ' . $error_message);
        } else {
            // Success or logical failure
            $error_col = ($http_status != 200) ? 'Erreur API' : '';
            Woo_Gads_Db::insert_log($order_id, $http_status, $payload, json_decode($body, true), $consent_log_msg . ($error_col ? ' | ' . $error_col : ''));
            if ($http_status == 200) {
                update_post_meta($order_id, '_gads_api_sent', '1');
                update_post_meta($order_id, '_gads_api_status', 'Succès');
            } else {
                update_post_meta($order_id, '_gads_api_sent', 'Failed HTTP: ' . $http_status);
                update_post_meta($order_id, '_gads_api_status', 'Échec API (' . $http_status . ')');
                
                $error_details = 'Erreur API Google Ads (HTTP ' . $http_status . ').';
                if (!empty($body)) {
                    $body_decoded = json_decode($body, true);
                    if (isset($body_decoded['error']['message'])) {
                        $error_details .= ' Détails : ' . $body_decoded['error']['message'];
                    }
                }
                $this->send_error_email($order_id, $error_details);
            }
        }
    }

    private function build_payload($order, $merchant_id, $conversion_action_id, $marketing_consent)
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

        // Consent Mode v2 Object
        $conversion['consent'] = array(
            'adUserData' => $marketing_consent ? 'GRANTED' : 'DENIED',
            'adPersonalization' => $marketing_consent ? 'GRANTED' : 'DENIED'
        );

        // Enhanced Conversions User Data (Only if Consent is GRANTED)
        if ($marketing_consent) {
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
        }

        return array(
            'conversions' => array($conversion),
            'partialFailure' => true,
        );
    }

    private function send_error_email($order_id, $error_message)
    {
        $settings = get_option('woo_gads_settings');
        
        if (empty($settings['enable_email_alerts']) || $settings['enable_email_alerts'] !== '1') {
            return;
        }

        $to = !empty($settings['alert_email']) ? sanitize_email($settings['alert_email']) : get_option('admin_email');
        if (!is_email($to)) {
            return;
        }

        $subject = 'Erreur Google Ads Server-Side - Commande #' . $order_id;
        
        $message = "Bonjour,\n\n";
        $message .= "Une erreur est survenue lors de l'envoi de la conversion pour la commande #" . $order_id . " à l'API Google Ads.\n\n";
        $message .= "Détails de l'erreur :\n" . $error_message . "\n\n";
        $message .= "Vous pouvez consulter le tableau de bord (onglet Diagnostic de l'API) pour plus d'informations et réessayer l'envoi manuellement.\n\n";
        $message .= "Cordialement,\nLe Plugin Woo Google Ads Server-Side";

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        // Prevent multiple emails for the same order if repeatedly retried rapidly (optional, but good practice).
        // For now, simple email sending.
        wp_mail($to, $subject, $message, $headers);
    }
}
