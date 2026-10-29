# MediCore — Plateforme de gestion clinique

MediCore est un projet de plateforme SaaS HealthTech destiné à faciliter l’administration des établissements de santé. Il vise à centraliser les opérations médicales, administratives et financières dans un environnement sécurisé, accessible sur le web et sur mobile.

> **Statut :** socle de développement initialisé. Le web propose une authentification réservée au personnel, les rôles et invitations, un tableau de bord et la création, la recherche, les filtres, les fiches détaillées et la modification des patients, la confirmation administrative des homonymes et l’archivage réversible. La gestion des rendez-vous inclut un agenda, les disponibilités et le suivi des statuts. Les consultations proposent des brouillons, une validation et un historique des corrections réservé en écriture au médecin auteur, avec partage explicite en lecture seule. Le dossier médical ajoute les antécédents, allergies, traitements déclarés, documents privés et journal des accès du médecin auteur. La facturation inclut un catalogue, des factures, des paiements partiels et des reçus imprimables. Le personnel dispose de fiches professionnelles, fonctions, spécialités et absences prises en compte dans le planning. Le tableau de bord adapte les indicateurs aux rôles et propose des paramètres d’établissement ainsi que des exports statistiques contrôlés. Le mobile dispose d’un écran d’accueil Flutter ; les modules mobiles et la préparation à la production restent à réaliser.

## Fonctionnalités prévues

| Module | Périmètre envisagé |
| --- | --- |
| Patients | Création et mise à jour des fiches patients, recherche et historique. |
| Rendez-vous | Planification, modification, annulation et suivi des rendez-vous. |
| Dossiers médicaux électroniques | Centralisation des antécédents, observations et documents médicaux. |
| Consultations | Saisie des comptes rendus et suivi des consultations. |
| Facturation et paiements | Émission des factures, enregistrement des paiements et suivi des soldes. |
| Médecins et personnel | Gestion des profils, des fonctions et des disponibilités. |
| Tableau de bord administratif | Vue d’ensemble de l’activité clinique et financière. |
| Authentification et accès | Connexion des utilisateurs et autorisations selon leurs responsabilités. |

## Technologies envisagées

- **Laravel** : API et logique métier côté serveur.
- **React.js** : interface web de gestion clinique.
- **Flutter** : application mobile.

Le serveur et le web React/TypeScript avec Inertia sont dans `web/` (Laravel 12, React 19). Le projet Flutter Android/iOS est dans `mobile/`. Les versions résolues sont enregistrées dans les fichiers de verrouillage.

Consulter le [guide de démarrage](docs/DEVELOPMENT.md), la [feuille de route](docs/ROADMAP.md) et la [liste des fonctionnalités et tâches web/mobile](docs/FEATURES.md).

Le [bilan complet de la partie web](docs/BILAN-WEB.md) récapitule les modules livrés, les tests, les limites et les priorités avant production.

Le [guide de reprise et de gestion des clés](docs/RECOVERY.md) décrit le chiffrement des consultations, les sauvegardes SQLite et les exercices de restauration.

La [recette web et les règles métier](docs/WEB-RECETTE.md) décrivent les scénarios navigateur, les homonymes, l’archivage et le partage entre médecins.

## Confidentialité et sécurité

Le dépôt de développement est destiné à rester **privé**. Ce choix ne remplace pas les protections nécessaires à une application manipulant des informations médicales.

Les exigences à définir et à vérifier avant toute utilisation avec des données réelles comprennent :

- Des permissions par rôle, limitées aux besoins de chaque utilisateur et contrôlées côté serveur.
- L’isolation des données entre établissements si plusieurs structures utilisent le service.
- Le chiffrement des communications, la protection des données stockées et la gestion des secrets.
- La traçabilité des accès et des modifications, sans exposer de données médicales dans les journaux techniques.
- Les sauvegardes, les tests de restauration et les règles de conservation des données.
- L’identification des obligations applicables selon les pays de déploiement et les usages retenus.

Aucune garantie de sécurité ou de conformité n’est revendiquée à ce stade. Ne pas ajouter au dépôt de dossiers patients réels, de pièces médicales, d’identifiants ou de secrets.

## Version de démonstration

Une démonstration pourra être créée séparément avec des données entièrement fictives, sans reprendre de code confidentiel. Sa publication devra être précédée d’une vérification de son contenu et de son historique.

## Présentation du projet

**Nom de dépôt envisagé :** `medicore-health-platform`

**Description courte pour GitHub :**

> Full-stack healthcare management platform for patient records, appointments, medical consultations, billing, and administrative operations.

**Description pour un portfolio :**

> Écosystème web et mobile de gestion clinique permettant de centraliser les dossiers patients, les consultations, les rendez-vous et la facturation.

Ces formulations décrivent la cible du projet. Tant que les applications ne sont pas implémentées, accompagner leur utilisation publique de la mention « projet en développement ».
