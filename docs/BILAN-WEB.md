# Bilan de la partie web MediCore

Bilan du 28 octobre 2026, établi sur le code local au commit `79213ea`, les guides WEB-01 à WEB-08, les contrôles locaux et les audits de dépendances. Le bilan initial était documentaire. La mise à jour du 29 octobre ci-dessous décrit les correctifs de dépendances appliqués ensuite.

## Mise à jour du 29 octobre 2026 — recette et besoins métier

À la demande de traiter les trois volets, le lot ajoute les homonymes confirmés et motivés par un administrateur, l’archivage réversible sans suppression, et le partage explicite/révocable en lecture seule des éléments d’un médecin pour un patient. Les corrections restent réservées à l’auteur ; le partage n’accorde aucun accès clinique aux administrateurs, secrétaires ou caissiers.

La suite PHP complète a réussi **184 tests et 2 044 assertions**, incluant les nouveaux droits, règles patient et la reprise des motifs chiffrés. La recette Playwright a réussi **26 scénarios sur Chromium**, sur bureau et à 390 × 844 : rôles, refus directs, patients/rendez-vous, consultation liée, correction, documents privés, facturation/paiements/reçu, CSRF, déconnexion, homonymes, archivage/réactivation et partage/révocation. TypeScript, contrôle des tests TypeScript, ESLint et compilation passent. L’audit npm après ajout des outils de test ne signale aucun avis.

Voir le [guide de recette et de règles métier](WEB-RECETTE.md) pour le détail, les commandes, l’isolation et les décisions restant à faire accepter par l’établissement. Les autres navigateurs, la recette humaine et l’accessibilité complète restent à vérifier. La CI est configurée ; son résultat distant n’a pas été inspecté. Les migrations n’ont pas été appliquées sur la base active locale.

## Mise à jour du 29 octobre 2026 — protection et reprise

Le point 2 dispose désormais d'une implémentation : chiffrement des consultations et de leurs révisions, migration des valeurs existantes, commandes de sauvegarde SQLite chiffrée et de restauration isolée, vérification des clés et des documents. Voir le [guide de reprise](RECOVERY.md).

Validation locale après ces changements : **177 tests et 1 888 assertions réussis**, dont migration des anciennes valeurs, annulation transactionnelle en cas d’échec, rotation avec ancienne clé, sauvegarde/restauration de la base et des documents, refus des archives altérées et des clés manquantes. Les exercices automatisés utilisent des données fictives. La migration n'a pas été exécutée sur la base active du développeur. Le coffre, le stockage distant, la fréquence, la rétention et la validation sur l'hébergement cible restent à provisionner. Le point 2 n'est donc pas déclaré terminé pour une exploitation réelle.

## Mise à jour du 29 octobre 2026 — dépendances

Le point 1 du plan de suite est réalisé pour les avis connus lors de la vérification : les fichiers `web/package-lock.json` et `web/composer.lock` ont été mis à jour sans changement de version majeure ni de code métier.

- npm : mises à jour compatibles, dont Vite 6.1.1 → 6.4.5, Rollup 4.66.0, axios 1.21.0, form-data 4.0.6, shell-quote 1.12.0 et brace-expansion 1.1.21.
- Composer : symfony/mime 7.4.19 → 7.4.22 et symfony/polyfill-mbstring 1.43.0 → 1.43.3.
- Installation propre `npm ci` réussie : **aucune vulnérabilité signalée**, contre 25 dépendances signalées initialement.
- `npm audit --omit=dev --json` : **aucune vulnérabilité signalée**, contre 17 dépendances signalées initialement.
- `composer audit --format=json` : **aucun avis et aucun paquet abandonné**, contre un avis initialement.
- Validation : **167 tests, 1 819 assertions réussis**, contrôle TypeScript, ESLint et compilation Vite réussis ; `composer validate --no-check-publish` réussi.

L'extension SQLite PHP a été chargée temporairement depuis `/tmp`, y compris dans les processus des tests de concurrence. Aucun réglage système ni donnée métier n'a été modifié. Les alertes npm de dépréciation sur ESLint 9 et lodash.isequal restent des sujets de maintenance distincts des avis de sécurité. Les résultats d'audit ne constituent pas une garantie de sécurité globale et doivent être revérifiés régulièrement.

Les sections suivantes conservent le diagnostic initial du 28 octobre ; leurs chiffres d'audit sont historiques.

## État général

