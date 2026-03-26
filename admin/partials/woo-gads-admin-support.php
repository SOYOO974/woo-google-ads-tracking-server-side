<?php
if (!defined('ABSPATH')) {
    exit;
}

$message_sent = false;
$error_message = '';

if (isset($_POST['woo_gads_support_submit'])) {
    if (!isset($_POST['woo_gads_support_nonce']) || !wp_verify_nonce($_POST['woo_gads_support_nonce'], 'woo_gads_support_action')) {
        $error_message = 'Erreur de sécurité. Veuillez réessayer.';
    } else {
        $user_message = sanitize_textarea_field($_POST['support_message']);
        
        if (empty($user_message)) {
            $error_message = 'Veuillez saisir un message.';
        } else {
            $to = 'soyoo.re@gmail.com';
            $subject = 'Support: Woo Google Ads Server-Side Tracking [' . get_bloginfo('name') . ']';
            
            $site_url = site_url();
            $wp_version = get_bloginfo('version');
            $wc_version = defined('WC_VERSION') ? WC_VERSION : 'Non installé';
            $plugin_version = defined('WOO_GADS_VERSION') ? WOO_GADS_VERSION : 'Inconnue';
            $php_version = phpversion();
            $admin_email = get_option('admin_email');
            
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'Reply-To: ' . get_bloginfo('name') . ' <' . $admin_email . '>'
            );
            
            $body = '<h2>Nouvelle demande de support</h2>';
            $body .= '<p><strong>Message :</strong></p>';
            $body .= '<blockquote>' . nl2br(esc_html($user_message)) . '</blockquote>';
            $body .= '<h3>Informations du site :</h3>';
            $body .= '<ul>';
            $body .= '<li><strong>URL du site :</strong> ' . esc_url($site_url) . '</li>';
            $body .= '<li><strong>Email Admin WP :</strong> ' . esc_html($admin_email) . '</li>';
            $body .= '<li><strong>Version du Plugin :</strong> ' . esc_html($plugin_version) . '</li>';
            $body .= '<li><strong>Version de WordPress :</strong> ' . esc_html($wp_version) . '</li>';
            $body .= '<li><strong>Version de WooCommerce :</strong> ' . esc_html($wc_version) . '</li>';
            $body .= '<li><strong>Version de PHP :</strong> ' . esc_html($php_version) . '</li>';
            $body .= '</ul>';
            
            if (wp_mail($to, $subject, $body, $headers)) {
                $message_sent = true;
            } else {
                $error_message = 'Erreur lors de l\'envoi du message. Veuillez vérifier la configuration de votre serveur d\'envoi d\'emails.';
            }
        }
    }
}
?>
<div class="wrap">
    <h2>Support & Contact</h2>
    <p>Vous rencontrez un problème avec le plugin ou vous avez une question ? N'hésitez pas à nous contacter directement depuis ce formulaire. Votre message sera envoyé à <strong>soyoo.re@gmail.com</strong>.</p>

    <?php if ($message_sent): ?>
        <div class="notice notice-success is-dismissible">
            <p>Votre message a été envoyé avec succès. Nous vous répondrons dans les plus brefs délais.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error_message); ?></p>
        </div>
    <?php endif; ?>

    <div class="postbox" style="padding: 20px; max-width: 800px; margin-top: 20px;">
        <form method="post" action="">
            <?php wp_nonce_field('woo_gads_support_action', 'woo_gads_support_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="support_message">Votre message</label></th>
                    <td>
                        <textarea name="support_message" id="support_message" rows="8" style="width: 100%;" placeholder="Décrivez votre problème ou votre question en détail..." required></textarea>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="woo_gads_support_submit" id="submit" class="button button-primary" value="Envoyer le message">
            </p>
            <p class="description">
                <em>Note : Ce formulaire transmettra automatiquement à l'équipe de support l'URL de votre site, la version du plugin, la version de WordPress, de WooCommerce et de PHP afin de faciliter le diagnostic.</em>
            </p>
        </form>
    </div>
</div>
