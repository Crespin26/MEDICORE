# Protection des consultations et reprise SQLite

## Périmètre livré

Les motifs, observations, comptes rendus et constantes des consultations sont chiffrés par les casts Laravel, ainsi que les instantanés et motifs de correction de leurs révisions. La migration `2026_10_29_000001_encrypt_consultation_content` transforme les valeurs existantes par lots dans une transaction, sans modifier les identifiants, dates, versions ou relations. Elle conserve les valeurs nulles.

Sur SQLite, les colonnes existantes acceptent déjà les chaînes chiffrées : aucune reconstruction des tables référencées n'est nécessaire. Sur les autres moteurs, les colonnes concernées sont élargies en texte ; cette branche n'a pas été validée sur un serveur MySQL/PostgreSQL. Il faut une qualification dédiée avant changement de moteur.

Le chiffrement utilise `APP_KEY`. Il complète celui des entrées et documents médicaux déjà présents. Il ne chiffre pas toutes les colonnes de la base : identités, liens, dates et métadonnées demeurent partiellement en clair. Une protection du volume et des accès au serveur reste nécessaire.

Les deux commandes de reprise sont exclusivement en ligne de commande, sans endpoint web :

```bash
php artisan medicore:backup /chemin/prive/clinique-2026-10-29.mcb
php artisan medicore:restore /chemin/prive/clinique-2026-10-29.mcb /chemin/prive/recette-restauration
```

La création est limitée à une **base SQLite sur disque**. Les extensions PHP `pdo_sqlite`, `sodium` et `zip` sont nécessaires ; les tests CI les demandent explicitement. Le dossier parent doit exister avec des permissions `0700`. Les destinations sous `public/` et dans le stockage médical sont refusées. Aucun fichier ou dossier existant n'est remplacé.

## Clés et conservation séparée

- `APP_KEY` : clé applicative actuelle, indispensable pour déchiffrer les données médicales.
- `APP_PREVIOUS_KEYS` : anciennes clés applicatives, séparées par des virgules. Laravel les essaie à la lecture après la clé actuelle.
- `MEDICORE_BACKUP_KEY` : clé indépendante de 32 octets, préfixée `base64:`, destinée uniquement à l'archive. Une clé identique à une clé applicative est refusée.

Générer la clé de sauvegarde dans un environnement privé, puis la placer dans le gestionnaire de secrets ou l'environnement sécurisé de l'application :

