# Fonctionnalités et tâches — MediCore

Ce document décrit le périmètre proposé du web et du mobile, ainsi que les travaux communs. Les cases cochées correspondent au code présent ; elles ne signifient pas que le produit est prêt pour la production.

Hypothèse de travail : le web et le mobile sont destinés au personnel de la clinique. Un portail patient constitue une extension distincte, à valider. La première version opérationnelle vise un seul établissement.

## 1. Version web — Laravel et React

### WEB-01 — Authentification et accès

- [x] Connexion et déconnexion par session Laravel.
- [x] Parcours de réinitialisation du mot de passe et paramètres du compte issus du starter.
- [x] Désactivation de l’inscription publique.
- [x] Création d’un administrateur en ligne de commande.
- [x] Contrôle des modules cliniques par permission selon le rôle.
- [x] Définir les permissions des administrateurs, médecins, secrétaires et caissiers.
- [x] Créer les écrans d’invitation, d’activation et de suspension des comptes.
- [x] Appliquer les permissions à chaque action et tester les refus d’accès.
- [x] Configurer et tester les invitations et la réinitialisation via le transport e-mail local.
- [ ] Valider la livraison vers une boîte réelle avec le fournisseur SMTP retenu.

Voir le [guide WEB-01 et la matrice des accès](WEB-01.md).

### WEB-02 — Gestion des patients

- [x] Créer une fiche avec prénom, nom, naissance et téléphone facultatif.
- [x] Afficher une liste paginée et modifier une fiche.
- [x] Valider les champs côté serveur.
- [x] Ajouter la recherche et les filtres.
- [x] Ajouter une page de détail avec les informations accessibles selon le rôle.
- [x] Ajouter les coordonnées facultatives (e-mail, adresse) et la détection des doublons d’identité.
- [x] Relier les rendez-vous au patient et afficher les rendez-vous accessibles sur sa fiche.
- [x] Relier les consultations au patient, avec historique de l’auteur et lecture des éléments explicitement partagés.
- [x] Relier les factures au patient avec accès réservé aux rôles de facturation.
- [ ] Finaliser la politique de conservation et de purge ; l’archivage réversible est disponible, sans suppression physique.

Voir le [guide WEB-02](WEB-02.md), notamment les limites du contrôle des homonymes.

- [x] Confirmer les homonymes distincts avec justification administrative et trace chiffrée.
- [x] Archiver et réactiver les patients sans suppression des historiques.

### WEB-03 — Rendez-vous

- [x] Créer les modèles et migrations des rendez-vous et disponibilités.
- [x] Afficher un calendrier et une liste par date, médecin et statut.
- [x] Planifier, déplacer et annuler un rendez-vous.
- [x] Gérer les statuts : prévu, confirmé, arrivé, terminé, annulé, absent.
- [x] Empêcher les chevauchements, y compris lors de demandes simultanées.
- [x] Définir le fuseau horaire de l’établissement et tester les horaires.

Voir le [guide WEB-03](WEB-03.md) pour les règles de disponibilité, les transitions et les limites.

### WEB-04 — Consultations

- [x] Associer une consultation à un patient et à un médecin.
- [x] Saisir motif, observations, constantes et compte rendu.
- [x] Gérer les états brouillon et validé.
- [x] Tracer les corrections après validation et l’identité de leur auteur.
- [x] Afficher l’historique des consultations selon les permissions.
- [x] Chiffrer le contenu des consultations et révisions, avec migration des valeurs existantes.

Voir le [guide WEB-04](WEB-04.md) pour les permissions cliniques, la validation et les corrections.

### WEB-05 — Dossier médical électronique

- [x] Définir la structure des antécédents, allergies et traitements.
- [x] Regrouper les informations cliniques dans une chronologie patient.
- [x] Ajouter les pièces jointes avec validation du format et de la taille.
- [x] Stocker les documents dans un espace privé et contrôler chaque téléchargement.
- [x] Journaliser la consultation et la modification du dossier.

Voir le [guide WEB-05](WEB-05.md) pour le périmètre par auteur, le chiffrement, les accès et les limites du traitement des documents.

- [x] Accorder et révoquer un partage patient en lecture seule entre médecins, limité aux éléments de l’auteur.

### WEB-06 — Facturation et paiements