Les huit incréments web sont implémentés dans leur périmètre initial. MediCore constitue un MVP fonctionnel à soumettre à une recette avec des données fictives. Cela ne signifie pas que toutes les fonctionnalités d'une clinique sont couvertes, ni que le produit est prêt pour des dossiers patients réels.

La préparation à la production reste ouverte : dépendances vulnérables, protection de certaines données stockées, exploitation, livraison des courriels et validation des usages réels. Aucun pourcentage global de complétude ne peut être établi sans un périmètre de production accepté.

## Architecture

- Application Laravel 12 avec React 19, TypeScript et Inertia 2 dans `web/` ; navigation et autorisations intégrées au serveur Laravel.
- Versions verrouillées examinées : Laravel 12.70.1, React 19.0.0, Inertia Laravel 2.0.28, Inertia React 2.0.3, TypeScript 5.7.3, Vite 6.1.1 et PHPUnit 11.5.57.
- SQLite pour le développement local. Les garanties testées sur SQLite ne constituent pas une validation sur un autre moteur ni sous forte charge.
- Un établissement : aucune isolation multi-établissements n'est implémentée.
- Aucune API mobile dédiée à ce stade ; le socle Flutter n'est pas connecté au serveur.

## Fonctionnalités livrées et limites

| Module | Disponible | Limites et suites |
| --- | --- | --- |
| [WEB-01 — Authentification](WEB-01.md) | Connexion, déconnexion, réinitialisation, paramètres du compte, rôles, invitations expirantes à usage unique, suspension et réactivation. Inscription publique fermée. Contrôles côté serveur, CSRF, limitation des tentatives de connexion et révocation des sessions des comptes suspendus. | Livraison SMTP réelle non validée. Politique de mot de passe à harmoniser entre invitation, changement et réinitialisation. Pas de second facteur implémenté. |
| [WEB-02 — Patients](WEB-02.md) | Création, modification, fiche détaillée, recherche, filtres et pagination. Identité, naissance et coordonnées ; liens vers les modules accessibles. Prévention des doublons, y compris à la création concurrente. | Pas d'archivage ni de fusion. La clé prénom + nom + date de naissance peut bloquer deux homonymes réels ; prévoir un traitement contrôlé. |
| [WEB-03 — Rendez-vous](WEB-03.md) | Agenda hebdomadaire et journalier, filtres, disponibilités datées, réservation, déplacement, confirmation, arrivée, fin, absence et annulation. Contrôle des chevauchements du patient et du médecin ; intégration des absences et horaires d'ouverture. | Pas de disponibilités récurrentes ni de rappels automatiques. Pas de rendez-vous traversant minuit. Historique exhaustif des changements à compléter. |
| [WEB-04 — Consultations](WEB-04.md) | Motif, observations, constantes, compte rendu, brouillon, validation, corrections motivées et versions. Association facultative à un rendez-vous compatible ; protection contre les modifications concurrentes. | Accès réservé au médecin auteur : pas de transmission entre médecins. Pas d'ordonnance ni de signature. Contenu des consultations et instantanés de révision non chiffrés au repos par les modèles actuels. |
| [WEB-05 — Dossier médical](WEB-05.md) | Antécédents, allergies et traitements déclarés, éléments actifs ou résolus, versions et corrections motivées. Documents PDF/JPEG/PNG privés, chiffrés, limités à 10 Mo, téléchargements autorisés et journal des accès du médecin auteur. | Pas de dossier partagé entre médecins. Pas d'antivirus intégré. Conservation, purge et restauration à définir. Un dossier vide ne prouve pas l'absence d'allergies. |
| [WEB-06 — Facturation](WEB-06.md) | Catalogue, brouillons, émission numérotée, paiements partiels, soldes et reçus. Prix et identité figés à l'émission, calculs en unités monétaires entières, idempotence, annulations et historique financier. XOF, EUR et USD sans conversion. | Encaissements enregistrés manuellement, sans prestataire bancaire. Impression HTML, sans génération PDF serveur. Pas de taxes, assurance, avoirs, remboursement automatique, rapprochement bancaire ni export comptable. Mentions à valider pour le déploiement. |
| [WEB-07 — Personnel](WEB-07.md) | Annuaire, profils professionnels, matricule, fonctions, spécialités et absences. Absences intégrées au planning ; conflits avec les rendez-vous bloqués ; historique administratif. | Pas de paie, contrats, compteurs de congés ou workflow complet de validation. Les qualifications renseignées ne sont pas vérifiées automatiquement. |
| [WEB-08 — Administration](WEB-08.md) | Indicateurs réels selon le rôle, périodes, activité, finances séparées par devise, paramètres d'établissement et horaires hebdomadaires. Exports CSV statistiques agrégés réservés à l'administrateur, motivés et journalisés. | Pas d'export nominatif de patients. Pas de multi-établissements, de calendrier complet des jours exceptionnels ni de plusieurs plages d'ouverture par jour. Les indicateurs financiers ne remplacent pas une comptabilité bancaire. |

