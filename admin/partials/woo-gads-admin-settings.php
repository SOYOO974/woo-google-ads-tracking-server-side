<?php
$settings = get_option('woo_gads_settings');
?>
<form method="post" action="options.php">
    <?php settings_fields('woo_gads_options'); ?>

    <table class="form-table">
        <tr>
            <th scope="row">Developer Token</th>
            <td>
                <input type="text" name="woo_gads_settings[developer_token]"
                    value="<?php echo esc_attr($settings['developer_token'] ?? ''); ?>" class="regular-text" />
                <a href="https://ads.google.com/aw/apicenter" target="_blank" class="button button-small"
                    style="margin-left:5px;">Aller au Centre API Google Ads</a>
            </td>
        </tr>
        <tr>
            <th scope="row">Merchant Customer ID (Google Ads ID)</th>
            <td>
                <input type="text" name="woo_gads_settings[merchant_id]"
                    value="<?php echo esc_attr($settings['merchant_id'] ?? ''); ?>" class="regular-text"
                    placeholder="ex: 1234567890 (sans tirets)" />
                <a href="https://ads.google.com/" target="_blank" class="button button-small"
                    style="margin-left:5px;">Ouvrir Google Ads</a>
            </td>
        </tr>
        <tr>
            <th scope="row">Manager Customer ID (MCC ID) [Optionnel]</th>
            <td>
                <input type="text" name="woo_gads_settings[manager_id]"
                    value="<?php echo esc_attr($settings['manager_id'] ?? ''); ?>" class="regular-text"
                    placeholder="ex: 9876543210 (sans tirets)" />
                <p class="description">Requis uniquement si l'email de connexion OAuth utilise un compte Administrateur (MCC) pour accéder au compte client Google Ads ci-dessus.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Conversion Action ID</th>
            <td>
                <input type="text" name="woo_gads_settings[conversion_action_id]"
                    value="<?php echo esc_attr($settings['conversion_action_id'] ?? ''); ?>" class="regular-text"
                    placeholder="ex: 123456" />
                <a href="https://ads.google.com/aw/conversions" target="_blank" class="button button-small"
                    style="margin-left:5px;">Voir les Conversions</a>
            </td>
        </tr>
        <tr>
            <th scope="row">Client ID OAuth</th>
            <td>
                <input type="text" name="woo_gads_settings[client_id]"
                    value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" class="regular-text" />
                <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="button button-small"
                    style="margin-left:5px;">Aller sur Google Cloud Console</a>
            </td>
        </tr>
        <tr>
            <th scope="row">Client Secret OAuth</th>
            <td>
                <input type="password" name="woo_gads_settings[client_secret]"
                    value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th scope="row">Refresh Token OAuth</th>
            <td>
                <input type="text" name="woo_gads_settings[refresh_token]" id="woo_gads_refresh_token"
                    value="<?php echo esc_attr($settings['refresh_token'] ?? ''); ?>" class="regular-text" />

                <?php
                $redirect_uri = admin_url('admin.php?page=woo-gads-server-side');
                $oauth = new Woo_Gads_Oauth();
                $auth_url = $oauth->get_auth_url($redirect_uri);
                ?>

                <div style="margin-top: 10px;">
                    <?php if ($auth_url): ?>
                        <a href="<?php echo esc_url($auth_url); ?>" class="button button-secondary">
                            <img src="https://www.gstatic.com/images/branding/product/1x/gsa_512dp.png" width="16"
                                style="vertical-align: middle; margin-right: 5px;">
                            Se connecter avec Google (Auto-Refresh Token)
                        </a>
                        <p class="description">
                            <strong>Important :</strong> Vous devez d'abord enregistrer votre Client ID et Client
                            Secret.<br>
                            <strong>URI de redirection à copier dans la console Google Cloud :</strong><br>
                            <code><?php echo esc_html($redirect_uri); ?></code>
                        </p>
                    <?php else: ?>
                        <p class="description" style="color: #d63638;">
                            Veuillez renseigner et enregistrer votre Client ID OAuth pour activer la connexion automatique.
                        </p>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <tr>
            <th scope="row">Nom du cookie de consentement (ex: Concord)</th>
            <td>
                <div style="margin-bottom: 10px;">
                    <button type="button" id="woo-gads-scan-cookies" class="button button-secondary">
                        <span class="dashicons dashicons-search"
                            style="vertical-align: middle; margin-top: 4px;"></span>
                        Scanner les cookies du domaine
                    </button>
                </div>

                <div id="woo-gads-cookie-selector-wrapper"
                    style="display: none; margin-bottom: 10px; padding: 15px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; border-left: 4px solid #2271b1;">
                    <p style="margin-top: 0;"><strong>Cookies détectés :</strong></p>
                    <select id="woo-gads-cookie-dropdown" style="max-width: 300px;"></select>
                    <p id="woo-gads-cookie-preview"
                        style="margin-bottom: 0; margin-top: 10px; font-size: 11px; font-family: monospace; word-break: break-all; color: #646970; background: #fff; padding: 5px; border: 1px solid #dcdcde;">
                        Sélectionnez un cookie pour voir sa valeur.
                    </p>
                </div>

                <input type="text" name="woo_gads_settings[consent_cookie_name]" id="woo_gads_consent_cookie_name"
                    value="<?php echo esc_attr($settings['consent_cookie_name'] ?? 'concord_consent'); ?>"
                    class="regular-text" />
                <p class="description">Le plugin vérifiera la présence de ce cookie avant d'envoyer la conversion.</p>

                <script type="text/javascript">
                    (function ($) {
                        $('#woo-gads-scan-cookies').on('click', function () {
                            var cookies = document.cookie.split('; ');
                            var $dropdown = $('#woo-gads-cookie-dropdown');
                            var $wrapper = $('#woo-gads-cookie-selector-wrapper');
                            var $input = $('#woo_gads_consent_cookie_name');
                            var $preview = $('#woo-gads-cookie-preview');

                            $dropdown.empty().append('<option value="">-- Sélectionnez un cookie --</option>');

                            var foundCookies = [];
                            for (var i = 0; i < cookies.length; i++) {
                                var parts = cookies[i].split('=');
                                var name = parts[0];
                                var value = parts.slice(1).join('=');
                                foundCookies.push({ name: name, value: value });
                            }

                            // Sorting logic: CMP keywords first
                            var keywords = ['concord', 'consent', 'cookie', 'complianz', 'gcs', 'gcd'];
                            foundCookies.sort(function (a, b) {
                                var aScore = 0, bScore = 0;
                                keywords.forEach(function (k) {
                                    if (a.name.toLowerCase().includes(k)) aScore++;
                                    if (b.name.toLowerCase().includes(k)) bScore++;
                                });
                                return bScore - aScore;
                            });

                            var concordCookie = null;
                            foundCookies.forEach(function (c) {
                                var isLikely = false;
                                keywords.forEach(function (k) { if (c.name.toLowerCase().includes(k)) isLikely = true; });

                                var label = c.name + (isLikely ? ' (Suggéré)' : '');
                                var $option = $('<option>').val(c.name).text(label).attr('data-value', c.value);
                                if (isLikely) $option.css('font-weight', 'bold');
                                $dropdown.append($option);

                                if (c.name.toLowerCase().includes('concord')) {
                                    concordCookie = c.name;
                                }
                            });

                            $wrapper.show();

                            if (concordCookie) {
                                $dropdown.val(concordCookie).trigger('change');
                            }
                        });

                        $('#woo-gads-cookie-dropdown').on('change', function () {
                            var name = $(this).val();
                            var value = $(this).find('option:selected').attr('data-value');
                            if (name) {
                                $('#woo_gads_consent_cookie_name').val(name);
                                $('#woo-gads-cookie-preview').text('Valeur brute : ' + value);
                            } else {
                                $('#woo-gads-cookie-preview').text('Sélectionnez un cookie pour voir sa valeur.');
                            }
                        });
                    })(jQuery);
                </script>
            </td>
        </tr>
        <tr>
            <th scope="row">Statut de commande déclencheur</th>
            <td>
                <select name="woo_gads_settings[order_status]">
                    <option value="on-hold" <?php selected($settings['order_status'] ?? 'processing', 'on-hold'); ?>>En attente (On hold)</option>
                    <option value="processing" <?php selected($settings['order_status'] ?? 'processing', 'processing'); ?>>En cours (Processing)</option>
                    <option value="completed" <?php selected($settings['order_status'] ?? 'processing', 'completed'); ?>>Terminée (Completed)</option>
                </select>
                <p class="description">Le statut de la commande WooCommerce à partir duquel l'API Google Ads sera
                    contactée.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Alertes Email</th>
            <td>
                <label>
                    <input type="checkbox" name="woo_gads_settings[enable_email_alerts]" value="1" <?php checked(isset($settings['enable_email_alerts']) ? $settings['enable_email_alerts'] : 0, 1); ?> />
                    Activer les alertes par email en cas d'erreur API
                </label>
                <div style="margin-top: 10px;">
                    <input type="email" name="woo_gads_settings[alert_email]" value="<?php echo esc_attr($settings['alert_email'] ?? get_option('admin_email')); ?>" class="regular-text" placeholder="Email pour les alertes" />
                    <p class="description">Email qui recevra les notifications d'échec de la transmission à Google Ads.</p>
                </div>
            </td>
        </tr>
    </table>

    <?php submit_button('Enregistrer les modifications'); ?>
</form>