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

Le profil `admin` peut consulter le tableau de bord et gérer les patients. Le profil `staff`, attribué par défaut, n’a pas accès aux modules cliniques. Aucun écran de gestion des rôles n’est encore fourni. L’inscription publique est désactivée. L’authentification web utilise les sessions Laravel et la protection CSRF ; les tentatives de connexion sont limitées par le starter.

SQLite sert au développement d’un seul établissement. Aucune isolation entre établissements n’est implémentée. Les données patients de cette première version se limitent à l’identité, la naissance et le téléphone ; les dossiers médicaux ne sont pas encore gérés. Utiliser des données fictives.

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

Consulter [mobile/README.md](../mobile/README.md). Flutter doit satisfaire la contrainte Dart du `pubspec.yaml`. L’application n’est pas encore reliée au serveur ; aucune API mobile n’est exposée.

Le lanceur Snap échoue dans l’environnement de préparation. Le SDK fonctionne directement via `/home/crespin/snap/flutter/common/flutter/bin/flutter`.

## Avant un déploiement

Cette version est destinée au développement local. Il reste notamment à définir l’organisation des établissements, les permissions métiers, l’audit des accès, le chiffrement au repos, les sauvegardes et leur restauration. Préparer une configuration de production avec HTTPS, `APP_DEBUG=false`, cookies sécurisés, secrets dédiés et transport mail, puis vérifier les obligations du lieu de déploiement.