## Répartition des accès

| Rôle | Accès principaux | Restrictions importantes |
| --- | --- | --- |
| Administrateur | Patients, planning global, comptes, personnel, facturation, catalogue, annulations, paramètres et exports statistiques. | Aucun accès aux notes de consultation et dossiers médicaux réservés au médecin auteur. |
| Médecin | Lecture des patients, agenda et disponibilités personnels, suivi de ses rendez-vous, consultations, dossier et documents dont il est auteur. | Pas de lecture des notes d'un autre médecin, de finance globale ni d'administration du personnel. |
| Secrétaire | Création et modification des patients, organisation des rendez-vous et suivi des statuts. | Aucun accès aux notes médicales, à la facturation ou à la gestion RH. |
| Caissier | Factures, paiements, reçus et recherche minimale du patient nécessaire à la facturation. | Pas de dossier patient complet, de notes, de planning, de catalogue ou d'annulations réservées à l'administrateur. |
| Ancien rôle `staff` | Compte sans permissions métier tant qu'un rôle n'est pas attribué. | Aucun accès clinique accordé implicitement. |

La séparation actuelle protège les données cliniques, mais son adéquation au fonctionnement d'une équipe de soins doit être validée avant usage réel, notamment pour les remplacements et la continuité du suivi.

## Vérifications et niveau de preuve

Exécution locale au moment du bilan initial : **167 tests PHPUnit, 1 819 assertions, réussis**. Le contrôle TypeScript, ESLint et la compilation Vite ont également réussi. Une nouvelle exécution après correction des dépendances a confirmé ces résultats le 29 octobre (voir ci-dessus).

Les tests couvrent notamment les autorisations, validations, statuts, corrections et plusieurs conflits réels entre processus : réservations concurrentes, doublons de paiement, dépassement du solde, absences face aux réservations et modification des horaires face aux réservations.

Le workflow `.github/workflows/checks.yml` configure les contrôles PHP, TypeScript, lint, build et Flutter. Aucun résultat distant GitHub Actions n'a été vérifié pour ce bilan.

Limites de la validation :

- Aucun taux de couverture mesuré ; le nombre de tests ne prouve pas une couverture exhaustive.
- Aucune suite navigateur de type Playwright, Cypress ou Dusk repérée dans les fichiers examinés.
- Recette complète par rôle, ergonomie, accessibilité, affichage sur petits écrans et navigateurs à réaliser.
- Pas de mesure de charge ni de validation sur le futur moteur de base de production.
- Pas d'audit d'intrusion effectué dans ce bilan.

L'environnement PHP local a nécessité des extensions SQLite chargées depuis `/tmp`. Cette solution temporaire ne remplace pas une installation reproductible des extensions requises.

## Sécurité : acquis et écarts

### Protections présentes

Les contrôles d'accès sont appliqués côté serveur. Les accès aux documents privés vérifient le rôle, l'auteur et le patient ; les fichiers sont stockés hors du répertoire public. Des contrôles de type, d'intégrité et de taille accompagnent leur chiffrement. Les entrées du dossier médical et leurs révisions sensibles utilisent aussi le chiffrement applicatif.

Les opérations financières et de planning sensibles utilisent des transactions et des verrous. Les consultations, dossiers, opérations financières, changements de personnel et paramètres disposent de mécanismes de version ou de traçabilité selon leur périmètre.

### Audits initiaux de dépendances du 28 octobre

| Contrôle | Résultat |
| --- | --- |
| `npm audit --json` | **25 dépendances signalées : 2 critiques, 13 élevées, 6 modérées et 4 faibles.** |
| `npm audit --omit=dev --json` | **17 dépendances signalées : 2 critiques, 9 élevées, 4 modérées et 2 faibles.** |
| `composer audit --format=json` | **Un avis de gravité moyenne sur `symfony/mime` : CVE-2026-45070.** Aucun paquet abandonné signalé. |

Les nombres npm désignent des dépendances signalées, pas autant de failles indépendantes ni de parcours exploitables démontrés. Les deux dépendances classées critiques sont `form-data` et `shell-quote`. Vite est également signalé avec une gravité élevée.

