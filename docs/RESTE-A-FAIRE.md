# MediCore — bilan des travaux restants

État du 29 octobre 2026, établi à partir du commit `0c19e0e`, du backlog, des guides et d'une lecture de l'état des migrations locales. Cette revue n'applique aucune migration et ne déploie rien.

## Situation actuelle

Les huit modules web du périmètre initial sont implémentés. Les homonymes confirmés, l'archivage réversible, le partage médical explicite en lecture seule, le chiffrement des consultations et les outils SQLite de sauvegarde/restauration sont disponibles dans le code.

L'API v1 et Flutter couvrent la session du personnel, les patients et l'agenda. Le mobile ne couvre pas encore les consultations/dossiers, documents, facturation/paiements ou notifications.

Dernières validations consignées : 191 tests PHP/2 107 assertions ; 108 scénarios navigateur et 20 contrôles d'accès rejoués après ajout de l'API ; 11 tests Flutter et un test HTTP réel Flutter/Laravel. Les audits npm/Composer ne signalaient pas d'avis lors des dernières vérifications. Ces résultats ne sont pas une nouvelle exécution pendant ce bilan.

Les mesures de charge sont locales et courtes. Aucun APK, build iOS ou essai sur appareil physique n'est validé. Aucun environnement de production n'est qualifié. Aucun pourcentage global de complétude n'est attribué, car le périmètre d'exploitation et les extensions retenues ne sont pas encore arrêtés.

## 1. Mettre la base locale à niveau

`artisan migrate:status`, exécuté en lecture seule pendant ce bilan, indique trois migrations **Pending** :

| Migration | Travail livré mais non appliqué à la base active |
| --- | --- |
| `2026_10_29_000001_encrypt_consultation_content` | Chiffrement des consultations et de leurs révisions existantes. |
| `2026_10_29_000002_add_patient_lifecycle_and_clinical_sharing` | Homonymes confirmés, archivage et partages entre médecins. |
| `2026_10_29_000003_create_personal_access_tokens_table` | Stockage des empreintes de jetons de l'API mobile. |

À faire : préparer la clé indépendante de sauvegarde, conserver les clés applicatives existantes, arrêter les écritures, créer une sauvegarde et vérifier sa restauration, puis appliquer les migrations dans l'ordre et recetter les écrans/API. Ne pas régénérer `APP_KEY` sur une base contenant des données chiffrées sans procédure de rotation.

Le [guide de reprise](RECOVERY.md) précise la maintenance, les clés historiques et le retour arrière par restauration. Les anciennes copies, pages SQLite et journaux susceptibles de contenir du texte en clair nécessitent aussi un traitement d'exploitation contrôlé. Cette étape est un déploiement de code déjà écrit, pas une fonctionnalité à réimplémenter.

## 2. Préparer l'exploitation avant des données réelles

| Priorité | Reste à faire | Preuve de fin attendue |
| --- | --- | --- |
| P0 | Choisir l'hébergement, le moteur de base et l'environnement de recette. | Environnement séparé reproductible, accès et responsabilités définis. |
| P0 | Préparer HTTPS, proxy de confiance, cookies/sessions, `APP_DEBUG=false`, secrets et permissions de stockage. | Configuration revue et parcours authentifiés vérifiés sur l'environnement cible. |
| P0 | Configurer le fournisseur SMTP. | Invitations et réinitialisations reçues sur des boîtes de recette, avec expiration et usage unique vérifiés. |
| P0 | Provisionner un coffre pour les clés applicatives, historiques et de sauvegarde. | Accès au coffre et déchiffrement vérifiés pendant un exercice de reprise. |
| P0 | Organiser sauvegardes distantes, fréquence, rétention, alertes et restauration. | Restauration complète depuis une copie distante, avec objectifs de perte de données et délai de reprise acceptés. |
| P0 | Configurer scheduler, workers et supervision. | Tâches réellement exécutées, nettoyage des jetons expirés, erreurs/échecs de sauvegarde détectés et alertes reçues. |
| P0 | Valider la [procédure de déploiement et de retour arrière](PRODUCTION.md) et les modèles fournis. | Essai sur recette, incluant migrations et compatibilité code/données. |

Le code de sauvegarde/restauration existe : ce qui reste concerne son exploitation réelle, sa destination indépendante et sa validation sur l'hébergement cible. La CI est configurée ; aucun résultat distant GitHub Actions n'a été inspecté pour ce bilan.

## 3. Compléter la sécurité et la gouvernance des données

