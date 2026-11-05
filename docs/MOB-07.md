# MOB-07 — Profil et notifications du personnel

Lot du 5 novembre 2026 : profil mobile modifiable, préférences, boîte de notifications,
rappels de rendez-vous et raccordement push Firebase optionnel. Le projet Firebase
n’existe pas encore : aucun envoi push réel n’a été réalisé ou validé.

## Parcours livré

Dans **Mon compte**, le personnel peut modifier son nom et son adresse e-mail.
Les validations sont celles du web ; le changement d’e-mail remet la vérification à zéro.
Le rôle, le statut et le mot de passe ne sont pas modifiables par cet endpoint.
Les changements actualisent le profil en mémoire sans conserver une nouvelle copie sur disque.

Les membres disposant de `appointments.view` accèdent à **Mes notifications** et aux
**Préférences de notification**. Un médecin ne reçoit que les événements de son propre
agenda ; les administrateurs et secrétaires peuvent suivre le planning global selon leurs choix.
Le caissier dispose de l’édition de son profil, sans activation des alertes d’agenda.

La boîte présente les créations, modifications, annulations et rappels de rendez-vous.
Elle est paginée par 20, indique les non-lues, permet une lecture idempotente et ouvre
l’agenda à la date concernée. Les droits sont revérifiés à chaque lecture : une ancienne
notification d’un rendez-vous réaffecté n’ouvre pas le planning d’un autre médecin.

## Préférences et rappels

Tous les canaux sont désactivés par défaut ; le personnel choisit explicitement :

- Les changements de rendez-vous dans la boîte.
- Les rappels dans la boîte.
- Un délai de 15, 30, 60, 120 ou 1 440 minutes avant le rendez-vous.
- Le push sur ses appareils, lorsque le serveur et le build le permettent.

Ces choix concernent le compte, pas seulement le téléphone. Désactiver le push supprime
les inscriptions serveur de tous ses appareils. L’autorisation Android/iOS reste propre
à chaque appareil et n’est demandée qu’après une action explicite d’activation.

Le scheduler crée chaque minute les rappels des rendez-vous futurs prévus/confirmés,
dans la fenêtre choisie. Une clé unique par rendez-vous, horaire, délai et destinataire
évite les doublons lors des passages suivants. Un déplacement rend le nouvel horaire
éligible à son propre rappel. Un rendez-vous passé, annulé ou déjà arrivé n’est pas rappelé.
Les événements de planning et la boîte sont écrits dans la transaction métier ; une
réservation annulée par rollback ne laisse pas de notification.

Les préférences désactivées ne produisent pas d’événements rétroactifs lors de leur activation.
La boîte historique n’est pas supprimée à chaque changement de préférence. Sa rétention
reste à intégrer à la politique d’exploitation du produit.

## API

Toutes les routes ci-dessous sont sous `/api/v1` et exigent un vrai jeton mobile Bearer actif.

| Méthode | Route | Contrat |
| --- | --- | --- |
| PATCH | `/me` | `name`, `email` ; profil courant et permissions dans `data`. |
| GET | `/notification-preferences` | Choix actuels, `can_receive_appointments`, `push_available`. |
| PUT | `/notification-preferences` | `appointment_updates`, `appointment_reminders`, `reminder_minutes`, `push_enabled`. |
| GET | `/notifications?page=1` | `notifications` paginées et `unread_count`, limités au destinataire et à ses droits actuels. |
| PATCH | `/notifications/{uuid}/read` | Lecture idempotente, réponse 204 ; un identifiant étranger reste inaccessible. |
| PUT | `/push-device` | `installation_id` Firebase enregistré, `platform=android|ios` ; réponse 204. |
| DELETE | `/push-device` | Désinscription de la seule session de cet appareil ; réponse 204. |

Le client utilise les API Firebase récentes `register`, `onRegistered` et `unregister`.
Le FID enregistré est chiffré en base ; son empreinte sert à l’unicité. Le transport HTTP v1
utilise le champ `message.fid`, pas l’ancien jeton d’enregistrement FCM.
Au plus cinq appareils sont conservés par compte.

L’inscription est liée à `personal_access_tokens`. Déconnexion et révocation suppriment
les inscriptions associées ; les sessions expirées sont refusées aussi au moment de l’envoi.
La durée actuelle du jeton mobile est de huit heures : un appareil doit disposer d’une
session valide pour recevoir le push. La boîte reste accessible après reconnexion autorisée.

## Confidentialité et livraison

Le push visible affiche uniquement :

> MediCore — Une information de rendez-vous est disponible. Ouvrez MediCore.

Il ne contient ni identité patient, ni motif, ni date, ni contenu clinique. Les données push
ne contiennent que l’UUID opaque de la notification. La date utile est récupérée dans
l’API authentifiée de la boîte. Un message externe ne peut pas imposer une URL de navigation.
Les SDK Firebase gèrent leur identifiant d’installation ; le code applicatif ne met pas
les dossiers ou la boîte en cache disque.

L’envoi est différé via une outbox persistante et un job Laravel. Aucun appel Firebase
n’a lieu pendant la réservation. Le worker revérifie préférences, accès, lecture,
fraîcheur, session et validité du rappel. Il ignore les rappels devenus obsolètes après
déplacement ou annulation. Les identifiants désinscrits chez Firebase sont supprimés.
Les erreurs du fournisseur sont remplacées par une erreur générique sans jeton ni clé.

