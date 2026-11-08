# WEB-01 — Authentification et accès du personnel

## Permissions de cette version

| Action | Administrateur | Médecin | Secrétaire | Caissier |
| --- | --- | --- | --- | --- |
| Tableau de bord | Oui | Oui | Oui | Oui |
| Liste des patients et nombre de patients | Oui | Oui | Oui | Non |
| Création et modification des fiches patients | Oui | Non | Oui | Non |
| Invitation du personnel | Oui | Non | Non | Non |
| Modification des rôles, suspension et réactivation | Oui | Non | Non | Non |
| Paramètres de son compte actif | Oui | Oui | Oui | Oui |
| Lecture de l’agenda | Global | Propre agenda | Global | Non |
| Création et déplacement des rendez-vous | Oui | Non | Oui | Non |
| Statuts des rendez-vous | Global | Propre agenda | Global | Non |
| Disponibilités | Gestion | Propres disponibilités | Non | Non |
| Consultations et dossier médical | Non | Auteur et partages directs en lecture | Non | Non |
| Saisie/correction clinique et partage | Non | Propres éléments, selon les règles métier | Non | Non |
| Factures, paiements et reçus | Oui | Non | Non | Oui |
| Annulation financière et catalogue | Oui | Non | Non | Non |
| Gestion du personnel et paramètres de la clinique | Oui | Non | Non | Non |
| Exports administratifs agrégés | Oui | Non | Non | Non |

Cette matrice décrit les modules web implémentés. Les rôles sont définis dans
`web/app/Enums/Role.php` et contrôlés sur chaque route. Les politiques ajoutent
les restrictions par patient, auteur et partage. Les menus reflètent les permissions
mais ne remplacent jamais les contrôles serveur. Le mobile expose seulement les
modules déjà disponibles dans l’API ; les permissions financières ou cliniques ne
créent pas à elles seules des écrans ou des routes API correspondants.

L’ancien rôle `staff` reste sans permission clinique jusqu’à son attribution à l’un des quatre rôles par un administrateur. La migration conserve les comptes existants actifs ; elle ne leur accorde aucun nouveau rôle.

## Mise en route

```bash
cd web
php artisan migrate
npm run build
# Uniquement si aucun administrateur n’existe encore :
php artisan medicore:create-admin
```

Utiliser les options PHP décrites dans `DEVELOPMENT.md` si SQLite n’est pas installé sur le système.

Après connexion en administrateur, ouvrir **Comptes du personnel** (`/staff`).

1. Saisir le nom, l’e-mail en minuscules et le rôle du membre.
2. Envoyer l’invitation. Le compte est créé en attente et ne peut pas se connecter.
3. Le destinataire ouvre le lien et choisit un mot de passe confirmé d’au moins 12 caractères.
4. Le compte devient actif ; le destinataire se connecte ensuite normalement.

Le lien expire après 48 heures. Seule son empreinte SHA-256 est stockée en base. Après acceptation il est supprimé. Un renvoi invalide le lien précédent. Si le transport e-mail échoue, le compte reste en attente et l’écran permet de renvoyer l’invitation.

Un administrateur peut modifier le rôle ou suspendre un autre compte actif. Il ne peut pas modifier ses propres accès ni supprimer son propre compte depuis les paramètres. Les modifications administratives sont sérialisées et revérifiées pour conserver un administrateur actif.

La suspension bloque les nouvelles connexions, invalide le jeton « se souvenir de moi » et supprime les sessions Laravel stockées en base. Toute requête d’un compte non actif provoque aussi sa déconnexion, y compris vers les paramètres. Conserver `SESSION_DRIVER=database` pour cette suppression immédiate des sessions persistantes. La réactivation d’un compte déjà activé se fait depuis le même écran ; une invitation en attente doit être acceptée pour être activée.

Depuis le renforcement du 8 novembre 2026, un changement de rôle depuis la gestion
des comptes invalide également les sessions Laravel persistantes et le jeton
« se souvenir de moi ». Les autres comptes conservent leurs sessions. Une sauvegarde
sans changement de rôle d’un compte actif ne force pas sa reconnexion.

## Sessions mobiles et réponses retardées

L’API utilise un Bearer Sanctum avec la capacité `mobile`, valable huit heures.
Une session navigateur seule ne donne pas accès aux routes mobiles. Le serveur
révoque les jetons mobiles après changement de mot de passe, rôle ou statut ;
la déconnexion mobile ne révoque que le jeton de l’appareil courant.

