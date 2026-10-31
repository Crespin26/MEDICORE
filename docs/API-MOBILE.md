# API v1 et première version mobile connectée

## Périmètre

L'API expose la connexion du personnel, les patients et l'agenda. Les consultations, documents médicaux, factures, paiements, notifications et mode hors ligne ne sont pas exposés dans ce premier lot mobile. Les contrôleurs patients/rendez-vous et les services métier sont ceux du web : doublons, archivage, disponibilités, absences et chevauchements ne sont pas réimplémentés dans Flutter.

## Authentification et accès

Base : `/api/v1`. Les réponses sont JSON et privées (`Cache-Control: no-store, private`), y compris les erreurs API. Les jetons sont des jetons Laravel Sanctum, stockés sous forme d'empreinte en base, avec une capacité `mobile` et une expiration de huit heures. La valeur utilisable est remise uniquement à la connexion.

| Méthode | Endpoint | Accès et résultat |
| --- | --- | --- |
| POST | `/session` | `email`, `password`, `device_name` ; renvoie `data.token`, `data.token_type`, `data.expires_at`, `data.user` avec permissions. Compte actif avec rôle attribué requis. |
| GET | `/me` | Profil minimal et permissions actuelles. |
| DELETE | `/session` | Révoque le jeton de l'appareil courant ; réponse 204. |
| GET | `/patients` | Administrateur, médecin, secrétaire ; recherche/filtres/pagination de 20. |
| GET | `/patients/{id}` | Même droit ; fiche d'identité et coordonnées, sans contenu de consultation ni motifs administratifs. |
| POST | `/patients` | Administrateur/secrétaire ; validations du web et création 201. |
| PATCH | `/patients/{id}` | Administrateur/secrétaire ; validation et contrôle de l'état archivé du web. |
| GET | `/appointments` | Administrateur/secrétaire : agenda global ; médecin : ses seuls rendez-vous, même si un autre médecin est demandé dans le filtre. |
| POST | `/appointments` | Administrateur/secrétaire ; planification via le service partagé. |
| PATCH | `/appointments/{id}` | Administrateur/secrétaire ; déplacement via le service partagé. |
| PATCH | `/appointments/{id}/status` | Statuts selon les règles du web ; le médecin ne peut agir que sur ses rendez-vous et les statuts autorisés. |

Chaque endpoint protégé exige `Authorization: Bearer <jeton>`. Une session web seule ne donne pas accès à l'API mobile. Les permissions du rôle sont revérifiées côté serveur ; la capacité du jeton ne remplace pas ces permissions.

Une suspension, un changement de rôle ou de mot de passe révoque les jetons de l'utilisateur. Un jeton expiré, révoqué ou sans capacité mobile est refusé. La connexion est limitée à cinq tentatives par minute par couple adresse/IP, et à dix par IP ; les requêtes mobiles authentifiées sont limitées à 120 par minute par utilisateur. Utiliser un cache persistant/partagé et vérifier ces limites sur l'hébergement cible. Le planificateur déclare un nettoyage quotidien des jetons expirés depuis 24 heures ; l'exécution réelle du scheduler reste à configurer.

### Réponses et champs

La liste des patients reprend l'enveloppe de pagination Laravel : `data`, `current_page`, `per_page`, `next_page_url`, `total`, etc. La recherche accepte `search`, les dates de naissance et le filtre `state=active|archived|all`. La liste mobile courante utilise les fiches actives. Les attributs internes d'identité/recherche sont masqués.

L'agenda accepte `date=AAAA-MM-JJ`, `doctor_id`, `status` et `page`. Il renvoie `appointments` (pagination), `date`, `timezone`, `doctors`, `availability` et `statuses`. Les horaires sont ceux de la clinique. La planification utilise `patient_id`, `doctor_id`, `starts_at`, `ends_at` avec le format local `AAAA-MM-JJTHH:mm` ; le serveur applique son fuseau et les règles de disponibilité.

Les détails/créations/modifications sont enveloppés dans `data`. Les erreurs de validation sont des réponses 422 avec `message` et `errors` indexées par champ. Les clients doivent aussi gérer 401 (reconnexion), 403 (refus), 429 (attendre) et les erreurs réseau. Les réponses ne donnent aucune garantie de fonctionnement hors ligne.

## Application Flutter

Fonctions livrées : connexion, restauration de session vérifiée par `/me`, déconnexion, modules affichés selon les permissions, recherche et pagination des patients, fiche, création/modification selon le rôle, agenda quotidien, filtre médecin, statuts et planification/déplacement depuis une fiche ou un rendez-vous. Les erreurs de validation du serveur sont affichées près des champs.

