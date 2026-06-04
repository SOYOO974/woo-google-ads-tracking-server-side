<?php
$logs = Woo_Gads_Db::get_logs(50);
?>
<div class="wrap">
    <h2>Diagnostic de l'API</h2>

    <div
        style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <button id="woo-gads-test-conn" class="button button-primary">Tester la connexion OAuth</button>
        <span id="woo-gads-test-result" style="margin-left: 10px; font-weight:bold;"></span>
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
            $consent_cookie_name = isset($settings['consent_cookie_name']) && !empty($settings['consent_cookie_name']) ? $settings['consent_cookie_name'] : 'concord_consent';
            ?>
            <p><strong>Nom du cookie à surveiller :</strong> <code><?php echo esc_html($consent_cookie_name); ?></code>
            </p>
            <div id="woo-gads-cookie-status"
                style="padding: 10px; border-radius: 4px; display: inline-block; font-weight: bold;">
                Vérification du cookie en cours...
            </div>
        </div>

        <script type="text/javascript">
            (function ($) {
                function getCookie(name) {
                    var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
                    if (match) return match[2];
                    
                    // Fallback for prefix matching
                    if (name.indexOf('concord-allow-state-') === 0) {
                        var cookies = document.cookie.split('; ');
                        for (var i = 0; i < cookies.length; i++) {
                            var parts = cookies[i].split('=');
                            if (parts[0].indexOf('concord-allow-state-') === 0) {
                                return parts[1];
                            }
                        }
                    }
                    return null;
                }

                var cookieName = '<?php echo esc_js($consent_cookie_name); ?>';
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
                        var hasConcordScript = html.toLowerCase().indexOf('concord') !== -1;
                        
                        if (value) {
                            if (hasConcordScript) {
                                statusDiv.html('<strong>Pastille verte :</strong> Cookie trouvé dans votre navigateur et script Concord détecté sur votre site.<br><small>Valeur : ' + decodeURIComponent(value) + '</small>')
                                    .css({ 'background': '#e7f9ed', 'color': '#116633', 'border': '1px solid #c3ebce', 'display': 'inline-block' });
                            } else {
                                statusDiv.html('<strong>Pastille orange :</strong> Cookie trouvé dans votre navigateur, mais le script Concord semble <strong>absent ou inactif</strong> sur votre page d\'accueil.<br><small>Veuillez vérifier que le script de la bannière Concord est bien installé sur votre thème ou via GTM. Valeur du cookie : ' + decodeURIComponent(value) + '</small>')
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
                    })
                    .catch(function(err) {
                        if (value) {
                            statusDiv.html('<strong>Pastille verte (locale) :</strong> Cookie trouvé, valeur : ' + decodeURIComponent(value) + '. <br><small>Impossible de vérifier la page d\'accueil en arrière-plan (' + err.message + ').</small>')
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
                        $consent = get_post_meta($order_id, '_woo_gads_consent', true);

                        // Click IDs
                        $gclid = get_post_meta($order_id, '_woo_gads_gclid', true);
                        $wbraid = get_post_meta($order_id, '_woo_gads_wbraid', true);
                        $gbraid = get_post_meta($order_id, '_woo_gads_gbraid', true);
                        $ids = array_filter(array('GCLID' => $gclid, 'WBRAID' => $wbraid, 'GBRAID' => $gbraid));

                        // API Status
                        $api_status = get_post_meta($order_id, '_gads_api_status', true);
                        if (empty($api_status)) {
                            // Regarder si ancien tag sent existe pour la compatibilité
                            $legacy_sent = get_post_meta($order_id, '_gads_api_sent', true);
                            if ($legacy_sent === '1') {
                                $api_status = 'Succès (Legacy)';
                            } elseif (!empty($legacy_sent)) {
                                $api_status = 'Erreur (Legacy)';
                            } else {
                                $api_status = 'En attente / Non traité';
                            }
                        }

                        // Consent Display Logic
                        $consent_display = '';
                        if ($consent === 'no_cookie_found' || empty($consent)) {
                            $consent_display = '<span style="color:#d63638; font-weight:bold;">DENIED (Aucun cookie)</span>';
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
                                <strong><?php echo esc_html($api_status); ?></strong>
                                <div style="margin-top: 5px;">
                                    <button type="button" class="button button-small woo-gads-retry-btn" data-order-id="<?php echo esc_attr($order_id); ?>">Renvoyer</button>
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
});
</script>