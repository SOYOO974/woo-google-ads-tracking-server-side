<?php

class Woo_Gads_Oauth
{

    private $client_id;
    private $client_secret;
    private $refresh_token;

    public function __construct()
    {
        $settings = get_option('woo_gads_settings');
        $this->client_id = isset($settings['client_id']) ? $settings['client_id'] : '';
        $this->client_secret = isset($settings['client_secret']) ? $settings['client_secret'] : '';
        $this->refresh_token = isset($settings['refresh_token']) ? $settings['refresh_token'] : '';
    }

    public function get_access_token($force_refresh = false)
    {
        if (empty($this->client_id) || empty($this->client_secret) || empty($this->refresh_token)) {
            return false;
        }

        $transient_key = 'woo_gads_access_token';

        if (!$force_refresh) {
            $token = get_transient($transient_key);
            if ($token) {
                return $token;
            }
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $this->refresh_token,
                'grant_type' => 'refresh_token'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Woo GAds OAuth Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['access_token'])) {
            // Expires in is usually 3600 (1 hour). We cache it for 59 minutes just to be safe.
            set_transient($transient_key, $data['access_token'], 59 * MINUTE_IN_SECONDS);
            return $data['access_token'];
        }

        error_log('Woo GAds OAuth Error: Token not found in response - ' . $body);
        return false;
    }

    public function get_auth_url($redirect_uri)
    {
        if (empty($this->client_id)) {
            return false;
        }

        $params = array(
            'client_id' => $this->client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'access_type' => 'offline',
            'prompt' => 'consent'
        );

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function exchange_code_for_token($code, $redirect_uri)
    {
        if (empty($this->client_id) || empty($this->client_secret)) {
            return false;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $code,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return isset($data['refresh_token']) ? $data['refresh_token'] : false;
    }
}
