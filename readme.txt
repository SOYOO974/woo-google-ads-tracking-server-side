=== Woo Google Ads Server-Side Tracking ===
Contributors: SOYOO
Tags: woocommerce, google ads, tracking, server-side
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Envoi des conversions WooCommerce à l'API Google Ads en server-side, respectant le consentement.

== Description ==

This plugin allows you to send WooCommerce conversions directly to the Google Ads API via server-side tracking, while respecting user consent settings.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/woo-google-ads-tracking-server-side` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the Settings->Woo Google Ads Tracking screen to configure the plugin.

== Changelog ==

= 1.4.2 =
* Scan dynamique approfondi des métadonnées : Détection automatique des identifiants de clic (GCLID, WBRAID, GBRAID) sur l'ensemble des champs personnalisés et métadonnées de commande enregistrés par n'importe quel plugin tiers.
* Forçage de statut optionnel : Ajout d'une option dans le scanner de rattrapage pour forcer l'envoi de toutes les commandes avec identifiant publicitaire, même si leur statut n'est pas coché dans les réglages (ex: commandes « En attente » / `on-hold`).
* Rapport de diagnostic enrichi : Ventilation détaillée des statuts non déclencheurs et alerte explicite si des commandes possèdent un GCLID mais ont été bloquées par leur statut.

= 1.4.1 =
* Outil de rattrapage rétroactif : Nouveau scanner interactif dans l'onglet Diagnostic permettant d'analyser les commandes des 7, 14, 30 ou 60 derniers jours et de renvoyer automatiquement toutes les conversions publicitaires manquées à Google Ads.
* Détection multi-sources des clics : Résolution avancée des identifiants (gclid, wbraid, gbraid) inspectant les métadonnées natives, les extensions tierces de tracking UTM (notamment WP Gens UTM Tracking via `_wpgens_gclid`), ainsi que les cookies et paramètres d'URL au checkout.
* Rétro-synchronisation HPOS automatique : Dès qu'un identifiant de clic est découvert dans une extension tierce ou méta de secours, il est automatiquement rétro-sauvegardé dans les métas natives HPOS de la commande (`_woo_gads_gclid`).
* Horodatage historique réel (conversionDateTime) : Utilisation de la date et heure réelles de passage de commande (`$order->get_date_created()`) au lieu de la date courante de la requête API, assurant une attribution historique exacte dans Google Ads sans fausser les rapports.
* Protection absolue anti-sur-attribution : Les commandes organiques, directes ou hors Google Ads (sans aucun identifiant de clic) sont ignorées et ne font l'objet d'aucun appel API (strict respect des enseignements de la v1.3.1).
* Inspection WP Gens dans le backoffice : Affichage explicite de l'identifiant détecté via WP Gens dans le volet « Détails métas » de chaque commande auditée.

= 1.4.0 =
* Compatibilité majeure HPOS : Déclaration officielle de compatibilité avec le stockage haute performance de WooCommerce (High-Performance Order Storage / Custom Order Tables).
* Abstraction CRUD WooCommerce : Remplacement des fonctions obsolètes WordPress (get_post_meta, update_post_meta, delete_post_meta) par l'API native WC_Order ($order->get_meta, $order->update_meta_data, $order->save), garantissant la persistance des clics et du consentement que HPOS soit actif avec ou sans synchronisation.
* Multi-hooks de commande universels : Capture des click IDs et du consentement compatible avec les blocs de commande Gutenberg (WooCommerce Blocks Store API), le checkout classique et les passerelles de paiement express (Apple Pay, PayPal Express, etc.).
* Déclencheurs de statut résilients : Écoute ajoutée sur woocommerce_payment_complete et woocommerce_order_status_changed pour ne rater aucune conversion même si la passerelle saute l'étape de transition standard.
* Résilience des cookies & ITP : Synchronisation automatique bidirectionnelle entre localStorage et document.cookie avec attributs SameSite=Lax pour éviter la perte des click IDs sous Safari ITP.
* Diagnostic & Inspection enrichis : Ajout d'une carte d'état de l'architecture HPOS, clarification entre absence de cookie et méta non enregistrée, et ajout d'un inspecteur de métadonnées brutes en 1 clic pour chaque commande auditée.
* Correctif affichage : Nettoyage du code HTML brut échappé dans la colonne Résultat API de l'audit des commandes et ajout d'un style couleur propre (vert, orange, gris, rouge).
* Localisation : Affichage du nom traduit en français du statut de commande (ex: « Annulée » au lieu de « cancelled »).
* Fiabilité OAuth (Auto-recovery 401) : Purge immédiate du transient de cache et rafraîchissement automatique du token d'accès auprès de Google en cas d'erreur HTTP 401, avec réessai transparent de la conversion avant toute alerte email.

= 1.3.3 =
* Feature / Ergonomie : Remplacement de la liste déroulante par des cases à cocher multi-statuts dans les réglages ("En cours" et "Terminée" cochés par défaut).
* Fiabilité : Déclenchement dès que la commande atteint l'un des statuts cochés, avec protection anti-doublon absolue (aucune conversion renvoyée si la commande passe successivement d'un statut coché à un autre).
* Rétrocompatibilité totale avec les configurations de statuts antérieures (chaîne unique).

= 1.3.2 =
* Amélioration / Filet de sécurité anti-perte : Gestion des commandes qui sautent directement au statut "Terminée" (completed) sans passer par "En cours" (processing), notamment via les modules de paiement (ex: WebToffee Stripe) ou des snippets d'auto-complétion.
* Correctif : Déblocage du bouton de renvoi manuel "Renvoyer" dans l'administration, désormais insensible au statut actuel de la commande ($force = true).
* Nouvelle option : Ajout de l'option recommandée "En cours ou Terminée (Recommandé - Anti-perte)" dans le réglage du statut déclencheur.
* Diagnostic : Message explicite dans l'audit des commandes lorsqu'une commande est en attente en raison d'un décalage entre son statut et le statut déclencheur configuré.

= 1.3.1 =
* Correctif critique : Rollback de l'envoi des conversions sans identifiant de clic (ECL). L'envoi sans GCLID/WBRAID/GBRAID provoquait une sur-attribution massive dans Google Ads (toutes les ventes du site étaient comptabilisées comme conversions Google Ads, même celles venant d'autres sources de trafic).
* Conservation de l'amélioration `userIdentifierSource: FIRST_PARTY` sur les UserIdentifiers (utile pour les Enhanced Conversions for Web quand un click ID est présent).

= 1.3.0 =
* Feature : Support des Enhanced Conversions for Leads (ECL) — les conversions sont désormais envoyées même sans identifiant de clic (GCLID/WBRAID/GBRAID), dès lors que des données utilisateur hachées (email, téléphone) sont disponibles et que le consentement marketing est accordé.
* Amélioration : Ajout du champ `userIdentifierSource: FIRST_PARTY` sur chaque UserIdentifier, requis par l'API Google Ads pour les ECL.
* Amélioration : Statut de succès distinct dans les logs et métadonnées de commande (`Succès (Enhanced Conversions for Leads)`) pour différencier les conversions envoyées sans click ID.

= 1.2.4 =
* Amélioration : Distinction dans la colonne "Erreur" des logs entre un cookie absent et un refus de consentement explicite.
* Sécurité/Alerte : Envoi automatique d'une alerte email à l'administrateur si 6 envois consécutifs de conversion se font sans cookie de consentement (panne potentielle de la CMP).

= 1.2.3 =
* Amélioration : Implémentation du repli par préfixe pour le cookie Concord (`concord-allow-state-`) pour fiabiliser le tracking lors des redéploiements de bannières.
* Fiabilisation : Amélioration du Live Browser Test qui vérifie désormais activement la présence du script Concord sur la page d'accueil de la boutique pour éviter les faux positifs.

= 1.2.2 =
* Amélioration : Ajout d'un bouton "Renvoyer" dans la table des dernières requêtes API de l'onglet Diagnostic.

= 1.2.1 =
* Amélioration : Ajout d'une colonne "Statut Commande" dans l'onglet Diagnostic pour identifier les commandes en attente.


= 1.2.0 =
* Feature: Added a "Support" tab in the plugin settings with a contact form.
* Enhancement: Technical information (site URL, plugin/WP versions) is automatically included in support emails.

= 1.1.0 =
* Feature: Support for Consent Mode v2 and improved click ID handling.
* Feature: Added email alerts for failed conversions and a manual retry button in diagnostics.
* Feature: Integrated automatic plugin updates directly from GitHub.
* Tweak: Allowed 'on-hold' order status to trigger Google Ads conversions.
* Fix: Resolved Google Ads API 404 error by stripping non-numeric characters from merchant ID.

= 1.0.0 =
* Initial release.