- Politique de mot de passe harmonisée le 30 octobre : au moins 12 caractères pour invitation, changement et réinitialisation. Évaluer encore le second facteur selon les accès.
- Ajouter une analyse des documents téléversés. Les contrôles de type, taille, chiffrement et intégrité existent déjà ; un antivirus n'est pas intégré.
- Vérifier les limites PHP et du proxy pour les documents de 10 Mo.
- Inventorier et protéger les identités, coordonnées, index de recherche, métadonnées et fichiers temporaires encore partiellement en clair. Le chiffrement clinique existant ne chiffre pas toute la base ni tous les volumes.
- Compléter les journaux des actions sensibles et des refus d'accès, leur surveillance et leur protection contre les modifications directes en base. Plusieurs historiques métier et journaux médicaux sont déjà implémentés ; il ne s'agit pas de partir de zéro.
- Définir conservation, purge, exports nominatifs autorisés et traitement des anciennes copies. L'archivage conserve les données et ne remplace pas cette politique.
- Faire accepter les règles de partage médical, y compris les brouillons et éléments futurs, les révocations et les départs de personnel. Une révocation ne rappelle pas les fichiers déjà téléchargés.
- Vérifier les obligations applicables au lieu d'hébergement et aux pays de déploiement avec les personnes compétentes ; aucune conformité n'est certifiée par les tests actuels.
- Si plusieurs établissements doivent utiliser la plateforme, concevoir et tester leur isolation **avant** leur ouverture. Ce besoin n'est pas un prérequis supplémentaire pour une instance explicitement limitée à un seul établissement.

## 4. Valider les usages web

Les parcours automatisés sont en place sur trois moteurs et petit écran. Il reste la [recette humaine](RECETTE-MANUELLE.md) :

- Acceptation des règles de patients, homonymes, archivage et relais entre médecins par l'établissement.
- Validation des mentions, règles et impressions de facturation pour le déploiement retenu.
- Recette avec lecteur d'écran, zoom, clavier et périphériques réellement utilisés.
- Tests avec les versions installées des navigateurs et les appareils visés ; WebKit automatisé ne valide pas chaque installation de Safari.
- Envois SMTP réels, scénarios de coupure réseau et reprise après incident.
- Vérification des résultats CI distants, puis préparation d'une démonstration isolée avec données fictives si elle est souhaitée.

Il n'existe pas de taux de couverture de code mesuré ni d'audit d'intrusion réalisé dans ces travaux. Ils peuvent compléter le niveau de preuve selon le périmètre d'exploitation ; les nombres de tests ne les remplacent pas.

## 5. Qualifier la charge et la reprise sur la cible

Le [banc local](PERFORMANCE.md) est livré : deux fois 100 requêtes, avec concurrence 5 puis 10, sur 1 001 patients et 10 004 rendez-vous fictifs ; aucune erreur HTTP et p95 de 120,36 ms puis 152,94 ms.

À faire : définir les volumes, le nombre d'utilisateurs et les temps de réponse attendus, puis mesurer lectures, écritures, endurance, verrous, ressources, erreurs et restauration sur le vrai moteur et l'hébergement de recette. Les résultats du serveur PHP de développement/SQLite ne prouvent aucune capacité de production. Les garanties de concurrence des autres moteurs restent à qualifier avant adoption.

## 6. Terminer la validation de la première version mobile

| Travail restant | Résultat attendu |
| --- | --- |
| Installer l’APK debug compilé le 30 octobre sur le téléphone USB et recetter les parcours. | SDK installé, compilation et signature vérifiées ; appareil pas encore détecté. |
| Compiler iOS avec macOS/Xcode et une signature adaptée. | Version de test iOS installable. |
| Vérifier Keystore/Keychain, expiration, révocation et déconnexion. | Tests sur matériel réel, y compris échecs réseau et stockage. |
| Vérifier l'arrière-plan, les captures et les cinq minutes d'inactivité. | Comportement de confidentialité accepté sur chaque plateforme. |
| Recetter les formulaires, clavier, tailles, accessibilité et fuseau de la clinique. | Parcours patients/agenda validés par les utilisateurs. |
| Valider la reprise de session et les saisies non enregistrées. | Limite connue : la navigation/saisie peut être réinitialisée au retour d'arrière-plan dans cette première version. |
| Définir identifiants, environnements, signatures et versions définitifs. | Builds reproductibles et distribution de test organisée. |
| Préparer les éléments de publication si une publication est retenue. | Processus de livraison défini ; aucun envoi aux stores n'est effectué. |

Le client Flutter, le test HTTP réel et un APK debug compilé existent. Le téléphone reste à connecter et à autoriser pour la recette matérielle ; iOS nécessite toujours macOS/Xcode.

