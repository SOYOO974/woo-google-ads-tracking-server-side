---
description: Créer et publier une nouvelle version du plugin sur GitHub
---

Ce workflow met à jour les numéros de version, crée un commit, pousse vers GitHub, et crée le tag de release.

1. Analyser les derniers commits depuis le précédent tag Git en exécutant la commande `git log $(git describe --tags --abbrev=0)..HEAD` (ou l'intégralité du log s'il n'y a pas encore de tag), ainsi que les modifications locales en cours.
2. En déduire la **nouvelle version sémantique** (patch, mineur, ou majeur) en fonction de l'importance des changements.
3. Rédiger automatiquement un **changelog** récapitulatif clair et concis.
4. Utiliser l'outil d'édition de fichier pour mettre à jour la ligne `Version: x.x.x` et `define('WOO_GADS_VERSION', 'x.x.x');` dans `c:\Antigravity\woo-tracking-server-side\woo-gads-server-side.php`.
5. Utiliser l'outil d'édition de fichier pour mettre à jour `Stable tag: x.x.x` et ajouter les détails de la version générée dans la section `== Changelog ==` du fichier `c:\Antigravity\woo-tracking-server-side\readme.txt`.

// turbo
4. Ajouter les fichiers modifiés (`git add woo-gads-server-side.php readme.txt`)

// turbo
5. Créer le commit (`git commit -m "Bump version to [VERSION]"`)

// turbo
6. Pousser le commit vers la branche courante (`git push origin main`)

// turbo
7. Créer un tag Git pour la release (`git tag -a v[VERSION] -m "Release v[VERSION]"`)

// turbo
8. Pousser le tag vers GitHub (`git push origin v[VERSION]`)

9. Vérifier si l'outil GitHub CLI (`gh`) est installé en lançant `gh --version`.
    - Si installé, exécuter : `gh release create v[VERSION] --title "v[VERSION]" --notes "[CHANGELOG]"`
    - Si l'outil n'est pas installé ou n'est pas authentifié, indiquer explicitement à l'utilisateur de se rendre sur la page GitHub de son dépôt dans l'onglet "Releases" pour y créer une nouvelle Release basée sur le tag `v[VERSION]` qui vient d'être poussé.
