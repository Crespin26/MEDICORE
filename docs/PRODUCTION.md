# Hébergement de MediCore

Ce guide prépare une installation **sur un serveur Linux unique**, pour un établissement,
avec Nginx, PHP-FPM et SQLite sur disque local. Il ne déploie rien automatiquement.
Le web React est compilé avec Vite et servi par Laravel ; Flutter utilise la même origine
via `https://votre-domaine/api/v1`. Aucun serveur Node ou SSR n’est nécessaire en exploitation.

Une installation répliquée, une base sur volume réseau ou un changement vers MySQL/PostgreSQL
exigent une qualification des verrous, des courses concurrentes et de la restauration.
Le mécanisme de sauvegarde fourni ne prend actuellement en charge que SQLite.

## Fichiers fournis

| Fichier | Usage |
| --- | --- |
| [production.env.example](../deploy/production.env.example) | Configuration sans secrets à compléter dans un fichier privé |
| [nginx.conf](../deploy/nginx.conf) | HTTPS, racine `web/public`, limites d’upload et front controller unique |
| [php-production.ini](../deploy/php-production.ini) | Erreurs masquées, OPcache et limites PHP |
| [medicore-worker.service](../deploy/medicore-worker.service) | Worker supervisé, arrêt gracieux et redémarrage |
| [medicore-scheduler.service](../deploy/medicore-scheduler.service), [timer](../deploy/medicore-scheduler.timer) | Exécution du scheduler chaque minute |

Les noms de domaine, chemins de certificats, utilisateurs et socket PHP sont des exemples.
Les adapter avant installation. Le profil utilise PHP 8.5, version utilisée par les tests ;
PHP 8.2 minimum est déclaré par le projet, mais n’a pas été requalifié dans ce lot.

## Préparer le serveur

Installer Nginx, PHP-FPM/CLI, Composer et les extensions Laravel, notamment `pdo_sqlite`,
`sqlite3`, `sodium`, `zip`, `mbstring`, `fileinfo`, `curl`, `openssl`, `dom`, `xml` et `intl`.
Installer Node 24 uniquement sur la machine qui compile les assets. Vérifier :

```bash
php -v
php -m
composer check-platform-reqs --no-dev
```

Organiser les fichiers ainsi :

```text
/srv/medicore/
  current -> releases/<version>
  releases/<version>/web/         # code et vendor en lecture pour PHP-FPM
  shared/.env                    # secrets, hors racine HTTP
  shared/database/database.sqlite
  shared/storage/                # documents, logs, sessions, vues, maintenance
  shared/backups/                # 0700, archives privées
```

Le compte de déploiement possède le code. PHP-FPM et les unités systemd utilisent le même
compte applicatif (ici `www-data`) et écrivent uniquement dans `shared` et `web/bootstrap/cache`.
Créer les dossiers `shared/storage/app/medical`, `app/private`, `framework/cache/data`,
`framework/sessions`, `framework/views` et `logs`. Les dossiers privés sont en 0750,
la base et `.env` en 0640 ; les parents de sauvegarde sont en 0700 et les archives en 0600.
Accorder les accès de groupe nécessaires. Ne pas utiliser `chmod -R 777`.
La création des répertoires et la configuration du serveur nécessitent les droits de l’administrateur système.

## Construire une version

Utiliser `umask 0027` pour les commandes de livraison et de cache, afin que les nouveaux fichiers
restent privés au compte applicatif et à son groupe. Le cache de configuration contient les secrets.

Dans un checkout propre, lancer les contrôles du [guide de développement](DEVELOPMENT.md), puis :

```bash
cd web
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
npm ci
npm run build
```

Conserver `vendor/`, `public/build/` et les fichiers applicatifs dans l’artefact.
Ne pas livrer `.env`, `public/hot`, les bases locales, les archives, les fixtures de tests,
`node_modules/` ou les documents de développement. Ne jamais utiliser `artisan serve` ou Vite dev en production.
Les variables `VITE_*` sont publiques : aucune clé ni aucun mot de passe ne doit y figurer.
Le dossier HTTP doit pointer **uniquement vers `web/public`**.

## Première installation, base neuve

1. Installer la version dans `releases/<version>` ; remplacer son dossier `web/storage`
   par un lien vers `shared/storage`, lier `web/.env` à `shared/.env`, puis créer `current`.
   Ne jamais remplacer un stockage déjà rempli par un répertoire vide.
2. Compléter le modèle d’environnement avec le domaine réel et le transport SMTP.
   `MAIL_SCHEME=smtp` permet STARTTLS ; `smtps` désigne TLS implicite selon le fournisseur.
   Ne pas recopier une ancienne variable `MAIL_ENCRYPTION` : la configuration lit désormais `MAIL_SCHEME`.
3. **Uniquement pour une base neuve et vide**, générer `APP_KEY` avec `php artisan key:generate`.
   Provisionner une clé indépendante `MEDICORE_BACKUP_KEY=base64:...` de 32 octets depuis un gestionnaire
   de secrets. Séquestrer les clés hors du serveur et hors des archives, selon [RECOVERY.md](RECOVERY.md).
   Lors d’un import, conserver impérativement l’ancienne `APP_KEY` et les éventuelles `APP_PREVIOUS_KEYS`.
4. Créer le fichier SQLite privé à l’emplacement configuré. Puis, sous le compte applicatif,
   depuis `current/web` :

```bash
php artisan down --retry=60
php artisan migrate --force
php artisan medicore:create-admin
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan medicore:production-check
```

5. Adapter et tester Nginx (`nginx -t`), installer un certificat valide et son renouvellement,
   puis installer les unités systemd dans `/etc/systemd/system/` et lancer `systemctl daemon-reload`.
   Activer `medicore-scheduler.timer` et `medicore-worker.service` après les contrôles locaux.
