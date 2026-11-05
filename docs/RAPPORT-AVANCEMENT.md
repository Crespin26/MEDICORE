# MediCore — Rapport complet d’avancement

**Date : 4 novembre 2026.** Référence du code examinée : `32a966e` sur `main`.

Ce rapport distingue le code livré, les validations déjà réalisées et la mise en service.
Il repose sur les sources, les routes, le backlog, les guides, l’artefact Android et des
contrôles locaux en lecture seule. Les suites de tests n’ont pas été relancées pour ce rapport.

## 1. Situation générale

| Partie | État constaté |
| --- | --- |
| Web Laravel / React | Huit modules du périmètre initial implémentés. |
| Backend et API | Logique métier partagée ; API v1 de connexion, patients et rendez-vous livrée. |
| Mobile Flutter | Première version connectée pour le personnel : connexion, patients, agenda et compte. |
| Android | SDK et Gradle installés ; APK debug compilé et signature vérifiée le 30 octobre. Fichier toujours présent. |
| iOS | Projet et configuration présents ; compilation et validation sur appareil non réalisées. |
| Base locale du dépôt | 15 migrations appliquées ; aucune migration du dossier courant en attente, vérification du 4 novembre. |
| Hébergement | Modèles et procédures fournis ; aucun environnement de production qualifié. |
| Git | Arbre propre au début du rapport ; `main` sans écart affiché avec la référence locale `origin/main`. Aucun état distant GitHub n’a été interrogé. |

Le périmètre actuel concerne le personnel d’un seul établissement. Un portail patient,
le multi-établissements, le paiement en ligne et la synchronisation hors ligne ne sont pas livrés.

## 2. Architecture livrée

- `web/` : Laravel 12, React 19, TypeScript et Inertia ; SQLite pour le profil local qualifié.
- `web/app/Services/` : réservation, consultations, facturation, personnel, indicateurs,
  partage clinique, audit, sauvegarde et contrôle de restauration.
- `web/app/Policies/` et middlewares : autorisations générales et contrôle des éléments cliniques.
- `mobile/` : Flutter Android/iOS, client HTTP partagé et stockage sécurisé du jeton.
- `docs/` : périmètre, règles des modules, API, maintenance, recette, reprise et hébergement.
- `deploy/` : exemples Nginx, PHP, environnement de production et unités systemd.
- `.github/workflows/checks.yml` : contrôles automatisés web/mobile et tests d’intégration.
- `scripts/run-mobile-usb.sh` : sélection du téléphone autorisé, transfert USB du port 8000
  et lancement Flutter avec l’API locale.

Les règles patients et rendez-vous sont réutilisées par le web et l’API. Les validations,
conflits et permissions ne sont pas recopiés dans Flutter.

## 3. Web : fonctionnalités réalisées

### WEB-01 — Authentification et contrôle des accès

- Connexion et déconnexion par session Laravel, protection CSRF et limitation des tentatives.
- Inscription publique désactivée ; création explicite d’un administrateur en ligne de commande.
- Invitations, activation à usage unique, expiration, renvoi et suspension des comptes.
- Réinitialisation et changement du mot de passe ; politique commune d’au moins 12 caractères.
- Permissions centralisées dans l’énumération des rôles et contrôles appliqués côté serveur.
- Gestion du profil et de l’apparence de l’interface.

| Rôle | Capacités principales |
| --- | --- |
| Administrateur | Gestion administrative, patients, planning, facturation, personnel, paramètres et exports. |
| Médecin | Lecture des identités patients, propre agenda, consultations et dossier clinique selon la propriété et les partages autorisés. |
| Secrétaire | Gestion des fiches patients et des rendez-vous ; lecture de sa fiche professionnelle. |
| Caissier | Facturation, encaissements autorisés et lecture de sa fiche professionnelle. |

Le rôle administrateur ne donne pas automatiquement accès aux contenus cliniques.
Le transport mail local a été testé ; la réception effective avec le fournisseur SMTP cible reste à valider.

### WEB-02 — Patients

- Création, liste paginée, recherche, filtres, fiche détaillée et modification.
- Identité et coordonnées : prénom, nom, naissance, téléphone, e-mail et adresse selon les champs requis/facultatifs.
- Normalisation et détection des doublons d’identité, avec contrainte d’unicité et gestion des courses.
- Confirmation administrative motivée de personnes distinctes portant la même identité.
- Archivage et réactivation avec historique, sans suppression physique.
- Refus d’archivage en présence de rendez-vous ou factures encore ouverts.
- Résumés des rendez-vous, consultations et factures selon les droits de l’utilisateur.