- [x] Définir le catalogue des actes, les tarifs et la devise.
- [x] Créer les factures avec lignes, numérotation et totaux calculés côté serveur.
- [x] Enregistrer les paiements, y compris partiels, et calculer le solde.
- [x] Gérer les statuts : brouillon, émise, partiellement payée, payée, annulée.
- [x] Produire une facture et un reçu imprimables.
- [x] Prévoir les corrections et annulations traçables.
- [x] Tester les montants en unités mineures et les doubles soumissions.

Voir le [guide WEB-06](WEB-06.md) pour les devises, états, encaissements manuels et limites des documents imprimables.

### WEB-07 — Médecins et personnel

- [x] Créer les profils professionnels, fonctions et spécialités.
- [x] Associer chaque profil à un compte utilisateur.
- [x] Gérer les disponibilités et absences.
- [x] Réserver la gestion des comptes et rôles aux personnes autorisées.

Voir le [guide WEB-07](WEB-07.md) pour les profils, les absences et leur interaction avec le planning médical.

### WEB-08 — Tableau de bord et administration

- [x] Afficher le nombre réel de patients enregistrés.
- [x] Afficher les rendez-vous du jour, consultations et paiements selon le rôle.
- [x] Ajouter les filtres de période et les états vides.
- [x] Configurer les coordonnées, horaires et devise de l’établissement.
- [x] Autoriser les exports uniquement selon une politique définie et les tracer.

Voir le [guide WEB-08](WEB-08.md) pour les définitions des indicateurs, les paramètres et la politique d’export agrégé.

## 2. Version mobile — Flutter Android/iOS

### MOB-01 — Socle et navigation

- [x] Initialiser le projet Flutter Android/iOS.
- [x] Créer les écrans de connexion et d’accueil connecté MediCore.
- [x] Tester la connexion, les droits affichés, la révocation et le masquage en arrière-plan.
- [x] Construire la navigation patients, agenda et compte adaptée aux petits écrans.
- [x] Gérer chargement, absence de connexion, erreurs et listes vides.
- [x] Configurer l’URL par build, HTTPS obligatoire et exception HTTP locale en debug.
- [ ] Valider le déploiement et les périphériques de production.

### MOB-02 — Connexion du personnel

- [x] Ajouter le client API et l’écran de connexion.
- [x] Intégrer le stockage sécurisé du jeton (validation Keystore/Keychain sur appareil encore requise).
- [x] Gérer expiration, révocation et déconnexion.
- [x] Adapter les actions visibles au rôle, avec contrôle systématique côté serveur.
- [x] Ajouter la fermeture après inactivité et le masquage en arrière-plan ; recette sur appareils à compléter.

### MOB-03 — Patients

- [x] Rechercher un patient et parcourir une liste paginée.
- [x] Afficher la fiche patient autorisée.
- [x] Créer et modifier une fiche si le rôle le permet.
- [x] Afficher les erreurs de validation retournées par l’API.

### MOB-04 — Agenda et rendez-vous

- [x] Afficher l’agenda journalier avec choix de la date, passée ou à venir.
- [x] Filtrer par médecin et présenter les informations du rendez-vous.
- [x] Planifier, déplacer, annuler et mettre à jour le statut selon les permissions.
- [x] Présenter les conflits d’horaire retournés par le serveur.

### MOB-05 — Consultations et dossiers médicaux

- [ ] Afficher l’historique et les informations cliniques autorisées.
- [ ] Saisir ou compléter une consultation selon le rôle.
- [ ] Consulter les documents privés avec téléchargement contrôlé.
- [ ] Éviter la persistance locale de données cliniques dans la première version.

### MOB-06 — Factures et paiements

- [ ] Afficher les factures et soldes autorisés.
- [ ] Consulter le détail d’une facture et les paiements associés.
- [ ] Enregistrer un paiement si le rôle le permet, avec protection contre les doublons.
- [ ] Accéder aux reçus produits par le serveur.

### MOB-07 — Notifications et profil

- [x] Afficher les informations du compte connecté.
- [ ] Permettre leur modification mobile selon les droits.
- [ ] Ajouter les notifications de rendez-vous et leurs préférences.
- [ ] Gérer les autorisations et les jetons de notification des appareils.
- [ ] Exclure les informations médicales du contenu visible sur l’écran verrouillé.

### MOB-08 — Livraison

