# Feuille de route MediCore

Le [backlog web et mobile](FEATURES.md) détaille les fonctionnalités, les tâches et leur état.

## Réalisé

- Serveur Laravel et interface React/TypeScript avec Inertia.
- Connexion, déconnexion, réinitialisation du mot de passe et paramètres du compte issus du starter officiel.
- Inscription publique désactivée et création d’administrateurs en ligne de commande.
- Permissions par rôle contrôlées côté serveur, invitations et gestion des accès du personnel (voir [WEB-01](WEB-01.md)).
- Tableau de bord avec le nombre réel de patients.
- Création, liste paginée et modification des fiches patients, validation serveur.
- Socle Flutter Android/iOS avec écran de présentation, sans connexion serveur.
- Tests d’authentification, de validation et de refus d’accès ; vérifications automatiques.

## Prochain incrément

Définir le fonctionnement d’un établissement et les responsabilités des administrateurs, médecins, secrétaires et caissiers. Implémenter la gestion du personnel et les permissions, puis les rendez-vous avec disponibilités, statuts et prévention des conflits.

## Incréments suivants

1. Consultations et dossiers médicaux avec historique des changements et contrôle des accès par métier.
2. Factures et paiements avec devise explicite, montants en unités mineures et traçabilité.
3. API mobile authentifiée, stockage sécurisé des sessions et connexion Flutter.
4. Indicateurs administratifs fondés sur les données réelles.
5. Préparation à la production : isolation si plusieurs établissements, audit des accès, sauvegardes testées et vérification des exigences applicables.

Chaque module devra inclure ses validations et ses tests d’autorisation. La suppression de patients et l’export de données attendent une politique de conservation et d’accès.
