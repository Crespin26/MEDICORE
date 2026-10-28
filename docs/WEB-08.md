# WEB-08 — Tableau de bord et administration

## Indicateurs selon le rôle

Le tableau de bord utilise les données enregistrées dans MediCore, sans chiffres de démonstration. Les sections sont calculées côté serveur selon les permissions :

| Indicateur | Administrateur | Médecin | Secrétaire | Caissier |
| --- | --- | --- | --- | --- |
| Patients actuels et créations sur la période | Oui | Oui | Oui | Non |
| Rendez-vous et agenda du jour | Tous | Ses rendez-vous | Tous | Non |
| Consultations par statut | Non | Ses consultations | Non | Non |
| Montants financiers par devise | Oui | Non | Non | Oui |
| Export statistique et journal des exports | Oui | Non | Non | Non |
| Paramètres de l’établissement | Oui | Non | Non | Non |

Les consultations sont seulement comptées par statut pour le médecin connecté. Aucun motif, compte rendu, constante ou document médical ne figure dans ces statistiques. L’agenda du jour affiche les huit premiers rendez-vous accessibles, avec un lien vers la liste complète.

## Périodes et définitions

La période par défaut couvre les trente derniers jours, jour courant inclus. Les dates de début et de fin sont inclusives, dans le fuseau de la clinique. La limite est de 93 jours ; les périodes inversées ou futures sont refusées. Le stockage et les comparaisons techniques utilisent UTC, avec conversion des bornes locales.

- Les nouveaux patients correspondent à la date de création de leur fiche.
- Les rendez-vous correspondent à leur date de début.
- Les consultations correspondent à leur date clinique et à leur statut actuel.
- Les patients enregistrés actuellement, le solde impayé actuel et l’agenda du jour restent indépendants du filtre de période. L’interface l’indique.

Les états vides sont explicites. Les répartitions des rendez-vous présentent les nombres par statut et des barres de comparaison.

## Montants financiers

Les devises restent séparées et ne sont jamais additionnées entre elles. Les montants sont calculés en unités mineures entières puis formatés côté serveur. Les valeurs numériques exportées en unités mineures sont transmises sous forme de chaînes pour préserver leur représentation exacte.

Pour chaque devise présente dans les factures :

- **Encaissements saisis** : paiements enregistrés pendant la période, avant déduction des annulations.
- **Paiements annulés** : écritures dont l’annulation a été enregistrée pendant la période, même si le paiement initial date d’avant.
- **Mouvements nets** : encaissements saisis moins écritures annulées sur cette période. Le résultat peut être négatif.
- **Impayé actuel** : somme des soldes de toutes les factures actuellement émises ou partiellement payées, quelle que soit leur date.

Les mouvements nets décrivent les écritures de MediCore ; ils ne représentent pas un relevé bancaire, un remboursement effectif ou un rapprochement de trésorerie. Les données sont recalculées à chaque demande et ne constituent pas une clôture historique figée.

## Paramètres de l’établissement

Le menu **Établissement** ouvre `/administration/settings`. Seul l’administrateur peut consulter et modifier :

- Nom, téléphone, e-mail et adresse.
- Devise des nouveaux actes et factures : XOF, EUR ou USD.
- Horaires hebdomadaires de réservation, facultatifs.

La devise enregistrée en base prend priorité sur `CLINIC_CURRENCY`. Avant le premier enregistrement, la configuration existante reste utilisée. Les anciennes factures, actes et paiements ne sont pas convertis. Un ancien formulaire de création indiquant une devise différente de la nouvelle configuration est refusé pour éviter une saisie dans la mauvaise devise.

Les nouvelles factures conservent les coordonnées de l’émetteur lors de leur émission. Un changement ultérieur de coordonnées ne réécrit pas leur version imprimable ni leurs reçus. Les factures déjà émises avant WEB-08 n’ont pas cette copie historique ; elles affichent les coordonnées courantes comme auparavant.

Chaque sauvegarde exige un motif et la version attendue. Les modifications conservent leur auteur, la date et une copie des paramètres dans un historique paginé. Une version dépassée ou un échec d’écriture de l’historique empêche la modification.

Le fuseau reste configuré au déploiement via `CLINIC_TIMEZONE`, et affiché en lecture seule dans ces paramètres. Conserver `APP_TIMEZONE=UTC`. Un changement de fuseau au déploiement exige une vérification du planning ; il n’est pas exposé comme une modification courante dans cette version.

## Horaires de réservation

Par défaut, les horaires d’établissement ne restreignent pas le planning existant. L’administrateur peut activer les restrictions, choisir au moins un jour ouvert et définir une plage d’ouverture par jour. La fermeture doit suivre l’ouverture le même jour ; les plages nocturnes, pauses multiples et fermetures exceptionnelles ne sont pas gérées dans cette première version.

Les nouveaux rendez-vous et disponibilités médicales doivent respecter ces plages. Les disponibilités propres au médecin et ses absences continuent aussi de s’appliquer. Les anciennes plages de disponibilité ne sont pas réécrites automatiquement.

Des horaires qui excluraient un rendez-vous prévu, confirmé ou arrivé et non terminé dans le temps sont refusés. Il faut d’abord déplacer ou annuler ces rendez-vous. Le contrôle des horaires est relu sous verrou lors d’une réservation. Un test à deux processus vérifie qu’une réservation et un changement incompatible d’horaires ne peuvent pas être enregistrés simultanément.

## Politique des exports

L’export disponible est uniquement un **CSV de statistiques agrégées**, réservé aux administrateurs. Il ne propose pas de ligne patient, rendez-vous nominatif, note médicale, consultation, fichier privé ou donnée du personnel. Il ne remplace pas un export comptable.

Depuis le tableau de bord :

1. Appliquer la période voulue.
2. Saisir le motif du téléchargement.
3. Télécharger le CSV, recalculé à ce moment pour la période affichée.

Les colonnes sont fixes : métrique, nombre, devise, montant en unités mineures, dates de période et horodatage UTC du calcul. Les noms de patients, coordonnées de l’établissement et motifs d’export ne sont pas inclus dans le CSV. Les noms de métriques proviennent du serveur, sans champ libre utilisateur dans les cellules exportées.

Chaque export enregistre l’auteur, son nom, la période, le motif, le nombre de lignes, la date et l’empreinte SHA-256 du fichier. Le journal est consultable dans `/administration/exports`. Le fichier lui-même n’est pas conservé sur le serveur. L’échec de journalisation bloque la restitution du CSV. Les réponses sont privées et sans cache ; la route est protégée par authentification, permission, CSRF et limitation de fréquence.

Aucune route d’édition ou de suppression du journal n’est exposée. La protection contre les modifications directes en base et la durée de conservation restent à définir dans les procédures d’exploitation.

## Installation et vérifications

```bash
cd web
php artisan migrate
npm run build
php artisan test
npm run typecheck
npm run lint:check
```

La migration initialise les paramètres avec le nom existant, sans activer d’horaires supplémentaires ni remplacer la devise issue de l’environnement. Elle ajoute les historiques et la copie des coordonnées d’émetteur aux nouvelles émissions.

Les tests couvrent les permissions, l’isolation des indicateurs, les bornes locales, les montants par devise, les annulations hors période, les versions des paramètres, les horaires, les formulaires de devise périmés, les coordonnées figées, les CSV sans identité et les échecs d’audit. Les essais de concurrence ont été exécutés sur SQLite ; les autres moteurs restent à vérifier avant leur adoption.
