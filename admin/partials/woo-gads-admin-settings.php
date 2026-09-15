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
            <th scope="row">Bannière de consentement native</th>
            <td>
                <?php $is_builtin = !empty($settings['enable_builtin_banner']); ?>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px;">
                    <input type="checkbox" name="woo_gads_settings[enable_builtin_banner]" id="woo_gads_enable_builtin_banner" value="1" <?php checked($is_builtin); ?> />
                    <strong>Activer la bannière de consentement intégrée (Google Consent Mode v2)</strong>
                </label>
                <p class="description" style="margin-top: 5px;">
                    Affiche une bannière native ultra-légère (< 4 Ko) conforme RGPD/CNIL, qui envoie automatiquement les signaux <code>gtag('consent', 'update', ...)</code> au navigateur et gère le cookie <code>woo_gads_consent</code> sans nécessiter de plugin externe (Concord, Complianz, etc.).
                </p>

                <div id="woo-gads-builtin-banner-options" style="margin-top: 15px; padding: 15px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; border-left: 4px solid #2271b1; <?php echo $is_builtin ? '' : 'display:none;'; ?>">
                    <h4 style="margin-top: 0; margin-bottom: 10px;">Personnalisation de la bannière</h4>
                    
                    <p style="margin-bottom: 10px;">
                        <label for="woo_gads_banner_message"><strong>Message affiché :</strong></label><br>
                        <textarea name="woo_gads_settings[banner_message]" id="woo_gads_banner_message" rows="2" style="width: 100%; max-width: 600px;"><?php echo esc_textarea($settings['banner_message'] ?? "Nous utilisons des cookies pour assurer le bon fonctionnement du site, mesurer l'audience et personnaliser les publicités."); ?></textarea>
                    </p>
                    
                    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 15px;">
                        <div>
                            <label for="woo_gads_banner_accept_text"><strong>Bouton Accepter :</strong></label><br>
                            <input type="text" name="woo_gads_settings[banner_accept_text]" id="woo_gads_banner_accept_text" value="<?php echo esc_attr($settings['banner_accept_text'] ?? 'Accepter'); ?>" class="regular-text" style="max-width: 180px;" />
                        </div>
                        <div>
                            <label for="woo_gads_banner_decline_text"><strong>Bouton Refuser :</strong></label><br>
                            <input type="text" name="woo_gads_settings[banner_decline_text]" id="woo_gads_banner_decline_text" value="<?php echo esc_attr($settings['banner_decline_text'] ?? 'Refuser'); ?>" class="regular-text" style="max-width: 180px;" />
                        </div>
                        <div>
                            <label for="woo_gads_banner_accent_color"><strong>Couleur d'accent (Bouton Accepter) :</strong></label><br>
                            <div style="display: flex; align-items: center; gap: 8px; margin-top: 3px;">
                                <input type="color" id="woo_gads_banner_accent_color_picker" value="<?php echo esc_attr($settings['banner_accent_color'] ?? '#111827'); ?>" style="width: 36px; height: 32px; padding: 2px; border: 1px solid #8c8f94; border-radius: 4px; cursor: pointer;" />
                                <input type="text" name="woo_gads_settings[banner_accent_color]" id="woo_gads_banner_accent_color" value="<?php echo esc_attr($settings['banner_accent_color'] ?? '#111827'); ?>" class="regular-text" style="max-width: 110px;" placeholder="#111827" />
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 10px;">
                        <div>
                            <label for="woo_gads_banner_position"><strong>Position de la bannière :</strong></label><br>
                            <?php $pos = $settings['banner_position'] ?? 'bottom-right'; ?>
                            <select name="woo_gads_settings[banner_position]" id="woo_gads_banner_position" style="max-width: 220px;">
                                <option value="bottom-right" <?php selected($pos, 'bottom-right'); ?>>Flottant bas-droite (Recommandé)</option>
                                <option value="bottom-left" <?php selected($pos, 'bottom-left'); ?>>Flottant bas-gauche</option>
                                <option value="bottom-center" <?php selected($pos, 'bottom-center'); ?>>Centré en bas</option>
                            </select>
                        </div>
                    </div>

                    <p style="margin-bottom: 0;">
                        <label for="woo_gads_banner_privacy_url"><strong>Lien Politique de Confidentialité (optionnel) :</strong></label><br>
                        <input type="url" name="woo_gads_settings[banner_privacy_url]" id="woo_gads_banner_privacy_url" value="<?php echo esc_attr($settings['banner_privacy_url'] ?? (function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '')); ?>" class="regular-text" style="width: 100%; max-width: 450px;" placeholder="https://..." />
                        <span class="description" style="display: block; margin-top: 3px;">Si renseigné, un lien cliquable sera ajouté au message du bandeau.</span>
                    </p>
                </div>
            </td>
        </tr>
        <tr id="woo-gads-cookie-setting-row">
            <th scope="row">Nom du cookie de consentement</th>
            <td>
                <div id="woo-gads-builtin-active-notice" style="<?php echo $is_builtin ? '' : 'display:none;'; ?> margin-bottom: 10px; padding: 10px 15px; background: #e7f9ed; border: 1px solid #c3ebce; border-radius: 4px; color: #116633;">
                    <strong>Bannière native active :</strong> Le cookie <code>woo_gads_consent</code> est automatiquement utilisé et géré par le plugin.
                </div>

                <div id="woo-gads-external-cookie-fields" style="<?php echo $is_builtin ? 'display:none;' : ''; ?>">
                    <div style="margin-bottom: 10px;">
                        <button type="button" id="woo-gads-scan-cookies" class="button button-secondary" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 12px; min-height: 30px;">
                            <span class="dashicons dashicons-search" style="font-size: 16px; width: 16px; height: 16px; line-height: 1; margin: 0; display: inline-flex; align-items: center; justify-content: center;"></span>
                            <span>Scanner les cookies du domaine (ex: Concord)</span>
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
                    <p class="description">Nom du cookie tiers dont la présence autorise la transmission complète (ex: <code>concord_consent</code>).</p>
                </div>

                <script type="text/javascript">
                    (function ($) {
                        $('#woo_gads_banner_accent_color_picker').on('input change', function () {
                            $('#woo_gads_banner_accent_color').val($(this).val());
                        });

                        $('#woo_gads_banner_accent_color').on('input change', function () {
                            var val = $(this).val();
                            if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                                $('#woo_gads_banner_accent_color_picker').val(val);
                            }
                        });

                        $('#woo_gads_enable_builtin_banner').on('change', function () {
                            if ($(this).is(':checked')) {
                                $('#woo-gads-builtin-banner-options').slideDown(200);
                                $('#woo-gads-builtin-active-notice').show();
                                $('#woo-gads-external-cookie-fields').hide();
                            } else {
                                $('#woo-gads-builtin-banner-options').slideUp(200);
                                $('#woo-gads-builtin-active-notice').hide();
                                $('#woo-gads-external-cookie-fields').show();
                            }
                        });

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
            <th scope="row">Statuts de commande déclencheurs</th>
            <td>
                <?php
                $target_statuses = Woo_Gads_Api::get_target_statuses($settings ?? array());
                $available_statuses = array(
                    'processing' => 'En cours (Processing)',
                    'completed'  => 'Terminée (Completed)',
                    'on-hold'    => 'En attente (On hold)',
                );
                foreach ($available_statuses as $status_key => $status_label) :
                ?>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="woo_gads_settings[order_statuses][]" value="<?php echo esc_attr($status_key); ?>" <?php checked(in_array($status_key, $target_statuses, true)); ?> />
                        <strong><?php echo esc_html($status_label); ?></strong>
                        <?php if (in_array($status_key, array('processing', 'completed'), true)) : ?>
                            <span style="color: #00a32a; font-size: 12px; font-weight: normal; margin-left: 5px;">(Recommandé)</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <p class="description">L'API Google Ads sera contactée dès que la commande atteint <strong>au moins l'un de ces statuts</strong>. Si la commande transite par plusieurs de ces statuts (ex: « En cours » puis « Terminée »), le garde-fou anti-doublon garantit qu'elle n'est envoyée qu'une seule fois.</p>
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