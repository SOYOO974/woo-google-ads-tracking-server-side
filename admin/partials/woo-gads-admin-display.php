<?php
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
?>
<div class="wrap">
    <h1>Woo Google Ads Server-Side Tracking</h1>

    <h2 class="nav-tab-wrapper">
        <a href="?page=woo-gads-server-side&tab=settings"
            class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Configuration</a>
        <a href="?page=woo-gads-server-side&tab=tutorial"
            class="nav-tab <?php echo $active_tab == 'tutorial' ? 'nav-tab-active' : ''; ?>">Tutoriel</a>
        <a href="?page=woo-gads-server-side&tab=logs"
            class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">Diagnostic & Logs</a>
        <a href="?page=woo-gads-server-side&tab=support"
            class="nav-tab <?php echo $active_tab == 'support' ? 'nav-tab-active' : ''; ?>">Support</a>
    </h2>

    <?php if (class_exists('Woo_Gads_Api') && Woo_Gads_Api::is_validate_only()): ?>
        <div class="notice notice-warning" style="border-left-color: #dba617;">
            <p>
                <strong><?php echo esc_html__('Test Mode (validateOnly) is active:', 'woo-gads-server-side'); ?></strong>
                <?php echo esc_html__('Google Ads API validates every conversion request without recording conversions in your Google Ads account. Disable Test Mode in Configuration once your validation tests are complete.', 'woo-gads-server-side'); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['oauth_success'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>Connexion Google réussie ! Le Refresh Token a été mis à jour.</p>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['oauth_error'])): ?>
        <div class="notice notice-error is-dismissible">
            <p>Erreur lors de la connexion Google. Vérifiez vos identifiants Client ID et Client Secret.</p>
        </div>
    <?php endif; ?>

    <div class="tabs-content" style="margin-top: 20px;">
        <?php
        if ($active_tab == 'settings') {
            require_once dirname(__FILE__) . '/woo-gads-admin-settings.php';
        } elseif ($active_tab == 'tutorial') {
            require_once dirname(__FILE__) . '/woo-gads-admin-tutorial.php';
        } elseif ($active_tab == 'logs') {
            require_once dirname(__FILE__) . '/woo-gads-admin-logs.php';
        } elseif ($active_tab == 'support') {
            require_once dirname(__FILE__) . '/woo-gads-admin-support.php';
        }
        ?>
    </div>
</div>