<div class="wrap">
    <div class="card" style="max-width: 800px; padding: 20px;">
        <h2>Guide de configuration Google Ads Server-Side</h2>

        <h3>Étape 1 : Obtenir le Developer Token</h3>
        <ol>
            <li>Connectez-vous à votre compte Google Ads (niveau compte administrateur/MCC idéalement).</li>
            <li>Allez dans <strong>Outils et paramètres > Configuration > Centre API</strong>.</li>
            <li>Remplissez le formulaire pour demander l'accès à l'API.</li>
            <li>Vous obtiendrez un <strong>Developer token</strong> (même en accès test, il est suffisant au début).
            </li>
        </ol>

        <h3>Étape 2 : Créer vos identifiants sur Google Cloud</h3>
        <p>C'est ici que vous créez la "porte" qui permet au plugin de parler à Google.</p>
        <ol>
            <li>Allez sur la <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>.</li>
            <li><strong>Créez un projet</strong> (ou sélectionnez-en un existant).</li>
            <li>Activez l'API : Allez dans <strong>API et services > Bibliothèque</strong>, cherchez <strong>"Google Ads
                    API"</strong> et cliquez sur <strong>Activer</strong>.</li>
            <li>Configurez l'écran de consentement : Allez dans <strong>API et services > Écran de consentement
                    OAuth</strong>. Choisissez "Utilisateur externe", remplissez les noms obligatoires, et enregistrez
                sans rien ajouter d'autre.</li>
            <li>Créez les clés : Allez dans <strong>API et services > Identifiants</strong>.</li>
            <li>Cliquez sur <strong>+ Créer des identifiants</strong> > <strong>ID client OAuth</strong>.</li>
            <li>Type d'application : <strong>Application Web</strong>.</li>
            <li><strong>IMPORTANT :</strong> Dans la section "URIs de redirection autorisés", cliquez sur <strong>+
                    AJOUTER UN URI</strong>.</li>
            <li>Copiez l'adresse qui s'affiche sous le bouton dans l'onglet <strong>Configuration</strong> de ce plugin
                (ex: <code>https://votre-site.com/wp-admin/admin.php?page=woo-gads-server-side</code>) et collez-la ici.
            </li>
            <li>Cliquez sur <strong>Créer</strong>. Google vous donne alors votre <strong>Client ID</strong> et
                <strong>Client Secret</strong>. Copiez-les dans l'onglet Configuration du plugin.
            </li>
        </ol>

        <h3>Étape 3 : Obtenir le Refresh Token</h3>
        <p>Il existe deux méthodes pour obtenir votre Refresh Token :</p>

        <h4>Option A : Méthode Automatique (Recommandée)</h4>
        <ol>
            <li>Dans l'onglet <strong>Configuration</strong>, renseignez votre <strong>Client ID</strong> et
                <strong>Client Secret</strong>.
            </li>
            <li>Cliquez sur le bouton <strong>"Enregistrer les modifications"</strong>.</li>
            <li>Une fois la page rechargée, cliquez sur le bouton <strong>"Se connecter avec Google"</strong> qui
                apparaît sous le champ Refresh Token.</li>
            <li>Autorisez l'accès et vous serez redirigé avec le token automatiquement rempli.</li>
            <li><strong>Note :</strong> Assurez-vous d'avoir ajouté l'URI de redirection (affichée sous le bouton) dans
                votre console Google Cloud (identifiants OAuth).</li>
        </ol>

        <h4>Option B : Méthode Manuelle (OAuth Playground)</h4>
        <ol>
            <li>Si la méthode automatique échoue, utilisez le <a href="https://developers.google.com/oauthplayground/"
                    target="_blank">Google OAuth Playground</a>.</li>
            <li>Appuyez sur l'engrenage (paramètres) et cochez <strong>"Use your own OAuth credentials"</strong>.</li>
            <li>Entrez votre Client ID et Client Secret.</li>
            <li>Sélectionnez le scope <code>https://www.googleapis.com/auth/adwords</code> et autorisez.</li>
            <li>Échangez le code pour obtenir le <strong>Refresh token</strong> et copiez-le ici.</li>
        </ol>

        <h3>Étape 4 : Créer l'action de conversion et récupérer l'ID</h3>
        <p>Pour que le suivi server-side fonctionne, vous devez créer une action de conversion spécifique dans Google
            Ads.</p>
        <ol>
            <li>Dans votre compte Google Ads, allez dans <strong>Objectifs > Conversions > Récapitulatif</strong>.</li>
            <li>Cliquez sur <strong>+ Nouvelle action de conversion</strong>.</li>
            <li>Choisissez <strong>Importation</strong> (Conversions hors ligne).</li>
            <li>Sélectionnez <strong>Importation à partir de clics</strong> (CRMs, fichiers, etc.).</li>
            <li>À l'étape suivante, choisissez la catégorie <strong>Achat</strong> (Purchase).</li>
            <li>Dans "Source de données", choisissez <strong>Se connecter plus tard</strong> ou <strong>Téléchargement
                    manuel</strong>.</li>
            <li>Une fois l'action créée et enregistrée, cliquez sur son nom dans la liste.</li>
            <li>Regardez l'URL dans la barre de votre navigateur : l'ID est le nombre situé après <code>ctid=</code>
                (ex: <code>...ctid=123456...</code>).</li>
            <li><strong>IMPORTANT :</strong> Pour éviter de compter les ventes en double (si vous avez déjà un tag GA4
                ou Google Ads classique), passez vos autres actions de conversion "Achat" en <strong>Action
                    secondaire</strong> (dans les paramètres de l'action de conversion, sous "Optimisation de l'objectif
                et de l'action"). Seule cette action server-side doit rester en <strong>Action principale</strong> pour
                être celle utilisée pour l'optimisation des enchères.</li>
        </ol>

        <p><em>Note :</em> Assurez-vous d'avoir entré le "Merchant Customer ID" sans tirets dans l'onglet Configuration.
        </p>
    </div>
</div>