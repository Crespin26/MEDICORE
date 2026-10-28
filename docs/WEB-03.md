# WEB-03 — Rendez-vous et disponibilités

## Utilisation

Le menu **Rendez-vous** ouvre `/appointments`. L’écran affiche une semaine de calendrier, la liste paginée du jour sélectionné, les filtres par médecin et statut, ainsi que les disponibilités déclarées. Chaque journée du calendrier présente au maximum trois rendez-vous et le nombre total ; ouvrir la journée donne accès à toute sa liste.

1. Activer au moins un compte avec le rôle médecin depuis les comptes du personnel.
2. Depuis **Gérer les disponibilités** (`/availabilities`), un administrateur ou le médecin concerné déclare une plage datée.
3. L’administrateur ou la secrétaire recherche un patient existant, le sélectionne, choisit le médecin et saisit le début et la fin du rendez-vous.
4. L’équipe suit le statut dans la liste du jour. Les rendez-vous prévus ou confirmés peuvent être déplacés ou annulés.

La fiche patient présente aussi ses dix rendez-vous aux dates les plus récentes, selon les droits de l’utilisateur. Un médecin n’y voit que ses propres rendez-vous.

## Permissions

| Action | Administrateur | Secrétaire | Médecin | Caissier / ancien rôle staff |
| --- | --- | --- | --- | --- |
| Consulter le calendrier et la liste | Tous | Tous | Son agenda | Non |
| Planifier ou déplacer | Oui | Oui | Non | Non |
| Confirmer ou annuler | Oui | Oui | Non | Non |
| Indiquer arrivé, terminé ou absent | Oui | Oui | Ses rendez-vous | Non |
| Déclarer ou retirer une disponibilité | Tous les médecins | Non | Ses disponibilités | Non |

Les filtres envoyés par le navigateur ne peuvent pas élargir l’agenda d’un médecin. Les recherches de patients pour la planification sont réservées aux personnes pouvant créer des rendez-vous et retournent au maximum 20 identités, sans adresse ni coordonnées.

## Horaires et disponibilités

- `CLINIC_TIMEZONE=Africa/Porto-Novo` est le fuseau par défaut, défini dans `web/config/clinic.php` et modifiable dans le `.env` du déploiement avec un identifiant IANA.
- Conserver `APP_TIMEZONE=UTC` pour le serveur et le stockage. La saisie et l’affichage utilisent le fuseau de la clinique, indépendamment de celui du navigateur.
- Les plages doivent commencer dans le futur ; la fin doit suivre le début le même jour local. Pour une plage passant minuit, déclarer les journées séparément ; les rendez-vous passant minuit ne sont pas pris en charge dans cette version.
- Les horaires inexistants ou ambigus lors d’un changement d’heure sont refusés explicitement.
- Les disponibilités sont datées. Il n’y a pas de récurrence hebdomadaire. Les absences datées sont gérées dans [WEB-07](WEB-07.md) et bloquent les réservations.
- Les horaires d’établissement peuvent aussi limiter les nouveaux créneaux (voir [WEB-08](WEB-08.md)). Une réservation doit être entièrement comprise dans une seule disponibilité. Deux disponibilités contiguës ne sont pas fusionnées automatiquement.
- Deux disponibilités d’un même médecin ne peuvent pas se chevaucher. Pour corriger une plage non utilisée, la retirer puis la recréer.
- Une disponibilité avec rendez-vous prévu, confirmé ou arrivé ne peut pas être retirée : déplacer ou annuler ces rendez-vous d’abord.

## Statuts

| Depuis | Vers |
| --- | --- |
| Prévu | Confirmé, arrivé, annulé, absent |
| Confirmé | Arrivé, annulé, absent |
| Arrivé | Terminé, annulé |
| Terminé, annulé, absent | Aucun autre statut |

Le médecin est limité aux transitions vers arrivé, terminé et absent, sur ses propres rendez-vous. L’arrivée, la fin et l’absence ne peuvent être enregistrées avant l’heure de début. Un déplacement remet le statut à **prévu** pour renouveler la confirmation. Une annulation conserve l’enregistrement et libère le créneau ; aucun endpoint de suppression de rendez-vous n’est exposé.

Un rendez-vous terminé indique uniquement la fin de ce rendez-vous : aucun compte rendu de consultation n’est créé automatiquement.

## Chevauchements et concurrence

Les rendez-vous prévus, confirmés et arrivés occupent leur intervalle. Deux rendez-vous ne peuvent pas se chevaucher pour le même médecin ni pour le même patient. Des créneaux contigus sont autorisés.

Les écritures passent par `AppointmentScheduler`. La vérification de la disponibilité et des conflits intervient dans la même transaction que l’écriture. Les mutations de planning utilisent des verrous sur les médecins ; la première version verrouille les médecins dans un ordre commun pour sécuriser les changements de médecin. Ce choix privilégie la cohérence pour un établissement et devra être réévalué sous forte charge.

SQLite prend un verrou d’écriture dès le début de la transaction, avant toute lecture. Un test avec deux processus indépendants réserve simultanément le même créneau : une demande réussit et l’autre reçoit un conflit de validation. Ce test est inclus dans la suite. Les autres moteurs n’ont pas été exécutés dans cet environnement ; leur comportement devra être vérifié avant une migration de base de données.

## Comptes et données existantes

Un compte médecin possédant un agenda ne peut plus être supprimé depuis ses paramètres. La suspension reste disponible aux administrateurs. Un médecin suspendu ou dont le rôle a changé ne peut pas recevoir de nouvelle réservation ; ses rendez-vous existants restent visibles à l’administration pour annulation ou réaffectation.

Les notifications aux patients, le rappel automatique, les motifs médicaux et l’historique détaillé des changements restent hors de cet incrément.

## Installation et vérification

```bash
cd web
php artisan migrate
npm run build
php artisan test
npm run typecheck
npm run lint:check
```

Le test de concurrence utilise une base SQLite temporaire privée et ne touche pas à la base locale. Il nécessite l’extension SQLite dans les processus PHP enfants. Si les extensions sont chargées avec des options `php -d`, transmettre les mêmes options en tableau JSON dans `MEDICORE_TEST_PHP_OPTIONS` lors de l’exécution des tests.
