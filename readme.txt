=== Woo Google Ads Server-Side Tracking ===
Contributors: SOYOO
Tags: woocommerce, google ads, tracking, server-side
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.3.2
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