### WEB-03 — Rendez-vous et disponibilités

- Agenda journalier et aperçu hebdomadaire, avec filtres de date, médecin et statut.
- Création, déplacement, annulation et transitions : prévu, confirmé, arrivé, terminé, annulé, absent.
- Disponibilités datées des médecins, horaires d’ouverture et prise en compte des absences.
- Contrôle des conflits médecin/patient, y compris entre demandes simultanées.
- Limitation des médecins à leur propre agenda et à leurs actions autorisées.
- Stockage UTC, saisie/affichage dans le fuseau clinique et contrôle des horaires ambigus.

Les disponibilités récurrentes et rappels automatiques restent des extensions.

### WEB-04 — Consultations

- Association à un patient, un médecin et éventuellement un rendez-vous éligible.
- Saisie du motif, des observations, des constantes et du compte rendu.
- Brouillon, validation, contrôle de version et corrections justifiées.
- Historique des révisions, identité de l’auteur et audit des opérations concernées.
- Chiffrement du contenu et des révisions ; migration des anciennes valeurs livrée et appliquée localement.
- Écriture réservée à l’auteur ; lecture élargie uniquement par les partages explicites autorisés.

### WEB-05 — Dossier médical et documents

- Antécédents, allergies et traitements déclarés, avec états et révisions.
- Chronologie des consultations, informations et documents autorisés.
- Dépôt PDF/JPEG/PNG limité à 10 Mo, vérification du type réel et de l’extension.
- Stockage privé chiffré, contrôle des téléchargements et de l’intégrité des fichiers.
- Journal des accès et modifications concernés.
- Partage direct en lecture seule entre médecins pour les éléments de l’auteur sur un patient ; révocation tracée.

Le partage ne permet ni de corriger le contenu d’un autre auteur ni de le repartager
transitivement. L’antivirus des fichiers n’est pas intégré.

### WEB-06 — Facturation et paiements

- Catalogue des actes, tarifs, activation et versions des prestations.
- Factures avec lignes, quantités, numérotation et totaux calculés côté serveur.
- États : brouillon, émise, partiellement payée, payée, annulée.
- Paiements reçus enregistrés manuellement, encaissements partiels, reçus et solde.
- Annulations comptables motivées, historisées et avec recalcul du solde.
- Factures et reçus imprimables en HTML authentifié.
- Protection contre les doubles soumissions par idempotence et transactions.
- Montants entiers en unités mineures pour XOF, EUR et USD ; pas de mélange de devises.

Aucun paiement bancaire en ligne, remboursement effectif, traitement fiscal complet,
assurance ou génération PDF serveur n’est livré.

### WEB-07 — Médecins et personnel

- Fiches professionnelles reliées aux comptes : fonction, service, spécialités et coordonnées professionnelles.
- Gestion du matricule et contrôle de version des fiches.
- Déclaration et annulation d’absences, avec motifs et historique.
- Refus des conflits avec les absences ou rendez-vous actifs.
- Consultation de sa fiche par le membre du personnel ; gestion réservée aux rôles autorisés.

La paie, les contrats, soldes de congés et circuits d’approbation restent hors du périmètre livré.

### WEB-08 — Tableau de bord et administration

- Indicateurs réels de patients, rendez-vous, consultations et finances selon le rôle.
- Filtres de période, présentation des états vides et séparation des devises.
- Coordonnées, horaires et devise de l’établissement, avec versions et historique des changements.
- Contrôle des changements d’horaires pour préserver les rendez-vous actifs.
- Exports CSV de statistiques agrégées, autorisés et tracés avec justification et empreinte.

## 4. API v1 réalisée

Base : `/api/v1`, réponses JSON privées.

| Domaine | Routes livrées |
| --- | --- |
| Session | `POST /session`, `GET /me`, `DELETE /session` |
| Patients | `GET/POST /patients`, `GET/PATCH /patients/{id}` |
| Rendez-vous | `GET/POST /appointments`, `PATCH /appointments/{id}`, `PATCH /appointments/{id}/status` |

