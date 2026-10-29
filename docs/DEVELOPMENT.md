# Développement local

## Web et serveur

Prérequis : PHP 8.2 ou supérieur avec les extensions Laravel et `pdo_sqlite`/`sqlite3`, Composer 2, Node.js 22 ou 24 et npm. Les fichiers de verrouillage fixent les dépendances.

```bash
cd web
composer install
cp .env.example .env
php artisan key:generate
# Créer le fichier uniquement s’il n’existe pas.
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
php artisan medicore:create-admin
composer run dev
```

Ouvrir http://localhost:8000. La commande de création demande le nom, l’adresse e-mail et un mot de passe masqué d’au moins 12 caractères. Aucun compte ni mot de passe par défaut n’est fourni. Ne pas écraser un `.env` existant ni régénérer une clé sur une installation contenant des données.

Les permissions des administrateurs, médecins, secrétaires et caissiers sont décrites dans le [guide WEB-01](WEB-01.md). Les administrateurs peuvent inviter le personnel, attribuer un rôle, suspendre et réactiver les comptes depuis `/staff`. Le rôle historique `staff` reste sans permission clinique jusqu’à attribution d’un rôle. L’inscription publique est désactivée. L’authentification web utilise les sessions Laravel et la protection CSRF ; les tentatives de connexion sont limitées.

SQLite sert au développement d’un seul établissement. Aucune isolation entre établissements n’est implémentée. Les huit modules web sont décrits dans les guides WEB-01 à WEB-08, notamment les consultations, dossiers médicaux et documents privés. Consulter le [bilan web](BILAN-WEB.md) pour leurs limites. Utiliser des données fictives.

Le courrier utilise le journal local par défaut : les liens de réinitialisation y apparaissent. Configurer un transport mail avant tout autre usage.

### Vérifications

```bash
php artisan test
npm run typecheck
npm run lint:check
npm run build
```

Sur la machine de préparation, le pilote SQLite a été extrait temporairement sous `/tmp/medicore-php` sans installation système. Si ce dossier existe encore, les commandes PHP peuvent être lancées ainsi :

```bash
php -d extension=/tmp/medicore-php/usr/lib/php/20250925/pdo_sqlite.so -d extension=/tmp/medicore-php/usr/lib/php/20250925/sqlite3.so artisan migrate
```

Pour un usage durable, installer les extensions SQLite correspondant à la version locale de PHP. Une erreur « could not find driver » indique leur absence.

## Mobile

Consulter [mobile/README.md](../mobile/README.md). Flutter doit satisfaire la contrainte Dart du `pubspec.yaml`. L’application utilise l’API v1 de session, patients et agenda ; voir le [guide API/mobile](API-MOBILE.md) et les commandes de test HTTP avec fixture isolée.

Le lanceur Snap échoue dans l’environnement de préparation. Le SDK fonctionne directement via `/home/crespin/snap/flutter/common/flutter/bin/flutter`.

## Avant un déploiement

Cette version est destinée au développement local. Les permissions métiers, plusieurs historiques et le chiffrement de certaines données existent déjà. Il reste notamment à valider l’organisation des établissements, renforcer la protection des journaux et provisionner les sauvegardes, les clés et la reprise sur l’hébergement cible. Le contenu des consultations dispose désormais d’une migration de chiffrement ; des commandes SQLite de sauvegarde chiffrée et restauration isolée sont disponibles. Lire le [guide de reprise et de gestion des clés](RECOVERY.md) avant toute migration de données existantes. Préparer une configuration de production avec HTTPS, `APP_DEBUG=false`, cookies sécurisés, secrets dédiés et transport mail, puis vérifier les obligations du lieu de déploiement.

## Recette web et nouvelles règles métier

La [recette web](WEB-RECETTE.md) décrit l’exécution Playwright sur base temporaire, les homonymes confirmés par l’administrateur, l’archivage réversible et les partages en lecture seule entre médecins. Appliquer les migrations après sauvegarde contrôlée avant d’utiliser ces nouveaux écrans sur une base existante. Les comptes fictifs des tests ne sont jamais provisionnés dans la base active.