Le client ne conserve que ce jeton dans le stockage sécurisé. Une génération de
session invalide les opérations de connexion, restauration et modification du
profil commencées avant une déconnexion ou une nouvelle connexion. Les lectures,
écritures et effacements du coffre sont sérialisés : un effacement ancien ne doit
pas supprimer un jeton nouvellement enregistré.

Une connexion annulée dont la réponse arrive tard révoque son jeton capturé sans
affecter le compte courant. Si le réseau empêche la révocation, le jeton reste
soumis à son expiration côté serveur. Un `401` ferme l’accès local même si sa
réponse n’est pas du JSON valide ; le `401` d’une ancienne session ne ferme pas
la nouvelle. Une réponse reçue après destruction du client ne publie plus de profil.

La réinitialisation de mot de passe est réservée aux comptes actifs et ne permet pas de contourner une invitation ou une suspension.

### Renforcement sécurité — 8 novembre 2026

Le changement de mot de passe depuis les paramètres ferme aussi la session courante
et demande une reconnexion. Changement/réinitialisation révoquent les sessions web
persistantes, jetons mobiles, cookies mémorisés et liens de réinitialisation existants.
Le mot de passe courant est revérifié sous verrou. La session web est liée au hash
vérifié lors de la connexion, ce qui refuse un ancien cookie après changement du secret.

Un changement d’adresse e-mail invalide les liens de réinitialisation de l’ancienne
et de la nouvelle adresse. Une vérification de lien déjà en cours ne peut pas écraser
un mot de passe ou une adresse modifiés entre-temps.

La connexion web est limitée par adresse IP en plus de la limite par identifiant.
Confirmation, changement et suppression de compte partagent une limite de cinq
vérifications de mot de passe par minute et par compte/IP ; la réinitialisation est
également limitée. Les mots de passe transmis sont bornés et les types invalides refusés.

En production, les hôtes qui ne correspondent pas à `APP_URL` sont refusés par
l’application, en plus du contrôle Nginx. Voir [la revue de sécurité](SECURITY-2026-11-08.md)
pour le périmètre vérifié et la procédure de révocation lors d’une mise à niveau.

## E-mails locaux et SMTP

Par défaut, `MAIL_MAILER=log` : les invitations et liens de réinitialisation sont écrits dans `web/storage/logs/laravel.log`. Aucun e-mail externe n’est envoyé. L’écran l’indique explicitement. Ces liens sont secrets : ne pas partager ni publier les journaux.

Pour vérifier l’application avec un serveur SMTP de développement déjà disponible, renseigner le `.env` local :

```dotenv
APP_URL=http://localhost:8000
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_ENCRYPTION=null
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=medicore@example.test
MAIL_FROM_NAME=MediCore
```

Le port dépend du serveur local choisi. Pour un fournisseur réel, utiliser ses valeurs SMTP, une adresse d’expédition autorisée et `APP_URL` avec le domaine HTTPS de l’application. Ne pas committer les identifiants.

Après toute modification :

```bash
php artisan config:clear
```

Vérifier ensuite une invitation et une réinitialisation sur une adresse de test autorisée. Contrôler leur réception, le domaine du lien et le refus des liens expirés ou réutilisés. Une remise au transport SMTP ne garantit pas l’arrivée en boîte de réception.

Les tests automatisés vérifient les notifications, le rendu et la remise au transport local en mémoire, ainsi que le cas d’échec d’envoi. La livraison vers une boîte réelle reste à valider avec un fournisseur SMTP configuré ; aucun fournisseur ni identifiant de production n’a été fourni.

## Vérifications

```bash
php artisan test
npm run typecheck
npm run lint:check
npm run build
```

Les tests couvrent les permissions par rôle, les requêtes directes interdites, les invitations à usage unique, l’expiration et le renvoi des liens, la suspension, la réactivation, la protection de l’administrateur et les e-mails locaux.

Le renforcement des sessions est couvert par `mobile/test/session_races_test.dart`
et le scénario de changement de rôle de `StaffAccessTest`. Voir également les
tests `MobileApiTest` pour les jetons, les expirations et les accès directs refusés.

La livraison SMTP réelle, les protections du stockage sur appareils, HTTPS et la
configuration de session sur l’hébergeur restent à recetter. La double authentification
n’est pas implémentée dans ce lot.
