<?php
$logs = Woo_Gads_Db::get_logs(50);
?>
<div class="wrap">
    <h2>Diagnostic de l'API</h2>

    <?php
    $hpos_active = false;
    $hpos_sync = false;
    if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
        $hpos_active = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        if (method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'is_custom_order_tables_in_sync')) {
            $hpos_sync = \Automattic\WooCommerce\Utilities\OrderUtil::is_custom_order_tables_in_sync();
        }
    }
    ?>
    <div style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-left: 4px solid #2271b1;">
        <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center; justify-content: space-between;">
            <div>
                <strong style="margin-right: 6px;">Stockage WooCommerce :</strong>
                <?php if ($hpos_active) : ?>
                    <span style="background: #e7f9ed; color: #116633; padding: 3px 8px; border-radius: 3px; font-weight: bold;">HPOS Actif (wp_wc_orders) ✅</span>
                    <small style="margin-left: 8px; color: #646970;">Synchronisation posts : <?php echo $hpos_sync ? 'Active' : 'Désactivée (Performance pure)'; ?></small>
                <?php else : ?>
                    <span style="background: #f0f0f1; color: #50575e; padding: 3px 8px; border-radius: 3px; font-weight: bold;">Hérité (Table wp_posts)</span>
                <?php endif; ?>
                <span style="margin-left: 12px; background: #e7f9ed; color: #116633; padding: 3px 8px; border-radius: 3px; font-weight: bold;">Compatible HPOS ✅</span>
            </div>
            <div>
                <button id="woo-gads-test-conn" class="button button-primary">Tester la connexion OAuth</button>
                <span id="woo-gads-test-result" style="margin-left: 10px; font-weight:bold;"></span>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            $('#woo-gads-test-conn').click(function (e) {
                e.preventDefault();
                var btn = $(this);
                var result = $('#woo-gads-test-result');

                btn.prop('disabled', true);
                result.text('Test en cours...').css('color', 'black');

                $.post(ajaxurl, {
                    action: 'woo_gads_test_connection'
                }, function (response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        result.text(response.data).css('color', 'green');
                    } else {
                        result.text(response.data).css('color', 'red');
                    }
                });
            });
        });
    </script>

    <div style="margin-top: 30px; margin-bottom: 30px;">
        <h2>Diagnostic de capture des données (Concord & Identifiants)</h2>

        <div class="card" style="max-width: 100%; margin-bottom: 20px;">
            <h3>1. Test de lecture du cookie en direct (Live Browser Test)</h3>
            <?php
            $settings = get_option('woo_gads_settings');
            $is_builtin = !empty($settings['enable_builtin_banner']);
            $consent_cookie_name = $is_builtin ? 'woo_gads_consent' : (isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name']) ? $settings['consent_cookie_name'] : 'concord_consent');
            ?>
            <?php if ($is_builtin) : ?>
                <p><strong>Mode de consentement :</strong> <span style="background: #e7f9ed; color: #116633; padding: 2px 8px; border-radius: 3px; font-weight: bold;">Bannière native intégrée (Google Consent Mode v2)</span></p>
                <p><strong>Cookie interne surveillé :</strong> <code>woo_gads_consent</code></p>
            <?php else : ?>
                <p><strong>Mode de consentement :</strong> Bannière tierce (ex: Concord)</p>
                <p><strong>Nom du cookie à surveiller :</strong> <code><?php echo esc_html($consent_cookie_name); ?></code></p>
            <?php endif; ?>

            <div id="woo-gads-cookie-status"
                style="padding: 10px; border-radius: 4px; display: inline-block; font-weight: bold;">
                Vérification du cookie en cours...
            </div>
        </div>

        <script type="text/javascript">
            (function ($) {
                function getCookie(name) {
                    var match = document.cookie.match(new RegExp('(^|; )' + name + '=([^;]+)'));
                    if (match) return decodeURIComponent(match[2]);
                    
                    // Fallback for prefix matching
                    if (name.indexOf('concord-allow-state-') === 0) {
                        var cookies = document.cookie.split('; ');
                        for (var i = 0; i < cookies.length; i++) {
                            var parts = cookies[i].split('=');
                            if (parts[0].indexOf('concord-allow-state-') === 0) {
                                return decodeURIComponent(parts[1]);
                            }
                        }
                    }
                    return null;
                }

                var cookieName = '<?php echo esc_js($consent_cookie_name); ?>';
                var isBuiltin = <?php echo $is_builtin ? 'true' : 'false'; ?>;
                var statusDiv = $('#woo-gads-cookie-status');
                var value = getCookie(cookieName);
                var homeUrl = '<?php echo esc_url(home_url('/')); ?>';

                statusDiv.text('Vérification du cookie et du script en cours...')
                    .css({ 'background': '#f6f7f7', 'color': '#50575e', 'border': '1px solid #dcdcde' });

                fetch(homeUrl)
                    .then(function(response) {
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.text();
                    })
                    .then(function(html) {
                        if (isBuiltin) {
                            var hasBannerScript = html.indexOf('woo-gads-banner') !== -1 || html.indexOf('woo_gads_consent') !== -1;
                            if (value) {
                                var isGranted = false;
                                try {
                                    var parsed = JSON.parse(value);
                                    if (parsed && parsed.marketing === true) isGranted = true;
                                } catch(e) {}

                                if (isGranted) {
                                    statusDiv.html('<strong>Pastille verte :</strong> Cookie <code>woo_gads_consent</code> détecté avec consentement <strong>ACCORDÉ (GRANTED)</strong>.<br><small>Enhanced Conversions actives. Valeur : ' + value + '</small>')
                                        .css({ 'background': '#e7f9ed', 'color': '#116633', 'border': '1px solid #c3ebce', 'display': 'inline-block' });
                                } else {
                                    statusDiv.html('<strong>Pastille orange :</strong> Cookie <code>woo_gads_consent</code> détecté avec consentement <strong>REFUSÉ (DENIED)</strong>.<br><small>Conversions envoyées en mode anonymisé. Valeur : ' + value + '</small>')
                                        .css({ 'background': '#fff8e5', 'color': '#b25e00', 'border': '1px solid #ffebc2', 'display': 'inline-block' });
                                }
                            } else {
                                if (hasBannerScript) {
                                    statusDiv.html('<strong>Pastille jaune :</strong> Bannière native active sur votre site, mais aucun choix enregistré dans ce navigateur.<br><small>Le bandeau est actuellement visible pour vos visiteurs.</small>')
                                        .css({ 'background': '#fff8e5', 'color': '#b25e00', 'border': '1px solid #ffebc2', 'display': 'inline-block', 'max-width': '100%' });
                                } else {
                                    statusDiv.html('<strong>Pastille rouge :</strong> Le script de la bannière native semble absent de la page d\'accueil. Vérifiez les réglages.')
                                        .css({ 'background': '#fbeaea', 'color': '#9b2626', 'border': '1px solid #f2cfcf', 'display': 'inline-block', 'max-width': '100%' });
                                }
                            }
                        } else {
                            var hasConcordScript = html.toLowerCase().indexOf('concord') !== -1;
                            
                            if (value) {
                                if (hasConcordScript) {
                                    statusDiv.html('<strong>Pastille verte :</strong> Cookie trouvé dans votre navigateur et script Concord détecté sur votre site.<br><small>Valeur : ' + value + '</small>')
                                        .css({ 'background': '#e7f9ed', 'color': '#116633', 'border': '1px solid #c3ebce', 'display': 'inline-block' });
                                } else {
                                    statusDiv.html('<strong>Pastille orange :</strong> Cookie trouvé dans votre navigateur, mais le script Concord semble <strong>absent ou inactif</strong> sur votre page d\'accueil.<br><small>Veuillez vérifier que le script de la bannière Concord est bien installé sur votre thème ou via GTM. Valeur du cookie : ' + value + '</small>')
                                        .css({ 'background': '#fff8e5', 'color': '#b25e00', 'border': '1px solid #ffebc2', 'display': 'inline-block', 'max-width': '100%' });
                                }
                            } else {
                                if (hasConcordScript) {
                                    statusDiv.html('<strong>Pastille jaune :</strong> Script Concord détecté sur votre page d\'accueil, mais aucun consentement n\'a été enregistré dans votre navigateur (Cookie introuvable).')
                                        .css({ 'background': '#fff8e5', 'color': '#b25e00', 'border': '1px solid #ffebc2', 'display': 'inline-block', 'max-width': '100%' });
                                } else {
                                    statusDiv.html('<strong>Pastille rouge :</strong> Cookie introuvable et script Concord absent sur votre page d\'accueil. Vérifiez l\'installation de votre bannière.')
                                        .css({ 'background': '#fbeaea', 'color': '#9b2626', 'border': '1px solid #f2cfcf', 'display': 'inline-block', 'max-width': '100%' });
                                }
                            }
                        }
                    })
                    .catch(function(err) {
                        if (value) {
                            statusDiv.html('<strong>Pastille verte (locale) :</strong> Cookie trouvé, valeur : ' + value + '. <br><small>Impossible de vérifier la page d\'accueil en arrière-plan (' + err.message + ').</small>')
                                .css({ 'background': '#e7f9ed', 'color': '#116633', 'border': '1px solid #c3ebce', 'display': 'inline-block' });
                        } else {
                            statusDiv.html('<strong>Pastille rouge :</strong> Cookie introuvable sur ce domaine. Vérifiez votre installation.')
                                .css({ 'background': '#fbeaea', 'color': '#9b2626', 'border': '1px solid #f2cfcf', 'display': 'inline-block' });
                        }
                    });
            })(jQuery);
        </script>

        <h3>2. Audit des dernières commandes (Backend Check)</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Order ID & Date</th>
                    <th>Statut Commande</th>
                    <th>Statut de Consentement</th>
                    <th>Identifiants de clic capturés</th>
                    <th>Données client (Enhanced Conversions)</th>
                    <th>Résultat API</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $recent_orders = wc_get_orders(array(
                    'limit' => 5,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ));

                if (empty($recent_orders)) {
                    echo '<tr><td colspan="6">Aucune commande trouvée.</td></tr>';
                } else {
                    foreach ($recent_orders as $order) {
                        $order_id = $order->get_id();

                        // HPOS-compatible meta reading with postmeta fallback
                        $consent = $order->get_meta('_woo_gads_consent');
                        if ($consent === '' || $consent === null) {
                            $consent = get_post_meta($order_id, '_woo_gads_consent', true);
                        }

                        // Click IDs
                        $gclid = $order->get_meta('_woo_gads_gclid');
                        if (empty($gclid)) { $gclid = get_post_meta($order_id, '_woo_gads_gclid', true); }
                        $wbraid = $order->get_meta('_woo_gads_wbraid');
                        if (empty($wbraid)) { $wbraid = get_post_meta($order_id, '_woo_gads_wbraid', true); }
                        $gbraid = $order->get_meta('_woo_gads_gbraid');
                        if (empty($gbraid)) { $gbraid = get_post_meta($order_id, '_woo_gads_gbraid', true); }
                        $ids = array_filter(array('GCLID' => $gclid, 'WBRAID' => $wbraid, 'GBRAID' => $gbraid));

                        // API Status & Sent
                        $api_status = $order->get_meta('_gads_api_status');
                        if (empty($api_status)) {
                            $api_status = get_post_meta($order_id, '_gads_api_status', true);
                        }
                        $api_sent = $order->get_meta('_gads_api_sent');
                        if (empty($api_sent)) {
                            $api_sent = get_post_meta($order_id, '_gads_api_sent', true);
                        }
                        $ids_saved = $order->get_meta('_woo_gads_ids_saved');

                        if (empty($api_status)) {
                            if ($api_sent === '1') {
                                $api_status = 'Succès (Legacy)';
                            } elseif (!empty($api_sent)) {
                                $api_status = 'Erreur (Legacy)';
                            } else {
                                $current_order_status = $order->get_status();
                                $target_statuses = Woo_Gads_Api::get_target_statuses($settings ?? array());
                                if (!in_array($current_order_status, $target_statuses, true)) {
                                    $status_name = wc_get_order_status_name($current_order_status);
                                    $api_status = 'En attente (Statut « ' . $status_name . ' » non coché)';
                                } else {
                                    $api_status = 'En attente (Non déclenché)';
                                }
                            }
                        }

                        // Consent Display Logic
                        $consent_display = '';
                        if ($consent === '' || $consent === null || $consent === false) {
                            $consent_display = '<span style="color:#b32d2e; font-weight:bold;">⚠️ Non enregistré</span><br><small style="color:#646970;">Hook non exécuté / Méta absente</small>';
                        } elseif ($consent === 'no_cookie_found') {
                            $consent_display = '<span style="color:#d63638; font-weight:bold;">DENIED (Cookie absent)</span><br><small style="color:#646970;">Cookie introuvable au checkout</small>';
                        } else {
                            $decoded = html_entity_decode(stripslashes($consent), ENT_QUOTES);
                            $parsed = json_decode($decoded, true);
                            if (is_array($parsed) && isset($parsed['marketing'])) {
                                if ($parsed['marketing'] === true) {
                                    $consent_display = '<span style="color:#00a32a; font-weight:bold;">GRANTED (Accepté)</span>';
                                } else {
                                    $consent_display = '<span style="color:#dba617; font-weight:bold;">DENIED (Refusé)</span>';
                                }
                            } else {
                                $consent_display = '<span style="color:#d63638; font-weight:bold;">Erreur Format / DENIED</span>';
                            }
                        }

                        // Customer Data
                        $email = $order->get_billing_email();
                        $phone = $order->get_billing_phone();
                        $has_data = (!empty($email) && !empty($phone));
                        ?>
                        <tr>
                            <td>
                                <strong>#<?php echo esc_html($order_id); ?></strong><br>
                                <small><?php echo esc_html($order->get_date_created()->date('d/m/Y H:i')); ?></small>
                            </td>
                            <td>
                                <?php 
                                $status_class = 'order-status status-' . esc_attr($order->get_status());
                                echo '<mark class="' . $status_class . '" style="background:transparent;"><span>' . esc_html(wc_get_order_status_name($order->get_status())) . '</span></mark>'; 
                                ?>
                            </td>
                            <td>
                                <?php echo $consent_display; ?>
                            </td>
                            <td>
                                <?php
                                if (empty($ids)) {
                                    echo '<span style="color:#646970;">Aucun identifiant</span>';
                                } else {
                                    foreach ($ids as $label => $val) {
                                        echo '<strong>' . $label . ':</strong> ' . esc_html($val) . '<br>';
                                    }
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ($has_data) {
                                    echo '<span style="color:#00a32a;">Prêtes pour le hachage</span>';
                                } else {
                                    echo '<span style="color:#d63638;">Manquantes (' . (empty($email) ? 'Email ' : '') . (empty($phone) ? 'Tel' : '') . ')</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $status_color = '#1d2327';
                                if (strpos($api_status, 'Succès') !== false) {
                                    $status_color = '#00a32a';
                                } elseif (strpos($api_status, 'Ignoré') !== false) {
                                    $status_color = '#646970';
                                } elseif (strpos($api_status, 'En attente') !== false) {
                                    $status_color = '#dba617';
                                } elseif (strpos($api_status, 'Échec') !== false || strpos($api_status, 'Erreur') !== false) {
                                    $status_color = '#d63638';
                                }
                                ?>
                                <strong style="color: <?php echo esc_attr($status_color); ?>;"><?php echo esc_html($api_status); ?></strong>
                                <div style="margin-top: 5px;">
                                    <button type="button" class="button button-small woo-gads-retry-btn" data-order-id="<?php echo esc_attr($order_id); ?>">Renvoyer</button>
                                    <button type="button" class="button button-small woo-gads-toggle-meta" data-target="meta-<?php echo esc_attr($order_id); ?>" style="margin-left: 4px;">Détails métas</button>
                                </div>
                                <div id="meta-<?php echo esc_attr($order_id); ?>" style="display:none; margin-top:8px; padding:8px 10px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; font-family:monospace; font-size:11px; text-align:left; line-height:1.6;">
                                    <div><strong>_woo_gads_gclid :</strong> <?php echo esc_html($gclid ?: '(vide)'); ?></div>
                                    <div><strong>_woo_gads_wbraid :</strong> <?php echo esc_html($wbraid ?: '(vide)'); ?></div>
                                    <div><strong>_woo_gads_gbraid :</strong> <?php echo esc_html($gbraid ?: '(vide)'); ?></div>
                                    <div><strong>_woo_gads_consent :</strong> <?php echo esc_html($consent ?: '(vide)'); ?></div>
                                    <div><strong>_gads_api_status :</strong> <?php echo esc_html($api_status ?: '(vide)'); ?></div>
                                    <div><strong>_gads_api_sent :</strong> <?php echo esc_html($api_sent ?: '(vide)'); ?></div>
                                    <div><strong>_woo_gads_ids_saved :</strong> <?php echo esc_html($ids_saved ?: '(vide)'); ?></div>
                                </div>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
    </div>

    <h2>Dernières requêtes API (Limite 50)</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 50px;">ID</th>
                <th style="width: 150px;">Date</th>
                <th style="width: 80px;">Order ID</th>
                <th style="width: 80px;">HTTP</th>
                <th>Erreur</th>
                <th>Payload</th>
                <th>Réponse API</th>
                <th style="width: 100px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="8">Aucune requête enregistrée pour le moment.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <?php echo esc_html($log->id); ?>
                        </td>
                        <td>
                            <?php echo esc_html($log->time); ?>
                        </td>
                        <td><a href="<?php echo get_edit_post_link($log->order_id); ?>">#
                                <?php echo esc_html($log->order_id); ?>
                            </a></td>
                        <td>
                            <?php
                            if ($log->http_status == 200) {
                                echo '<span style="color:green;font-weight:bold;">200</span>';
                            } else {
                                echo '<span style="color:red;font-weight:bold;">' . esc_html($log->http_status) . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php echo esc_html($log->error); ?>
                        </td>
                        <td>
                            <textarea readonly rows="2"
                                style="width:100%; font-size:10px;"><?php echo esc_textarea(is_string($log->payload) ? $log->payload : json_encode($log->payload)); ?></textarea>
                        </td>
                        <td>
                            <textarea readonly rows="2"
                                style="width:100%; font-size:10px;"><?php echo esc_textarea(is_string($log->response) ? $log->response : json_encode($log->response)); ?></textarea>
                        </td>
                        <td>
                            <button type="button" class="button button-small woo-gads-retry-btn" data-order-id="<?php echo esc_attr($log->order_id); ?>">Renvoyer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script type="text/javascript">
jQuery(document).ready(function ($) {
    $('.woo-gads-retry-btn').click(function(e) {
        e.preventDefault();
        var btn = $(this);
        var orderId = btn.data('order-id');
        var originalText = btn.text();
        
        if (!confirm('Voulez-vous vraiment forcer le renvoi de cette conversion à Google Ads ?')) {
            return;
        }
        
        btn.prop('disabled', true).text('Envoi...');
        
        $.post(ajaxurl, {
            action: 'woo_gads_retry_conversion',
            order_id: orderId,
            _ajax_nonce: '<?php echo wp_create_nonce("woo_gads_retry"); ?>'
        }, function(response) {
            btn.prop('disabled', false).text(originalText);
            if (response.success) {
                alert('Commande renvoyée. Vérifiez le nouveau statut.');
                location.reload();
            } else {
                alert('Erreur : ' + (response.data || 'Une erreur est survenue.'));
            }
        }).fail(function() {
            btn.prop('disabled', false).text(originalText);
            alert('Erreur réseau. Veuillez réessayer.');
        });
    });

    $('.woo-gads-toggle-meta').click(function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        $('#' + targetId).slideToggle(150);
    });
});
</script>