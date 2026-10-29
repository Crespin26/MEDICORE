# Recette humaine de la partie web

Cette grille complète les [tests navigateur](WEB-RECETTE.md). Elle est à exécuter sur une instance de recette isolée avec des données fictives, puis à faire accepter par les responsables de l'établissement. Les cases ci-dessous ne sont pas validées par le passage des tests automatisés.

Renseigner pour chaque séance : date, version/commit, navigateur et version, taille d'écran, rôle, personne qui réalise la recette, résultat et référence d'anomalie. Ne pas inclure de contenu médical réel dans les captures ou tickets.

## Parcours métier

| À valider | Rôle | Scénario et résultat attendu |
| --- | --- | --- |
| [ ] | Administrateur | Inviter un collaborateur, recevoir le courriel sur une boîte de recette, activer le compte ; vérifier expiration et usage unique du lien. |
| [ ] | Personnel | Recevoir un courriel de réinitialisation et changer le mot de passe ; vérifier que l'ancien mot de passe ne fonctionne plus. |
| [ ] | Secrétaire | Créer un patient, le retrouver par nom/référence/téléphone, corriger ses coordonnées ; vérifier les messages sans perdre la saisie utile. |
| [ ] | Administrateur | Confirmer deux homonymes réellement distincts dans les données fictives ; vérifier les références distinctes et leurs historiques séparés. |
| [ ] | Secrétaire et médecin | Planifier, déplacer et suivre le rendez-vous jusqu'à son arrivée ; confirmer l'affichage des horaires locaux et le refus des chevauchements/absences. |
| [ ] | Médecin | Rattacher une consultation, enregistrer un brouillon, valider puis corriger avec motif ; retrouver le contenu antérieur et l'auteur. |
| [ ] | Deux médecins | Accorder un partage, lire les éléments autorisés sans pouvoir les corriger, révoquer puis vérifier le refus d'une nouvelle lecture/téléchargement. |
| [ ] | Médecin | Ajouter un document fictif, vérifier son nom de téléchargement, sa lisibilité et le traitement des erreurs de format/taille. |
| [ ] | Caissier | Émettre une facture, encaisser partiellement puis solder ; vérifier totaux, devise, reçu et impression sur l'imprimante cible. |
| [ ] | Administrateur | Corriger/annuler une opération autorisée avec motif ; vérifier les historiques, soldes et indicateurs sans effacement de trace. |
| [ ] | Administrateur | Vérifier les mentions des factures/reçus pour l'établissement et le lieu de déploiement retenus. |
| [ ] | Administrateur | Archiver une fiche clôturée, vérifier son absence des recherches opérationnelles, retrouver ses historiques et la réactiver. |
| [ ] | Tous | Suspendre un compte pendant une session ; vérifier le refus des nouvelles actions et les parcours de reconnexion/déconnexion. |

## Accessibilité et ergonomie

| À valider | Vérification humaine |
| --- | --- |
| [ ] | Parcourir les écrans sans souris avec Tab/Maj+Tab/Entrée/Espace/Échap ; tous les contrôles utiles sont accessibles, sans piège de focus. |
| [ ] | Vérifier la visibilité du focus en thèmes clair et sombre, y compris tableaux, liens, menus et confirmations. |
| [ ] | Avec un lecteur d'écran réellement utilisé par l'équipe, vérifier titres, repères, noms des contrôles, erreurs et annonces des mises à jour. |
| [ ] | Vérifier l'ordre de lecture et la compréhension des éléments partagés, de leur auteur et de l'état archivé. |
| [ ] | Vérifier zoom 200 % et 400 %, agrandissement du texte et petits écrans ; aucun contrôle essentiel ne doit être masqué. |
| [ ] | Vérifier le menu sur petit écran, les longues listes, les libellés longs et les formulaires avec erreurs. |
| [ ] | Vérifier les indicateurs de chargement et la réduction des animations selon les préférences utilisateur. |
| [ ] | Vérifier que statuts et erreurs ne sont pas compréhensibles uniquement par leur couleur. |
| [ ] | Vérifier les fichiers téléchargés, fenêtres d'impression et confirmations avec les périphériques de l'établissement. |

## Compatibilité et décision

- [ ] Tester les versions de navigateurs réellement installées, sur les systèmes d'exploitation et appareils visés. WebKit automatisé ne vaut pas une validation de Safari installé sur chaque appareil Apple.
- [ ] Tester les conditions réseau retenues pour l'établissement et les reprises après erreur ; les tests locaux ne mesurent pas ces conditions.
- [ ] Consigner les anomalies, leur impact, leur résolution et la contre-vérification.
- [ ] Faire accepter les règles métier et les résultats par les personnes habilitées ; conserver la référence de la décision.

Les tests axe vérifient certaines règles automatisables. Leur absence de violation sur les pages scannées ne certifie pas la conformité globale ni l'expérience avec un lecteur d'écran. Les envois SMTP, les mentions de facturation, le stockage distant des sauvegardes et les tests sur l'hébergement cible restent des validations séparées.