6. Sortir de maintenance avec `php artisan up`. Vérifier les critères ci-dessous avant l’ouverture aux utilisateurs.

Ne lancer aucun seeder de démonstration sur la base hébergée.
Les migrations initialisent les paramètres de clinique ; le compte administrateur est créé explicitement.

## Mettre à jour une installation existante

Prévoir une fenêtre de maintenance : la migration de chiffrement des consultations n’est pas réversible
vers le texte clair. Procédure, sous les comptes appropriés :

1. Préparer et tester la nouvelle version sans toucher à `current` ni au stockage partagé.
2. Dans l’ancienne version, activer la maintenance ; arrêter le worker, le timer **et toute tâche
   de scheduler encore en cours**. Arrêter également imports et autres processus qui écrivent.
3. Créer une archive avec `medicore:backup`, puis effectuer sa restauration de contrôle vers un dossier
   neuf et privé selon [RECOVERY.md](RECOVERY.md). Copier l’archive chiffrée hors du serveur.
   Prévoir assez d’espace privé pour l’archive et ses fichiers temporaires.
4. Relier la nouvelle version aux mêmes `.env` et `storage`, puis basculer `current` pendant la maintenance.
   Reconstruire ses caches et appliquer les migrations :

```bash
php artisan config:cache
php artisan migrate --force
php artisan route:cache
php artisan view:cache
php artisan medicore:production-check
```

5. Recharger PHP-FPM, redémarrer le worker, réactiver le timer et quitter la maintenance après succès.
   Un échec laisse l’application en maintenance : corriger ou suivre le retour arrière ci-dessous.

Le nom du service PHP-FPM dépend de l’installation. Les liens de versions doivent être changés
atomiquement par l’outil de déploiement choisi. Les caches compilés sont privés et propres à chaque version.

## Contrôles avant ouverture

`php artisan medicore:production-check` renvoie 0 si ses contrôles locaux passent et 1 sinon.
La commande est en lecture seule : configuration effective, clés valides et indépendantes,
cookies, journalisation, mailer, extensions, assets, caches, stockage, connexion et migrations.
Elle n’affiche ni valeur de secret ni message brut d’erreur de connexion.
L’exécuter sous le même compte et avec le même PHP que l’application.
Elle ne certifie ni le serveur externe ni les sauvegardes distantes.

Vérifier sur l’URL hébergée :

- HTTP redirige vers HTTPS, certificat valide, cookies Secure/HttpOnly, absence de contenu mixte.
- `/up` répond 200 : c’est un signal de démarrage Laravel, **pas un test complet de la base ni des parcours**.
- Connexion, invitations et réinitialisations : un mail arrive réellement et ses liens utilisent le bon domaine.
- Parcours par rôle de la [recette manuelle](RECETTE-MANUELLE.md), téléchargement privé et impressions.
- Une URL `.env`, une base SQLite ou un fichier médical ne peut pas être téléchargé directement.
- Worker et timer tournent et leurs échecs sont surveillés ; les jetons expirés sont élagués.
- Supervision de l’espace disque, certificats, erreurs, disponibilité, charge et état des sauvegardes.
- Sauvegarde chiffrée distante, rétention définie et restauration répétée en environnement isolé.
- Charge et concurrence sur le serveur choisi : la [baseline locale](PERFORMANCE.md) n’est pas une capacité contractuelle.

Le fichier Nginx désactive les access logs contenant des recherches de patients ; les erreurs Nginx
restent limitées au niveau critique. Les journaux Laravel, PHP et systemd restent sensibles et privés.
Un niveau warning ne garantit pas leur anonymisation : ne pas y ajouter de données médicales,
limiter leurs accès/rétention et vérifier le contenu des incidents avant export vers un outil tiers.
Configurer une supervision sans identifiants dans les URL ou les étiquettes de métriques.

## Proxy TLS et accès mobile

Le profil Nginx termine lui-même TLS : laisser `TRUSTED_PROXIES` vide.
Si un équilibreur termine TLS en amont, adapter Nginx au réseau réel, limiter l’accès à l’origine
et renseigner uniquement les IP/CIDR des proxies contrôlés. Ne pas utiliser `*`.
Le middleware réinitialise les proxies par requête et ne fait pas confiance à un nom d’hôte de plateforme.
Tester le protocole détecté et l’IP client pour éviter liens HTTP et limitations partagées à tort.

Compiler Flutter avec `--dart-define=MEDICORE_API_URL=https://votre-domaine/api/v1`.
Les applications Android/iOS utilisent HTTPS et Bearer ; leur construction, signature et validation
sur appareils restent à effectuer selon [API-MOBILE.md](API-MOBILE.md) et [mobile/README.md](../mobile/README.md).
Aucune ouverture CORS universelle n’est nécessaire pour ces clients natifs.

## Retour arrière

Ne pas exécuter un `migrate:rollback` global ni régénérer les clés.
Un simple retour au code précédent n’est sûr que si son schéma et ses casts restent compatibles.
Sinon, en maintenance et sans aucun writer, restaurer ensemble la base et les documents de l’archive
validée avec les clés correspondantes, puis remettre une version compatible. Les écritures postérieures
à l’archive doivent être traitées avec le responsable d’exploitation avant reprise.
Conserver la base défaillante dans un stockage privé pour analyse ; ne pas l’écraser automatiquement.

## Références

- [Déploiement Laravel 12](https://laravel.com/docs/12.x/deployment).
- [Requêtes et proxies de confiance](https://laravel.com/docs/12.x/requests#configuring-trusted-proxies).
