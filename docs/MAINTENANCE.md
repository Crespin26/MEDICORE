# Architecture et maintenance du code

## Périmètre

Le code applicatif est réparti entre `web/app`, `web/routes`, `web/config`,
`web/database`, `web/resources` et `mobile/lib`. Les dépendances `vendor`,
`node_modules`, le code Flutter généré et les bundles compilés ne sont pas modifiés
manuellement. Les composants `components/ui` constituent les primitives partagées ;
les règles métier ne doivent pas y être ajoutées.

La revue transversale du 30 octobre 2026 porte sur les responsabilités, contrats,
permissions, transactions, sérialisation, cycle de vie et préparation à l’hébergement.
Elle complète les tests existants ; ce n’est pas une certification de sécurité ni
une validation de l’infrastructure d’un futur hébergeur.

## Où modifier une règle

| Couche | Responsabilité | Exemples |
| --- | --- | --- |
| Routes et middleware | Authentification, permissions générales, limites et confidentialité des réponses | `routes/web.php`, `routes/api.php`, `EnsureMobileAccess`, `PrivateResponses` |
| Requests / contrôleurs | Valider les entrées, orchestrer le cas d’usage et préparer la réponse | `SaveConsultationRequest`, `PatientController` |
| Policies | Autorisation sur un enregistrement identifié | Consultations, informations et documents médicaux |
| Services | Transactions et invariants métier réutilisables | `AppointmentScheduler`, `ConsultationRecorder`, `BillingService`, `PersonnelManager` |
| Models | Relations, conversions, champs chiffrés, index dérivés et champs non sérialisables | `Patient`, `User`, `Consultation` |
| Support | Fonctions techniques limitées et testables | `Money`, `ClinicTime`, `ReportingPeriod`, `ProductionConfiguration` |
| React | Présentation, saisie et erreurs ; aucune autorité sur permissions ou montants | Pages et composants de formulaires métier |
| Flutter | Session sécurisée, appels HTTP, navigation et formulaires | `ApiClient`, `main.dart`, `screens/` |
| Exploitation | Configuration hôte, persistance, workers et procédures | `deploy/`, [PRODUCTION.md](PRODUCTION.md), [RECOVERY.md](RECOVERY.md) |

Les contrôleurs patients et rendez-vous servent actuellement le web et l’API avec
une branche explicite de réponse JSON. Garder les mêmes validations et services pour
les deux clients. Introduire des Resources/contrôleurs API distincts quand les contrats
évolueront indépendamment, sans recopier les règles de réservation.

## Invariants à préserver

- Les permissions d’interface ne remplacent jamais les contrôles serveur. Un administrateur
  n’a pas automatiquement accès au contenu clinique. Un partage direct donne la lecture,
  jamais la correction des éléments d’un autre auteur ni un partage transitif.
- Verrouiller avant de lire les conflits et respecter l’ordre commun aux opérations
  de rendez-vous, d’archivage et de paramètres. SQLite ne fournit pas `SELECT FOR UPDATE` :
  le premier verrou d’écriture et les tests de concurrence sont intentionnels.
- Les versions de formulaire évitent les écrasements. Les jetons d’idempotence évitent
  les doublons de factures et paiements ; ne pas les régénérer à chaque tentative réseau.
- Les montants persistés sont des entiers en unités mineures. `Money` définit les échelles
  XOF/EUR/USD ; ne pas additionner des devises ou remplacer les calculs par des flottants.
- Les dates de rendez-vous sont stockées en UTC. Une saisie locale passe par `ClinicTime`
  et refuse les heures ambiguës/inexistantes. Les rapports utilisent une fin exclusive.
- Une correction clinique conserve une révision chiffrée et son motif. L’audit ne contient
  pas le texte clinique. Un échec d’écriture de l’audit interrompt le parcours concerné.
- Les documents restent chiffrés dans le disque privé `medical`, avec contrôle d’intégrité
  avant téléchargement. Ne pas créer de lien public vers ce dossier.
- Ne pas appeler `env()` hors de `config/` : la production utilise `config:cache`.
  Les proxies de confiance sont lus à l’exécution de la requête, après le bootstrap.
- Ne pas régénérer `APP_KEY` sur une base existante. Changer de clé ou de moteur de base
  nécessite un plan de récupération et une qualification séparée.
- Flutter ne persiste que le jeton dans le stockage sécurisé. Un profil, un patient,
  un mot de passe ou une réponse clinique ne doit pas être ajouté au cache disque.

## Commenter et faire évoluer une fonction

