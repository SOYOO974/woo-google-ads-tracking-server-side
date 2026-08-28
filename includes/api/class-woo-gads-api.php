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
            // Fallback for prefix match if it's a concord-allow-state cookie
            if (strpos($consent_cookie, 'concord-allow-state-') === 0) {
                foreach ($_COOKIE as $key => $val) {
                    if (strpos($key, 'concord-allow-state-') === 0) {
                        $cookie_value = stripslashes($val);
                        break;
                    }
                }
            }
        }

        if (!$cookie_value) {
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

        $consent_log_msg = '';
        if ($marketing_consent) {
            $consent_log_msg = 'Envoi avec données clients (GRANTED)';
        } elseif ($cookie_value) {
            $consent_log_msg = 'Envoi anonymisé (DENIED - Refusé par l\'utilisateur)';
        } else {
            $consent_log_msg = 'Envoi anonymisé (DENIED - Cookie absent)';
        }

        // Extract settings
        $developer_token = isset($settings['developer_token']) ? $settings['developer_token'] : '';
        $merchant_id = isset($settings['merchant_id']) ? preg_replace('/[^0-9]/', '', $settings['merchant_id']) : '';
        $conversion_action_id = isset($settings['conversion_action_id']) ? $settings['conversion_action_id'] : '';
        $manager_id = isset($settings['manager_id']) ? preg_replace('/[^0-9]/', '', $settings['manager_id']) : '';

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
                        // Fallback pour les erreurs non-JSON (ex: page HTML 404 de Google)
                        $error_details .= ' Détails bruts : ' . trim(substr(strip_tags($body), 0, 500));
                    }
                }
                
                // Mettre à jour avec plus de contexte pour être visible dans le backoffice si on le souhaite
                update_post_meta($order_id, '_gads_api_status', 'Échec API (' . $http_status . ') - ' . substr($error_details, 0, 150));
                
                $this->send_error_email($order_id, $error_details);
            }
        }

        // Check if cookies have been missing consecutively in recent logs
        $this->check_consecutive_missing_cookies();
    }

    private function build_payload($order, $merchant_id, $conversion_action_id, $marketing_consent)
    {
        $order_id = $order->get_id();
        $gclid = get_post_meta($order_id, '_woo_gads_gclid', true);
        $wbraid = get_post_meta($order_id, '_woo_gads_wbraid', true);
        $gbraid = get_post_meta($order_id, '_woo_gads_gbraid', true);

        $has_click_id = !empty($gclid) || !empty($wbraid) || !empty($gbraid);

        if (!$has_click_id) {
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
                    'userIdentifierSource' => 'FIRST_PARTY',
                    'hashedEmail' => hash('sha256', strtolower(trim($email)))
                );
            }

            $phone = $order->get_billing_phone();
            if (!empty($phone)) {
                $clean_phone = ltrim(trim($phone), '+');
                $user_identifier[] = array(
                    'userIdentifierSource' => 'FIRST_PARTY',
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
                        'userIdentifierSource' => 'FIRST_PARTY',
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

    private function check_consecutive_missing_cookies()
    {
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
            if (strpos($log->error, 'Cookie absent') !== false) {
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