## 7. Développer les modules mobiles suivants

### Consultations et dossier médical — MOB-05

Exposer les endpoints et construire les écrans pour l'historique, la saisie/validation/correction, les informations du dossier et les documents privés. Réutiliser les droits auteur/partage et l'audit serveur. Définir le traitement des téléchargements et éviter une persistance locale non maîtrisée. Ce lot n'est pas implémenté dans l'API/mobile actuel.

### Facturation et paiements — MOB-06

Définir les contrats monétaires de l'API, exposer factures/soldes/paiements/reçus, puis les écrans. Réutiliser les calculs entiers, les clés d'idempotence et les permissions du web. Les garanties contre les doubles paiements doivent couvrir les retries mobiles. Ce lot n'est pas implémenté dans l'API/mobile actuel.

### Profil et notifications — MOB-07

La lecture du profil existe ; sa modification mobile reste à développer. Ajouter les préférences, permissions, jetons d'appareil et notifications de rendez-vous avec un contenu adapté à l'écran verrouillé. Aucun fournisseur de notifications ni envoi push mobile n'est intégré.

## 8. Extensions web à choisir selon les besoins

Ces extensions ne sont pas toutes nécessaires pour lancer le périmètre web initial d'un seul établissement :

| Domaine | Extensions non livrées |
| --- | --- |
| Planning | Disponibilités récurrentes, rappels automatiques, historique exhaustif des changements, horaires exceptionnels/jours fériés et plages multiples selon besoin. |
| Patients | Fusion contrôlée, exports nominatifs et purge après définition des politiques. L'archivage et les homonymes confirmés sont déjà livrés. |
| Clinique | Prescriptions/ordonnances, signature électronique et intégrations externes. |
| Facturation | Taxes, remises, assurances, avoirs, remboursements effectifs, rapprochement bancaire et export comptable. Un prestataire de paiement en ligne n'est pas intégré. |
| Documents financiers | Génération PDF serveur si nécessaire ; les impressions HTML via navigateur existent déjà. |
| Personnel | Contrats, paie, soldes de congés, circuits d'approbation, gardes et récurrences. |
| Administration | Indicateurs complémentaires et multi-établissements si retenus. Les tableaux de bord et CSV statistiques agrégés existent déjà. |
| Mobile | Mode hors ligne et synchronisation : hors du premier périmètre, à cadrer séparément avant implémentation. |

## Ordre de réalisation recommandé

1. Sauvegarder/restaurer, puis appliquer les trois migrations locales en attente.
2. Préparer une recette hébergée avec HTTPS, secrets, SMTP, workers/scheduler et supervision.
3. Mettre en service les sauvegardes distantes et réussir une reprise sur cette cible ; finaliser les protections de fichiers, mots de passe et politiques de données.
4. Faire accepter la recette métier web et valider la capacité sur l'environnement cible.
5. Compiler et recetter le mobile patients/agenda sur Android/iOS.
6. Développer MOB-05 puis MOB-06, et le profil/notifications selon les priorités retenues.

Les étapes de recette mobile peuvent avancer en parallèle de l'exploitation web. Le code de base est largement livré ; la mise en service fiable, la validation humaine et les modules mobiles suivants constituent maintenant l'essentiel du travail restant. Aucun délai fiable ne peut être chiffré sans les décisions d'hébergement, les volumes et les priorités métier.

Le 30 octobre, une revue de maintenabilité a ajouté les contrats de fonctions, le diagnostic `medicore:production-check` et les modèles d’hébergement. Voir [MAINTENANCE.md](MAINTENANCE.md) et [PRODUCTION.md](PRODUCTION.md). Ces préparatifs ne constituent pas un déploiement effectif.

## Mise à jour du lancement local — 30 octobre 2026

Les trois migrations mentionnées comme en attente dans le bilan initial ont été appliquées
sur la base locale, après sauvegarde chiffrée et vérification d’une restauration isolée.
Les clés applicatives existantes ont été conservées. Le serveur web/API, le worker
et le scheduler ont été démarrés localement ; cela ne constitue pas une mise en production.
Le SDK Android et Gradle ont été installés avec accord sur les licences pour préparer
le lancement sur un téléphone USB. La validation sur appareil reste à effectuer.

L’APK debug a ensuite été compilé avec succès et sa signature vérifiée :
`mobile/build/app/outputs/flutter-apk/app-debug.apk` (151,8 Mio, API 24 minimum, ARM/ARM64/x86_64).
Aucun téléphone USB n’était détecté lors de cette préparation ; aucune installation
ou recette sur appareil n’est encore déclarée réussie.
