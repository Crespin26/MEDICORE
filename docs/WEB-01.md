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

Cette matrice porte uniquement sur les modules déjà disponibles. Elle n’accorde pas encore d’accès aux futurs dossiers médicaux, consultations ou paiements. Les rôles sont définis dans `web/app/Enums/Role.php` et contrôlés sur chaque route. Les menus et formulaires reflètent ces mêmes permissions.

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

La réinitialisation de mot de passe est réservée aux comptes actifs et ne permet pas de contourner une invitation ou une suspension.

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
