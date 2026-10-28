# Feuille de route MediCore

Le [backlog web et mobile](FEATURES.md) détaille les fonctionnalités, les tâches et leur état.

## Réalisé

- Serveur Laravel et interface React/TypeScript avec Inertia.
- Connexion, déconnexion, réinitialisation du mot de passe et paramètres du compte issus du starter officiel.
- Inscription publique désactivée et création d’administrateurs en ligne de commande.
- Permissions par rôle contrôlées côté serveur, invitations et gestion des accès du personnel (voir [WEB-01](WEB-01.md)).
- Tableau de bord avec le nombre réel de patients.
- Création, recherche, filtres, liste paginée, détail et modification des fiches patients, coordonnées facultatives et prévention des doublons (voir [WEB-02](WEB-02.md)).
- Calendrier hebdomadaire, agenda du jour, disponibilités datées, statuts et prévention des chevauchements (voir [WEB-03](WEB-03.md)).
- Consultations du médecin auteur, brouillons, validation et historique des corrections (voir [WEB-04](WEB-04.md)).
- Dossier médical par médecin auteur, informations versionnées, documents privés chiffrés et journal des accès (voir [WEB-05](WEB-05.md)).
- Catalogue, factures versionnées, encaissements partiels, reçus imprimables et annulations tracées (voir [WEB-06](WEB-06.md)).
- Fiches professionnelles liées aux comptes, fonctions et spécialités, absences intégrées au planning et historique administratif (voir [WEB-07](WEB-07.md)).
- Socle Flutter Android/iOS avec écran de présentation, sans connexion serveur.
- Tests d’authentification, de validation et de refus d’accès ; vérifications automatiques.

## Prochain incrément

Développer les indicateurs administratifs et les paramètres de l’établissement (WEB-08). Les comptes, fiches professionnelles et absences sont disponibles dans WEB-01 et WEB-07.

## Incréments suivants

1. Compléter la préparation du dossier médical à la production : protections des données existantes, analyse des fichiers et politique de conservation.
2. Compléter la facturation selon les besoins du déploiement : mentions de l’établissement, règles tarifaires et opérations comptables supplémentaires.
3. API mobile authentifiée, stockage sécurisé des sessions et connexion Flutter.
4. Indicateurs administratifs fondés sur les données réelles.
5. Préparation à la production : isolation si plusieurs établissements, audit des accès, sauvegardes testées et vérification des exigences applicables.

Chaque module devra inclure ses validations et ses tests d’autorisation. La suppression de patients et l’export de données attendent une politique de conservation et d’accès.
