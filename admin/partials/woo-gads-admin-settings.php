<?php
$settings = get_option('woo_gads_settings');
?>
<form method="post" action="options.php">
    <?php settings_fields('woo_gads_options'); ?>
    <input type="hidden" name="woo_gads_settings[_from_settings_form]" value="1" />

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
                <p class="description" style="margin-top: 5px;">
                    <strong>Important (Conditions relatives aux données client) :</strong> Pour autoriser Google Ads à traiter les conversions améliorées (Enhanced Conversions avec email/téléphone), rendez-vous dans Google Ads dans le menu latéral gauche : <em>Objectifs &gt; Conversions &gt; Paramètres des conversions</em>, déroulez la section <strong>« Conditions relatives au traitement des données client »</strong>, cochez la case d'acceptation et enregistrez. <em>(Attention : ne pas cliquer sur « Connecter une source de données » sur l'action de conversion, qui lance un assistant d'importation de fichiers manuels).</em> En cas de conditions non encore acceptées, le plugin assure un repli automatique transparent sur le Click ID seul (GCLID) pour qu'aucune vente ne soit perdue.
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">Fenêtre d'attribution des clics</th>
            <td>
                <?php $click_window = isset($settings['click_window_days']) ? (int) $settings['click_window_days'] : 90; ?>
                <select name="woo_gads_settings[click_window_days]" id="woo_gads_click_window_days" style="max-width: 280px;">
                    <option value="30" <?php selected($click_window, 30); ?>>30 jours (Standard Google Ads)</option>
                    <option value="60" <?php selected($click_window, 60); ?>>60 jours</option>
                    <option value="90" <?php selected($click_window, 90); ?>>90 jours (Longue durée First-Party - Recommandé)</option>
                </select>
                <p class="description" style="margin-top: 5px;">
                    Durée de rétention des cookies first-party et du stockage local des identifiants (<code>gclid</code>, <code>_gcl_aw</code>, <code>wbraid</code>, <code>gbraid</code>).<br>
                    <strong>Alignement avec Google Ads :</strong> Si votre action de conversion dans Google Ads utilise une fenêtre plus courte (ex: 30 jours), les clics dépassant la fenêtre de Google Ads sont automatiquement classés en « Ignoré (Clic expiré) » sans générer d'alerte d'erreur.
                </p>
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
                    Affiche une bannière native ultra-légère (&lt; 4 Ko) conforme RGPD/CNIL, qui envoie automatiquement les signaux <code>gtag('consent', 'update', ...)</code> au navigateur et gère le cookie <code>woo_gads_consent</code> sans nécessiter de plugin externe (Concord, Complianz, etc.).
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

                    <p style="margin-bottom: 12px;">
                        <label for="woo_gads_banner_privacy_url"><strong>Lien Politique de Confidentialité (optionnel) :</strong></label><br>
                        <input type="url" name="woo_gads_settings[banner_privacy_url]" id="woo_gads_banner_privacy_url" value="<?php echo esc_attr($settings['banner_privacy_url'] ?? (function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '')); ?>" class="regular-text" style="width: 100%; max-width: 450px;" placeholder="https://..." />
                        <span class="description" style="display: block; margin-top: 3px;">Si renseigné, un lien cliquable sera ajouté au message du bandeau.</span>
                    </p>

                    <div style="margin-top: 15px; padding: 12px 16px; background: #ffffff; border: 1px solid #dcdcde; border-radius: 4px; border-left: 4px solid #2271b1;">
                        <h4 style="margin-top: 0; margin-bottom: 8px; color: #1d2327;">Obligation CNIL : Lien de réouverture (Revoyure dans le Footer)</h4>
                        <p style="margin-bottom: 10px; font-size: 13px; color: #50575e; line-height: 1.5;">
                            Conformément aux directives de la CNIL, les internautes doivent pouvoir retirer ou modifier leur consentement à tout moment, aussi facilement qu'ils l'ont donné. Pour préserver votre ergonomie sans imposer de pastille flottante intempestive, intégrez simplement ce shortcode dans votre pied de page (widget texte, bloc FSE, template ou menu) :
                        </p>
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <code style="font-size: 13px; padding: 6px 12px; background: #f0f0f1; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: 600; font-family: monospace;">[woo_gads_cookie_settings]</code>
                            <button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText('[woo_gads_cookie_settings]'); this.innerText='Copié !'; var btn=this; setTimeout(function(){btn.innerText='Copier le shortcode';}, 2000);">Copier le shortcode</button>
                        </div>
                        <p class="description" style="margin-top: 8px; margin-bottom: 0;">
                            <strong>Personnalisations :</strong> <code>[woo_gads_cookie_settings text="Gérer mes cookies"]</code> ou création d'un élément de menu avec l'URL <code>#woo-gads-cookies</code> et la classe <code>woo-gads-reopen-consent</code>.
                        </p>
                    </div>
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
            <th scope="row"><?php echo esc_html__('Quote / Lead Payment Methods (Purchase Lock)', 'woo-gads-server-side'); ?></th>
            <td>
                <?php
                $lead_methods_list = Woo_Gads_Api::get_lead_payment_methods(is_array($settings) ? $settings : array());
                $lead_methods_str = implode(', ', $lead_methods_list);
                $detected_gateways = array();
                if (function_exists('WC') && WC()->payment_gateways()) {
                    $all_gateways = WC()->payment_gateways()->payment_gateways();
                    if (is_array($all_gateways)) {
                        foreach ($all_gateways as $gw_id => $gw_obj) {
                            $gw_title = method_exists($gw_obj, 'get_title') ? wp_strip_all_tags((string) $gw_obj->get_title()) : $gw_id;
                            $detected_gateways[] = $gw_id . ($gw_title !== '' && $gw_title !== $gw_id ? ' (' . $gw_title . ')' : '');
                        }
                    }
                }
                ?>
                <input type="text" name="woo_gads_settings[lead_payment_methods]" id="woo_gads_lead_payment_methods"
                    value="<?php echo esc_attr($lead_methods_str); ?>" class="regular-text"
                    placeholder="we_quote_request" />
                <p class="description" style="margin-top: 5px;">
                    <?php echo esc_html__('Comma-separated WooCommerce payment gateway IDs representing quote or lead requests (default: we_quote_request). Any order placed with one of these gateways will NEVER trigger the Purchase conversion action—even if "On hold" is enabled or manual resend is clicked—unless the order is genuinely paid (is_paid() or payment date set).', 'woo-gads-server-side'); ?>
                </p>
                <?php if (!empty($detected_gateways)) : ?>
                    <p class="description" style="margin-top: 4px; font-size: 12px; color: #646970;">
                        <strong><?php echo esc_html__('Detected payment gateways:', 'woo-gads-server-side'); ?></strong>
                        <code><?php echo esc_html(implode(' | ', $detected_gateways)); ?></code>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__('Quote Request Conversion ("Demande de devis")', 'woo-gads-server-side'); ?></th>
            <td>
                <?php
                $lead_enabled = !empty($settings['enable_lead_conversion']) && (string) $settings['enable_lead_conversion'] !== '0';
                $lead_action_id_val = $settings['lead_conversion_action_id'] ?? '';
                $lead_ratio_val = isset($settings['lead_value_ratio']) && trim((string) $settings['lead_value_ratio']) !== '' ? $settings['lead_value_ratio'] : '0.25';
                $lead_cap_val = isset($settings['lead_value_cap']) && trim((string) $settings['lead_value_cap']) !== '' ? $settings['lead_value_cap'] : '750';
                $lead_statuses_selected = Woo_Gads_Api::get_lead_target_statuses(is_array($settings) ? $settings : array());
                $available_lead_statuses = array(
                    'on-hold' => __('On hold (En attente)', 'woo-gads-server-side'),
                    'pending' => __('Pending payment (Attente paiement)', 'woo-gads-server-side'),
                );
                ?>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px;">
                    <input type="checkbox" name="woo_gads_settings[enable_lead_conversion]" id="woo_gads_enable_lead_conversion" value="1" <?php checked($lead_enabled); ?> />
                    <strong><?php echo esc_html__('Enable dedicated Google Ads conversion tracking for quote requests', 'woo-gads-server-side'); ?></strong>
                </label>
                <p class="description" style="margin-top: 5px;">
                    <?php echo esc_html__('Sends quote orders matching the payment methods above to a separate Google Ads Lead conversion action with orderId "devis-{order_id}" and a weighted/capped value, without affecting Purchase metas (_gads_api_sent). If a quote is later paid, the full real sale is sent to the Purchase action.', 'woo-gads-server-side'); ?>
                </p>

                <div id="woo-gads-lead-options" style="margin-top: 15px; padding: 15px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; border-left: 4px solid #2271b1; <?php echo $lead_enabled ? '' : 'display:none;'; ?>">
                    <div style="margin-bottom: 14px;">
                        <label for="woo_gads_lead_conversion_action_id"><strong><?php echo esc_html__('Quote Conversion Action ID:', 'woo-gads-server-side'); ?></strong></label><br>
                        <input type="text" name="woo_gads_settings[lead_conversion_action_id]" id="woo_gads_lead_conversion_action_id"
                            value="<?php echo esc_attr($lead_action_id_val); ?>" class="regular-text" style="margin-top: 4px; max-width: 420px; width: 100%;"
                            placeholder="ex: 7012345678 ou customers/1234567890/conversionActions/7012345678" />
                        <p class="description" style="margin-top: 4px;">
                            <?php echo esc_html__('Accepts either the numeric Conversion Action ID or the full Google Ads resource name (Import > Click conversions, category "Request quote").', 'woo-gads-server-side'); ?>
                        </p>
                    </div>

                    <div style="display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 14px;">
                        <div>
                            <label for="woo_gads_lead_value_ratio"><strong><?php echo esc_html__('Value Ratio (0 to 1):', 'woo-gads-server-side'); ?></strong></label><br>
                            <input type="number" step="0.01" min="0" max="10" name="woo_gads_settings[lead_value_ratio]" id="woo_gads_lead_value_ratio"
                                value="<?php echo esc_attr($lead_ratio_val); ?>" class="small-text" style="margin-top: 4px; width: 110px;" />
                            <p class="description" style="margin-top: 3px;"><?php echo esc_html__('Default: 0.25 (25% of order total incl. tax)', 'woo-gads-server-side'); ?></p>
                        </div>
                        <div>
                            <label for="woo_gads_lead_value_cap"><strong><?php echo esc_html__('Maximum Value Cap (EUR):', 'woo-gads-server-side'); ?></strong></label><br>
                            <input type="number" step="0.01" min="0" name="woo_gads_settings[lead_value_cap]" id="woo_gads_lead_value_cap"
                                value="<?php echo esc_attr($lead_cap_val); ?>" class="small-text" style="margin-top: 4px; width: 130px;" />
                            <p class="description" style="margin-top: 3px;"><?php echo esc_html__('Default: 750 € — Formula: min(Total TTC × Ratio, Cap)', 'woo-gads-server-side'); ?></p>
                        </div>
                    </div>

                    <div>
                        <strong><?php echo esc_html__('Quote Trigger Statuses:', 'woo-gads-server-side'); ?></strong>
                        <div style="margin-top: 6px;">
                            <?php foreach ($available_lead_statuses as $l_status_key => $l_status_label) : ?>
                                <label style="display: inline-block; margin-right: 20px; margin-bottom: 6px;">
                                    <input type="checkbox" name="woo_gads_settings[lead_order_statuses][]" value="<?php echo esc_attr($l_status_key); ?>" <?php checked(in_array($l_status_key, $lead_statuses_selected, true)); ?> />
                                    <?php echo esc_html($l_status_label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <script type="text/javascript">
                    (function ($) {
                        $('#woo_gads_enable_lead_conversion').on('change', function () {
                            if ($(this).is(':checked')) {
                                $('#woo-gads-lead-options').slideDown(200);
                            } else {
                                $('#woo-gads-lead-options').slideUp(200);
                            }
                        });
                    })(jQuery);
                </script>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__('API Test Mode (validateOnly)', 'woo-gads-server-side'); ?></th>
            <td>
                <?php $validate_only_checked = !empty($settings['validate_only']) && (string) $settings['validate_only'] !== '0'; ?>
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="woo_gads_settings[validate_only]" value="1" <?php checked($validate_only_checked); ?> />
                    <strong><?php echo esc_html__('Enable validateOnly mode (validate payloads without recording conversions in Google Ads)', 'woo-gads-server-side'); ?></strong>
                </label>
                <p class="description" style="margin-top: 5px;">
                    <?php echo esc_html__('Adds "validateOnly": true to uploadClickConversions requests. Ideal for testing Purchase and Quote flows in local/staging environments before going live.', 'woo-gads-server-side'); ?>
                </p>
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