```bash
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Ne pas fournir les clés en arguments des commandes de sauvegarde/restauration : elles pourraient apparaître dans l'historique du shell ou la liste des processus. Ne pas les copier dans le dépôt, les logs, les tickets ou l'archive. `.env.example` contient uniquement des emplacements vides.

Conserver hors de l'archive, dans un coffre distinct et accessible aux responsables de reprise : la clé de sauvegarde correspondant à chaque archive, toutes les clés applicatives requises et leur période d'utilisation. Prévoir une seconde copie protégée et tester l'accès au coffre pendant les exercices de restauration. La perte des clés rend la récupération impossible.

### Rotation applicative

La gestion de `APP_PREVIOUS_KEYS` suit le [mécanisme officiel Laravel](https://laravel.com/docs/12.x/encryption#gracefully-rotating-encryption-keys).

1. Vérifier l'escrow des clés et restaurer une sauvegarde de contrôle avant changement.
2. Générer une nouvelle clé dans le coffre, placer l'ancienne `APP_KEY` dans `APP_PREVIOUS_KEYS` en conservant les clés plus anciennes encore nécessaires, puis provisionner la nouvelle `APP_KEY`.
3. Actualiser le cache de configuration selon le déploiement et redémarrer les processus persistants.
4. Vérifier la lecture des dossiers, révisions et documents existants ainsi que les nouvelles écritures ; faire une nouvelle sauvegarde et une restauration de contrôle.

Les nouvelles écritures utilisent la nouvelle clé. Les anciennes valeurs ne sont **pas automatiquement rechiffrées**. Ne pas retirer les anciennes clés tant qu'elles sont nécessaires aux données actives ou aux sauvegardes conservées. Ne pas lancer `key:generate` à l'aveugle sur une application contenant des données chiffrées.

Pour une rotation de `MEDICORE_BACKUP_KEY`, provisionner une nouvelle clé indépendante pour les prochaines archives et conserver l'ancienne dans le coffre pour restaurer les archives historiques. Les archives existantes ne sont pas modifiées.

## Sauvegarde cohérente

1. Préparer un dossier privé hors du répertoire public, avec assez d'espace pour la base, les documents, une archive temporaire et l'archive finale. Vérifier l'espace du répertoire temporaire PHP.
2. Placer l'application en maintenance avec `php artisan down`, arrêter les workers et planificateurs qui peuvent écrire, et interdire les accès directs en écriture à la base et aux fichiers. Le mode maintenance est contrôlé par la commande, mais ne bloque pas à lui seul les workers ni les accès administrateur à la base.
3. Exécuter `medicore:backup` avec un nom de fichier neuf. La commande refuse la création hors maintenance.
4. Exécuter `medicore:restore` vers un nouveau dossier pour contrôler immédiatement la récupération, puis terminer la recette selon la section suivante.
5. Relancer les processus et `php artisan up` après vérification du succès ou du diagnostic d'échec.
6. Copier l'archive chiffrée vers une destination de sauvegarde indépendante, vérifier son empreinte et appliquer la politique de rétention retenue. Un fichier sur le même disque ne protège pas d'une perte du serveur.

La base est copiée avec `VACUUM INTO`, qui produit un instantané SQLite cohérent, y compris lorsque la base utilise un WAL. Tous les fichiers réguliers du stockage médical sont inclus. Les liens symboliques sont refusés.

Avant création de l'archive, la commande contrôle l'intégrité SQLite, les clés des champs médicaux connus et la présence, le déchiffrement, la taille et le SHA-256 de chaque document référencé. Les consultations des sauvegardes précédant la migration restent reconnues comme des valeurs historiques en clair à l'intérieur de l'archive chiffrée.

L'archive utilise le flux authentifié [libsodium secretstream XChaCha20-Poly1305](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php), traité par blocs pour éviter de charger toute la base en mémoire. Le format impose un bloc final et refuse les blocs altérés, tronqués ou les données ajoutées. Les documents individuels sont cependant déchiffrés en mémoire lors des contrôles, comme au téléchargement dans l'application.

L'archive contient la base, les documents et un manifeste avec l'empreinte de la base, la date et les empreintes SHA-256 des clés applicatives présentes. Elle n'inclut ni `.env`, ni valeur de clé, ni code applicatif. Les fichiers temporaires contenant la base et le ZIP sont placés dans un dossier privé `0700`, puis supprimés en sortie normale ou sur exception. Utiliser un disque temporaire protégé ; un arrêt brutal peut nécessiter le nettoyage manuel de `medicore-recovery-*`. La suppression ne constitue pas un effacement physique garanti.

## Restauration et exercice de reprise

La restauration peut se faire sur une machine de recette pendant que l'application source fonctionne : elle ne modifie jamais sa connexion, sa base ni ses fichiers actifs.

1. Préparer une instance isolée, sans SMTP réel, sans workers envoyant des messages et avec accès réservé aux opérateurs. Utiliser le code et les migrations compatibles avec la sauvegarde.
2. Provisionner depuis le coffre la bonne `MEDICORE_BACKUP_KEY` et les `APP_KEY`/`APP_PREVIOUS_KEYS` historiques. La commande exige toutes les empreintes de clés applicatives enregistrées dans le manifeste, même si certaines clés n'étaient plus utilisées par les données.
3. Exécuter `medicore:restore archive.mcb nouveau-dossier`. Son parent privé doit être sur un volume offrant assez d'espace. L'extraction temporaire est créée dans ce parent pour permettre la publication du dossier par renommage sur le même système de fichiers.
4. La commande vérifie l'authentification complète avant extraction ; refuse les chemins sortant du dossier, contrôle l'empreinte de la base, `integrity_check`, les clés étrangères, la lisibilité des champs chiffrés et les documents référencés. Un échec ne publie aucun dossier restauré.
5. Dans l'instance de recette uniquement, pointer la base vers `nouveau-dossier/database.sqlite` et configurer le stockage médical pour utiliser `nouveau-dossier/medical`. Exécuter les migrations nécessaires si la sauvegarde est plus ancienne que la version cible, en maintenance et après copie de sécurité.
6. Vérifier les accès par rôle, l'ouverture d'une consultation et de son historique, les antécédents, le téléchargement d'un document, les factures et soldes. Consigner durée, taille, date de sauvegarde, version du code, opérateur et résultat, sans contenu clinique.
7. Pour une reprise réelle, planifier la bascule des chemins dans la configuration en maintenance, après arrêt de tous les processus écrivains. Conserver les données précédentes pour le retour arrière, puis reprendre le service après recette. Aucun remplacement de production n'est automatisé par ces commandes.

## Déployer la migration de chiffrement

Ne pas servir simultanément l'ancien et le nouveau code : leurs formats de consultation diffèrent.

1. Arrêter les écritures et activer la maintenance.
2. Provisionner les clés et créer une sauvegarde **avant migration**, avec les commandes de cette version. Vérifier sa restauration dans un autre dossier.
3. Exécuter `php artisan migrate --force` avec la clé applicative déjà utilisée pour les dossiers existants. La migration refuse de transformer des consultations existantes hors maintenance.
4. Contrôler les lectures et écritures de recette, puis sauvegarder/restaurer à nouveau après migration.
5. Redémarrer les processus avec le nouveau code et rouvrir l'application.

Le retour `down()` est volontairement bloqué : il ne réintroduit pas les notes en clair. En cas de retour arrière, restaurer ensemble une sauvegarde antérieure et le code correspondant, selon la procédure de bascule. Les écritures postérieures à la sauvegarde nécessitent un traitement explicite ; elles ne sont pas récupérées automatiquement.

Le chiffrement des valeurs ne garantit pas l'effacement des anciennes pages SQLite, journaux, instantanés disque ou sauvegardes antérieures. Prévoir, en maintenance et selon les règles d'exploitation validées, la réécriture contrôlée de la base, le traitement du WAL et la conservation/destruction des anciennes copies. Garder les volumes et archives protégés pendant toute leur durée de vie.

## Limites d'exploitation restantes

Le code et les exercices automatisés démontrent la reprise locale de données fictives. Le coffre réel, le stockage distant, la fréquence, la rétention, les objectifs RPO/RTO, l'espace disque, la supervision, le nettoyage après incident et la recette sur l'hébergement cible restent à configurer et à tester. Aucun secret, planning automatique ou destination externe n'a été provisionné par cette modification. Aucune migration n'a été exécutée sur la base active du développeur durant ces travaux.
