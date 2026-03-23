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
                    return null;
                }

                var cookieName = '<?php echo esc_js($consent_cookie_name); ?>';
                var statusDiv = $('#woo-gads-cookie-status');
                var value = getCookie(cookieName);

                if (value) {
                    statusDiv.text('Pastille verte : "Cookie trouvé, valeur : ' + value + '"')
                        .css({ 'background': '#e7f9ed', 'color': '#116633', 'border': '1px solid #c3ebce' });
                } else {
                    statusDiv.text('Pastille rouge : "Cookie introuvable sur ce domaine. Vérifiez votre bannière Concord"')
                        .css({ 'background': '#fbeaea', 'color': '#9b2626', 'border': '1px solid #f2cfcf' });
                }
            })(jQuery);
        </script>

        <h3>2. Audit des dernières commandes (Backend Check)</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Order ID & Date</th>
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
                    echo '<tr><td colspan="4">Aucune commande trouvée.</td></tr>';
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
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="7">Aucune requête enregistrée pour le moment.</td>
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
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>