- Jetons Sanctum Bearer, empreinte stockée en base, capacité mobile et expiration de huit heures.
- Refus des comptes inactifs, rôles inconnus, jetons expirés/révoqués et accès par cookie seul.
- Révocation des jetons lors d’un changement de mot de passe, de rôle ou de statut.
- Validation, pagination, dates, erreurs 401/403/422/429 et limitation des requêtes.
- Nettoyage quotidien des anciens jetons expirés déclaré au scheduler.

Les API des consultations, dossiers, documents, factures, paiements et notifications restent à développer.
Les contrats détaillés sont dans [API-MOBILE.md](API-MOBILE.md).

## 5. Mobile Flutter réalisé

- Connexion du personnel, restauration de session et déconnexion.
- Jeton dans le stockage sécurisé ; aucun mot de passe ni dossier patient persisté par le code applicatif.
- Navigation adaptée au rôle entre patients, agenda et compte.
- Recherche, pagination, détail, création et modification des patients selon les permissions.
- Agenda quotidien, sélection de date et de médecin, disponibilités et détails des rendez-vous.
- Planification, déplacement, annulation et statuts autorisés ; affichage des conflits serveur.
- Gestion des chargements, listes vides, erreurs réseau, validation et session révoquée.
- Masquage en arrière-plan et fermeture après cinq minutes d’inactivité.
- HTTPS requis ; HTTP local autorisé uniquement en debug avec activation explicite.
- Android : sauvegarde applicative désactivée et `FLAG_SECURE` déclaré.

La reprise peut réinitialiser la navigation et les saisies non enregistrées. Le fonctionnement
réel du stockage sécurisé, du cycle de vie et de l’accessibilité reste à recetter sur appareil.

### Android et iOS

L’APK debug du 30 octobre est présent :
`mobile/build/app/outputs/flutter-apk/app-debug.apk`, environ 151,8 Mio, version `1.0.0+1`.
Sa compilation et sa signature ont été vérifiées lors de sa création. Il cible Android API 36,
avec API 24 minimum et architectures ARM, ARM64 et x86_64.

Le SDK Android, le NDK, les outils de compilation, Gradle et CMake ont été installés pour ce lancement.
L’APK debug n’est pas une livraison de production. Les identifiants définitifs,
la signature de distribution et la publication restent à préparer.
Le projet iOS est présent ; aucun build Xcode ni essai iPhone n’est validé.

## 6. Sécurité, sauvegardes et maintenabilité livrées

- Contrôles serveur par rôle, auteur et partage ; tests des accès directs interdits.
- Réponses web/API et exceptions non mises en cache ; en-têtes de confidentialité.
- Chiffrement des contenus cliniques concernés, révisions et documents, sans prétendre chiffrer toute la base.
- Journaux cliniques et historiques métier pour plusieurs opérations sensibles.
- Sauvegarde SQLite/documents dans une archive chiffrée avec clé indépendante.
- Restauration vers une nouvelle cible privée, contrôle du schéma, des clés et des documents.
- Procédure de conservation des clés et de reprise ; aucun retour automatique des consultations vers le texte clair.
- Contrats de fonctions documentés en PHPDoc/JSDoc/Dart, services métier et permissions centralisées.
- Correction du suivi du thème système après navigation et du comportement lorsque le stockage navigateur est bloqué.
- Configuration explicite des proxies, politique de mot de passe harmonisée et délai SMTP borné.

Le 30 octobre, une sauvegarde locale chiffrée et sa restauration isolée ont précédé
l’application des trois dernières migrations. Le 4 novembre, le fichier SQLite du dépôt
contient les 15 migrations attendues. Aucune donnée patient n’a été consultée pour ce rapport.

## 7. Tests et validations consignés

| Contrôle | Dernière preuve disponible |
| --- | --- |
| PHPUnit, revue du 30 octobre | 201 tests, 2 141 assertions réussies. |
| Contrôles ciblés après ajustement du diagnostic | 10 tests, 34 assertions ; sous-ensemble, à ne pas ajouter au total global. |
| Playwright, revue du 30 octobre | 58 scénarios Chromium bureau/petit écran réussis. |
| Campagne navigateur précédente du 29 octobre | 108 scénarios multi-navigateurs ; Firefox/WebKit non rejoués lors de la revue du 30. |
| Flutter | 11 tests unitaires/widget réussis et analyse sans anomalie lors de la dernière revue. |
| Intégration HTTP Flutter/Laravel | Un test réel exécuté séparément sur fixture temporaire dans la campagne précédente. |
| Qualité web | TypeScript application/tests, ESLint, Pint, validation Composer et build Vite réussis. |
| Préparation serveur | Diagnostic production sur installation temporaire, syntaxe Nginx et unités systemd vérifiés. |
| Android | Build APK debug et signature vérifiés le 30 octobre ; appareil non validé. |