L’outbox traite au plus 100 notifications par passage et ne pousse pas les événements
anciens de plus d’une heure. Les jobs disposent de trois tentatives ; une reprise peut
produire une livraison répétée. Les clés de regroupement et l’étiquette de notification
limitent les doublons visuels ; aucune livraison push exactement une fois n’est promise.
Un refus OS, un téléphone hors ligne, une session expirée ou un worker arrêté peuvent
empêcher le push. La boîte privée reste la source consultable dans l’application.

## Installation du lot

La migration additive `2026_11_05_000001_create_mobile_notifications_tables` ajoute
`notification_preferences`, `appointment_notifications` et `push_devices`.
Avant mise à niveau d’une base existante, suivre [RECOVERY.md](RECOVERY.md), préserver
les clés, arrêter les écritures et valider la sauvegarde/restauration. Puis :

```bash
cd web
composer install
php artisan migrate
php artisan schedule:list
```

Les tâches déclarées chaque minute sont :

```bash
php artisan medicore:appointment-reminders
php artisan medicore:dispatch-push
```

En exploitation, utiliser le scheduler et le worker décrits dans [PRODUCTION.md](PRODUCTION.md).
Le cache de verrouillage doit être persistant pour les tâches et les jobs uniques.
Laisser `MEDICORE_PUSH_ENABLED=false` tant que Firebase n’est pas configuré : profil,
préférences, boîte et rappels internes fonctionnent alors sans fournisseur externe.

Depuis `mobile/`, `flutter pub get` et un build debug normal suffisent pour ce périmètre sans push.
Les nouveaux écrans sont inclus sans initialiser le SDK Firebase tant que son activation
par build est désactivée.

## Activation Firebase ultérieure

1. Créer un projet Firebase et activer Cloud Messaging HTTP v1. Enregistrer les applications
   Android/iOS avec leurs identifiants exacts. Les identifiants actuels sont encore provisoires.
2. Configurer les applications avec les outils officiels FlutterFire ; vérifier les ressources
   natives `google-services.json` / Google Services Gradle pour Android et
   `GoogleService-Info.plist` inclus dans le bundle iOS. Le code initialise l’application Firebase
   par sa configuration native. Les configurations par environnement sont exclues du dépôt.
3. Pour iOS, activer les capacités Push Notifications et les modes d’arrière-plan appropriés,
   le provisioning et la clé APNs du projet. Les métadonnées FID et l’opt-out d’auto-initialisation
   sont déjà déclarés ; ils ne remplacent pas cette configuration native.
4. Sur le serveur, provisionner un compte de service autorisé à envoyer pour ce projet.
   Conserver son JSON privé **hors du dépôt et hors de `web/public`**, puis définir :

```dotenv
MEDICORE_PUSH_ENABLED=true
MEDICORE_FIREBASE_CREDENTIALS=/etc/medicore/firebase-service-account.json
```

5. Recréer le cache de configuration, vérifier `medicore:production-check`, puis redémarrer
   les workers. Le backend utilise Google Auth et un accès OAuth à durée limitée conservé
   chiffré dans le cache applicatif.
6. Compiler le client avec `--dart-define=MEDICORE_PUSH_ENABLED=true` en plus de son URL API.
   Aucune clé de compte de service ne doit figurer dans Flutter ou les paramètres du build.
7. Activer les préférences dans l’application, accepter l’autorisation OS et vérifier sur
   appareil réel : réception, premier plan/arrière-plan, ouverture, renouvellement FID,
   déconnexion, expiration, changement de compte et absence de données médicales à l’écran verrouillé.

Sans projet Firebase et appareil configuré, la livraison externe ne peut pas être déclarée validée.

## Vérifications

Les tests backend couvrent profil propre, unicité, rôle non modifiable, authentification,
opt-in, destinataires, accès après réaffectation, rollback, rappels idempotents, chiffrement,
révocation de session, outbox, sessions expirées, contenu HTTP v1 et erreurs expurgées.
Les appels Firebase sont simulés : aucun message réel n’est envoyé par les tests.

Les tests Flutter couvrent édition et erreurs de profil, boîte/pagination/lecture,
notification vers la bonne date d’agenda, consentement OS simulé, inscription FID,
désactivation, chargement impossible et inscription retardée après déconnexion.
Le test HTTP d’intégration réel utilise une fixture locale séparée.

Références : [Flutter/FCM et inscription FID](https://firebase.google.com/docs/cloud-messaging/flutter/get-started),
[API HTTP v1](https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages),
[authentification serveur](https://firebase.google.com/docs/cloud-messaging/send/v1-api),
[Google Auth PHP](https://docs.cloud.google.com/php/docs/reference/auth/latest).

## Validation et état local du lot

- Suite Laravel : 213 tests, 2 245 assertions réussies.
- Flutter : analyse sans anomalie, 18 tests unitaires/widget réussis ; le test HTTP réel est exécuté séparément et ignoré dans la suite standard.
- APK debug final compilé et signature vérifiée ; le transport Firebase reste désactivé dans ce build.
- Régression navigateur : 58 scénarios Chromium bureau et petit écran réussis.
- Test HTTP Flutter/Laravel étendu : profil, préférences, réservation, réception/lecture de la notification et révocation réussis sur fixture fictive.
- TypeScript, ESLint, build web et Pint : réussis.
- Dépendance officielle Google Auth ajoutée ; les quatre paquets Symfony signalés par l’audit ont reçu leurs correctifs compatibles, sans avis Composer restant après mise à jour.
- Migration locale appliquée le 5 novembre après sauvegarde chiffrée et restauration isolée vérifiée. La clé applicative existante a été conservée. Aucun serveur n’a été démarré pour ce lot.

Le push Firebase réel, les permissions OS réelles et iOS restent à qualifier sur appareils.
