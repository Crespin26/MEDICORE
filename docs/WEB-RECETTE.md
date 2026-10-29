# Recette web et règles métier — 29 octobre 2026

## Périmètre

Ce lot couvre la recette navigateur des quatre rôles, les homonymes, l'archivage réversible et le relais entre médecins. Les scénarios sont exécutés avec des données fictives dans une application isolée. Ils ne constituent pas une validation clinique, une certification de conformité ou une recette exhaustive de l'établissement.

## Homonymes

La prévention des doublons par prénom, nom et naissance reste active, y compris pour une identité déjà archivée. Après vérification, un administrateur peut confirmer explicitement qu'il s'agit d'une personne distincte, avec une justification de 5 à 1 000 caractères. Le formulaire présente cette option séparément de l'identité.

La nouvelle personne conserve une référence MC différente et ses propres rendez-vous, consultations et factures. Elle ne partage aucune donnée par l'effet de l'homonymie. L'exception est enregistrée sur sa fiche ; son motif est chiffré dans `patient_events`. Une correction de coordonnées conserve l'exception. Un changement d'identité déclenche une nouvelle vérification des doublons. Le secrétaire ne peut pas forcer une création, même par requête directe.

Il n'y a pas de fusion automatique ni de réattribution des historiques. La vérification physique de l'identité reste à organiser dans l'établissement.

## Archivage

Un administrateur peut archiver ou réactiver une fiche avec un motif obligatoire. Aucun patient, document, historique, rendez-vous ou facture n'est supprimé. Les décisions restent enregistrées avec auteur, date et motif chiffré. L'historique administratif affiché à l'administrateur exclut les motifs de partage médical.

L'archivage est refusé s'il subsiste un rendez-vous prévu, confirmé ou arrivé, ou une facture brouillon, émise ou partiellement payée. Ces conditions sont vérifiées dans une transaction avec les verrous utilisés par les opérations concernées. La qualification de concurrence de cette nouvelle règle sur d'autres moteurs reste à faire.

Une fiche archivée :

- disparaît de la liste active et des recherches de patient pour une nouvelle réservation ou facture ; les filtres « Archivées » et « Toutes » permettent de retrouver sa fiche selon les droits existants ;
- reste consultable avec ses données et historiques, sans élargissement des permissions ;
- refuse les modifications d'identité, nouvelles réservations, consultations, informations médicales, pièces jointes et nouvelles factures ;
- conserve les corrections autorisées sur les éléments historiques, avec leurs règles de version et de justification ; les opérations financières historiques restent tracées. Une annulation de paiement historique peut recréer un solde sur une fiche archivée : il faut le traiter sans effacer la trace ;
- retrouve les nouvelles opérations après réactivation motivée.

Le total administratif des patients reste un total historique de fiches, comprenant les archivées. Le filtre de la liste est distinct de ce compteur. L'archivage ne définit ni durée de conservation ni purge : ces règles restent à valider pour le déploiement.

## Partage entre médecins

Depuis le dossier d'un patient, un médecin peut accorder à un autre médecin actif un accès en lecture seule à **ses propres** consultations (y compris brouillons), révisions, informations médicales et documents de ce patient, existants et futurs. Une confirmation affiche ce périmètre avant l'enregistrement et un motif de 5 à 1 000 caractères est requis.

Le partage est limité au couple auteur/destinataire et au patient choisi. Il n'ouvre pas les autres patients de l'auteur ni les éléments d'autres médecins. Le destinataire voit les éléments dans le dossier et peut ouvrir leurs versions ou télécharger leurs documents. Les consultations partagées sont accessibles depuis la fiche patient et la chronologie ; la liste « Mes consultations » et les indicateurs personnels restent centrés sur les éléments dont le médecin est auteur.

Seul l'auteur peut corriger ses éléments. Le destinataire peut créer ses propres éléments si le patient est actif, mais ne peut ni modifier ceux de l'auteur, ni les retransmettre en accordant un partage de ses propres éléments. Les administrateurs, secrétaires et caissiers ne peuvent pas accorder ou recevoir ce droit médical par les endpoints de partage.

L'auteur peut révoquer le partage avec un motif. Les prochaines lectures et téléchargements sont alors refusés côté serveur et les éléments disparaissent des listes accessibles au destinataire. Une réponse déjà reçue ou un fichier déjà téléchargé ne peut pas être rappelé. Un destinataire suspendu ou ayant perdu son rôle médecin ne bénéficie plus du droit effectif. Les partages précédemment accordés par un auteur ensuite suspendu sont conservés pour la continuité du suivi ; leur revue doit faire partie du départ du personnel.

Les octrois/révocations et leurs motifs chiffrés sont conservés ; les lectures, versions et téléchargements utilisent le journal médical existant, attribué au lecteur réel. Le journal affiché dans l'application reste celui de l'utilisateur connecté. Les comptes référencés dans les partages ou décisions ne sont pas supprimables depuis leurs paramètres : utiliser la suspension.

Ce fonctionnement ne présume pas d'une règle de consentement valable dans tous les contextes. L'établissement doit valider les personnes habilitées, la justification du relais, le traitement des départs et le périmètre incluant les brouillons avant des données réelles.

## Automatisation navigateur