- [ ] Tester les parcours sur appareils Android et iOS.
- [ ] Vérifier l’accessibilité, les formulaires et les différentes tailles d’écran.
- [ ] Configurer les identifiants définitifs, signatures et versions.
- [ ] Préparer les versions de test, puis les éléments de publication.

## 3. Serveur et travaux communs

### API-01 — API partagée

- [x] Exposer l’API v1 de session, patients et agenda (voir [API/mobile](API-MOBILE.md)).
- [x] Mettre en place l’authentification mobile et la révocation des sessions.
- [x] Réutiliser les règles métier et permissions du web.
- [x] Standardiser validation, pagination, erreurs et dates du premier périmètre.
- [ ] Définir les contrats monétaires de la future API financière.
- [x] Documenter les endpoints et tester les accès directs non autorisés.

### SEC-01 — Protection et exploitation

- [x] Exclure les secrets, dépendances et bases SQLite du dépôt.
- [x] Ajouter des en-têtes interdisant la mise en cache des réponses web, API et exceptions.
- [x] Harmoniser la politique de mot de passe et configurer explicitement les proxies de confiance.
- [x] Fournir le diagnostic de production et les modèles Nginx/PHP/systemd (voir [hébergement](PRODUCTION.md)).
- [ ] Compléter la couverture des actions/refus sensibles, la surveillance et la protection des journaux métier déjà implémentés.
- [ ] Configurer HTTPS, secrets de production et protection des données stockées.
- [x] Fournir une sauvegarde SQLite chiffrée et une restauration isolée avec tests sur données fictives (voir [reprise et clés](RECOVERY.md)).
- [ ] Provisionner le coffre de clés, le stockage distant, la rétention et valider la reprise sur l’hébergement cible.
- [ ] Définir la conservation des données et les exigences applicables au déploiement.
- [ ] Si plusieurs établissements sont retenus, implémenter leur isolation avant leur ouverture.

### QA-01 — Qualité et livraison

- [x] Ajouter des tests serveur d’authentification, de patients et d’autorisation.
- [x] Définir les contrôles CI web et mobile dans GitHub Actions.
- [ ] Vérifier les résultats CI après publication du dépôt.
- [x] Mettre à jour et auditer les dépendances web (aucun avis npm/Composer après correction du 29 octobre).
- [x] Ajouter une recette navigateur des parcours critiques par rôle sur bureau et petit écran (voir [recette web](WEB-RECETTE.md)).
- [x] Étendre la recette automatisée à Firefox et WebKit et ajouter des contrôles axe, de thème sombre et de navigation clavier.
- [ ] Faire accepter la [recette humaine](RECETTE-MANUELLE.md) avec les responsables métier, les lecteurs d’écran et les appareils/navigateurs réellement installés.
- [ ] Préparer un environnement de démonstration avec données entièrement fictives.

- [x] Ajouter une référence de charge HTTP locale sur données fictives (voir [mesures](PERFORMANCE.md)).
- [ ] Qualifier la charge et l’endurance sur le moteur et l’hébergement cibles.

## 4. Ordre de réalisation proposé

| Lot | Objectif | Dépendances | Critère de fin |
| --- | --- | --- | --- |
| 1 | Rôles, personnel et fiches patients | Socle existant | Chaque rôle accède uniquement aux actions permises ; fiches recherchables. |
| 2 | Agenda et rendez-vous web | Lot 1 | Un rendez-vous peut être suivi sans chevauchement pour un médecin. |
| 3 | Consultations et dossier médical web | Lots 1 et 2, audit et stockage privé | Informations cliniques consultables et modifiables selon le rôle, avec traçabilité. |
| 4 | Facturation et paiements web | Lot 1, catalogue des actes | Totaux et soldes exacts ; paiements et corrections traçables. |
| 5 | API et première version mobile connectée | API-01, lots 1 et 2 | Connexion, patients et agenda fonctionnent sur appareil avec contrôles serveur. |
| 6 | Modules cliniques et financiers mobiles | Lots 3, 4 et 5 | Les parcours autorisés fonctionnent sur mobile sans duplication des règles métier. |
| 7 | Notifications et préparation au déploiement | Lots précédents | Vérifications de livraison, restauration et permissions terminées. |

Les protections, tests et contrôles d’accès accompagnent chaque lot. Le fonctionnement hors ligne, un portail patient, le paiement en ligne et les intégrations externes restent des extensions à cadrer séparément.