Les résultats proviennent de campagnes distinctes et ne s’additionnent pas.
Le test HTTP mobile est volontairement ignoré par un `flutter test` standard sans sa fixture.
La CI décrit ces contrôles ; ses résultats distants n’ont pas été vérifiés pour ce rapport.

Une baseline locale a aussi testé 100 requêtes de lecture sur 1 001 patients et 10 004 rendez-vous
fictifs, avec concurrence 5 puis 10 : toutes les réponses étaient HTTP 200, p95 d’environ
120 et 153 ms. Ces mesures courtes sur serveur PHP de développement/SQLite ne prouvent
pas la capacité d’un futur hébergement. Voir [PERFORMANCE.md](PERFORMANCE.md).

## 8. Préparation à l’hébergement

Livrés : exemples Nginx HTTPS, PHP de production, environnement sans secrets, worker,
service/timer du scheduler, procédure de déploiement et de retour arrière.
La commande `php artisan medicore:production-check` contrôle la configuration effective,
les dépendances, caches, assets, permissions de stockage et état des migrations sans afficher les secrets.

Le profil préparé vise un serveur unique et SQLite. Il reste à choisir et configurer l’hébergeur,
le domaine, les certificats, SMTP, le coffre de clés, les sauvegardes distantes, les alertes,
la supervision et la recette sur l’environnement cible. Les exemples n’ont pas installé de services
ni déployé une production. Voir [PRODUCTION.md](PRODUCTION.md).

## 9. État opérationnel vérifié le 4 novembre

- Le serveur local précédemment démarré ne répond plus sur `127.0.0.1:8000` :
  `/up`, `/login` et `/api/v1/me` ne sont pas joignables lors de ce contrôle.
- Aucun téléphone n’apparaît dans `adb devices -l`.
- L’APK Android existe toujours ; aucune installation sur téléphone ni recette physique n’est déclarée réussie.
- L’état actuel des workers et du scheduler n’a pas été contrôlé ; leur démarrage du 30 octobre
  ne permet pas de les considérer actifs aujourd’hui.

Aucun serveur n’a été redémarré et aucune migration n’a été exécutée pour établir ce rapport.

## 10. Ce qui reste avant une mise en service complète

1. Redémarrer l’environnement local si nécessaire et connecter un téléphone reconnu par ADB.
2. Installer/recetter le mobile patients/agenda sur Android ; construire et valider iOS sur macOS/Xcode.
3. Développer MOB-05 : consultations, dossier médical et documents privés via API et Flutter.
4. Développer MOB-06 : factures, paiements, reçus et idempotence mobile.
5. Compléter MOB-07 : modification du profil, notifications et préférences.
6. Valider la recette humaine web/mobile et les besoins métier réels, notamment facturation et partage clinique.
7. Mettre en service l’hébergement de recette, SMTP réel, secrets, sauvegardes distantes et restauration.
8. Finaliser antivirus des fichiers, rétention/purge, protection/surveillance des journaux et choix de MFA.
9. Qualifier charge, endurance et concurrence sur l’hébergement cible ; vérifier la CI distante.
10. Préparer les signatures et identifiants définitifs pour la distribution mobile.

Les prescriptions, le paiement en ligne, les assurances, la paie, le portail patient,
le multi-établissements et le mode hors ligne sont des extensions à choisir selon le périmètre métier.

## Documents de référence

- [Fonctionnalités et tâches](FEATURES.md).
- [Maintenance et architecture](MAINTENANCE.md).
- [API et mobile](API-MOBILE.md), [lancement Flutter](../mobile/README.md).
- [Recette navigateur](WEB-RECETTE.md) et [recette humaine](RECETTE-MANUELLE.md).
- [Sauvegarde et reprise](RECOVERY.md), [hébergement](PRODUCTION.md).
- [Travaux restants et historique des mises à jour](RESTE-A-FAIRE.md).