Les tests sont dans `web/tests/browser/clinical.spec.ts`, avec configuration `web/playwright.config.ts`. Ils utilisent Chromium sur deux tailles de fenêtre : bureau et 390 × 844. La seconde configuration teste le web sur petit écran ; elle ne simule pas une application Flutter ni un véritable appareil mobile.

Les parcours couvrent :

| Parcours | Vérifications |
| --- | --- |
| Administrateur, médecin, secrétaire, caissier | Connexion, pages autorisées, accès directs refusés, navigation sur petit écran et un seul repère principal `<main>`. |
| Personnel sans rôle et compte suspendu | Refus des accès métier et refus de connexion du compte suspendu. |
| Secrétariat | Création, recherche, refus du doublon ordinaire et réservation dans une disponibilité réelle. |
| Médecin auteur | Consultation liée à un rendez-vous arrivé, validation, correction motivée et historique. |
| Dossier | Ajout d'une information, téléversement d'un PNG fictif, téléchargement et comparaison exacte des octets. |
| Confidentialité | Refus de la consultation et du document à un autre médecin sans partage. |
| Facturation | Création du brouillon, émission, paiement partiel de 2 000 sur 5 000 XOF, solde de 3 000 puis zéro, reçu accessible. |
| Sécurité de session | Rejet d'une écriture sans jeton CSRF et déconnexion suivie du retour à la connexion sur une page protégée. |
| Homonyme et archivage | Confirmation administrative, fiche distincte, archivage visible dans le filtre dédié et réactivation. |
| Partage | Octroi explicite, lecture sans bouton de correction, puis révocation et refus de l'URL directe. |

La recette a révélé des repères `<main>` imbriqués dans la mise en page. Le conteneur de la barre latérale est maintenant un `<div>` et le contenu des paramètres dispose de son propre `<main>`.

### Exécution locale

Depuis `web/`, avec PHP et les extensions SQLite, sodium et zip requis par l'ensemble des tests :

```bash
composer install
npm ci
npx playwright install chromium
npm run build
npm run typecheck
npm run typecheck:e2e
npm run lint:check
npm run test:e2e
php artisan test
```

`MEDICORE_TEST_PHP_BINARY` permet de choisir le binaire PHP. Si des options PHP sont nécessaires, `MEDICORE_TEST_PHP_OPTIONS` accepte un tableau JSON d'arguments, par exemple des paires `-d`, `extension=/chemin/extension.so`. Elles sont transmises sans shell au processus de préparation et au serveur.

Le lanceur crée un dossier temporaire privé `medicore-e2e-*`, une base SQLite neuve, une clé applicative aléatoire et des sessions dans cette base. Il force le cache en mémoire, les courriels dans un transport de test, le stockage médical dans ce dossier et l'absence de fichier Vite `hot`. Il utilise `APP_ENV=e2e` pour conserver le véritable middleware CSRF. Aucun endpoint de contournement de connexion n'est ajouté : les tests passent par le formulaire réel.

La base et la configuration actives du développeur ne sont ni migrées ni remplacées. Le port `127.0.0.1:8123` doit être libre : aucun serveur existant n'est réutilisé. Le serveur est arrêté et le dossier supprimé en fin d'exécution normale ; un arrêt brutal peut nécessiter un nettoyage manuel. Ne pas exécuter plusieurs recettes simultanées sur ce même port. Les tests utilisent un worker et des créneaux distincts pour les deux tailles de fenêtre.

Les identifiants `*@medicore.test` et le mot de passe fixe du fichier de fixture sont exclusivement fictifs et ne sont créés que dans cette base temporaire. La fixture n'est pas un seeder de démonstration à exécuter sur une base réelle. Le routeur de test n'accepte que le serveur PHP CLI dans l'environnement de recette et n'est pas un fichier du répertoire public.

La CI configure les contrôles, l'installation de Chromium et la recette. Les traces et captures sur échec sont ignorées par Git ; la CI les conserve cinq jours. Elles contiennent uniquement les données fictives de cette fixture. Aucun résultat distant GitHub Actions n'a été inspecté ici.

## Résultats locaux

Le 29 octobre 2026 : **26 scénarios navigateur réussis** sur Chromium, répartis entre bureau et petit écran. Les contrôles TypeScript applicatif et navigateur, ESLint et la compilation Vite ont réussi. L’audit npm après ajout des outils de test signale **zéro vulnérabilité**. La suite PHP complète a réussi **184 tests et 2 044 assertions**, incluant la restauration des nouveaux motifs chiffrés. Pint et les vérifications de diff ont également réussi.

## Déploiement et recette métier restante

Appliquer les migrations avec le guide de [reprise](RECOVERY.md), en maintenance et après sauvegarde/restauration vérifiée ; la migration de chiffrement du point 2 doit précéder celle des nouveaux états et partages. La base active locale n'a pas été migrée durant ce lot. Les nouvelles raisons chiffrées sont incluses dans les contrôles de restauration pour les sauvegardes contenant ces tables.

Avant une mise en service, faire accepter par les responsables métier les règles ci-dessus, puis compléter la recette : invitations et réinitialisations réellement reçues, annulations financières, documents imprimés et mentions attendues, continuité lors d'un départ de médecin, audit avec lecteur d'écran, navigation clavier et autres navigateurs. Le fait que les scénarios automatisés passent ne vaut pas acceptation par l'établissement. Les mentions de facturation restent à valider selon le déploiement.