Le jeton utilise `flutter_secure_storage`, sans persistance des dossiers/patients ni du mot de passe dans le code applicatif. Les données affichées ne sont conservées qu'en mémoire. Le client refuse les redirections HTTP et les URL contenant des identifiants. HTTPS est obligatoire ; HTTP n'est accepté qu'en debug, avec activation explicite, pour les hôtes locaux/émulateur prévus.

La reprise de session actualise les permissions ; 401 efface l'accès local et retire les pages protégées de la navigation. Les vues sont masquées lorsque l'application devient inactive et la session est revérifiée au retour. Cette première version réinitialise la navigation et les saisies non enregistrées pendant cette reprise : aucun brouillon local n'est garanti. Après cinq minutes d'inactivité, la session locale est fermée et une révocation est tentée. Ce délai et le comportement sur appareils physiques restent à accepter lors de la recette.

La déconnexion retire immédiatement l'accès en mémoire et tente d'effacer le stockage sécurisé, sans attendre le réseau. En l'absence de réseau, la révocation distante ne peut pas être garantie avant expiration : utiliser aussi la suspension/changement de mot de passe pour un appareil perdu. Android désactive les sauvegardes applicatives et déclare `FLAG_SECURE` pour protéger les captures. Le masquage et le stockage sécurisé doivent être vérifiés sur appareils réels, particulièrement sur iOS.

### Démarrage

Appliquer les migrations dans `web/` après la [procédure de sauvegarde/reprise](RECOVERY.md), puis démarrer le serveur. Ne pas utiliser de dossiers réels pour cette recette.

```bash
cd web
composer install
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Sur un serveur contenant des consultations anciennes, suivre les étapes de maintenance du guide avant `migrate`. Aucune migration n'a été appliquée à la base active du développeur durant ce lot.

```bash
cd mobile
flutter pub get
flutter run --dart-define=MEDICORE_API_URL=https://votre-serveur/api/v1
```

Pour l'émulateur Android et un serveur local fictif uniquement :

```bash
flutter run --dart-define=MEDICORE_API_URL=http://10.0.2.2:8000/api/v1 --dart-define=MEDICORE_ALLOW_HTTP=true
```

L'exception de transport Android est limitée au manifeste debug. En release, le client refuse HTTP. Pour iOS, utiliser HTTPS et vérifier la signature et les capacités Keychain avec Xcode. Les identifiants de publication restent provisoires.

## Vérifications

Le 29 octobre 2026 : suite complète de 191 tests PHP/2 107 assertions, onze tests Flutter unitaires/widget, et un test HTTP réel Flutter/Laravel exécuté séparément. Les formulaires patient et rendez-vous sont testés avec validation/conflict retournés par le serveur. Flutter analyze ne signale aucune anomalie.

- Tests PHP avec de vrais jetons Bearer : expiration/révocation, capacité, compte inactif, limites de connexion, permissions, isolation de l'agenda et conflits de réservation.
- Tests Flutter : transport, stockage injecté, expiration, déconnexion hors réseau, formulaire, modules interdits, retrait d'une fiche ouverte après révocation et masquage en arrière-plan.
- Test HTTP Flutter/Laravel réel avec fixture temporaire : `cd web && npm run test:mobile-live`. `MEDICORE_FLUTTER_BINARY` permet de choisir le SDK, `MEDICORE_TEST_PHP_OPTIONS` les extensions PHP temporaires si nécessaires.

Le test HTTP est volontairement ignoré par un `flutter test` seul sans fixture, puis exécuté explicitement par la commande ci-dessus. La CI configure ce test commun aux deux applications.

Sur la machine de développement, Flutter analyze/tests et le test HTTP sont exécutables. Le SDK Android a été installé le 30 octobre 2026. La compilation de `mobile/build/app/outputs/flutter-apk/app-debug.apk` a réussi et sa signature a été vérifiée (version debug, API 24 minimum, ARM/ARM64/x86_64). Aucun téléphone USB n’était détecté : l’installation et la recette sur appareil physique restent à effectuer. La compilation iOS nécessite macOS/Xcode et n'a pas été exécutée sous Linux. Les tests avec stockage injecté ne prouvent pas à eux seuls le fonctionnement du Keychain/Keystore matériel.

Références : [Sanctum pour applications mobiles](https://laravel.com/docs/12.x/sanctum#mobile-application-authentication), [réseau Flutter](https://docs.flutter.dev/cookbook/networking/fetch-data), [stockage sécurisé Flutter](https://pub.dev/packages/flutter_secure_storage).

### Lancement sur un téléphone USB

Le script `scripts/run-mobile-usb.sh`, depuis la racine du dépôt, configure `adb reverse`
pour relier le téléphone au serveur local sur le port 8000 et lance Flutter en debug.
Voir les [instructions USB](../mobile/README.md#téléphone-android-branché-en-usb).