Des outils de développement sont déclarés dans `dependencies` : le résultat `--omit=dev` ne mesure donc pas à lui seul la surface réellement exposée en production. Il faut examiner les chaînes de dépendances, les usages et le mode de déploiement, appliquer les mises à jour compatibles, puis relancer les contrôles. Ces alertes ont été traitées le 29 octobre ; voir les résultats actualisés en tête du document.

Références des avis : [form-data](https://github.com/advisories/GHSA-fjxv-7rqg-78g4), [shell-quote](https://github.com/advisories/GHSA-w7jw-789q-3m8p), [symfony/mime](https://symfony.com/cve-2026-45070). Les résultats sont ceux des registres interrogés au moment du bilan et peuvent évoluer.

### Écarts à traiter avant des données réelles

- Étendre la protection au repos aux consultations et à leurs révisions ; inventorier également les identités et autres métadonnées laissées en clair. Préparer une migration des données existantes et la gestion des clés.
- Harmoniser les règles de mot de passe : l'invitation impose 12 caractères, tandis que changement et réinitialisation utilisent la règle Laravel par défaut. Évaluer le second facteur selon les accès et le contexte.
- Ajouter une analyse des fichiers téléversés et aligner les limites PHP avec la limite applicative de 10 Mo.
- Définir la conservation, l'archivage, les suppressions autorisées et les sauvegardes chiffrées ; prouver la restauration, y compris avec les clés nécessaires au déchiffrement.
- Renforcer la protection des journaux : leur présence en base ne les rend pas inaltérables face à un administrateur de base. Les refus d'accès et événements de sécurité nécessitent aussi une stratégie de surveillance.
- Valider les obligations et les modalités d'hébergement pour les pays et établissements concernés ; aucune conformité n'est certifiée par ce bilan.

## Exploitation

La configuration examinée est locale : `APP_ENV=local`, `APP_DEBUG=true`, SQLite, courriels dans les logs et `SESSION_ENCRYPT=false`. Ces choix décrivent l'environnement de développement, pas une configuration de production déjà déployée.

Avant mise en service : préparer HTTPS, cookies et sessions, secrets dédiés, transport SMTP, files de traitement si nécessaires, tâches planifiées, permissions de stockage, gestion des clés, supervision et alertes. Aucun dispositif de sauvegarde avec restauration éprouvée ni de déploiement de production n'a été validé ici.

Les invitations et réinitialisations doivent être testées avec de vrais courriels de recette : réception, expiration et invalidation des liens, sans utiliser de données patients réelles.

## Plan de suite proposé

| Priorité | Travail | Critère de validation |
| --- | --- | --- |
| P0 — réalisé le 29 octobre | Mettre à jour et analyser les dépendances signalées. | Aucun avis npm/Composer après correction ; tests et compilation réussis. Surveillance régulière à maintenir. |
| P0 — outils et tests locaux livrés le 29 octobre | Protéger les consultations stockées et organiser les clés, sauvegardes et restauration. | Migration et reprise testées sur données fictives ; coffre, copies distantes et exercice sur hébergement cible encore requis. |
| P0 | Préparer la configuration d'exploitation, le SMTP et la surveillance. | Parcours d'invitation/réinitialisation reçus, configuration revue, alertes vérifiées. |
| P1 — règles et fonctions livrées localement | Homonymes, archivage et relais entre médecins ; validation des règles et documents de facturation. | Scénarios automatisés et règles documentées ; acceptation par l’établissement et mentions de facturation encore requises. |
| P1 — automatisation livrée | Recette navigateur par rôle et accès interdits. | 26 scénarios Chromium réussis sur deux tailles de fenêtre ; recette humaine, autres navigateurs et accessibilité complète à terminer. |
| P1 | Mesurer la charge sur le moteur et l'hébergement cibles. | Objectifs de volume et temps de réponse définis, conflits et restauration vérifiés dans cet environnement. |
| P2 | Étendre selon les besoins : rappels, récurrence, exports comptables, assurances et API mobile. | Périmètre priorisé après recette du socle web. |

## Conclusion

Le socle fonctionnel web couvre les huit modules prévus et bénéficie de tests métier substantiels. La prochaine étape utile est une phase de stabilisation et de recette, en poursuivant par la protection des données après les correctifs de dépendances du 29 octobre. Une utilisation avec des données médicales réelles reste conditionnée à ces travaux et à la validation de l'exploitation.
