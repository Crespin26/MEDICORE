# WEB-07 — Médecins et personnel

## Parcours et permissions

Le menu **Personnel** (`/personnel`) permet à l’administrateur de rechercher les membres, compléter leur fiche professionnelle et gérer leurs absences. La liste comprend les comptes actifs, en attente d’invitation et suspendus ; elle peut être filtrée par rôle et statut, et recherchée par nom, e-mail, matricule, fonction ou service.

Le menu **Mon profil professionnel** (`/personnel/me`) permet à chaque membre actif ayant un rôle attribué de consulter sa propre fiche et ses absences. Ce parcours est en lecture seule. L’ancien rôle non attribué `staff` reste sans accès jusqu’à l’attribution d’un rôle.

| Action | Administrateur | Médecin | Secrétaire / caissier |
| --- | --- | --- | --- |
| Liste et fiches de tout le personnel | Oui | Non | Non |
| Modifier une fiche professionnelle | Oui | Non | Non |
| Lire sa propre fiche et ses absences | Oui | Oui | Oui |
| Déclarer ou annuler une absence | Oui | Non | Non |
| Lire l’historique administratif | Oui | Non | Non |
| Déclarer ou retirer une disponibilité médicale | Tous les médecins | Ses disponibilités | Non |
| Gérer invitations, comptes et rôles | Oui | Non | Non |

Les invitations et rôles restent dans **Comptes du personnel** (`/staff`), selon WEB-01. Les écrans sont reliés par des liens. Un profil professionnel ne crée pas un nouveau compte ; il complète un compte existant, avec au maximum une fiche par utilisateur.

## Fiche professionnelle

Champs disponibles :

- Matricule facultatif, unique, jusqu’à 40 caractères (majuscules, chiffres, tirets et tirets bas).
- Fonction professionnelle obligatoire, jusqu’à 150 caractères.
- Service ou département facultatif, jusqu’à 150 caractères.
- Jusqu’à dix spécialités déclarées, de 100 caractères chacune.
- Téléphone professionnel facultatif, jusqu’à 30 caractères.
- Identifiant professionnel déclaré facultatif, jusqu’à 100 caractères.
- Date d’entrée facultative, pouvant indiquer une arrivée prévue.

Fonction, spécialités, identifiant professionnel et date d’entrée sont des informations déclarées. Ils n’accordent aucun rôle ou droit d’accès et ne réalisent aucune vérification auprès d’un registre externe. Le rôle et le statut du compte restent les sources des autorisations et de l’activation.

Chaque enregistrement exige un motif administratif. Une version attendue est vérifiée sous verrou pour refuser l’écrasement d’une correction concurrente. Les anciennes valeurs, l’auteur, son nom au moment de l’action, la date et le motif sont conservés dans l’historique administratif. La première création concurrente d’une fiche ne peut pas produire deux profils pour un compte.

## Absences

L’administrateur déclare une période avec début, fin, catégorie (congé, formation ou indisponibilité) et motif administratif. Ne pas renseigner de diagnostic ni de données médicales dans ce motif.

- La fin doit suivre le début et se situer dans le futur au moment de la déclaration. Une absence déjà commencée mais non terminée peut être déclarée.
- Une période peut couvrir plusieurs journées.
- La saisie utilise le fuseau de la clinique, puis est convertie en UTC pour le stockage.
- Les heures inexistantes ou ambiguës lors d’un changement d’heure sont refusées.
- Les absences actives d’une même personne ne peuvent pas se chevaucher ; des périodes contiguës sont autorisées.
- Des rendez-vous prévus, confirmés ou arrivés sur la période empêchent la déclaration. Ils doivent être déplacés ou annulés d’abord.
- Une absence active empêche de créer ou déplacer un rendez-vous sur la période.
- Une nouvelle disponibilité médicale ne peut pas chevaucher une absence active.

Les disponibilités médicales existantes sont conservées lorsqu’une absence est déclarée. Le contrôle final de réservation tient compte de l’absence ; la présence d’une ancienne plage de disponibilité ne permet pas de la contourner.

L’agenda affiche les absences des médecins pour la journée et les filtres sélectionnés. Il transmet le nom du médecin et la période, sans catégorie ni motif. Un médecin ne voit dans l’agenda que ses propres périodes ; l’administrateur et la secrétaire voient les périodes nécessaires à la planification. Le caissier n’obtient pas d’accès à l’agenda.

Une absence incorrecte se corrige en l’annulant avec un motif, puis en déclarant la bonne période. L’enregistrement initial reste dans l’historique. L’annulation libère le blocage de réservation, sous réserve des disponibilités et autres rendez-vous. Elle ne crée ni ne déplace de rendez-vous automatiquement.

## Concurrence et traçabilité

Les absences et réservations utilisent les mêmes transactions et verrous sur les membres concernés. Sur SQLite, le verrou d’écriture est pris avant toute lecture. Le test à deux processus lance une réservation et une déclaration d’absence sur le même créneau : une seule opération réussit, l’autre reçoit un conflit de validation.

Le contrôle de conflit est aussi exécuté lors d’un déplacement. Les modifications de profils, créations et annulations d’absences sont enregistrées avec leur événement d’historique dans la même transaction. Un échec d’écriture de cet événement annule la modification.

L’historique n’est accessible qu’à l’administrateur. Il n’existe pas d’endpoint de modification ou de suppression des événements, des profils ou des absences. Un compte référencé dans un dossier professionnel ou comme auteur de cet historique ne peut pas être supprimé depuis ses paramètres ; sa suspension reste possible.

Cette traçabilité est applicative et ne protège pas contre un opérateur capable de modifier directement la base. Les essais de concurrence ont été exécutés sur SQLite ; les autres moteurs restent à vérifier avant leur adoption.

## Limites

Cette version ne gère pas la paie, les contrats, les soldes de congés, une demande d’absence avec circuit d’approbation, les horaires de travail du personnel non médical, les gardes ou les récurrences de planning. Les disponibilités médicales réutilisent les plages datées de WEB-03.

Les données professionnelles et leur historique restent des informations privées à protéger dans les procédures d’exploitation et de sauvegarde. Aucune purge automatique ni durée de conservation particulière n’est mise en place par cet incrément.

## Installation et vérifications

```bash
cd web
php artisan migrate
npm run build
php artisan test
npm run typecheck
npm run lint:check
```

La migration ajoute les fiches, absences et événements, sans modifier les comptes ou rôles existants. Les profils sont complétés à la demande par l’administrateur.

Les tests couvrent les permissions, la consultation de sa propre fiche, l’unicité des matricules, les versions, les périodes d’absence, la confidentialité des motifs dans l’agenda, les déplacements refusés, les annulations, le retour arrière transactionnel et les demandes simultanées. Les tests de concurrence nécessitent SQLite dans les processus enfants ; utiliser `MEDICORE_TEST_PHP_OPTIONS` si les extensions sont chargées par des options PHP temporaires.