Les PHPDoc, JSDoc et commentaires Dart expliquent le résultat, les préconditions et les effets
importants : écriture, verrou, audit, version, unité monétaire ou fuseau. Les commentaires
internes expliquent les décisions qui pourraient sembler superflues, comme le verrou SQLite,
le nettoyage des écouteurs ou l’effacement immédiat du profil à la déconnexion.
Les types portent les détails évidents ; ne pas recopier chaque instruction en commentaire.
Mettre à jour le contrat lorsqu’une règle change, ainsi que sa documentation fonctionnelle.

Pour une fonctionnalité nouvelle : valider et autoriser côté serveur, ajouter la règle au service
concerné, préparer une réponse minimale, puis brancher les formulaires React/Flutter.
Tester le refus d’accès, les limites métier et les courses/idempotences si la modification
concerne des écritures concurrentes. Ne pas tester uniquement une copie de l’implémentation.

## Contrôles avant livraison

Depuis `web/` :

```bash
composer validate --strict
vendor/bin/pint --test
npm run typecheck
npm run typecheck:e2e
npm run lint:check
npm run build
php artisan test
npm run test:e2e
```

Depuis `mobile/` :

```bash
flutter analyze
flutter test
```

Le build Vite doit finir **avant** les tests navigateur pour ne pas remplacer leurs chunks
pendant une navigation. Ces tests utilisent une base temporaire et des identités fictives.
Le test Flutter HTTP réel se lance séparément avec `npm run test:mobile-live` depuis `web/`.
Voir [WEB-RECETTE.md](WEB-RECETTE.md) pour les dépendances navigateur et
[API-MOBILE.md](API-MOBILE.md) pour les options de développement local.
La CI contrôle aussi le style PHP pour éviter la dérive après cette revue.

## Changements de cette revue

- Contrats des fonctions métier documentés dans Laravel, les formulaires/hooks React
  et les opérations asynchrones Flutter.
- Liste des permissions centralisée dans `Role` pour enregistrer les Gates et les props web sans doublon.
- Politique commune d’au moins 12 caractères pour invitations, changements et réinitialisations ;
  plafond de saisie 72 caractères et création administrateur harmonisée.
- Thème compatible avec l’import SSR, écouteur système conservé après navigation,
  synchronisation entre contrôles/onglets et navigation utilisable si `localStorage` est bloqué.
- Configuration explicite des proxies, sans confiance automatique fondée sur le nom d’hôte.
- Réponses d’exception privées également pour le web ; réglage SMTP `MAIL_SCHEME` et délai borné.
- Diagnostic CLI de production, modèles Nginx/PHP/systemd et procédure de mise en service.

## Limites encore à traiter

Le diagnostic CLI vérifie une configuration locale ; il ne prouve pas la livraison SMTP,
les droits du processus PHP-FPM si lancé avec un autre compte, le chiffrement du disque,
la disponibilité externe ni la sauvegarde distante. Les accès médicaux doivent encore être
qualifiés avec les personnes métier en recette. L’antivirus des documents, le MFA,
la gestion de rétention et les builds mobiles signés restent décrits dans
[RESTE-A-FAIRE.md](RESTE-A-FAIRE.md).

Le profil de production fourni exige un serveur unique et SQLite. Avant de multiplier les instances,
qualifier les verrous sur le moteur cible, partager les sessions/cache/stockage et adapter les sauvegardes.

## Validation de la revue — 30 octobre 2026

| Contrôle | Résultat local |
| --- | --- |
| PHPUnit | 201 tests, 2 141 assertions |
| Playwright Chromium, bureau et petit écran | 58 scénarios réussis, dont les régressions du thème et du stockage navigateur bloqué |
| TypeScript application et navigateur, ESLint, build Vite | Réussis |
| Composer validate, Pint | Réussis ; style PHP ajouté à la CI |
| Flutter analyze / tests | Analyse sans erreur, 11 tests réussis ; test HTTP réel ignoré dans cette exécution standard |
| Installation temporaire, données neuves fictives | Migrations, caches configuration/routes/vues et `medicore:production-check` réussis |
| Nginx | `nginx -t` réussi sur une copie temporaire avec certificat de test, chemins et ports locaux adaptés |
| systemd | Trois unités validées par `systemd-analyze verify`, sans installation ni démarrage |

Les extensions SQLite et le binaire Nginx ont été extraits dans `/tmp` pour les vérifications,
sans installation système. Les migrations de contrôle ont utilisé une base temporaire neuve ;
la base de travail n’a pas été migrée. Firefox/WebKit, la charge sur un serveur cible,
la distribution mobile et le déploiement réel n’ont pas été revalidés dans cette revue.
